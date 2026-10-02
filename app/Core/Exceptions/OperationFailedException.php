<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use RuntimeException;

/**
 * Operational failure that is not a validation/not-found/auth problem
 * (e.g. a missing template, a failed file move, a lost concurrent update).
 * Extends RuntimeException so existing generic handling is unchanged.
 */
final class OperationFailedException extends RuntimeException
{
}
