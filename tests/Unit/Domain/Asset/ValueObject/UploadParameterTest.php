<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\ValueObject\UploadParameter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadParameterTest extends TestCase
{
    #[Test]
    public function itTrimsNameWhenConstructedWithSurroundingWhitespace(): void
    {
        // Arrange
        $name = '  Content-Type  ';
        $value = 'image/png';

        // Act
        $parameter = new UploadParameter($name, $value);

        // Assert
        self::assertSame('Content-Type', $parameter->name);
        self::assertSame($value, $parameter->value);
    }

    #[Test]
    #[DataProvider('invalidNameProvider')]
    public function itThrowsInvalidArgumentExceptionWhenNameIsEmptyAfterTrim(string $name): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload parameter name cannot be empty');

        // Act
        $this->createUploadParameter($name, 'image/png');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    private function createUploadParameter(string $name, string $value): UploadParameter
    {
        return new UploadParameter($name, $value);
    }
}
