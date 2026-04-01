<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\UploadCompletionProofSource;
use App\Domain\Asset\ValueObject\UploadCompletionProof;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadCompletionProofTest extends TestCase
{
    #[Test]
    public function itTrimsNameAndStoresSource(): void
    {
        // Arrange
        $name = '  etag  ';

        // Act
        $proof = new UploadCompletionProof($name, UploadCompletionProofSource::RESPONSE_HEADER);

        // Assert
        self::assertSame('etag', $proof->name);
        self::assertSame(UploadCompletionProofSource::RESPONSE_HEADER, $proof->source);
    }

    #[Test]
    #[DataProvider('invalidNameProvider')]
    public function itThrowsInvalidArgumentExceptionWhenNameIsEmptyAfterTrim(string $name): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload completion proof name cannot be empty');

        // Act
        $this->createUploadCompletionProof($name, UploadCompletionProofSource::RESPONSE_HEADER);
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

    private function createUploadCompletionProof(string $name, UploadCompletionProofSource $source): UploadCompletionProof
    {
        return new UploadCompletionProof($name, $source);
    }
}
