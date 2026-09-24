<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run by docker/entrypoint.sh before migrating. MySQL can take a few seconds
// to accept connections, as on `docker compose up`, and a migrate that runs
// first fails and takes the container down with it.
Artisan::command('app:wait-for-database {--timeout=60 : Seconds to keep trying}', function (): int {
    $deadline = time() + (int) $this->option('timeout');

    while (true) {
        try {
            DB::select('select 1');
            $this->info('Database is reachable.');

            return 0;
        } catch (Throwable $e) {
            if (time() >= $deadline) {
                $this->error('Database still unreachable: '.$e->getMessage());

                return 1;
            }

            // Start the next try on a fresh connection.
            DB::purge();
            sleep(2);
        }
    }
})->purpose('Wait until the database accepts connections, for container start-up');
