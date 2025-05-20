<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class LimitedFunctionalityModeActivatedEvent
 *
 * Dispatched by the DatabaseFailoverManager when both primary and failover database
 * connections are unavailable, and the system switches to the configured 'blocking' connection.
 * This indicates that the application is now operating in a limited functionality mode.
 */
class LimitedFunctionalityModeActivatedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the blocking connection that has been activated.
     *
     * @var string
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName The name of the blocking connection that was activated.
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
