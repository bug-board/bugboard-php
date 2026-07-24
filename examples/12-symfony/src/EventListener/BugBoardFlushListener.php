<?php

declare(strict_types=1);

namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flush after the response has been sent. `kernel.terminate` fires after the
 * response reaches the client, so reporting adds no latency to the request.
 *
 * This is the piece the bundle does NOT register for you — and under worker
 * runtimes (FrankenPHP, RoadRunner, Swoole) it is the ONLY thing that delivers
 * your reports, because the shutdown hook never fires.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class BugBoardFlushListener
{
    public function __construct(private readonly BugBoardClient $bugboard) {}

    public function __invoke(TerminateEvent $event): void
    {
        // No-op on an empty buffer, so this costs nothing on quiet requests.
        $this->bugboard->flush();
    }
}
