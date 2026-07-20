<?php

declare(strict_types=1);

namespace BugBoard;

use JsonSerializable;
use Stringable;
use Throwable;
use Traversable;

/**
 * One normalized report — the JSON body sent to `POST /api/v1/tasks`.
 *
 * Everything is clamped to the API limits client-side (title ≤ 255 chars,
 * tags ≤ 50 chars, description truncated well below the 64 KB server cap) so
 * a report can never fail validation on size.
 */
final readonly class Payload
{
    public const SEVERITIES = ['critical', 'major', 'moderate', 'minor'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    private const MAX_TITLE_LENGTH = 255;

    private const MAX_TAG_LENGTH = 50;

    private const MAX_DESCRIPTION_LENGTH = 60000;

    /**
     * Encoding flags for a stringified description.
     *
     * PARTIAL_OUTPUT_ON_ERROR rather than THROW_ON_ERROR: a cycle, a resource,
     * NAN, or depth overflow degrades only the offending node to null/0 and the
     * rest of the dump survives. Throwing would discard the whole description —
     * exactly the silent data loss this ladder exists to prevent. Bad UTF-8 is
     * the one case PARTIAL handles badly (it nulls the whole string), so
     * INVALID_UTF8_SUBSTITUTE covers it with U+FFFD.
     */
    private const DESCRIBE_JSON_FLAGS = JSON_PRETTY_PRINT
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /** An infinite generator must not hang the app the SDK is monitoring. */
    private const MAX_TRAVERSABLE_ITEMS = 1000;

    private const TRUNCATION_MARKER = "\n… truncated";

    /** Laravel's Arrayable, matched by name so the SDK gains no Laravel dependency. */
    private const ARRAYABLE = 'Illuminate\Contracts\Support\Arrayable';

    /** @param list<string> $tags */
    private function __construct(
        public string $severity,
        public string $priority,
        public string $title,
        public ?string $description,
        public array $tags,
        public ?string $fileName = null,
        public ?int $lineNumber = null,
    ) {}

    /**
     * Build a payload for one report. The severity/priority come from the
     * method name (never from user input), and the config's environment,
     * release, and default tags are folded into the tags.
     *
     * @param  array<int, string>|string  $tags
     * @param  array{file: string, line: int}|null  $location  Auto-captured call site.
     */
    public static function make(
        string $severity,
        string $priority,
        string $title,
        mixed $description,
        array|string $tags,
        Config $config,
        ?array $location = null,
    ): self {
        $baseTags = $config->defaultTags;

        if ($config->environment !== null) {
            $baseTags[] = 'env:'.$config->environment;
        }

        if ($config->release !== null) {
            $baseTags[] = 'release:'.$config->release;
        }

        return new self(
            severity: $severity,
            priority: $priority,
            title: mb_substr($title, 0, self::MAX_TITLE_LENGTH),
            description: self::describe($description),
            tags: self::normalizeTags([...$baseTags, ...self::normalizeTags($tags)]),
            fileName: $location['file'] ?? null,
            lineNumber: $location['line'] ?? null,
        );
    }

    /**
     * Rebuild a payload from a (possibly beforeSend-mutated) array, re-applying
     * every clamp so a hook can never produce an invalid request.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $severity = $data['severity'] ?? null;
        $priority = $data['priority'] ?? null;
        $description = $data['description'] ?? null;
        $tags = $data['tags'] ?? [];
        $fileName = $data['file_name'] ?? null;
        $lineNumber = $data['line_number'] ?? null;

        return new self(
            severity: in_array($severity, self::SEVERITIES, true) ? $severity : 'moderate',
            priority: in_array($priority, self::PRIORITIES, true) ? $priority : 'medium',
            title: mb_substr(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '', 0, self::MAX_TITLE_LENGTH),
            description: self::describe($description),
            tags: self::normalizeTags(is_array($tags) || is_string($tags) ? $tags : []),
            fileName: is_string($fileName) ? $fileName : null,
            lineNumber: is_int($lineNumber) ? $lineNumber : (is_numeric($lineNumber) ? (int) $lineNumber : null),
        );
    }

    /**
     * The request-body array (description and call site omitted when absent).
     *
     * @return array<string, string|int|list<string>>
     */
    public function toArray(): array
    {
        $body = [
            'severity' => $this->severity,
            'priority' => $this->priority,
            'title' => $this->title,
            'tags' => $this->tags,
        ];

        if ($this->description !== null) {
            $body['description'] = $this->description;
        }

        if ($this->fileName !== null) {
            $body['file_name'] = $this->fileName;
        }

        if ($this->lineNumber !== null) {
            $body['line_number'] = $this->lineNumber;
        }

        return $body;
    }

    /** The exact JSON string to transmit (and, for HMAC auth, to sign). */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Turn the description argument into text.
     *
     * Total by construction: every branch returns, and user code invoked along
     * the way (jsonSerialize(), __toString(), toArray(), a Traversable) is
     * caught. A throw here would be swallowed by Client::__call()'s backstop
     * into a debug-gated log line, so the report would ship with its
     * description silently missing.
     */
    private static function describe(mixed $description): ?string
    {
        if ($description === null) {
            return null;
        }

        try {
            $text = self::stringify($description);
        } catch (Throwable) {
            $text = self::label($description);
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::MAX_DESCRIPTION_LENGTH) {
            return $text;
        }

        // Reserve the marker's length inside the cap, so the result is exactly
        // MAX_DESCRIPTION_LENGTH and a cut-off dump reads as truncated rather
        // than corrupt.
        return mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH - mb_strlen(self::TRUNCATION_MARKER))
            .self::TRUNCATION_MARKER;
    }

    /** The type ladder: the first rung that can represent the value wins. */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        // (string) true is "1" and (string) false is "" — the second would trim
        // to empty and drop the description entirely. Spell both out, as JS does.
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return self::formatFloat($value);
        }

        if ($value instanceof Throwable) {
            return self::describeThrowable($value);
        }

        if (is_object($value)) {
            return self::describeObject($value);
        }

        if (is_array($value)) {
            return self::encode($value);
        }

        // Resources, and whatever PHP adds next.
        return self::label($value);
    }

    private static function describeObject(object $value): string
    {
        // json_encode() invokes jsonSerialize() itself.
        if ($value instanceof JsonSerializable) {
            return self::encode($value);
        }

        // Laravel's Arrayable. Illuminate\Http\Request, Eloquent models, and
        // collections all implement it, and all of them json_encode() to an
        // empty shell without it — a Request encodes as
        // {"attributes":{},"request":{},"query":{},"server":{},…}.
        // This rung must precede Stringable: Request implements both, and
        // __toString() renders the whole raw HTTP message instead of the input.
        // is_a() with a string name returns false cleanly when Laravel is
        // absent, without triggering an autoload failure.
        if (is_a($value, self::ARRAYABLE)) {
            return self::encode($value->toArray());
        }

        // PHP 8 implements Stringable for anything declaring __toString().
        if ($value instanceof Stringable) {
            return $value->__toString();
        }

        if ($value instanceof Traversable) {
            return self::encode(self::traverse($value));
        }

        $json = self::encode($value);

        // json_encode() sees public properties only, so an object whose state
        // is all private encodes to "{}" and tells nobody anything. Name the
        // class instead — but leave a genuinely empty object as "{}", which is
        // honest and matches the JS SDK.
        return $json === '{}' && (array) $value !== [] ? self::label($value) : $json;
    }

    /**
     * Materialize a Traversable, bounded. iterator_to_array() is unusable here:
     * it would hang forever on an infinite generator, and it throws on the
     * object keys that SplObjectStorage yields.
     *
     * @param  Traversable<mixed, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function traverse(Traversable $value): array
    {
        $items = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count++ >= self::MAX_TRAVERSABLE_ITEMS) {
                $items[] = '… truncated';

                break;
            }

            if (is_int($key) || is_string($key)) {
                $items[$key] = $item;
            } else {
                $items[] = $item;
            }
        }

        return $items;
    }

    /** Pretty JSON, two-space indented so the bytes match the JS SDK exactly. */
    private static function encode(mixed $value): string
    {
        $json = json_encode($value, self::DESCRIBE_JSON_FLAGS);

        // PARTIAL_OUTPUT_ON_ERROR makes this unreachable in practice, but the
        // return type is string|false and this function must be total.
        if ($json === false) {
            return self::label($value);
        }

        // PHP's JSON_PRETTY_PRINT indent is a hard-coded four spaces with no
        // knob. JSON escapes newlines inside strings, so every literal newline
        // in the output is structural and halving line-leading runs is lossless.
        return (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $matches): string => str_repeat(' ', intdiv(strlen($matches[0]), 2)),
            $json,
        );
    }

    /**
     * Float → the text JS's String(number) produces. json_encode() uses the
     * shortest round-tripping representation (serialize_precision = -1); a
     * (string) cast honours `precision` and renders 0.1 + 0.2 as "0.3".
     */
    private static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }

        // json_encode() writes exponents as "1.0e+25"; JS writes "1e+25".
        return (string) preg_replace('/\.0(?=$|[eE])/', '', (string) json_encode($value));
    }

    private static function describeThrowable(Throwable $value): string
    {
        // The construction site is not part of the trace frames, so include
        // file:line explicitly — it is usually the throw site.
        return sprintf(
            "%s: %s in %s:%d\n%s",
            $value::class,
            $value->getMessage(),
            $value->getFile(),
            $value->getLine(),
            $value->getTraceAsString(),
        );
    }

    /** Last resort: name the thing. get_debug_type() is total and never throws. */
    private static function label(mixed $value): string
    {
        return '['.get_debug_type($value).']';
    }

    /**
     * Normalize array-or-CSV tags: trim, drop empties, de-dupe, clamp to 50 chars.
     *
     * @param  array<int, string>|string  $tags
     * @return list<string>
     */
    private static function normalizeTags(array|string $tags): array
    {
        $list = is_array($tags) ? $tags : explode(',', $tags);
        $clean = [];

        foreach ($list as $tag) {
            $tag = mb_substr(trim((string) $tag), 0, self::MAX_TAG_LENGTH);

            if ($tag !== '' && ! in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return $clean;
    }
}
