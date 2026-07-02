<?php

declare(strict_types=1);

namespace BugBoard\Laravel\Facades;

use BugBoard\Client;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the shared BugBoard client.
 *
 * @method static void critical(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void criticalLow(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void criticalMedium(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void criticalHigh(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void major(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void majorLow(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void majorMedium(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void majorHigh(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void moderate(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void moderateLow(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void moderateMedium(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void moderateHigh(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void minor(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void minorLow(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void minorMedium(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void minorHigh(string $title, string|\Throwable|null $description = null, array<int, string>|string $tags = [])
 * @method static void flush()
 * @method static int droppedCount()
 *
 * @see Client
 */
final class BugBoard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
