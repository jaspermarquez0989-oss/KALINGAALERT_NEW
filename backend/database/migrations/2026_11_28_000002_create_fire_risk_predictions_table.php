<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_risk_predictions', function (Blueprint $table) {
            $table->id();
            $table->string('barangay', 120)->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('score_percent', 5, 1);
            $table->decimal('threshold', 7, 6);
            $table->boolean('threshold_met');
            $table->string('model_version', 120);
            $table->unsignedSmallInteger('prediction_radius_meters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['created_at', 'threshold_met']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_risk_predictions');
    }
};
