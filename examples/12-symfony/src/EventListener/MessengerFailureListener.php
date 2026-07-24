<?php

declare(strict_types=1);

namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Report the FINAL failure of a Messenger message.
 */
#[AsEventListener]
final class MessengerFailureListener
{
    public function __construct(private readonly BugBoardClient $bugboard) {}

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        // Only report the final failure — not every retry attempt. Without this
        // guard, one flaky message produces four identical reports.
        if ($event->willRetry()) {
            return;
        }

        $this->bugboard->major(
            'Message handling failed: ' . $event->getEnvelope()->getMessage()::class,
            $event->getThrowable(),
            ['messenger'],
        );

        // A Messenger worker is long-lived: the shutdown hook won't run for hours,
        // so flush explicitly here.
        $this->bugboard->flush();
    }
}
