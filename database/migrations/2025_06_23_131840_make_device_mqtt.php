<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('mqtt_broker_id')->nullable()->constrained('mqtt_brokers')->onDelete('set null');
            $table->json('mqtt_topics_config')->nullable(); // Custom topic configuration
            
            $table->index('mqtt_broker_id');
        });
    }

    public function down()
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['mqtt_broker_id']);
            $table->dropColumn(['mqtt_broker_id', 'mqtt_client_id_suffix', 'mqtt_topics_config']);
        });
    }
};
