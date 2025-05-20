<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExitedLimitedFunctionalityModeEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the connection to which the system was restored (primary or failover).
     */
    public string $restoredToConnectionName;

    /**
     * Create a new event instance.
     *
     * @param string $restoredToConnectionName
     * @return void
     */
    public function __construct(string $restoredToConnectionName)
    {
        $this->restoredToConnectionName = $restoredToConnectionName;
    }
}
