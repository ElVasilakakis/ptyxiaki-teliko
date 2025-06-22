<?php

namespace App\Services;

class GeofencingService
{
    /**
     * Check if a GPS coordinate is inside a polygon (geofence)
     */
    public function isPointInsidePolygon($latitude, $longitude, $polygon)
    {
        if (!$polygon || !is_array($polygon)) {
            return false;
        }

        // Extract coordinates from GeoJSON polygon
        if (!isset($polygon['features'][0]['geometry']['coordinates'][0])) {
            return false;
        }

        $coordinates = $polygon['features'][0]['geometry']['coordinates'][0];
        
        return $this->pointInPolygon((float)$latitude, (float)$longitude, $coordinates);
    }

    /**
     * Ray casting algorithm to determine if point is inside polygon
     * Uses the standard ray casting algorithm with proper edge case handling
     */
    private function pointInPolygon($lat, $lng, $vertices)
    {
        $inside = false;
        $n = count($vertices);
        $j = $n - 1;

        for ($i = 0; $i < $n; $i++) {
            // Vertices are [lng, lat] format in GeoJSON
            $xi = (float)$vertices[$i][0]; // longitude
            $yi = (float)$vertices[$i][1]; // latitude
            $xj = (float)$vertices[$j][0]; // longitude
            $yj = (float)$vertices[$j][1]; // latitude

            // Check if point is exactly on a vertex
            if ($xi == $lng && $yi == $lat) {
                return true;
            }

            // Ray casting test
            if ((($yi > $lat) !== ($yj > $lat)) && 
                ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
            $j = $i;
        }

        return $inside;
    }

    /**
     * Calculate distance between two GPS points (in meters)
     * Useful for debugging and validation
     */
    public function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get the center point of a polygon (for debugging)
     */
    public function getPolygonCenter($polygon)
    {
        if (!$polygon || !is_array($polygon)) {
            return null;
        }

        if (!isset($polygon['features'][0]['geometry']['coordinates'][0])) {
            return null;
        }

        $coordinates = $polygon['features'][0]['geometry']['coordinates'][0];
        $totalLat = 0;
        $totalLng = 0;
        $count = count($coordinates);

        foreach ($coordinates as $coord) {
            $totalLng += $coord[0];
            $totalLat += $coord[1];
        }

        return [
            'lat' => $totalLat / $count,
            'lng' => $totalLng / $count
        ];
    }

    /**
     * Debug method to test the geofencing with your specific data
     */
    public function debugGeofencing($lat, $lng, $polygon)
    {
        $result = [
            'point' => ['lat' => $lat, 'lng' => $lng],
            'is_inside' => $this->isPointInsidePolygon($lat, $lng, $polygon),
            'polygon_center' => $this->getPolygonCenter($polygon)
        ];

        if ($result['polygon_center']) {
            $result['distance_to_center'] = $this->calculateDistance(
                $lat, $lng,
                $result['polygon_center']['lat'],
                $result['polygon_center']['lng']
            );
        }

        return $result;
    }

    /**
     * Check if a point is inside a simple rectangular boundary
     * Optimized for rectangular geofences
     */
    public function isPointInsideRectangle($lat, $lng, $minLat, $maxLat, $minLng, $maxLng)
    {
        return ($lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng);
    }

    /**
     * Validate GeoJSON structure
     */
    public function validateGeoJSON($geojson)
    {
        if (!is_array($geojson)) {
            return false;
        }

        if (!isset($geojson['type']) || $geojson['type'] !== 'FeatureCollection') {
            return false;
        }

        if (!isset($geojson['features']) || !is_array($geojson['features'])) {
            return false;
        }

        if (empty($geojson['features'])) {
            return false;
        }

        $feature = $geojson['features'][0];
        if (!isset($feature['geometry']['type']) || $feature['geometry']['type'] !== 'Polygon') {
            return false;
        }

        if (!isset($feature['geometry']['coordinates'][0]) || !is_array($feature['geometry']['coordinates'][0])) {
            return false;
        }

        return true;
    }
}
