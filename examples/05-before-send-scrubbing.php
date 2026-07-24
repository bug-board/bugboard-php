<?php

/**
 * Scrub PII and drop reports with a `beforeSend` closure.
 * ══════════════════════════════════════════════════════
 *
 * Demonstrates: the last-mile hook that sees every report right before it's sent.
 * Key type:     any.
 *
 * beforeSend receives the payload as an ARRAY — the exact body about to be sent —
 * and returns it (mutated or not), or null to drop the report. It's your single
 * point for redaction and filtering; it sees every report regardless of where in
 * your app it came from.
 *
 * The return value is re-validated through Payload::fromArray(), so a hook can't
 * produce an invalid request — an unknown severity falls back to `moderate`, and
 * every length clamp is re-applied. You don't need to be careful about lengths.
 *
 * Keep the closure fast and total: it runs synchronously on your request path.
 * If it throws, that one report is lost (the backstop catches it), so your app
 * is unaffected — but don't rely on that.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\ClientBuilder;
use BugBoard\Config;

$bugboard = ClientBuilder::create(new Config(
    keyId: 'bbk_demo',
    signingSecret: 'bb_sec_demo',
    logLocally: true, // so you can watch the scrubber's output
    debug: true,

    beforeSend: function (array $payload): ?array {
        // $payload = [
        //     'severity' => 'critical', 'priority' => 'high',
        //     'title' => '…', 'tags' => ['…'],
        //     'description' => '…',    // present only if one was given (always a string here)
        //     'file_name' => '…', 'line_number' => 42, // present if captureLocation is on
        // ];

        // 1. Drop anything from the health-check path entirely.
        if (str_contains($payload['title'], 'HealthCheck')) {
            return null;
        }

        // 2. Scrub emails out of the description.
        $payload['description'] = preg_replace(
            '/[\w.+-]+@[\w-]+\.[\w.]+/',
            '[email]',
            $payload['description'] ?? ''
        ) ?: null;

        // 3. Route a subsystem's reports to a shared team tag.
        if (in_array('billing', $payload['tags'], true)) {
            $payload['tags'][] = 'team:payments';
        }

        return $payload;
    },
));

$bugboard->major('Payment failed for user alice@example.com', 'contact bob@example.com', ['billing']);
$bugboard->minor('HealthCheck ping'); // dropped by beforeSend

$bugboard->flush();

// hideApiResponse (default true) is deliberately NOT in the payload — it's a
// header, so it stays out of reach of beforeSend and readable when encrypted.
