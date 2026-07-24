<?php

declare(strict_types=1);

namespace App\EventListener;

use BugBoard\Client as BugBoardClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Report every unhandled exception.
 */
#[AsEventListener]
final class ExceptionListener
{
    public function __construct(private readonly BugBoardClient $bugboard) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // 4xx are client mistakes, not your bugs — skip them.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return;
        }

        $this->bugboard->critical(
            $exception->getMessage() ?: $exception::class,
            $exception,
            ['unhandled'],
        );
    }
}
