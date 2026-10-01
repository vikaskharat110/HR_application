<?php
/** Great-circle distance (Haversine), in metres — port of lib/geo.ts. */
declare(strict_types=1);

function distance_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}
