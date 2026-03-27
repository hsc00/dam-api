<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\ValueObject\UploadId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadIdTest extends TestCase
{
    private const VALID_UPLOAD_ID = '123e4567-e89b-42d3-a456-426614174000';

    #[Test]
    public function itStoresValueWhenUuidIsValid(): void
    {
        // Arrange
        $value = self::VALID_UPLOAD_ID;

        // Act
        $uploadId = new UploadId($value);

        // Assert
        self::assertNotEmpty($uploadId->value);
        self::assertSame($value, $uploadId->value);
    }

    #[Test]
    public function itReturnsStringValueWhenCastToString(): void
    {
        // Arrange
        $uploadId = new UploadId(self::VALID_UPLOAD_ID);

        // Act
        $value = (string) $uploadId;

        // Assert
        self::assertSame(self::VALID_UPLOAD_ID, $value);
    }

    #[Test]
    #[DataProvider('invalidUploadIdProvider')]
    public function itThrowsInvalidArgumentExceptionWhenValueIsNotUuid(string $value): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UploadId format');

        // Act
        $this->createUploadId($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidUploadIdProvider(): array
    {
        return [
            'empty string' => [''],
            'not a uuid' => ['upload-123'],
            'wrong version' => ['123e4567-e89b-62d3-a456-426614174000'],
        ];
    }

    private function createUploadId(string $value): UploadId
    {
        return new UploadId($value);
    }
}
