<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PhpMqtt\Client\Facades\MQTT;

class MqttController extends Controller
{
    public function publish(Request $request)
    {
        $topic = $request->input('topic');
        $message = $request->input('message');
        
        $mqtt = MQTT::connection();
        $mqtt->publish($topic, $message);
        $mqtt->disconnect();
        
        return response()->json(['status' => 'Message published']);
    }
}
