<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class ConnectionDownEvent
 *
 * Dispatched by the ConnectionStateManager when a monitored database connection
 * has been determined to be DOWN after reaching its failure threshold during health checks.
 * This is a generic event for any monitored connection becoming unavailable.
 * Specific events like PrimaryConnectionDownEvent or FailoverConnectionDownEvent may also be dispatched.
 */
class ConnectionDownEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the database connection that has gone down.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the connection that is down.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
