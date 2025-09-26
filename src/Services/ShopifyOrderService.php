<?php

namespace ShopifyPaymentFix\Services;

use Exception;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

class ShopifyOrderService
{
    use Loggable;

    private ConfigRepository $config;

    public function __construct(ConfigRepository $config)
    {
        $this->config = $config;
    }

    /**
     * Fetch a Shopify order via GraphQL using the external order ID from plentymarkets.
     *
     * @param string $externalOrderId
     *
     * @return array|null
     */
    public function fetchOrderByExternalId(string $externalOrderId): ?array
    {
        $shopName = trim((string) $this->config->get('ShopifyPaymentFix.global.shopName'));
        $apiVersion = trim((string) $this->config->get('ShopifyPaymentFix.global.apiVersion'));
        $accessToken = trim((string) $this->config->get('ShopifyPaymentFix.global.accessToken'));

        if ($shopName === '' || $apiVersion === '' || $accessToken === '') {
            $this->getLogger('ShopifyOrderService_fetchOrderByExternalId')
                ->error('ShopifyPaymentFix::logs.configMissing', [
                    'orderId' => 'n/a',
                    'key' => $shopName === '' ? 'global.shopName' : ($apiVersion === '' ? 'global.apiVersion' : 'global.accessToken'),
                ]);
            return null;
        }

        $endpoint = sprintf(
            'https://%s.myshopify.com/admin/api/%s/graphql.json',
            $shopName,
            $apiVersion
        );

        $orderId = $this->formatGraphQlId($externalOrderId);
        if ($orderId === null) {
            return null;
        }

        $query = <<<'GRAPHQL'
query getOrder($id: ID!) {
  order(id: $id) {
    id
    name
    paymentGatewayNames
    transactions(first: 10) {
      amountSet {
        presentmentMoney {
          amount
          currencyCode
        }
        shopMoney {
          amount
          currencyCode
        }
      }
      createdAt
      formattedGateway
      gateway
      id
      kind
      manuallyCapturable
      maximumRefundableV2 {
        amount
        currencyCode
      }
      receiptJson
      paymentId
      processedAt
      status
    }
  }
}
GRAPHQL;

        $payload = json_encode([
            'query' => $query,
            'variables' => ['id' => $orderId],
        ], JSON_THROW_ON_ERROR);

        $response = $this->executeRequest($endpoint, $accessToken, $payload);

        if (!isset($response['data']['order']) || $response['data']['order'] === null) {
            $errors = $response['errors'] ?? [];
            $message = $errors ? json_encode($errors) : 'Order not returned by Shopify.';
            $this->getLogger('ShopifyOrderService_fetchOrderByExternalId')
                ->error('ShopifyPaymentFix::logs.fetchFailed', [
                    'externalOrderId' => $externalOrderId,
                    'message' => $message,
                ]);
            return null;
        }

        return $response['data']['order'];
    }

    private function executeRequest(string $endpoint, string $accessToken, string $payload): array
    {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new Exception('Unable to initialise curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $accessToken,
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL error: ' . $error);
        }

        curl_close($ch);

        if ($status >= 400) {
            throw new Exception('Shopify responded with status ' . $status . ' body: ' . $body);
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function formatGraphQlId(string $externalOrderId): ?string
    {
        $trimmed = trim($externalOrderId);
        if ($trimmed === '') {
            return null;
        }

        if (stripos($trimmed, 'gid://shopify/Order/') === 0) {
            return $trimmed;
        }

        $digits = preg_replace('/[^0-9]/', '', $trimmed);
        if ($digits === '') {
            $this->getLogger('ShopifyOrderService_formatGraphQlId')
                ->warning('ShopifyPaymentFix::logs.unusableExternalOrderId', [
                    'externalOrderId' => $externalOrderId,
                ]);
            return null;
        }

        $normalized = ltrim($digits, '0');
        if ($normalized === '') {
            $normalized = $digits;
        }

        return 'gid://shopify/Order/' . $normalized;
    }
}
