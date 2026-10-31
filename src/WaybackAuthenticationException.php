<?php

declare(strict_types=1);

namespace RsMachine;

use RuntimeException;

/**
 * Save Page Now 2 rejected the account's keys: every request will fail until they're fixed.
 */
class WaybackAuthenticationException extends RuntimeException
{
}
