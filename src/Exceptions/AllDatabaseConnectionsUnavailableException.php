<?php

namespace Nuxgame\LaravelDynamicDBFailover\Exceptions;

use RuntimeException;

/**
 * Class AllDatabaseConnectionsUnavailableException
 *
 * Thrown when both the primary and failover database connections are determined to be unavailable.
 * This typically means the application has switched to the 'blocking' connection and is operating
 * in a limited functionality mode where database queries will fail.
 */
class AllDatabaseConnectionsUnavailableException extends RuntimeException
{
    /**
     * AllDatabaseConnectionsUnavailableException constructor.
     *
     * @param string $message The exception message.
     * @param int $code The exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct($message = "All configured database connections (primary and failover) are currently unavailable. Application is in limited functionality mode.", $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
