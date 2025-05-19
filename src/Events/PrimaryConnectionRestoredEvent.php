<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PrimaryConnectionRestoredEvent
{
    use Dispatchable, SerializesModels;

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
