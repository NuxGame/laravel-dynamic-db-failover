<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Class SwitchedToFailoverConnectionEvent
 *
 * Dispatched by the DatabaseFailoverManager when the system switches the
 * active database connection to the configured failover connection.
 * This typically occurs when the primary connection becomes unavailable.
 */
class SwitchedToFailoverConnectionEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The name of the connection that was previously active.
     * Can be null if this is the first determination.
     *
     * @var string|null
     */
    public ?string $previousConnectionName;

    /**
     * The name of the failover connection that is now active.
     *
     * @var string
     */
    public string $newConnectionName;

    /**
     * Create a new event instance.
     *
     * @param string|null $previousConnectionName The name of the connection that was previously active.
     * @param string $newConnectionName The name of the failover connection that is now active.
     * @return void
     */
    public function __construct(?string $previousConnectionName, string $newConnectionName)
    {
        $this->previousConnectionName = $previousConnectionName;
        $this->newConnectionName = $newConnectionName;
    }
}
