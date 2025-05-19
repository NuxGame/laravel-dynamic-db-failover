<?php

namespace Nuxgame\LaravelDynamicDBFailover\Constants;

final class ConnectionStatus
{
    public const HEALTHY = 'HEALTHY';
    public const DOWN = 'DOWN';
    public const UNKNOWN = 'UNKNOWN'; // Initial state or when cache is unavailable

    // Private constructor to prevent instantiation
    private function __construct() {}
}
