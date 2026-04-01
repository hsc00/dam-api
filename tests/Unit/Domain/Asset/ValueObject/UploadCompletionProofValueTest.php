<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\ValueObject\UploadCompletionProofValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadCompletionProofValueTest extends TestCase
{
    #[Test]
    public function itTrimsValueWhenConstructed(): void
    {
        // Arrange
        $value = '  etag-value  ';

        // Act
        $proofValue = new UploadCompletionProofValue($value);

        // Assert
        self::assertSame('etag-value', $proofValue->value);
    }

    #[Test]
    #[DataProvider('invalidValueProvider')]
    public function itThrowsInvalidArgumentExceptionWhenValueIsEmptyAfterTrim(string $value): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload completion proof value cannot be empty');

        // Act
        $this->createUploadCompletionProofValue($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    private function createUploadCompletionProofValue(string $value): UploadCompletionProofValue
    {
        return new UploadCompletionProofValue($value);
    }
}
