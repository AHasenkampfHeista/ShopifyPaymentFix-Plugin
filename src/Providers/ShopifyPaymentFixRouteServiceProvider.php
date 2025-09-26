<?php

namespace ShopifyPaymentFix\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

class ShopifyPaymentFixRouteServiceProvider extends RouteServiceProvider
{
    public function map(Router $router): void
    {
        $router->get('shopify-payment-fix/test-order', 'ShopifyPaymentFix\Controllers\ShopifyOrderTestController@fetch');
    }
}
