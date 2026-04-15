<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\ValueObject\AssetId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetIdTest extends TestCase
{
    private const VALID_ASSET_ID = '123e4567-e89b-42d3-a456-426614174000';
    private const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    #[Test]
    public function itReturnsSameValueWhenConstructedWithValidUuidV4(): void
    {
        // Arrange
        $value = self::VALID_ASSET_ID;

        // Act
        $assetId = new AssetId($value);

        // Assert
        self::assertSame($value, $assetId->value);
    }

    #[Test]
    public function itReturnsDistinctUuidV4ValuesWhenGenerateCalled(): void
    {
        // Arrange

        // Act
        $firstAssetId = AssetId::generate();
        $secondAssetId = AssetId::generate();

        // Assert
        self::assertMatchesRegularExpression(self::UUID_V4_PATTERN, $firstAssetId->value);
        self::assertMatchesRegularExpression(self::UUID_V4_PATTERN, $secondAssetId->value);
        self::assertNotSame($firstAssetId->value, $secondAssetId->value);
    }

    #[Test]
    public function itReturnsStringValueWhenCastToString(): void
    {
        // Arrange
        $assetId = new AssetId(self::VALID_ASSET_ID);

        // Act
        $value = (string) $assetId;

        // Assert
        self::assertSame(self::VALID_ASSET_ID, $value);
    }

    #[Test]
    #[DataProvider('invalidAssetIdProvider')]
    public function itThrowsInvalidArgumentExceptionWhenValueIsNotUuidV4(string $value): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid AssetId format');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAssetIdProvider(): array
    {
        return [
            'empty string' => [''],
            'not a uuid' => ['asset-123'],
            'wrong version' => ['123e4567-e89b-12d3-a456-426614174000'],
            'wrong variant' => ['123e4567-e89b-42d3-c456-426614174000'],
        ];
    }
}
