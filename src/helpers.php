<?php

use Illuminate\Support\Carbon;

if (!function_exists('parseShopifyDate')) {
    function parseShopifyDate(?string $dateStr)
    {
        if (!$dateStr) {
            return null;
        }

        // iso and short
        try {
            $result = Carbon::parse($dateStr);

            return $result;
        } catch (\Throwable $th) {
        }

        // american
        try {
            $result = Carbon::createFromFormat('d/m/Y', $dateStr);

            return $result;
        } catch (\Throwable $th) {
        }

        // australian
        try {
            $result = Carbon::createFromFormat('m/d/Y', $dateStr);

            return $result;
        } catch (\Throwable $th) {
        }

        // errors
        return null;
    }
}
