<?php

declare(strict_types=1);

namespace BugBoard\Tests;

use BugBoard\Location;
use PHPUnit\Framework\TestCase;

final class LocationTest extends TestCase
{
    public function test_it_captures_the_calling_file_and_line(): void
    {
        $line = __LINE__ + 1;
        $location = Location::capture();

        $this->assertNotNull($location);
        $this->assertSame(__FILE__, $location['file']);
        $this->assertSame($line, $location['line']);
    }

    public function test_it_skips_sdk_frames_and_reports_the_user_call_site(): void
    {
        // Call through a closure defined in this test file: the first frame
        // outside the SDK source dir is this file, so we get a real location.
        $capture = fn (): ?array => Location::capture();
        $location = $capture();

        $this->assertNotNull($location);
        $this->assertSame(__FILE__, $location['file']);
    }
}
