<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fire_incidents', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->index();
            $table->string('barangay')->index();
            $table->string('sitio')->nullable();
            $table->string('incident_type', 40);
            $table->string('cause', 40);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('injuries')->default(0);
            $table->unsignedSmallInteger('fatalities')->default(0);
            $table->decimal('property_damage_estimate', 14, 2)->nullable();
            $table->string('source_reference');
            $table->string('source_url', 2048)->nullable();
            $table->text('details')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->index(['verification_status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fire_incidents');
    }
};
