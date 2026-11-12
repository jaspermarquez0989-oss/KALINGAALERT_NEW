<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('alert_risk_area', function (Blueprint $table) {
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('risk_area_id')->constrained()->restrictOnDelete();
            $table->json('coverage_snapshot')->nullable();
            $table->timestamps();
            $table->primary(['alert_id', 'risk_area_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_risk_area');
    }
};
