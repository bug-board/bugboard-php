<?php

declare(strict_types=1);

namespace BugBoard\Exceptions;

/** 5xx or a network/timeout failure. Retried with backoff. */
final class ServerException extends BugBoardException {}
