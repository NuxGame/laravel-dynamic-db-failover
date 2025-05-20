<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FailoverConnectionRestoredEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the failover connection that has been restored.
     */
    public string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param string $connectionName
     * @return void
     */
    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }
}
