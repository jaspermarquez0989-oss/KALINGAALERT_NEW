<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('fire_risk_prediction_id')
                ->nullable()
                ->after('hazard_source_id')
                ->constrained('fire_risk_predictions')
                ->nullOnDelete();
            $table->string('affected_barangay', 120)->nullable();
            $table->decimal('affected_latitude', 10, 7)->nullable();
            $table->decimal('affected_longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fire_risk_prediction_id');
            $table->dropColumn([
                'affected_barangay',
                'affected_latitude',
                'affected_longitude',
            ]);
        });
    }
};
