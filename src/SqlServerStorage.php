<?php

namespace Elazaroo\PulseSqlsrv;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Storage\DatabaseStorage;
use Laravel\Pulse\Value;
use RuntimeException;

/**
 * Pulse storage for SQL Server.
 *
 * The methods below are adapted from Laravel\Pulse\Storage\DatabaseStorage
 * (MIT, Copyright (c) Taylor Otwell). Only the parts that depend on the
 * database dialect are overridden; everything else is inherited.
 *
 * @phpstan-type AggregateRow array{bucket: int, period: int, type: string, aggregate: string, key: string, value: int|float, count?: int}
 */
class SqlServerStorage extends DatabaseStorage
{
    /**
     * Store the items.
     *
     * @param  Collection<int, Entry|Value>  $items
     */
    public function store(Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        [$entries, $values] = $items->partition(fn (Entry|Value $entry) => $entry instanceof Entry);

        $entryChunks = $entries
            ->reject->isOnlyBuckets() // @phpstan-ignore method.notFound
            ->when(
                $this->requiresManualKeyHash(),
                fn ($entries) => $entries->map(fn ($entry) => [
                    ...($attributes = $entry->attributes()),
                    'key_hash' => md5($attributes['key']),
                ]),
                fn ($entries) => $entries->map->attributes()
            )
            ->pipe($this->chunk(...));

        [$counts, $minimums, $maximums, $sums, $averages] = array_values($entries
            ->reduce(function ($carry, $entry) {
                foreach ($entry->aggregations() as $aggregation) {
                    $carry[$aggregation][] = $entry;
                }

                return $carry;
            }, ['count' => [], 'min' => [], 'max' => [], 'sum' => [], 'avg' => []])
        );

        $countChunks = $this->preaggregateCounts(collect($counts)) // @phpstan-ignore argument.templateType, argument.templateType
            ->pipe($this->chunk(...));

        $minimumChunks = $this->preaggregateMinimums(collect($minimums)) // @phpstan-ignore argument.templateType, argument.templateType
            ->pipe($this->chunk(...));

        $maximumChunks = $this->preaggregateMaximums(collect($maximums)) // @phpstan-ignore argument.templateType, argument.templateType
            ->pipe($this->chunk(...));

        $sumChunks = $this->preaggregateSums(collect($sums)) // @phpstan-ignore argument.templateType, argument.templateType
            ->pipe($this->chunk(...));

        $averageChunks = $this->preaggregateAverages(collect($averages)) // @phpstan-ignore argument.templateType, argument.templateType
            ->pipe($this->chunk(...));

        $valueChunks = $this
            ->collapseValues($values)
            ->when(
                $this->requiresManualKeyHash(),
                fn ($values) => $values->map(fn ($value) => [
                    ...($attributes = $value->attributes()),
                    'key_hash' => md5($attributes['key']),
                ]),
                fn ($values) => $values->map->attributes()
            )
            ->pipe($this->chunk(...));

        $this->connection()->transaction(function () use ($entryChunks, $countChunks, $minimumChunks, $maximumChunks, $sumChunks, $averageChunks, $valueChunks) {
            $entryChunks->each(fn ($chunk) => $this->connection()
                ->table('pulse_entries')
                ->insert($chunk->all()));

            $countChunks->each(fn ($chunk) => $this->upsertCount($chunk->all()));

            $minimumChunks->each(fn ($chunk) => $this->upsertMin($chunk->all()));

            $maximumChunks->each(fn ($chunk) => $this->upsertMax($chunk->all()));

            $sumChunks->each(fn ($chunk) => $this->upsertSum($chunk->all()));

            $averageChunks->each(fn ($chunk) => $this->upsertAvg($chunk->all()));

            $valueChunks->each(fn ($chunk) => $this->connection()
                ->table('pulse_values')
                ->upsert($chunk->all(), ['type', 'key_hash'], ['timestamp', 'value'])
            );
        }, 3);
    }

    /**
     * Insert new records or update the existing ones and update the count.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertCount(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $this->prepareAggregates($values),
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('pulse_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.value')} + "excluded"."value"
                        SQL),
                    'sqlsrv' => new Expression(
                        "{$this->wrap('pulse_aggregates.value')} + {$this->castUpsertValue('laravel_source.value')}"
                    ),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the minimum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMin(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $this->prepareAggregates($values),
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "least({$this->wrap('pulse_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'least(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        least({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        min({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlsrv' => new Expression(
                        "iif({$this->wrap('pulse_aggregates.value')} < {$this->castUpsertValue('laravel_source.value')}, {$this->wrap('pulse_aggregates.value')}, {$this->castUpsertValue('laravel_source.value')})"
                    ),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the maximum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertMax(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $this->prepareAggregates($values),
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "greatest({$this->wrap('pulse_aggregates.value')}, {$this->wrap('laravel_upsert_alias.value')})"
                            : 'greatest(`value`, values(`value`))'
                    ),
                    'pgsql' => new Expression(<<<SQL
                        greatest({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlite' => new Expression(<<<SQL
                        max({$this->wrap('pulse_aggregates.value')}, "excluded"."value")
                        SQL),
                    'sqlsrv' => new Expression(
                        "iif({$this->wrap('pulse_aggregates.value')} > {$this->castUpsertValue('laravel_source.value')}, {$this->wrap('pulse_aggregates.value')}, {$this->castUpsertValue('laravel_source.value')})"
                    ),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the sum.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertSum(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $this->prepareAggregates($values),
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            [
                'value' => match ($driver = $connection->getDriverName()) {
                    'mariadb', 'mysql' => new Expression(
                        $connection->getConfig('use_upsert_alias')
                            ? "{$this->wrap('pulse_aggregates.value')} + {$this->wrap('laravel_upsert_alias.value')}"
                            : '`value` + values(`value`)'
                    ),
                    'pgsql', 'sqlite' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.value')} + "excluded"."value"
                        SQL),
                    'sqlsrv' => new Expression(
                        "{$this->wrap('pulse_aggregates.value')} + {$this->castUpsertValue('laravel_source.value')}"
                    ),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
                },
            ]
        );
    }

    /**
     * Insert new records or update the existing ones and the average.
     *
     * @param  list<AggregateRow>  $values
     */
    protected function upsertAvg(array $values): int
    {
        $connection = $this->connection();

        return $connection->table('pulse_aggregates')->upsert(
            $this->prepareAggregates($values),
            ['bucket', 'period', 'type', 'aggregate', 'key_hash'],
            match ($driver = $connection->getDriverName()) {
                'mariadb', 'mysql' => $connection->getConfig('use_upsert_alias') ? [
                    'value' => new Expression(
                        "({$this->wrap('pulse_aggregates.value')} * {$this->wrap('pulse_aggregates.count')} + ({$this->wrap('laravel_upsert_alias.value')} * {$this->wrap('laravel_upsert_alias.count')})) / ({$this->wrap('pulse_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')})"
                    ),
                    'count' => new Expression(
                        "{$this->wrap('pulse_aggregates.count')} + {$this->wrap('laravel_upsert_alias.count')}"
                    ),
                ] : [
                    'value' => new Expression('(`value` * `count` + (values(`value`) * values(`count`))) / (`count` + values(`count`))'),
                    'count' => new Expression('`count` + values(`count`)'),
                ],
                'pgsql', 'sqlite' => [
                    'value' => new Expression(<<<SQL
                        ({$this->wrap('pulse_aggregates.value')} * {$this->wrap('pulse_aggregates.count')} + ("excluded"."value" * "excluded"."count")) / ({$this->wrap('pulse_aggregates.count')} + "excluded"."count")
                        SQL),
                    'count' => new Expression(<<<SQL
                        {$this->wrap('pulse_aggregates.count')} + "excluded"."count"
                        SQL),
                ],
                'sqlsrv' => [
                    'value' => new Expression(
                        "({$this->wrap('pulse_aggregates.value')} * {$this->wrap('pulse_aggregates.count')} + ({$this->castUpsertValue('laravel_source.value')} * {$this->castUpsertCount('laravel_source.count')})) / ({$this->wrap('pulse_aggregates.count')} + {$this->castUpsertCount('laravel_source.count')})"
                    ),
                    'count' => new Expression(
                        "{$this->wrap('pulse_aggregates.count')} + {$this->castUpsertCount('laravel_source.count')}"
                    ),
                ],
                default => throw new RuntimeException("Unsupported database driver [{$driver}]"),
            }
        );
    }

    /**
     * Retrieve aggregate values for the given types.
     *
     * @param  string|list<string>  $types
     * @param  'count'|'min'|'max'|'sum'|'avg'  $aggregate
     * @return Collection<int, object>
     */
    public function aggregateTypes(
        string|array $types,
        string $aggregate,
        CarbonInterval $interval,
        ?string $orderBy = null,
        string $direction = 'desc',
        int $limit = 101,
    ): Collection {
        if (! in_array($aggregate, $allowed = ['count', 'min', 'max', 'sum', 'avg'])) {
            throw new InvalidArgumentException("Invalid aggregate type [$aggregate], allowed types: [".implode(', ', $allowed).'].');
        }

        $types = is_array($types) ? $types : [$types];
        $orderBy ??= $types[0];

        return $this->connection()
            ->query()
            ->select([
                'key' => fn (Builder $query) => $query
                    ->select('key')
                    ->from('pulse_entries', as: 'keys')
                    ->whereColumn('keys.key_hash', 'aggregated.key_hash')
                    ->limit(1),
                ...$types,
            ])
            ->fromSub(function (Builder $query) use ($types, $aggregate, $interval, $orderBy, $direction, $limit) {
                $query->select('key_hash');

                foreach ($types as $type) {
                    $query->selectRaw(match ($aggregate) {
                        'count' => "sum({$this->wrap($type)})",
                        'min' => "min({$this->wrap($type)})",
                        'max' => "max({$this->wrap($type)})",
                        'sum' => "sum({$this->wrap($type)})",
                        'avg' => "avg({$this->wrap($type)})",
                    }." as {$this->wrap($type)}");
                }

                $query->fromSub(function (Builder $query) use ($types, $aggregate, $interval) {
                    $now = CarbonImmutable::now();
                    $period = $interval->totalSeconds / 60;
                    $windowStart = (int) ($now->getTimestamp() - $interval->totalSeconds + 1);
                    $currentBucket = (int) (floor($now->getTimestamp() / $period) * $period);
                    $oldestBucket = $currentBucket - $interval->totalSeconds + $period;

                    // Tail
                    $query->select('key_hash');

                    foreach ($types as $type) {
                        $query->selectRaw(match ($aggregate) {
                            'count' => "count(case when ({$this->wrap('type')} = ?) then 1 else null end)",
                            'min' => "min(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                            'max' => "max(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                            'sum' => "sum(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                            'avg' => "avg(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                        }." as {$this->wrap($type)}", [$type]);
                    }

                    $query
                        ->from('pulse_entries')
                        ->whereIn('type', $types)
                        ->where('timestamp', '>=', $windowStart)
                        ->where('timestamp', '<=', $oldestBucket - 1)
                        ->groupBy('key_hash');

                    // Buckets
                    $query->unionAll(function (Builder $query) use ($types, $aggregate, $period, $oldestBucket) {
                        $query->select('key_hash');

                        foreach ($types as $type) {
                            $query->selectRaw(match ($aggregate) {
                                'count' => "sum(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                                'min' => "min(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                                'max' => "max(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                                'sum' => "sum(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                                'avg' => "avg(case when ({$this->wrap('type')} = ?) then {$this->wrap('value')} else null end)",
                            }." as {$this->wrap($type)}", [$type]);
                        }

                        $query
                            ->from('pulse_aggregates')
                            ->where('period', $period)
                            ->whereIn('type', $types)
                            ->where('aggregate', $aggregate)
                            ->where('bucket', '>=', $oldestBucket)
                            ->groupBy('key_hash');
                    });
                }, as: 'results')
                    ->groupBy('key_hash')
                    ->orderBy($orderBy, $direction)
                    ->limit($limit);
            }, as: 'aggregated')
            ->get();
    }

    /**
     * Split the rows into chunks the database accepts in a single statement.
     *
     * SQL Server accepts at most 2,100 parameters per request, and the statement
     * itself takes some of them when it is executed through sp_executesql, so
     * the configured chunk size is reduced to stay safely below that limit.
     *
     * @template TRow of array<string, mixed>
     *
     * @param  Collection<int, TRow>  $rows
     * @return Collection<int, Collection<int, TRow>>
     */
    protected function chunk(Collection $rows): Collection
    {
        $size = $this->config->get('pulse.storage.database.chunk');

        if ($rows->isNotEmpty() && $this->connection()->getDriverName() === 'sqlsrv') {
            $size = min($size, intdiv(2000, count($rows->first())));
        }

        return $rows->chunk($size);
    }

    /**
     * Prepare the aggregate rows for the upsert.
     *
     * SQL Server derives the type of each column in a table value constructor using
     * type precedence. As integers outrank strings, a column mixing the two would
     * make the driver cast every value to an integer, so they are unified here.
     *
     * @param  list<AggregateRow>  $values
     * @return list<AggregateRow>
     */
    protected function prepareAggregates(array $values): array
    {
        if ($this->connection()->getDriverName() !== 'sqlsrv') {
            return $values;
        }

        return array_map(fn (array $value) => [ // @phpstan-ignore return.type
            ...$value,
            'value' => (string) $value['value'],
        ], $values);
    }

    /**
     * Wrap and cast an upsert source value for drivers without implicit conversion.
     */
    protected function castUpsertValue(string $value): string
    {
        return "cast({$this->wrap($value)} as decimal(20, 2))";
    }

    /**
     * Wrap and cast an upsert source count for drivers without implicit conversion.
     */
    protected function castUpsertCount(string $value): string
    {
        return "cast({$this->wrap($value)} as int)";
    }

    /**
     * Determine whether a manually generated key hash is required.
     */
    protected function requiresManualKeyHash(): bool
    {
        return in_array($this->connection()->getDriverName(), ['sqlite', 'sqlsrv']);
    }
}
