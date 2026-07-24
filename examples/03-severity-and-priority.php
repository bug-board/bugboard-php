<?php

/**
 * All 16 reporting methods, and what the description accepts.
 * ══════════════════════════════════════════════════════════
 *
 * Demonstrates: the full reporting surface and the kinds of value you can pass
 *               as a description.
 * Key type:     any (this file uses logLocally, so it needs no real credentials).
 * Run it:       php examples/03-severity-and-priority.php
 *
 * There is no report() method — the METHOD NAME is the classification. The
 * client exposes exactly 16 methods, one per severity×priority pair. A bare
 * severity name is the medium-priority variant.
 *
 *              low            medium (default)               high
 *   critical   criticalLow    critical / criticalMedium      criticalHigh
 *   major      majorLow       major    / majorMedium         majorHigh
 *   moderate   moderateLow    moderate / moderateMedium      moderateHigh
 *   minor      minorLow       minor    / minorMedium         minorHigh
 *
 * Every method takes (string $title, mixed $description = null, array|string $tags = []).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;

// logLocally prints reports instead of sending them, so this runs with no key.
$bugboard = ClientBuilder::create(new Config(
    keyId: 'bbk_demo',
    signingSecret: 'bb_sec_demo',
    logLocally: true,
    debug: true,
));

// ─── The four you'll actually use ─────────────────────────────────────────────
$bugboard->critical('Payment provider returned 500');
$bugboard->major('Checkout is slow');
$bugboard->moderate('Image thumbnail failed to generate');
$bugboard->minor('Tooltip is misaligned on Safari');

// ─── Priority variants (Low / Medium / High) ──────────────────────────────────
$bugboard->criticalHigh('Database connection pool exhausted');
$bugboard->criticalLow('Feature flag lookup fell back to default');
$bugboard->majorHigh('Search index is stale by > 1 hour');
$bugboard->minorLow('Deprecated API parameter used');

// ─── Tags accept an array or a CSV string ─────────────────────────────────────
$bugboard->major('Stripe webhook signature verification failed', null, ['payments', 'stripe']);
$bugboard->major('Stripe webhook signature verification failed', null, 'payments,stripe');

// ─── What the description accepts: pass whatever you already have ──────────────

// A string — unchanged.
$bugboard->minor('Cache miss', 'redis key user:profile:42 was cold');

// A Throwable — class, message, file:line, and stack trace.
try {
    throw new RuntimeException('capture declined');
} catch (Throwable $e) {
    $bugboard->major('Failed to capture payment', $e, ['payments']);
}

// An array — pretty-printed JSON. No need to json_encode() first.
$bugboard->major('Validation failed', [
    'user_id' => 42,
    'errors' => ['email' => 'required'],
]);

// A JsonSerializable / Arrayable / __toString object resolves through a ladder;
// the first rung that can represent the value wins. Here, a JsonSerializable:
$bugboard->moderate('Order snapshot', new class implements JsonSerializable {
    public function jsonSerialize(): array
    {
        return ['id' => 'ord_1', 'total' => 1999];
    }
});

// A scalar — stringified (true, false, 0, 1.5, NaN, …).
$bugboard->moderate('Retry budget consumed', 0);

// ─── Dedup: keep the title stable ─────────────────────────────────────────────
$requestId = 'req_abc123';

// Bad — a new card per request, forever. Don't do this:
// $bugboard->major("Webhook {$requestId} failed at " . time());

// Good — one card whose occurrence count climbs; variable data in the description:
$bugboard->major('Webhook processing failed', ['request_id' => $requestId, 'at' => time()]);

$bugboard->flush(); // this script exits, so force delivery of the (logged) reports
