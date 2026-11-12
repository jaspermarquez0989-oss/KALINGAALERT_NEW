<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hazard_sources', function (Blueprint $table) {
            $table->string('external_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('hazard_sources')->whereNull('created_by')->exists()) {
            throw new RuntimeException('Cannot remove automated source records while imported records have no staff creator.');
        }

        Schema::table('hazard_sources', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }
};
