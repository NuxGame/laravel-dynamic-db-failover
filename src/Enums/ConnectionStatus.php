<?php

namespace Nuxgame\LaravelDynamicDBFailover\Enums;

/**
 * Enum ConnectionStatus
 *
 * Represents the possible health statuses of a database connection being monitored
 * by the dynamic failover system.
 */
enum ConnectionStatus: string
{
    /** The connection is confirmed to be healthy and operational. */
    case HEALTHY = 'HEALTHY';

    /** The connection is confirmed to be down or unresponsive. */
    case DOWN = 'DOWN';

    /** The connection status is not yet determined (e.g., initial state) or cannot be reliably ascertained (e.g., cache issues). */
    case UNKNOWN = 'UNKNOWN'; // Initial state or when cache is unavailable
}
