<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\UploadCompletionProofSource;
use App\Domain\Asset\UploadHttpMethod;
use App\Domain\Asset\ValueObject\UploadCompletionProof;
use App\Domain\Asset\ValueObject\UploadParameter;
use App\Domain\Asset\ValueObject\UploadTarget;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UploadTargetTest extends TestCase
{
    #[Test]
    public function itReturnsUploadTargetWhenInputsAreValid(): void
    {
        // Arrange
        $signedHeader = new UploadParameter('  Content-Type  ', 'image/png');
        $completionProof = new UploadCompletionProof('  etag  ', UploadCompletionProofSource::RESPONSE_HEADER);
        $expiresAt = new DateTimeImmutable('2026-01-20T12:34:56+00:00');

        // Act
        $target = new UploadTarget(
            'https://example.test/upload',
            UploadHttpMethod::PUT,
            [$signedHeader],
            $completionProof,
            $expiresAt,
        );

        // Assert
        self::assertSame('https://example.test/upload', $target->url);
        self::assertSame(UploadHttpMethod::PUT, $target->method);
        self::assertSame([$signedHeader], $target->signedHeaders);
        self::assertSame($completionProof, $target->completionProof);
        self::assertSame($expiresAt, $target->expiresAt);
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function itThrowsInvalidArgumentExceptionWhenUrlIsEmptyAfterTrim(string $url): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload target URL cannot be empty');

        // Act
        $this->createUploadTarget(
            $url,
            [],
        );
    }

    #[Test]
    #[DataProvider('nonAbsoluteUrlProvider')]
    public function itThrowsInvalidArgumentExceptionWhenUrlIsNotAnAbsoluteUrlWithHost(string $url): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload target URL must be an absolute URL with a host');

        // Act
        $this->createUploadTarget($url, []);
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenUrlIsNotAValidAbsoluteUrl(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload target URL must be a valid absolute URL');

        // Act
        $this->createUploadTarget('https://example.test/contains space', []);
    }

    #[Test]
    #[DataProvider('disallowedInsecureUrlProvider')]
    public function itThrowsInvalidArgumentExceptionWhenRemoteUrlDoesNotUseHttps(string $url): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Upload target URL must use HTTPS, except http://localhost, http://127.0.0.1, or http://[::1] for local development'
        );

        // Act
        $this->createUploadTarget($url, []);
    }

    #[Test]
    #[DataProvider('allowedLocalDevelopmentUrlProvider')]
    public function itReturnsUploadTargetWhenLocalDevelopmentHttpUrl(string $url): void
    {
        // Act
        $target = $this->createUploadTarget($url, []);

        // Assert
        self::assertSame($url, $target->url);
    }

    #[Test]
    #[DataProvider('schemeAndHostNormalizationProvider')]
    public function itReturnsNormalizedUrlWhenSchemeAndHostAreMixedCase(string $input, string $expected): void
    {
        // Act
        $target = $this->createUploadTarget($input, []);

        // Assert
        self::assertSame($expected, $target->url);
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenSignedHeadersAreNotAList(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload target signed headers must be a list');

        // Act
        $this->createUploadTarget(
            'https://example.test/upload',
            ['Content-Type' => new UploadParameter('Content-Type', 'image/png')],
        );
    }

    #[Test]
    public function itThrowsInvalidArgumentExceptionWhenSignedHeadersContainNonUploadParameters(): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload target signed headers must contain only UploadParameter instances');

        // Act
        $this->createUploadTarget(
            'https://example.test/upload',
            ['Content-Type'],
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAbsoluteUrlProvider(): array
    {
        return [
            'relative path' => ['/upload'],
            'non-url string' => ['not-a-url'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function disallowedInsecureUrlProvider(): array
    {
        return [
            'remote http url' => ['http://example.test/upload'],
            'insecure localhost-like hostname' => ['http://localhost.example.test/upload'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedLocalDevelopmentUrlProvider(): array
    {
        return [
            'localhost' => ['http://localhost/upload'],
            'localhost with port' => ['http://localhost:8000/upload'],
            'ipv4 loopback' => ['http://127.0.0.1/upload'],
            'ipv4 loopback with port' => ['http://127.0.0.1:9000/upload'],
            'ipv6 loopback' => ['http://[::1]/upload'],
            'ipv6 loopback with port' => ['http://[::1]:9000/upload'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function schemeAndHostNormalizationProvider(): array
    {
        return [
            'uppercase http scheme and host' => ['HTTP://LOCALHOST/path', 'http://localhost/path'],
            'mixed-case https with port, query, and fragment' => ['HTTPS://Example.COM:8443/Some/Path?Q=1#Frag', 'https://example.com:8443/Some/Path?Q=1#Frag'],
            'userinfo with bracketed ipv6' => ['HTTPS://User:Pass@[2001:DB8::1]:8443/Some/Path?Q=1#Frag', 'https://User:Pass@[2001:db8::1]:8443/Some/Path?Q=1#Frag'],
            'already normalized' => ['https://example.com/Already', 'https://example.com/Already'],
        ];
    }

    /**
     * @param array<array-key, mixed> $signedHeaders
     */
    private function createUploadTarget(string $url, array $signedHeaders): UploadTarget
    {
        return new UploadTarget(
            $url,
            UploadHttpMethod::PUT,
            $signedHeaders,
            new UploadCompletionProof('etag', UploadCompletionProofSource::RESPONSE_HEADER),
            new DateTimeImmutable('2026-01-20T12:34:56+00:00'),
        );
    }
}
