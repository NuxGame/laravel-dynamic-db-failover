<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class PrimaryConnectionDownEvent
 *
 * Dispatched by the ConnectionStateManager specifically when the configured
 * primary database connection has been determined to be DOWN after reaching
 * its failure threshold during health checks.
 */
class PrimaryConnectionDownEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the primary connection that went down.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the primary connection that is down.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
