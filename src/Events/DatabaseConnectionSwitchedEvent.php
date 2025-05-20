<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class DatabaseConnectionSwitchedEvent
 *
 * Dispatched by the DatabaseFailoverManager when the application's default database connection
 * is switched from one connection to another (e.g., from primary to failover, or failover to blocking).
 * This is a general event indicating a change in the active connection.
 * More specific events like SwitchedToPrimaryConnectionEvent or SwitchedToFailoverConnectionEvent
 * provide more context about the nature of the switch.
 */
class DatabaseConnectionSwitchedEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the previously active database connection.
     * Null if this is the initial connection setup.
     *
     * @var string|null
     */
    public ?string $previousConnectionName;

    /**
     * The name of the newly activated database connection.
     *
     * @var string
     */
    public string $newConnectionName;

    /**
     * Create a new event instance.
     *
     * @param string|null $previousConnectionName The name of the connection that was previously active.
     * @param string $newConnectionName The name of the connection that is now active.
     * @return void
     */
    public function __construct(?string $previousConnectionName, string $newConnectionName)
    {
        $this->previousConnectionName = $previousConnectionName;
        $this->newConnectionName = $newConnectionName;
    }
}
