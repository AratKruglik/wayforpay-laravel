<?php

declare(strict_types=1);

namespace AratKruglik\WayForPay;

use Illuminate\Support\ServiceProvider;

class WayForPayServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/Config/wayforpay.php' => config_path('wayforpay.php'),
        ], 'wayforpay-config');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/Config/wayforpay.php', 'wayforpay'
        );

        $this->app->singleton(\AratKruglik\WayForPay\Services\SignatureGenerator::class, function ($app) {
            return new \AratKruglik\WayForPay\Services\SignatureGenerator(
                $app['config']->get('wayforpay.secret_key')
            );
        });

        $this->app->singleton(\AratKruglik\WayForPay\Services\WayForPayService::class, function ($app) {
            return new \AratKruglik\WayForPay\Services\WayForPayService(
                $app->make(\AratKruglik\WayForPay\Services\SignatureGenerator::class),
                $app->make(\Illuminate\Http\Client\Factory::class)
            );
        });

        $this->app->bind(\AratKruglik\WayForPay\Contracts\WayForPayInterface::class, \AratKruglik\WayForPay\Services\WayForPayService::class);

        $this->app->alias(\AratKruglik\WayForPay\Services\WayForPayService::class, 'wayforpay');
    }
}
