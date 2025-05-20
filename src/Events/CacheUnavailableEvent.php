<?php

namespace Nuxgame\LaravelDynamicDBFailover\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Class CacheUnavailableEvent
 *
 * Dispatched when the caching service encounters an error (e.g., becomes unavailable)
 * during operations performed by the ConnectionStateManager, such as trying to read
 * or write connection health statuses. This event carries the exception that occurred.
 */
class CacheUnavailableEvent
{
    use Dispatchable, SerializesModels;

    /**
     * The exception that was caught when interacting with the cache.
     *
     * @var Throwable
     */
    public Throwable $exception;

    /**
     * Create a new event instance.
     *
     * @param Throwable $exception The exception that occurred during a cache operation.
     * @return void
     */
    public function __construct(Throwable $exception)
    {
        $this->exception = $exception;
    }
}
