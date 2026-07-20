<?php

declare(strict_types=1);

namespace BugBoard\Laravel\Facades;

use BugBoard\Client;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the shared BugBoard client.
 *
 * @method static void critical(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void criticalLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void criticalMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void criticalHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void major(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void majorLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void majorMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void majorHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void moderate(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void moderateLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void moderateMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void moderateHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void minor(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void minorLow(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void minorMedium(string $title, mixed $description = null, array<int, string>|string $tags = [])
 * @method static void minorHigh(string $title, mixed $description = null, array<int, string>|string $tags = [])
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
