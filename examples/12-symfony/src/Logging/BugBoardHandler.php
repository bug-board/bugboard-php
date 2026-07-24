<?php

declare(strict_types=1);

namespace App\Logging;

use BugBoard\Client as BugBoardClient;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Route existing Monolog logs to your board without touching call sites.
 *
 * Be deliberate about the level — ERROR and up is usually right; WARNING will
 * flood you. Wire it up in config/packages/prod/monolog.yaml:
 *
 *   monolog:
 *       handlers:
 *           bugboard:
 *               type: service
 *               id: App\Logging\BugBoardHandler
 *               level: error
 *
 * The same pattern works in Laravel with a custom log channel
 * (`'via' => BugBoardLogger::class`).
 */
final class BugBoardHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly BugBoardClient $bugboard,
        Level $level = Level::Error,
    ) {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $method = match (true) {
            $record->level->value >= Level::Critical->value => 'criticalHigh',
            $record->level->value >= Level::Error->value    => 'major',
            default                                          => 'moderate',
        };

        // $record->message keeps its {placeholders} uninterpolated — a good fit
        // for server-side dedup: 'User {id} not found' is one card, not one per user.
        $this->bugboard->{$method}(
            $record->message,
            $record->context['exception'] ?? null,
            ['monolog', $record->channel],
        );
    }
}
