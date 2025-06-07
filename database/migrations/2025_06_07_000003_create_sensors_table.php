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
        Schema::create('sensors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            $table->string('sensor_type');
            $table->string('sensor_name');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('unit')->nullable();
            $table->json('thresholds')->nullable();
            $table->float('value')->nullable();
            $table->float('accuracy')->nullable();
            $table->timestamp('reading_timestamp')->nullable();
            $table->boolean('enabled')->default(true);
            $table->float('calibration_offset')->nullable();
            $table->timestamp('last_calibration')->nullable();
            $table->boolean('alert_enabled')->default(false);
            $table->float('alert_threshold_min')->nullable();
            $table->float('alert_threshold_max')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sensors');
    }
};
