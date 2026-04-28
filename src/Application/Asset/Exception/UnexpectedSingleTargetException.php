<?php

declare(strict_types=1);

namespace App\Application\Asset\Exception;

use RuntimeException;

/**
 * Thrown when a storage adapter returns an unexpected number of upload targets
 * for an operation that expects a single target (e.g. single-file uploads).
 *
 * Example:
 * ```php
 * throw new UnexpectedSingleTargetException('Expected one target', $targetUrl, 'startUpload');
 * ```
 */
final class UnexpectedSingleTargetException extends RuntimeException
{
    private readonly string $target;
    private readonly ?string $operation;

    public function __construct(string $message = '', string $target = '', ?string $operation = null, int $code = 0, ?\Throwable $previous = null)
    {
        $this->target = $target;
        $this->operation = $operation;

        parent::__construct($message === '' ? 'Unexpected single upload target' : $message, $code, $previous);
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getOperation(): ?string
    {
        return $this->operation;
    }
}
