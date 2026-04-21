<?php

namespace App\Providers;

use App\Services\InventoryService;
use App\Services\SalesOrderService;
use App\Services\PurchaseOrderService;
use Illuminate\Support\ServiceProvider;

/**
 * AppServiceProvider
 *
 * LARAVEL CONCEPT: Service Container & Dependency Injection
 *
 * We register our ERP service classes here as singletons.
 * A singleton means Laravel creates the object ONCE and reuses it
 * throughout the request lifecycle — efficient for stateless services.
 *
 * Because SalesOrderService and PurchaseOrderService both DEPEND ON
 * InventoryService (they inject it in their constructors), Laravel's
 * container automatically resolves and injects the correct instance.
 *
 * This is Dependency Injection in action:
 *   Controller → SalesOrderService → InventoryService
 * Each layer knows nothing about how the layers below are constructed.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register InventoryService first (others depend on it)
        $this->app->singleton(InventoryService::class);

        // Laravel auto-injects InventoryService into these constructors
        $this->app->singleton(SalesOrderService::class);
        $this->app->singleton(PurchaseOrderService::class);
    }

    public function boot(): void
    {
        //
    }
}
