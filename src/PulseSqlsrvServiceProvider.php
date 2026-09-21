<?php

namespace Elazaroo\PulseSqlsrv;

use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Contracts\Storage;

class PulseSqlsrvServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->usesSqlServer()) {
            $this->app->bind(Storage::class, SqlServerStorage::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['pulse-sqlsrv', 'pulse-sqlsrv-migrations']);
        }
    }

    /**
     * Determine whether Pulse stores its data on SQL Server.
     */
    protected function usesSqlServer(): bool
    {
        $config = $this->app->make('config');

        $connection = $config->get('pulse.storage.database.connection')
            ?? $config->get('database.default');

        return $config->get("database.connections.{$connection}.driver") === 'sqlsrv';
    }
}
