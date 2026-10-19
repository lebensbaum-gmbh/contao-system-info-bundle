<?php

declare(strict_types=1);

namespace Lebensbaum\ContaoSystemInfoBundle\Tests\Fixtures;

use Symfony\Component\Console\Event\ConsoleEvent;

/** A service resolved from a split, generated container, just like the FOS listener. */
final class ConsoleLifecycleListener
{
    public function __construct(private readonly string $tracePath, private readonly string $kind)
    {
        file_put_contents($this->tracePath, 'constructed:'.$this->kind."\n", FILE_APPEND);
    }

    public function onConsoleEvent(ConsoleEvent $event): void
    {
        file_put_contents($this->tracePath, 'called:'.$this->kind."\n", FILE_APPEND);
    }
}
