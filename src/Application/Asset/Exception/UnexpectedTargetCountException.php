<?php

declare(strict_types=1);

namespace App\Application\Asset\Exception;

use App\Domain\Asset\Exception\AssetDomainException;

/**
 * Thrown when the number of upload targets returned by the storage adapter
 * does not match the expected chunk count for an asset.
 */
final class UnexpectedTargetCountException extends AssetDomainException
{
    private readonly int $expectedCount;
    private readonly int $actualCount;

    public function __construct(int $expectedCount = 0, int $actualCount = 0, string $message = '')
    {
        if ($message === '') {
            $message = sprintf('Unexpected upload target count: expected %d, got %d', $expectedCount, $actualCount);
        }

        parent::__construct($message);
        $this->expectedCount = $expectedCount;
        $this->actualCount = $actualCount;
    }

    public static function fromCounts(int $expected, int $actual): self
    {
        return new self($expected, $actual);
    }

    public function getExpectedCount(): int
    {
        return $this->expectedCount;
    }

    public function getActualCount(): int
    {
        return $this->actualCount;
    }
}
