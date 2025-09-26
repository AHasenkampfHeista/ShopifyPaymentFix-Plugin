<?php

namespace ShopifyPaymentFix\EventProcedures;

use Exception;
use Plenty\Modules\Authorization\Services\AuthHelper;
use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;
use ShopifyPaymentFix\Services\PaymentSyncService;
use ShopifyPaymentFix\Services\ShopifyOrderService;
use Plenty\Modules\Order\Models\Order;

class ShopifyPaymentProcedure
{
    use Loggable;

    private const EXTERNAL_ORDER_ID_TYPE_ID = 7;

    private ShopifyOrderService $shopifyOrderService;
    private PaymentSyncService $paymentSyncService;
    private ConfigRepository $config;
    private AuthHelper $authHelper;

    public function __construct(
        ShopifyOrderService $shopifyOrderService,
        PaymentSyncService $paymentSyncService,
        ConfigRepository $config,
        AuthHelper $authHelper
    ) {
        $this->shopifyOrderService = $shopifyOrderService;
        $this->paymentSyncService = $paymentSyncService;
        $this->config = $config;
        $this->authHelper = $authHelper;
    }

    public function handle(EventProceduresTriggered $event): void
    {
        /** @var Order $order */
        $order = $event->getOrder();
        if ($order === null) {
            return;
        }

        $orderId = (int) $order->id;
        $externalOrderId = $this->extractExternalOrderId($order);
        if ($externalOrderId === '') {
            $this->getLogger('ShopifyPaymentProcedure_handle')
                ->warning('ShopifyPaymentFix::logs.missingExternalOrderId', [
                    'orderId' => $orderId,
                ]);
            return;
        }

        $enableDebug = (string) $this->config->get('ShopifyPaymentFix.global.enableDebugLog') === '1';

        try {
            $shopifyOrder = $this->shopifyOrderService->fetchOrderByExternalId($externalOrderId);
        } catch (Exception $exception) {
            $this->getLogger('ShopifyPaymentProcedure_handle')
                ->error('ShopifyPaymentFix::logs.fetchFailed', [
                    'externalOrderId' => $externalOrderId,
                    'message' => $exception->getMessage(),
                ]);
            return;
        }

        if ($shopifyOrder === null) {
            return;
        }

        $gatewayNames = array_map('strtolower', $shopifyOrder['paymentGatewayNames'] ?? []);
        if (!$this->isShopifyPaypalSplit($gatewayNames)) {
            if ($enableDebug) {
                $this->getLogger('ShopifyPaymentProcedure_handle')
                    ->info('ShopifyPaymentFix::logs.noPaypalSplit', [
                        'orderId' => $orderId,
                        'externalOrderId' => $externalOrderId,
                        'gatewayNames' => implode(', ', $gatewayNames),
                    ]);
            }
            return;
        }

        $this->authHelper->processUnguarded(function () use ($order, $externalOrderId, $shopifyOrder, $enableDebug): void {
            $this->paymentSyncService->ensurePaypalPayment($order, $externalOrderId, $shopifyOrder, $enableDebug);
        });
    }

    private function isShopifyPaypalSplit(array $gatewayNames): bool
    {
        if (empty($gatewayNames)) {
            return false;
        }

        return in_array('shopify_payments', $gatewayNames, true)
            && in_array('paypal', $gatewayNames, true);
    }

    private function extractExternalOrderId(Order $order): string
    {
        $properties = $order->properties;
        if (empty($properties)) {
            return '';
        }

        foreach ($properties as $property) {
            $typeId = null;
            $value = null;

            if (is_array($property)) {
                $typeId = $property['typeId'] ?? null;
                $value = $property['value'] ?? null;
            } elseif (is_object($property)) {
                $typeId = $property->typeId ?? null;
                $value = $property->value ?? null;
            }

            if ((int) $typeId !== self::EXTERNAL_ORDER_ID_TYPE_ID) {
                continue;
            }

            return trim((string) $value);
        }

        return '';
    }
}
