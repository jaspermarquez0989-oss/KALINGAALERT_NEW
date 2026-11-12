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
        Schema::create('hazard_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->index();
            $table->string('organization');
            $table->string('reference')->nullable();
            $table->string('title');
            $table->text('details');
            $table->string('source_url')->nullable();
            $table->timestamp('issued_at');
            $table->string('verification_status')->default('pending')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hazard_sources');
    }
};
