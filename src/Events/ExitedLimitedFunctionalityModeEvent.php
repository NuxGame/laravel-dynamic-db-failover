<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class ExitedLimitedFunctionalityModeEvent
 *
 * Dispatched by the DatabaseFailoverManager when the system transitions
 * out of limited functionality mode (i.e., when it was using the 'blocking' connection)
 * and successfully switches back to a functional database connection (either primary or failover).
 */
class ExitedLimitedFunctionalityModeEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the connection to which the system was restored (primary or failover).
     *
     * @var string
     */
    public string $restoredToConnectionName;

    /**
     * Create a new event instance.
     *
     * @param string $restoredToConnectionName The name of the connection (primary or failover) that is now active.
     * @return void
     */
    public function __construct(string $restoredToConnectionName)
    {
        $this->restoredToConnectionName = $restoredToConnectionName;
    }
}
