<?php

declare(strict_types=1);

namespace BugBoard\Exceptions;

/** 401 (bad key, bad signature, expired timestamp) or 403 (origin not allowed). Never retried. */
final class AuthException extends BugBoardException {}
