<?php

if (!function_exists('bccomp')) {
    function bccomp($left, $right, $scale = 2) {
        $multiplier = pow(10, $scale);
        $leftVal = (int) round((float)$left * $multiplier);
        $rightVal = (int) round((float)$right * $multiplier);
        if ($leftVal > $rightVal) return 1;
        if ($leftVal < $rightVal) return -1;
        return 0;
    }
}

if (!function_exists('bcadd')) {
    function bcadd($left, $right, $scale = 2) {
        return number_format((float)$left + (float)$right, $scale, '.', '');
    }
}

if (!function_exists('bcsub')) {
    function bcsub($left, $right, $scale = 2) {
        return number_format((float)$left - (float)$right, $scale, '.', '');
    }
}
