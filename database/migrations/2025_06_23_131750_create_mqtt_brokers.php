<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('mqtt_brokers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // Friendly name for the broker
            $table->string('host'); // MQTT broker hostname/IP
            $table->integer('port')->default(1883); // MQTT port
            $table->string('protocol')->default('mqtt'); // mqtt, mqtts, ws, wss
            $table->string('username')->nullable(); // Authentication username
            $table->string('password')->nullable(); // Authentication password
            $table->boolean('tls_enabled')->default(false); // SSL/TLS encryption
            $table->json('tls_options')->nullable(); // TLS configuration options
            $table->integer('keep_alive')->default(60); // Keep alive interval
            $table->integer('connect_timeout')->default(30); // Connection timeout
            $table->boolean('clean_session')->default(false); // Clean session flag
            $table->integer('qos')->default(1); // Default QoS level
            $table->string('client_id_prefix')->default('laravel'); // Client ID prefix
            $table->boolean('auto_reconnect')->default(true); // Auto-reconnect enabled
            $table->integer('max_reconnect_attempts')->default(5); // Max reconnection attempts
            $table->integer('reconnect_delay')->default(5); // Delay between reconnections
            $table->string('last_will_topic')->nullable(); // Last will topic
            $table->string('last_will_message')->nullable(); // Last will message
            $table->integer('last_will_qos')->default(1); // Last will QoS
            $table->boolean('last_will_retain')->default(true); // Last will retain flag
            $table->string('status')->default('active'); // active, inactive, error
            $table->timestamp('last_connected_at')->nullable(); // Last successful connection
            $table->text('connection_error')->nullable(); // Last connection error
            $table->json('statistics')->nullable(); // Connection statistics
            $table->boolean('is_default')->default(false); // Default broker flag
            $table->text('description')->nullable(); // Broker description
            $table->timestamps();
            
            // Indexes
            $table->index(['host', 'port']);
            $table->index('status');
            $table->index('is_default');
        });
    }

    public function down()
    {
        Schema::dropIfExists('mqtt_brokers');
    }
};
