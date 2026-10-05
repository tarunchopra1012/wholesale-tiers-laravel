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
     * The shop's one automatic discount in Shopify, the one that runs the
     * checkout Function. Null until the first sync creates it.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // A Shopify GID, e.g. gid://shopify/DiscountAutomaticNode/123.
            $table->string('tier_discount_id')->nullable()->after('uninstalled_at');
            $table->timestamp('tier_discount_synced_at')->nullable()->after('tier_discount_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['tier_discount_id', 'tier_discount_synced_at']);
        });
    }
};
