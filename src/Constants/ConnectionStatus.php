<?php

namespace Nuxgame\LaravelDynamicDBFailover\Constants;

enum ConnectionStatus: string
{
    case HEALTHY = 'HEALTHY';
    case DOWN = 'DOWN';
    case UNKNOWN = 'UNKNOWN'; // Initial state or when cache is unavailable
}
