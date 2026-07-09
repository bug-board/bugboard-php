<?php

declare(strict_types=1);

namespace BugBoard;

/**
 * Call-site capture.
 *
 * Every reporting method wants to record *where in the user's code* it was
 * called — the file and line — the way an error's throw site is recorded.
 * Whoever synchronously invokes a reporting method is on the PHP call stack at
 * that instant, so `debug_backtrace()` taken inside the SDK contains the user's
 * frame. We walk the trace, skip the SDK's own frames, and return the file and
 * line where control left the SDK for user code.
 *
 * This works in every calling context (inside a `catch`, a loop, a closure,
 * a framework request cycle, or a plain top-level call) because the capture is
 * synchronous with the call.
 */
final class Location
{
    /**
     * Capture the file and line of the code that called into the SDK.
     *
     * Each backtrace frame carries the location where its function was *called*
     * — so the user's call site is the location recorded on the **last frame in
     * the SDK dispatch chain**, whether that is `Client::__call` (a direct
     * `$client->criticalHigh(...)`) or a framework facade's `__callStatic`
     * (`BugBoard::criticalHigh(...)`). We skip every dispatch frame and return
     * the location of the last one, which points at user code. Dispatch frames
     * are recognised by class — the `BugBoard\…` namespace, plus the framework
     * facade base that forwards static calls (its inherited `__callStatic` is
     * reported under the framework class, not our subclass). The SDK's own test
     * namespace is excluded so the library treats test callers as user code.
     *
     * Returns `null` on any failure or when no dispatch frame carries a
     * location. Reporting must never throw, so this never does.
     *
     * @return array{file: string, line: int}|null
     */
    public static function capture(): ?array
    {
        try {
            /** @var array{file?: string, line?: int}|null $lastDispatchFrame */
            $lastDispatchFrame = null;

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (! self::isDispatchFrame($frame)) {
                    break; // First frame in user code — stop; the answer is behind us.
                }

                $lastDispatchFrame = $frame;
            }

            $file = $lastDispatchFrame['file'] ?? null;
            $line = $lastDispatchFrame['line'] ?? null;

            if (is_string($file) && is_int($line)) {
                return ['file' => $file, 'line' => $line];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a frame is part of the SDK's own dispatch chain (so it should be
     * skipped). Everything under the `BugBoard\` namespace counts — including
     * the Laravel/Symfony facade subclasses — as does the framework facade base
     * whose inherited `__callStatic` forwards a static facade call into the
     * client. The SDK's own test namespace is excluded so it stands in for user
     * code when the library is exercised by its tests.
     *
     * @param  array{class?: string, ...}  $frame
     */
    private static function isDispatchFrame(array $frame): bool
    {
        $class = $frame['class'] ?? null;

        if (! is_string($class)) {
            return false;
        }

        if ($class === 'Illuminate\\Support\\Facades\\Facade') {
            return true;
        }

        return str_starts_with($class, 'BugBoard\\')
            && ! str_starts_with($class, 'BugBoard\\Tests\\');
    }
}
