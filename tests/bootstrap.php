<?php

/**
 * Bootstrap file for PHPUnit
 * Completely suppresses PHPUnit deprecation warnings
 */

// Load composer's autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Completely suppress E_DEPRECATED and E_USER_DEPRECATED notices
error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// In case PHPUnit uses an internal mechanism to track deprecations, try to hook into it
if (class_exists('PHPUnit\\Util\\Deprecation')) {
    $reflection = new ReflectionClass('PHPUnit\\Util\\Deprecation');
    $property = $reflection->getProperty('instance');
    $property->setAccessible(true);
    $instance = $property->getValue();

    if ($instance !== null) {
        $method = $reflection->getMethod('neverTriggered');
        $method->setAccessible(true);
        $method->invoke($instance);
    }
}
