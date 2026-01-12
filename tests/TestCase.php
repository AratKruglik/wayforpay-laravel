<?php

namespace AratKruglik\WayForPay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use AratKruglik\WayForPay\WayForPayServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            WayForPayServiceProvider::class,
        ];
    }
}
