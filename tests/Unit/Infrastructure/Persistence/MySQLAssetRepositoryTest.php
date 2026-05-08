<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence;

use App\Domain\Asset\AssetStatus;
use App\Domain\Asset\ValueObject\AccountId;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MySQLAssetRepositoryTest extends TestCase
{
    #[Test]
    public function itReturnsAnEmptyListWhenSearchLimitIsZero(): void
    {
        // Arrange
        $repository = $this->createRepositoryForValidationOnly();

        // Act
        $results = $repository->searchByFileName(new AccountId('account-zero-limit'), 'report', AssetStatus::UPLOADED, 0, 0);

        // Assert
        self::assertSame([], $results);
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenSearchOffsetIsNegative(): void
    {
        // Arrange
        $repository = $this->createRepositoryForValidationOnly();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Search offset cannot be negative.');

        // Act
        $repository->searchByFileName(new AccountId('account-negative-offset'), 'report', AssetStatus::UPLOADED, -1, 10);
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenSearchLimitIsNegative(): void
    {
        // Arrange
        $repository = $this->createRepositoryForValidationOnly();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Search limit cannot be negative.');

        // Act
        $repository->searchByFileName(new AccountId('account-negative-limit'), 'report', AssetStatus::UPLOADED, 0, -1);
    }

    private function createRepositoryForValidationOnly(): MySQLAssetRepository
    {
        /** @var PDO $connection */
        $connection = $this->createMock(PDO::class);

        return new MySQLAssetRepository($connection);
    }
}
