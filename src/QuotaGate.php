<?php

declare(strict_types=1);

namespace BugBoard;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Client-side suppression for reports the server would only discard.
 *
 * Once the server says it is dropping reports, sending more of them achieves
 * nothing: the response is a 200 the SDK is contractually forbidden from
 * retrying (API reference §6.1), so every further report is a wasted round trip
 * from inside the customer's app. The gate closes for as long as the drop is
 * expected to last, and reports are discarded before they reach the network.
 *
 * It re-opens on its own, and the first report through afterwards is an
 * ordinary send — if nothing has changed the server drops it again and re-arms
 * the gate, costing one request per window rather than one per report.
 *
 * Pass a {@see QuotaStore} to keep the deadline across requests; see that
 * interface for why PHP in particular needs one.
 */
final class QuotaGate
{
    public const REASON_QUOTA = 'quota';

    public const REASON_PAUSED = 'paused';

    public const REASON_ARCHIVED = 'archived';

    /** Not a wire value: a `reason` this SDK version does not recognize. */
    public const REASON_UNKNOWN = 'unknown';

    /**
     * How long a non-quota drop suppresses for.
     *
     * `paused` and `archived` are lifecycle states a human flips in the
     * dashboard, so unlike a quota window they have no predictable end. Half an
     * hour is long enough to stop a busy app hammering an endpoint that is
     * discarding everything, and short enough that un-pausing a project doesn't
     * cost a day of reports. An unrecognized reason gets the same treatment:
     * without knowing what it means, the short window is the one that can't do
     * much damage.
     */
    private const LIFECYCLE_SUPPRESSION_SECONDS = 1800;

    private ?int $until = null;

    private bool $loaded = false;

    private int $discarded = 0;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock Injectable time source, for tests. */
    public function __construct(
        private readonly Logger $logger,
        private readonly ?QuotaStore $store = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Whether reports should be discarded locally right now. Counts the
     * discard, so call it once per report.
     */
    public function shouldDiscard(): bool
    {
        $until = $this->deadline();

        if ($until === null) {
            return false;
        }

        if (($this->clock)() >= $until) {
            // The window has passed. Re-open and let this report through as the
            // probe that finds out whether anything changed.
            $this->logger->debug(sprintf(
                'Quota suppression lifted after discarding %d report(s) locally.',
                $this->discarded,
            ));

            $this->until = null;
            $this->discarded = 0;
            $this->store?->clear();

            return false;
        }

        $this->discarded++;
        $this->logger->debug(sprintf(
            'Report discarded locally: suppressed until %s (%d so far).',
            gmdate('c', $until),
            $this->discarded,
        ));

        return true;
    }

    /** Arm the gate after the server discarded a report. */
    public function arm(string $reason): void
    {
        $now = ($this->clock)();

        $next = $reason === self::REASON_QUOTA
            ? self::nextUtcMidnight($now)
            : $now + self::LIFECYCLE_SUPPRESSION_SECONDS;

        // Only announce a gate that is actually closing further than it already
        // was — a burst of buffered reports all landing on the same drop must
        // not produce a burst of identical warnings.
        $current = $this->deadline();

        if ($current !== null && $next <= $current) {
            return;
        }

        $this->until = $next;
        $this->loaded = true;
        $this->store?->suppressUntil($next);

        $this->logger->warn(sprintf(
            'Report dropped by the server: %s. Suppressing reports locally until %s.',
            self::describe($reason),
            gmdate('c', $next),
        ));
    }

    /**
     * Read the drop envelope from a 2xx body, returning the reason or null when
     * the report was not dropped.
     *
     * `dropped` + `reason` is the current contract; `quota_exceeded` is a legacy
     * alias the server still ships alongside it, and is all an older server
     * sends. Either flag means the report was accepted and discarded.
     *
     * @param  array<string, mixed>  $body
     */
    public static function reasonFrom(array $body): ?string
    {
        if (($body['dropped'] ?? null) !== true && ($body['quota_exceeded'] ?? null) !== true) {
            return null;
        }

        $reason = $body['reason'] ?? null;

        if (in_array($reason, [self::REASON_QUOTA, self::REASON_PAUSED, self::REASON_ARCHIVED], true)) {
            return $reason;
        }

        // No `reason` at all means an older server, where the legacy flag only
        // ever meant a spent allowance. A `reason` we don't recognize is a newer
        // server saying something this version can't interpret.
        return $reason === null ? self::REASON_QUOTA : self::REASON_UNKNOWN;
    }

    /**
     * The current deadline, reading the store once per instance.
     *
     * A store that throws is treated as an open gate: suppression is an
     * optimization, and losing it must never cost the host app a report.
     */
    private function deadline(): ?int
    {
        if ($this->loaded) {
            return $this->until;
        }

        $this->loaded = true;

        try {
            $this->until = $this->store?->suppressedUntil();
        } catch (Throwable $exception) {
            $this->logger->debug('Quota store unreadable: '.$exception->getMessage());
            $this->until = null;
        }

        return $this->until;
    }

    /**
     * When the account's allowance next refills.
     *
     * The server anchors the account-wide pool to UTC and rolls it at midnight,
     * whatever timezones the owner's projects span, so this is exact rather
     * than a guess.
     *
     * One caveat: the server's per-project containment cap rolls at the
     * *project's* own midnight but reports the same `quota` reason, so a drop
     * caused by that cap can suppress past its real reset. That trade is
     * deliberate — the cap is an abuse ceiling a normal project never reaches,
     * and the alternative is guessing a timezone the SDK isn't told.
     */
    private static function nextUtcMidnight(int $now): int
    {
        return (new DateTimeImmutable('@'.$now))
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('tomorrow midnight')
            ->getTimestamp();
    }

    private static function describe(string $reason): string
    {
        return match ($reason) {
            self::REASON_QUOTA => "the project owner's event allowance is exhausted",
            self::REASON_PAUSED => 'the project is paused',
            self::REASON_ARCHIVED => 'the project is archived',
            default => 'the server is discarding reports',
        };
    }
}
