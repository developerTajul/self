<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Events;

use Core\Shared\Domain\Contracts\EventDispatcherContract;

/**
 * Domain events published as WordPress actions.
 *
 * @implements EventDispatcherContract
 */
final class WpEventDispatcher implements EventDispatcherContract
{
    /**
     * @param string $prefix The plugin's action prefix, without the
     *                       `_core_event` suffix this adapter adds.
     */
    public function __construct(private readonly string $prefix) {}

    /**
     * Publishes one event as a general action and a per-class action.
     *
     * @param object $event
     *
     * @return void
     */
    public function dispatch(object $event): void
    {
        do_action($this->prefix . '_core_event', $event);
        do_action($this->prefix . '_core_event_' . $event::class, $event);
    }
}
