<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Keeps the worker's console listeners usable after its container files change. */
final class MaintenanceConsoleLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        // FrameworkBundle can wrap the service in a LazyCommand, so checking
        // the concrete command class would miss the production worker.
        if ('maintend:maintenance-job' !== $event->getCommand()?->getName()) {
            return;
        }

        // Symfony dispatches these events AFTER Command::execute() returns.
        // The worker can replace/remove its own compiled container in Composer
        // or contao-setup subprocesses. Resolve lazy listener factories while
        // that original container still exists (notably FOS cache invalidation).
        // This only instantiates listeners: events, priorities and exceptions
        // retain their normal behavior; no flush or termination is skipped.
        $this->dispatcher->getListeners(ConsoleEvents::TERMINATE);
        $this->dispatcher->getListeners(ConsoleEvents::ERROR);
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['onConsoleCommand', 2048]];
    }
}
