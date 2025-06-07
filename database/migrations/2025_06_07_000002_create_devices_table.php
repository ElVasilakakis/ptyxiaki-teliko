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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_unique_id')->unique();
            $table->string('device_number')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('api_url')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('name');
            $table->string('device_type')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('hardware_revision')->nullable();
            $table->string('power_source')->nullable();
            $table->string('status')->default('offline');
            $table->text('last_error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('wifi_connected')->nullable();
            $table->integer('wifi_rssi')->nullable();
            $table->integer('uptime_seconds')->nullable();
            $table->integer('free_heap')->nullable();
            $table->integer('last_status_check')->nullable();
            $table->integer('valid_sensors')->nullable();
            $table->integer('total_sensors')->nullable();
            $table->integer('health_percentage')->nullable();
            $table->string('wifi_strength')->nullable();
            $table->string('memory_status')->nullable();
            $table->string('sensor_status')->nullable();
            $table->string('chip_model')->nullable();
            $table->integer('chip_revision')->nullable();
            $table->integer('cpu_frequency')->nullable();
            $table->integer('flash_size')->nullable();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('land_id')->constrained()->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->json('application_data')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
