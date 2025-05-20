<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class FailoverConnectionDownEvent
 *
 * Dispatched by the ConnectionStateManager specifically when the configured
 * failover database connection has been determined to be DOWN after reaching
 * its failure threshold during health checks.
 */
class FailoverConnectionDownEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the failover connection that went down.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the failover connection that is down.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
