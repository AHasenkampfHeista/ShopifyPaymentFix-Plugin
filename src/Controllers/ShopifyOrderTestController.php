<?php

namespace ShopifyPaymentFix\Controllers;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Http\Response;
use ShopifyPaymentFix\Services\ShopifyOrderService;
use Throwable;

class ShopifyOrderTestController extends Controller
{
    public function fetch(Request $request, ShopifyOrderService $orderService, Response $response): Response
    {
        $externalOrderId = (string) $request->input('externalOrderId', '');
        if ($externalOrderId === '') {
            return $this->respondWithJson(
                $response,
                [
                    'ok' => false,
                    'message' => 'Provide the externalOrderId query parameter.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $order = $orderService->fetchOrderByExternalId($externalOrderId);
        } catch (Throwable $throwable) {
            return $this->respondWithJson(
                $response,
                [
                    'ok' => false,
                    'message' => $throwable->getMessage(),
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        if ($order === null) {
            return $this->respondWithJson(
                $response,
                [
                    'ok' => false,
                    'message' => 'Order not found or fetch failed. Inspect plugin logs for details.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }



        return $this->respondWithJson(
            $response,
            [
                'ok' => true,
                'order' => $order,
            ]
        );
    }

    private function respondWithJson(Response $response, array $data, int $status = Response::HTTP_OK): Response
    {
        return $response->make(
            json_encode($data, JSON_PRETTY_PRINT),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
