<?php

/**
 * Testing: fake transport, dry runs, and disabling.
 * ═════════════════════════════════════════════════
 *
 * Demonstrates: asserting on what your code reports, with no HTTP and no network.
 * Key type:     none needed.
 *
 * TransportInterface is a single-method seam. Pass a fake to Client directly —
 * no HTTP, no network, no builder. The one thing to remember: reports are
 * buffered until flush(), so call flush() before asserting or your fake will be
 * empty and the test fails confusingly.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use BugBoard\Client;
use BugBoard\Config;
use BugBoard\Payload;
use BugBoard\TransportInterface;

// ─── The fake ─────────────────────────────────────────────────────────────────
final class FakeTransport implements TransportInterface
{
    /** @var list<Payload> */
    public array $sent = [];

    public function send(Payload $payload): void
    {
        $this->sent[] = $payload;
    }
}

// A unit under test that takes the client as a dependency.
final class CheckoutService
{
    public function __construct(private readonly Client $bugboard) {}

    public function charge(bool $failing): void
    {
        try {
            if ($failing) {
                throw new RuntimeException('card declined');
            }
        } catch (Throwable $e) {
            $this->bugboard->criticalHigh('Payment capture failed', $e, ['payments']);
        }
    }
}

// ─── The test (framework-agnostic assertions via assert()) ────────────────────
$transport = new FakeTransport();

$bugboard = new Client(
    new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'),
    $transport,
);

(new CheckoutService($bugboard))->charge(failing: true);

$bugboard->flush(); // reports are buffered until here — don't forget it

assert(count($transport->sent) === 1);
assert($transport->sent[0]->severity === 'critical');
assert($transport->sent[0]->priority === 'high');
assert(str_contains($transport->sent[0]->title, 'Payment'));

echo "All assertions passed.\n";

/*
 * In PHPUnit the same shape uses $this->assertSame(...) etc.
 *
 * In Laravel, bind the fake so the facade and injected clients both use it:
 *   $this->app->singleton(Client::class, fn () => new Client(
 *       new Config(keyId: 'bbk_test', signingSecret: 'bb_sec_test'),
 *       new FakeTransport(),
 *   ));
 *
 * Other options:
 *   - Disable entirely:  new Config(enabled: false)  (also: no credentials = inert)
 *   - Dry run:           new Config(..., logLocally: true)  — real config resolution,
 *                        payload building, and beforeSend, printed instead of sent.
 */
