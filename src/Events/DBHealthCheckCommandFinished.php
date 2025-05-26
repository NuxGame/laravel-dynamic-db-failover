<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Console\Command; // For exit codes

class DBHealthCheckCommandFinished implements ShouldQueue // Renamed class
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $connectionsChecked;
    public \DateTimeInterface $endTime;
    public int $exitCode;

    /**
     * Create a new event instance.
     *
     * @param array $connectionsChecked
     * @param int $exitCode Command::SUCCESS or Command::FAILURE
     * @return void
     */
    public function __construct(array $connectionsChecked = [], int $exitCode = Command::SUCCESS)
    {
        $this->connectionsChecked = $connectionsChecked;
        $this->exitCode = $exitCode;
        $this->endTime = now();
    }
}
