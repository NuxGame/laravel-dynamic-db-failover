<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class DBHealthCheckCommandStarted implements ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $connectionsToCheck;
    public \DateTimeInterface $startTime;

    /**
     * Create a new event instance.
     *
     * @param array $connectionsToCheck
     * @return void
     */
    public function __construct(array $connectionsToCheck = [])
    {
        $this->connectionsToCheck = $connectionsToCheck;
        $this->startTime = now();
    }
}
