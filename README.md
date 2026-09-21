# Pulse SQL Server

SQL Server storage driver for [Laravel Pulse](https://github.com/laravel/pulse).

Pulse's first-party storage supports MySQL, MariaDB, PostgreSQL and SQLite. Applications running on
SQL Server have to provision a second database engine only to store Pulse data. This package adds
SQL Server as a storage option, so Pulse can keep its data next to the rest of your application.

It is a drop-in add-on, not a fork: you install Pulse as usual and this package swaps a single class.

> This is a community package. It is not affiliated with or endorsed by Laravel.
> It grew out of [laravel/pulse#540](https://github.com/laravel/pulse/pull/540), which the
> maintainers closed asking for it to be released as a package.

## Requirements

- PHP 8.1+
- Laravel Pulse 1.8+ (tested against Laravel 12 and 13)
- SQL Server 2019 or later (including Azure SQL Database), with the `pdo_sqlsrv` extension

## Installation

```shell
composer require elazaroo/pulse-sqlsrv
```

Publish the migration from this package. **Do not publish the one shipped with Pulse**: it has no
SQL Server branch and fails on an unsupported driver.

```shell
php artisan vendor:publish --tag=pulse-sqlsrv-migrations
php artisan migrate
```

That is all. Pulse uses your default database connection unless you point it somewhere else with
`PULSE_DB_CONNECTION`, and the driver is picked automatically when that connection is `sqlsrv`.

The published migration covers every driver Pulse supports, so an application that runs on SQL
Server in production and on SQLite locally can rely on this single migration.

### Recommended connection options

`pdo_sqlsrv` returns every column as a string unless told otherwise. Pulse formats those values
correctly either way, but if you read the tables yourself you may prefer real numbers:

```php
'options' => [
    PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE => true,
],
```

## What it changes

Only the parts of the storage that depend on the SQL dialect:

- **Upserts.** SQL Server has neither `ON DUPLICATE KEY UPDATE` nor `ON CONFLICT`. The aggregate
  upserts are rewritten on top of the `MERGE` statement that Laravel generates, using `iif()`
  instead of `least()`/`greatest()` so they also work on SQL Server 2019.
- **Type conversion.** Laravel binds PHP integers as `PDO::PARAM_INT` and floats as
  `PDO::PARAM_STR`. SQL Server resolves the type of each column in a table value constructor by
  precedence, and integers outrank strings, so a column mixing both made the server try to convert
  `'233.33333333333'` to an integer. Aggregate values are normalised before the upsert.
- **Parameter limit.** SQL Server accepts at most 2,100 parameters per request, while Pulse chunks
  1,000 records at a time. Records are chunked according to their number of columns instead.
- **Key hash.** SQL Server has no MD5 function usable in a computed column, so the hash is computed
  in PHP, reusing the path Pulse already has for SQLite.
- **Boolean literal.** `then true` in the type aggregation becomes `then 1`, which T-SQL accepts.

Everything else — recorders, cards, the dashboard, the ingest — is Pulse's own code, untouched.

## Testing

The suite runs against SQL Server:

```shell
DB_CONNECTION=sqlsrv DB_HOST=127.0.0.1 DB_PORT=1433 DB_DATABASE=pulse DB_USERNAME=sa DB_PASSWORD=... vendor/bin/pest
```

## Credits

The overridden methods are adapted from [laravel/pulse](https://github.com/laravel/pulse), MIT
licensed, Copyright (c) Taylor Otwell.

## License

MIT. See [LICENSE.md](LICENSE.md).
