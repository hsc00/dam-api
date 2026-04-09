<?php

declare(strict_types=1);

namespace App\Domain\Asset\Exception;

use RuntimeException;

/**
 * Exception thrown when an Asset domain rule is violated.
 */
class AssetDomainException extends RuntimeException
{
}
