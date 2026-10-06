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
     * Nullable, with no backfill: a tier without a name is shown by its tag,
     * as every tier was before this column.
     */
    public function up(): void
    {
        Schema::table('tier_settings', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tier_settings', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
