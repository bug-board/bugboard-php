<?php

declare(strict_types=1);

namespace BugBoard\Exceptions;

use RuntimeException;

/**
 * Base class for every SDK exception.
 *
 * Reporting is fire-and-forget, so these are never thrown into the host app —
 * they are caught internally and surfaced through the debug logger.
 */
class BugBoardException extends RuntimeException {}
