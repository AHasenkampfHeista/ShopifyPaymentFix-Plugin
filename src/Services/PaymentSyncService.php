<?php

namespace ShopifyPaymentFix\Services;

use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Payment\Models\Payment;
use Plenty\Modules\Payment\Models\PaymentProperty;
use Plenty\Modules\Order\Models\OrderAmount;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Order\Models\Order;

class PaymentSyncService
{
    use Loggable;

    private PaymentRepositoryContract $paymentRepository;
    private PaymentOrderRelationRepositoryContract $orderRelationRepository;
    private ConfigRepository $config;

    public function __construct(
        PaymentRepositoryContract $paymentRepository,
        PaymentOrderRelationRepositoryContract $orderRelationRepository,
        ConfigRepository $config
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->orderRelationRepository = $orderRelationRepository;
        $this->config = $config;
    }

    /**
     * Ensure an order has a PayPal payment created from the Shopify transaction.
     *
     * @param Order $order
     * @param string $externalOrderId
     * @param array $shopifyOrder
     * @param bool $enableDebug
     */
    public function ensurePaypalPayment(Order $order, string $externalOrderId, array $shopifyOrder, bool $enableDebug = false): void
    {
        $orderId = (int) $order->id;
        $paypalMopId = (int) $this->config->get('ShopifyPaymentFix.global.paypalMopId');

        if ($paypalMopId <= 0) {
            $this->getLogger('PaymentSyncService_ensurePaypalPayment')
                ->error('ShopifyPaymentFix::logs.configMissing', [
                    'orderId' => $orderId,
                    'key' => 'global.paypalMopId',
                ]);
            return;
        }

        $paypalTransaction = $this->extractPaypalTransaction($shopifyOrder);
        if ($paypalTransaction === null) {
            $this->getLogger('PaymentSyncService_ensurePaypalPayment')
                ->warning('ShopifyPaymentFix::logs.missingPaypalAmount', [
                    'orderId' => $orderId,
                    'externalOrderId' => $externalOrderId,
                ]);
            return;
        }

        $existingPayments = $this->paymentRepository->getPaymentsByOrderId($orderId);
        if ($this->hasMatchingPayment($existingPayments, $paypalTransaction['transactionId'], $paypalMopId)) {
            if ($enableDebug) {
                $this->getLogger('PaymentSyncService_ensurePaypalPayment')
                    ->info('ShopifyPaymentFix::logs.paymentAlreadyExists', [
                        'orderId' => $orderId,
                        'transactionId' => $paypalTransaction['transactionId'],
                    ]);
            }
            return;
        }

        $receivedAt = $paypalTransaction['processedAt'] ?? date('Y-m-d H:i:s');
        $currency = $paypalTransaction['currency'];
        $amount = $paypalTransaction['amount'];
        $transactionId = $paypalTransaction['transactionId'];

        $paymentData = [
            'mopId' => $paypalMopId,
            'type' => Payment::PAYMENT_TYPE_BOOKED,
            'status' => Payment::STATUS_APPROVED,
            'amount' => $amount,
            'currency' => $currency,
            'exchangeRate' => $this->determineExchangeRate($order, $currency),
            'isSystemPayment' => false,
            'receivedAt' => $receivedAt,
            'properties' => [
                [
                    'typeId' => 1,
                    'value' => $transactionId,
                ],
                [
                    'typeId' => 22,
                    'value' => 'Shopify split payment fix',
                ],
                [
                    'typeId' => 23,
                    'value' => 'shopify_paypal_split'
                ],
            ],
            'regenerateHash' => true,
        ];

        $payment = $this->paymentRepository->createPayment($paymentData);

        $this->orderRelationRepository->createOrderRelationWithValidation(
            (int) $payment->id,
            $orderId,
            null,
            true
        );

        $this->getLogger('PaymentSyncService_ensurePaypalPayment')
            ->notice('ShopifyPaymentFix::logs.createdPayment', [
                'orderId' => $orderId,
                'paymentId' => $payment->id,
                'transactionId' => $transactionId,
            ]);
    }

    private function extractPaypalTransaction(array $shopifyOrder): ?array
    {
        $transactions = $shopifyOrder['transactions']['edges'] ?? [];
        foreach ($transactions as $transactionEdge) {
            $node = $transactionEdge['node'] ?? [];
            $gateway = strtolower((string) ($node['gateway'] ?? ''));
            if (strpos($gateway, 'paypal') === false) {
                continue;
            }

            $amountSet = $node['amountSet']['shopMoney'] ?? [];
            $amount = isset($amountSet['amount']) ? (float) $amountSet['amount'] : null;
            $currency = $amountSet['currencyCode'] ?? null;
            if ($amount === null || $currency === null) {
                continue;
            }

            return [
                'transactionId' => (string) ($node['id'] ?? ''),
                'amount' => $amount,
                'currency' => $currency,
                'processedAt' => $node['processedAt'] ?? null,
            ];
        }

        return null;
    }

    private function hasMatchingPayment(array $existingPayments, string $transactionId, int $paypalMopId): bool
    {
        foreach ($existingPayments as $payment) {
            $paymentArray = $this->normalizeToArray($payment);

            $mopId = isset($paymentArray['mopId']) ? (int) $paymentArray['mopId'] : null;
            if ($mopId !== $paypalMopId) {
                continue;
            }

            $properties = $paymentArray['properties'] ?? [];
            foreach ($properties as $property) {
                $propertyData = $this->normalizeToArray($property);
                if ((int) ($propertyData['typeId'] ?? 0) === 1 && (string) ($propertyData['value'] ?? '') === $transactionId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function determineExchangeRate(Order $order, string $paymentCurrency): float
    {
        $amounts = $order->amounts ?? [];
        foreach ($amounts as $amount) {
            $amountData = $this->normalizeToArray($amount);

            $currency = $amountData['currency'] ?? null;
            if ($currency === null) {
                continue;
            }

            if ($currency === $paymentCurrency) {
                return 1.0;
            }

            $exchangeRate = $amountData['exchangeRate'] ?? null;
            if ($exchangeRate !== null) {
                return (float) $exchangeRate;
            }
        }

        return 1.0;
    }

    private function normalizeToArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof Payment || $value instanceof PaymentProperty || $value instanceof OrderAmount) {
            return $value->toArray();
        }

        if (is_object($value)) {
            $json = json_encode($value);
            if (is_string($json)) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }

            return (array) $value;
        }

        return [];
    }
}
