<?php

namespace ShopifyPaymentFix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use ShopifyPaymentFix\Services\ShopifyOrderService;
use Throwable;

class ShopifyOrderTestController extends Controller
{
    public function fetch(Request $request, ShopifyOrderService $orderService): string
    {
        $externalOrderId = (string) $request->input('externalOrderId', '');
        if ($externalOrderId === '') {
            return json_encode([
                'ok' => false,
                'message' => 'Provide the externalOrderId query parameter.',
            ]);
        }

        try {
            $order = $orderService->fetchOrderByExternalId($externalOrderId);
        } catch (Throwable $throwable) {
            return json_encode([
                'ok' => false,
                'message' => $throwable->getMessage(),
            ]);
        }

        if ($order === null) {
            return json_encode([
                'ok' => false,
                'message' => 'Order not found or fetch failed. Inspect plugin logs for details.',
            ]);
        }

        return json_encode([
            'ok' => true,
            'order' => $order,
        ]);
    }
}
