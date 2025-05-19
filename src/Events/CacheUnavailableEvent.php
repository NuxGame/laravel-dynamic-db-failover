<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CacheUnavailableEvent
{
    use Dispatchable, SerializesModels;

    public Throwable $exception;

    /**
     * Create a new event instance.
     *
     * @param Throwable $exception
     * @return void
     */
    public function __construct(Throwable $exception)
    {
        $this->exception = $exception;
    }
}
