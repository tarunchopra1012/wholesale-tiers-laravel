<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Install asks Shopify for an expiring offline token: the access token
     * lives one hour, the refresh token 90 days. All three columns are
     * nullable, and null means "does not expire" — Shopify only returns the
     * expiry fields when it actually issued an expiring token.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token');
            // text, not string: same encrypted-envelope reason as access_token.
            $table->text('refresh_token')->nullable()->after('access_token_expires_at');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('refresh_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['access_token_expires_at', 'refresh_token', 'refresh_token_expires_at']);
        });
    }
};
