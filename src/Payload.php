<?php

declare(strict_types=1);

namespace BugBoard;

use Throwable;

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

    /** @param list<string> $tags */
    private function __construct(
        public string $severity,
        public string $priority,
        public string $title,
        public ?string $description,
        public array $tags,
    ) {}

    /**
     * Build a payload for one report. The severity/priority come from the
     * method name (never from user input), and the config's environment,
     * release, and default tags are folded into the tags.
     *
     * @param  array<int, string>|string  $tags
     */
    public static function make(
        string $severity,
        string $priority,
        string $title,
        string|Throwable|null $description,
        array|string $tags,
        Config $config,
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

        return new self(
            severity: in_array($severity, self::SEVERITIES, true) ? $severity : 'moderate',
            priority: in_array($priority, self::PRIORITIES, true) ? $priority : 'medium',
            title: mb_substr(is_scalar($data['title'] ?? null) ? (string) $data['title'] : '', 0, self::MAX_TITLE_LENGTH),
            description: is_string($description) ? self::describe($description) : null,
            tags: self::normalizeTags(is_array($tags) || is_string($tags) ? $tags : []),
        );
    }

    /**
     * The request-body array (description omitted when absent).
     *
     * @return array<string, string|list<string>>
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

        return $body;
    }

    /** The exact JSON string to transmit (and, for HMAC auth, to sign). */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Turn the description argument into text: strings pass through,
     * Throwables contribute their message + trace (+ class name).
     */
    private static function describe(string|Throwable|null $description): ?string
    {
        if ($description === null) {
            return null;
        }

        if ($description instanceof Throwable) {
            // The construction site is not part of the trace frames, so
            // include file:line explicitly — it is usually the throw site.
            $description = sprintf(
                "%s: %s in %s:%d\n%s",
                $description::class,
                $description->getMessage(),
                $description->getFile(),
                $description->getLine(),
                $description->getTraceAsString(),
            );
        }

        $description = trim($description);

        return $description === '' ? null : mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
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
