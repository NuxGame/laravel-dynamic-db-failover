<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SwitchedToPrimaryConnectionEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the connection that was previously active.
     * Can be null if this is the first determination.
     */
    public ?string $previousConnectionName;

    /**
     * The name of the primary connection that is now active.
     */
    public string $newConnectionName;

    /**
     * Create a new event instance.
     *
     * @param string|null $previousConnectionName
     * @param string $newConnectionName
     * @return void
     */
    public function __construct(?string $previousConnectionName, string $newConnectionName)
    {
        $this->previousConnectionName = $previousConnectionName;
        $this->newConnectionName = $newConnectionName;
    }
}
