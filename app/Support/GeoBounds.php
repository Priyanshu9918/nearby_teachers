<?php

namespace App\Support;

class GeoBounds
{
    /** Conservative spherical bounds; exact distance is still checked by MySQL. */
    public static function sql(float $latitude, float $longitude, float $radiusMeters, string $alias = ''): array
    {
        if ($alias !== '' && ! preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
            throw new \InvalidArgumentException('Invalid geographical table alias.');
        }
        $latitudeColumn = ($alias === '' ? '' : $alias.'.').'latitude';
        $longitudeColumn = ($alias === '' ? '' : $alias.'.').'longitude';
        // Slightly smaller than MySQL's mean earth radius, to avoid excluding edge matches.
        $angle = $radiusMeters / 6350000;
        $deltaLatitude = rad2deg($angle);
        $south = max(-90, $latitude - $deltaLatitude);
        $north = min(90, $latitude + $deltaLatitude);
        $sql = '('.$latitudeColumn.' BETWEEN ? AND ?';
        $bindings = [$south, $north];
        if ($south > -90 && $north < 90) {
            $deltaLongitude = rad2deg(asin(min(1, sin($angle) / cos(deg2rad($latitude)))));
            $west = $longitude - $deltaLongitude;
            $east = $longitude + $deltaLongitude;
            if ($west < -180) {
                $sql .= ' AND ('.$longitudeColumn.' >= ? OR '.$longitudeColumn.' <= ?)';
                array_push($bindings, $west + 360, $east);
            } elseif ($east > 180) {
                $sql .= ' AND ('.$longitudeColumn.' >= ? OR '.$longitudeColumn.' <= ?)';
                array_push($bindings, $west, $east - 360);
            } else {
                $sql .= ' AND '.$longitudeColumn.' BETWEEN ? AND ?';
                array_push($bindings, $west, $east);
            }
        }

        return [$sql.')', $bindings];
    }
}
