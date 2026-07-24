<?php

/**
 * Long-running worker: flush per unit of work.
 * ════════════════════════════════════════════
 *
 * Demonstrates: correct delivery from a process that doesn't exit for hours.
 * Key type:     secret (server-side).
 *
 * The SDK's shutdown hook only fires when the process finally EXITS. For a short
 * script that's fine. For a long-running worker or daemon it's a trap: reports
 * pile up in the buffer, and a worker that reports faster than it exits will hit
 * maxQueueSize (default 100) and start dropping.
 *
 * The fix is one line: flush at the end of each unit of work.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;

$bugboard = ClientBuilder::create(new Config(
    keyId: getenv('BUGBOARD_KEY_ID') ?: null,
    signingSecret: getenv('BUGBOARD_SIGNING_SECRET') ?: null,
    environment: getenv('APP_ENV') ?: 'production',
    defaultTags: ['worker'],
));

// A stand-in job source. In real life this is your queue / stream / cron loop.
$jobs = [
    fn () => null,                                    // succeeds
    fn () => throw new RuntimeException('bad row'),   // fails
    fn () => null,                                    // succeeds
];

foreach ($jobs as $i => $handle) {
    $name = "job#$i";

    try {
        $handle();
    } catch (Throwable $e) {
        // Stable title from the job name — repeated failures dedupe into one card.
        $bugboard->major("Job failed: $name", $e, ['queue']);
    } finally {
        // Deliver per job, not per process lifetime. Without this, a busy worker
        // eventually overflows the buffer and silently drops reports.
        $bugboard->flush();
    }
}

// droppedCount() tells you how many reports were lost to buffer overflow — worth
// emitting as a health metric so you notice if you're under-flushing.
if ($bugboard->droppedCount() > 0) {
    fwrite(STDERR, "WARNING: {$bugboard->droppedCount()} reports dropped to overflow\n");
}

echo "Worker loop finished.\n";
