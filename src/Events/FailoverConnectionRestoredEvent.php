<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class FailoverConnectionRestoredEvent
 *
 * Dispatched by the ConnectionStateManager specifically when the configured
 * failover database connection has been determined to be HEALTHY after being down.
 */
class FailoverConnectionRestoredEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the failover connection that has been restored.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the failover connection that has been restored.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
