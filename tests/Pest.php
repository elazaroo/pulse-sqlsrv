<?php

use Laravel\Pulse\Facades\Pulse;
use Tests\TestCase;

uses(TestCase::class)
    ->beforeEach(function () {
        // Pulse swallows storage exceptions on purpose, which would turn a
        // broken query into an empty dashboard instead of a failing test.
        Pulse::handleExceptionsUsing(fn (Throwable $e) => throw $e);
    })
    ->in('Feature');
