<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class PrimaryConnectionRestoredEvent
 *
 * Dispatched by the ConnectionStateManager specifically when the configured
 * primary database connection has been determined to be HEALTHY after being down.
 */
class PrimaryConnectionRestoredEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the primary connection that has been restored.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the primary connection that was restored.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
