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

        $point = ['lat' => $latitude, 'lng' => $longitude];
        $vertices = [];

        // Extract coordinates from GeoJSON polygon
        if (isset($polygon['features'][0]['geometry']['coordinates'][0])) {
            $coordinates = $polygon['features'][0]['geometry']['coordinates'][0];
            foreach ($coordinates as $coord) {
                $vertices[] = ['lng' => $coord[0], 'lat' => $coord[1]];
            }
        } else {
            return false;
        }

        return $this->pointInPolygon($point, $vertices);
    }

    /**
     * Ray casting algorithm to determine if point is inside polygon
     */
    private function pointInPolygon($point, $vertices)
    {
        $intersections = 0;
        $verticesCount = count($vertices);

        for ($i = 1; $i < $verticesCount; $i++) {
            $vertex1 = $vertices[$i - 1];
            $vertex2 = $vertices[$i];

            // Check if point is on horizontal boundary
            if ($vertex1['lat'] == $vertex2['lat'] && 
                $vertex1['lat'] == $point['lat'] && 
                $point['lng'] > min($vertex1['lng'], $vertex2['lng']) && 
                $point['lng'] < max($vertex1['lng'], $vertex2['lng'])) {
                return true; // On boundary
            }

            // Ray casting algorithm
            if ($point['lat'] > min($vertex1['lat'], $vertex2['lat']) && 
                $point['lat'] <= max($vertex1['lat'], $vertex2['lat']) && 
                $point['lng'] <= max($vertex1['lng'], $vertex2['lng']) && 
                $vertex1['lat'] != $vertex2['lat']) {
                
                $xinters = ($point['lat'] - $vertex1['lat']) * 
                          ($vertex2['lng'] - $vertex1['lng']) / 
                          ($vertex2['lat'] - $vertex1['lat']) + $vertex1['lng'];

                if ($xinters == $point['lng']) {
                    return true; // On boundary
                }

                if ($vertex1['lng'] == $vertex2['lng'] || $point['lng'] <= $xinters) {
                    $intersections++;
                }
            }
        }

        // Odd number of intersections = inside
        return ($intersections % 2 != 0);
    }
}
