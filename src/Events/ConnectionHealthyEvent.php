<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class ConnectionHealthyEvent
 *
 * Dispatched by the ConnectionStateManager when a monitored database connection
 * has been determined to be HEALTHY after a successful health check.
 * This often occurs when a connection recovers after being down or when its status is first confirmed as healthy.
 * Specific events like PrimaryConnectionRestoredEvent or FailoverConnectionRestoredEvent may also be dispatched.
 */
class ConnectionHealthyEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the database connection that has become healthy.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the connection that is healthy.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
