<?php

namespace Tests;

use Elazaroo\PulseSqlsrv\PulseSqlsrvServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pulse\PulseServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use PDO;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Get the package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            PulseServiceProvider::class,
            PulseSqlsrvServiceProvider::class,
        ];
    }

    /**
     * Define the environment setup.
     */
    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config) {
            $config->set('queue.failed.driver', 'null');

            if ($config->get('database.default') !== 'sqlsrv') {
                return;
            }

            // SQL Server returns every column as a string unless the driver is
            // told to preserve numeric types, and the server used for testing
            // has a self signed certificate.
            $config->set('database.connections.sqlsrv.options', [
                PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
            ]);

            $config->set('database.connections.sqlsrv.trust_server_certificate', true);
        });
    }
}
