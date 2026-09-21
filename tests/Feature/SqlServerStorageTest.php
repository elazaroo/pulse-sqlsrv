<?php

use Carbon\CarbonInterval;
use Elazaroo\PulseSqlsrv\SqlServerStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Contracts\Storage;
use Laravel\Pulse\Facades\Pulse;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'sqlsrv') {
        test()->markTestSkipped('SQL Server is required for these tests.');
    }
});

it('replaces the storage driver', function () {
    expect(app(Storage::class))->toBeInstanceOf(SqlServerStorage::class);
});

it('creates the tables', function () {
    foreach (['pulse_values', 'pulse_entries', 'pulse_aggregates'] as $table) {
        expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue();
    }

    expect(DB::getSchemaBuilder()->getColumnType('pulse_aggregates', 'key_hash'))->toBe('nchar');
});

it('stores entries and aggregates', function () {
    Carbon::setTestNow('2000-01-01 00:00:00');

    foreach ([100, 200, 300] as $value) {
        Pulse::record('slow_request', 'GET /users', $value)->count()->min()->max()->sum()->avg();
    }

    Pulse::ingest();

    $entries = Pulse::ignore(fn () => DB::table('pulse_entries')->get());
    expect($entries)->toHaveCount(3);
    expect($entries[0]->key_hash)->toBe(md5('GET /users'));

    $aggregate = Pulse::aggregate('slow_request', ['count', 'min', 'max', 'sum', 'avg'], CarbonInterval::hour())->first();
    expect($aggregate->key)->toBe('GET /users');
    expect($aggregate->count)->toEqual(3);
    expect($aggregate->min)->toEqual(100);
    expect($aggregate->max)->toEqual(300);
    expect($aggregate->sum)->toEqual(600);
    expect($aggregate->avg)->toEqual(200);
});

it('merges aggregates across ingests', function () {
    Carbon::setTestNow('2000-01-01 00:00:00');

    Pulse::record('slow_request', 'GET /users', 100)->count()->min()->max()->sum()->avg();
    Pulse::ingest();

    Pulse::record('slow_request', 'GET /users', 300)->count()->min()->max()->sum()->avg();
    Pulse::ingest();

    $aggregate = Pulse::aggregate('slow_request', ['count', 'min', 'max', 'sum', 'avg'], CarbonInterval::hour())->first();
    expect($aggregate->count)->toEqual(2);
    expect($aggregate->min)->toEqual(100);
    expect($aggregate->max)->toEqual(300);
    expect($aggregate->sum)->toEqual(400);
    expect($aggregate->avg)->toEqual(200);
});

it('aggregates several types at once', function () {
    Carbon::setTestNow('2000-01-01 00:00:00');

    Pulse::record('cache_hit', 'users')->count();
    Pulse::record('cache_hit', 'users')->count();
    Pulse::record('cache_miss', 'users')->count();
    Pulse::ingest();

    $row = Pulse::aggregateTypes(['cache_hit', 'cache_miss'], 'count', CarbonInterval::hour())->first();
    expect($row->cache_hit)->toEqual(2);
    expect($row->cache_miss)->toEqual(1);

    $totals = Pulse::aggregateTotal(['cache_hit', 'cache_miss'], 'count', CarbonInterval::hour());
    expect($totals['cache_hit'])->toEqual(2);
    expect($totals['cache_miss'])->toEqual(1);
});

it('stores values', function () {
    Pulse::set('system', 'web-1', json_encode(['cpu' => 12]));
    Pulse::ingest();

    $values = Pulse::values('system');
    expect($values)->toHaveCount(1);
    expect(json_decode($values['web-1']->value)->cpu)->toEqual(12);
});

it('builds a graph', function () {
    Carbon::setTestNow('2000-01-01 00:00:00');

    Pulse::record('slow_query', 'select 1', 500)->max();
    Pulse::ingest();

    $graph = Pulse::graph(['slow_query'], 'max', CarbonInterval::hour());
    expect($graph['select 1']['slow_query']->filter()->first())->toEqual(500);
});

it('stores more records than fit in a single statement', function () {
    // SQL Server accepts at most 2,100 parameters per request, while Pulse
    // chunks 1,000 records at a time. Without chunking by column count the
    // ingest fails, and Pulse discards the data without raising anything.
    Carbon::setTestNow('2000-01-01 00:00:00');

    for ($i = 0; $i < 1_000; $i++) {
        Pulse::record('type', "key:{$i}", $i)->count()->min()->max()->sum()->avg();
        Pulse::set('type', "key:{$i}", (string) $i);
    }

    Pulse::ingest();

    expect(Pulse::ignore(fn () => DB::table('pulse_entries')->count()))->toBe(1_000);
    expect(Pulse::ignore(fn () => DB::table('pulse_aggregates')->count()))->toBe(1_000 * 5 * 4); // 5 aggregates, 4 periods
    expect(Pulse::ignore(fn () => DB::table('pulse_values')->count()))->toBe(1_000);
});

it('trims and purges', function () {
    Carbon::setTestNow('2000-01-01 00:00:00');

    Pulse::record('slow_request', 'GET /users', 100)->count();
    Pulse::set('system', 'web-1', '{}');
    Pulse::ingest();

    Carbon::setTestNow('2000-01-09 00:00:00');
    Pulse::trim();

    expect(Pulse::ignore(fn () => DB::table('pulse_entries')->count()))->toBe(0);

    Pulse::record('slow_request', 'GET /users', 100)->count();
    Pulse::ingest();
    Pulse::purge();

    expect(Pulse::ignore(fn () => DB::table('pulse_entries')->count()))->toBe(0);
    expect(Pulse::ignore(fn () => DB::table('pulse_aggregates')->count()))->toBe(0);
    expect(Pulse::ignore(fn () => DB::table('pulse_values')->count()))->toBe(0);
});
