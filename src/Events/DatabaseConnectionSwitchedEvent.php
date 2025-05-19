<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DatabaseConnectionSwitchedEvent
{
    use Dispatchable, SerializesModels;

    public ?string $previousConnectionName;
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
