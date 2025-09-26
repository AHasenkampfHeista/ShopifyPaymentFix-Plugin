<?php

namespace ShopifyPaymentFix\Providers;

use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Plugin\ServiceProvider;
use ShopifyPaymentFix\EventProcedures\ShopifyPaymentProcedure;

class ShopifyPaymentFixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(ShopifyPaymentFixRouteServiceProvider::class);
    }

    public function boot(EventProceduresService $eventProceduresService): void
    {
        $eventProceduresService->registerProcedure(
            'shopifySplitPaypal',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Shopify PayPal-Zahlung ergänzen',
                'en' => 'Add Shopify PayPal payment'
            ],
            ShopifyPaymentProcedure::class . '@handle'
        );
    }
}
