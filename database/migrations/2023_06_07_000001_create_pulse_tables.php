<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create the Pulse tables.
 *
 * Adapted from laravel/pulse (MIT, Copyright (c) Taylor Otwell) to add the
 * SQL Server variant. Publish this migration instead of the one shipped with
 * Pulse: that one has no SQL Server branch and fails on an unsupported driver.
 */
return new class extends Migration
{
    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return Config::get('pulse.storage.database.connection');
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('pulse_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $this->keyHash($table);
            $table->mediumText('value');

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For fast lookups and purging...
            $table->unique(['type', 'key_hash']); // For data integrity and upserts...
        });

        $schema->create('pulse_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('timestamp');
            $table->string('type');
            $table->mediumText('key');
            $this->keyHash($table);
            $table->bigInteger('value')->nullable();

            $table->index('timestamp'); // For trimming...
            $table->index('type'); // For purging...
            $table->index('key_hash'); // For mapping...
            $table->index(['timestamp', 'type', 'key_hash', 'value']); // For aggregate queries...
        });

        $schema->create('pulse_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bucket');
            $table->unsignedMediumInteger('period');
            $table->string('type');
            $table->mediumText('key');
            $this->keyHash($table);
            $table->string('aggregate');
            $table->decimal('value', 20, 2);
            $table->unsignedInteger('count')->nullable();

            $table->unique(['bucket', 'period', 'type', 'aggregate', 'key_hash']); // Force "on duplicate update"...
            $table->index(['period', 'bucket']); // For trimming...
            $table->index('type'); // For purging...
            $table->index(['period', 'type', 'aggregate', 'bucket']); // For aggregate queries...
        });
    }

    /**
     * Add the key hash column.
     *
     * SQL Server has no MD5 function that may be used in a computed column,
     * so the hash is stored as a plain column and written by Pulse itself.
     */
    protected function keyHash(Blueprint $table): void
    {
        match ($this->driver()) {
            'mariadb', 'mysql' => $table->char('key_hash', 16)->charset('binary')->virtualAs('unhex(md5(`key`))'),
            'pgsql' => $table->uuid('key_hash')->storedAs('md5("key")::uuid'),
            'sqlite' => $table->string('key_hash'),
            'sqlsrv' => $table->char('key_hash', 32),
            default => throw new RuntimeException("Unsupported database driver [{$this->driver()}]."),
        };
    }

    /**
     * Get the database connection driver.
     */
    protected function driver(): string
    {
        return DB::connection($this->getConnection())->getDriverName();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->dropIfExists('pulse_values');
        $schema->dropIfExists('pulse_entries');
        $schema->dropIfExists('pulse_aggregates');
    }
};
