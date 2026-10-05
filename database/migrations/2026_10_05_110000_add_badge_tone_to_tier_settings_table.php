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
     * A string, not an enum column: the list of tones is Polaris's and can
     * grow without a migration. App\Enums\BadgeTone guards what goes in.
     * Existing tiers keep the blue they have always had.
     */
    public function up(): void
    {
        Schema::table('tier_settings', function (Blueprint $table) {
            $table->string('badge_tone', 20)->default('info')->after('discount_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tier_settings', function (Blueprint $table) {
            $table->dropColumn('badge_tone');
        });
    }
};
