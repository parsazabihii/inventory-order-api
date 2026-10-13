<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['db']->connection()->getDriverName() !== 'pgsql' || $app['db']->connection()->getDatabaseName() !== 'inventory_order_test') {
            throw new \RuntimeException('Tests may only run against the disposable PostgreSQL database inventory_order_test. Clear cached configuration and check .env.testing.');
        }

        return $app;
    }
}
