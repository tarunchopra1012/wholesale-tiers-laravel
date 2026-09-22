<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run unless tests are on in-memory SQLite.
     *
     * RefreshDatabase runs migrate:fresh on any database that isn't in
     * memory, which would drop every table in the dev database — including
     * the installed shop and its tokens. phpunit.xml points tests at SQLite;
     * this makes a mistake there (or a cached config) fail the run loudly
     * instead of emptying the shops table.
     *
     * Runs after the app has booted, so it checks the connection Laravel
     * actually resolved, not what phpunit.xml intended.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $connection = config('database.default');

        if ($connection !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            throw new RuntimeException(
                "Tests must run on in-memory SQLite, but the connection is '{$connection}'. Check the DB lines in phpunit.xml.",
            );
        }

        return parent::setUpTraits();
    }
}
