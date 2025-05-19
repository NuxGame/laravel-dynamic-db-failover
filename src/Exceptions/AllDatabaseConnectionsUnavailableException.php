<?php

namespace Nuxgame\LaravelDynamicDBFailover\Exceptions;

use RuntimeException;

class AllDatabaseConnectionsUnavailableException extends RuntimeException
{
    public function __construct($message = "All configured database connections (primary and failover) are currently unavailable. Application is in limited functionality mode.", $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
