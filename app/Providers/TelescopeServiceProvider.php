<?php

declare(strict_types=1);

namespace App\Providers;

use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Local only. AppServiceProvider registers this when APP_ENV is local and the
 * package is installed; the production image has neither, so this class is
 * never loaded there. That is also why there's no access gate: in local,
 * Telescope lets every visitor in, and config/telescope.php keeps the
 * dashboard off the tunnel's public address.
 */
final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Kept out of database/migrations so production's `migrate` never
        // creates Telescope's tables.
        $this->loadMigrationsFrom(database_path('migrations/telescope'));

        $this->hideSecrets();

        // DEBUG(dump): DEBUG_DUMP_API=false keeps /api dumps out of Telescope.
        // Runs as each entry is recorded, while the request is still current.
        Telescope::filter(fn (\Laravel\Telescope\IncomingEntry $entry): bool => $entry->type !== \Laravel\Telescope\EntryType::DUMP // DEBUG(dump)
            || config('telescope.watchers.'.\Laravel\Telescope\Watchers\DumpWatcher::class.'.api') // DEBUG(dump)
            || ! request()->is('api/*')); // DEBUG(dump)
    }

    /**
     * Telescope stores what it records in plain text in MySQL. The ID token
     * in incoming `Authorization` headers is already hidden by default.
     */
    private function hideSecrets(): void
    {
        // Sent on every outgoing Admin API call.
        Telescope::hideRequestHeaders(['x-shopify-access-token']);

        // Sent in the form body of the OAuth code exchange and token refresh.
        Telescope::hideRequestParameters(['client_secret', 'refresh_token']);

        // Shopify's answer to that exchange or refresh.
        Telescope::hideResponseParameters(['access_token', 'refresh_token']);
    }
}
