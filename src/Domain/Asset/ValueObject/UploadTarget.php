<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

use App\Domain\Asset\UploadHttpMethod;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class UploadTarget
{
    private const ALLOWED_LOCAL_DEVELOPMENT_HOSTS = ['localhost', '127.0.0.1', '::1'];
    private const EMPTY_URL_MESSAGE = 'Upload target URL cannot be empty';
    private const INVALID_ABSOLUTE_URL_MESSAGE = 'Upload target URL must be a valid absolute URL';
    private const LOCAL_DEVELOPMENT_MOCK_CHUNK_INDEX_PATTERN = '/^(0|[1-9][0-9]*)$/';
    private const LOCAL_DEVELOPMENT_MOCK_CHUNK_SEGMENT = 'chunk';
    private const LOCAL_DEVELOPMENT_MOCK_HOST = 'uploads';
    private const LOCAL_DEVELOPMENT_MOCK_PATH_SEGMENT_COUNT = 4;
    private const MISSING_HOST_MESSAGE = 'Upload target URL must be an absolute URL with a host';
    private const TRANSPORT_SECURITY_MESSAGE = 'Upload target URL must use HTTPS, except http://localhost, http://127.0.0.1, http://[::1], or mock://uploads for local development';

    public string $url;
    /**
     * @var list<UploadParameter>
     */
    public array $signedHeaders;

    /**
     * @param array<array-key, mixed> $signedHeaders
     */
    public function __construct(
        string $url,
        public UploadHttpMethod $method,
        array $signedHeaders,
        public UploadCompletionProof $completionProof,
        public DateTimeImmutable $expiresAt,
    ) {
        $this->url = self::normalizeUrl($url);
        $this->signedHeaders = self::normalizeSignedHeaders($signedHeaders);
    }

    /**
     * @param array<array-key, mixed> $signedHeaders
     *
     * @return list<UploadParameter>
     */
    private static function normalizeSignedHeaders(array $signedHeaders): array
    {
        if (! array_is_list($signedHeaders)) {
            throw new InvalidArgumentException('Upload target signed headers must be a list');
        }

        $normalizedSignedHeaders = [];

        foreach ($signedHeaders as $signedHeader) {
            if (! $signedHeader instanceof UploadParameter) {
                throw new InvalidArgumentException('Upload target signed headers must contain only UploadParameter instances');
            }

            $normalizedSignedHeaders[] = $signedHeader;
        }

        return $normalizedSignedHeaders;
    }

    private static function normalizeUrl(string $url): string
    {
        $normalizedUrl = trim($url);

        self::assertUrlIsNotEmpty($normalizedUrl);
        self::assertUrlDoesNotContainWhitespace($normalizedUrl);

        $parsedUrl = self::parseAbsoluteUrl($normalizedUrl);

        $scheme = strtolower($parsedUrl['scheme']);
        $rawHost = $parsedUrl['host'];
        $host = strtolower(trim($rawHost, '[]'));

        self::assertTransportSecurity($normalizedUrl, $parsedUrl, $scheme, $host);

        return self::rebuildNormalizedUrl($parsedUrl, $scheme, $host);
    }

    private static function assertUrlIsNotEmpty(string $url): void
    {
        if ($url === '') {
            throw new InvalidArgumentException(self::EMPTY_URL_MESSAGE);
        }
    }

    private static function assertUrlDoesNotContainWhitespace(string $url): void
    {
        if (preg_match('/\s/', $url) === 1) {
            throw new InvalidArgumentException(self::INVALID_ABSOLUTE_URL_MESSAGE);
        }
    }

    /**
     * @return array{scheme: string, host: string, user?: string, pass?: string, port?: int, path?: string, query?: string, fragment?: string}
     */
    private static function parseAbsoluteUrl(string $url): array
    {
        $parsedUrl = parse_url($url);

        if (
            ! is_array($parsedUrl)
            || ! isset($parsedUrl['scheme'], $parsedUrl['host'])
            || trim((string) $parsedUrl['host']) === ''
        ) {
            throw new InvalidArgumentException(self::MISSING_HOST_MESSAGE);
        }

        return $parsedUrl;
    }

    /**
     * @param array{scheme: string, host: string, user?: string, pass?: string, port?: int, path?: string, query?: string, fragment?: string} $parsedUrl
     */
    private static function assertTransportSecurity(string $url, array $parsedUrl, string $scheme, string $host): void
    {
        if ($scheme === 'mock') {
            self::assertAllowedLocalDevelopmentMockUrl($parsedUrl, $host);

            return;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(self::INVALID_ABSOLUTE_URL_MESSAGE);
        }

        if ($scheme !== 'https' && ! self::isAllowedLocalDevelopmentUrl($scheme, $host)) {
            throw new InvalidArgumentException(self::TRANSPORT_SECURITY_MESSAGE);
        }
    }

    /**
     * @param array{scheme: string, host: string, user?: string, pass?: string, port?: int, path?: string, query?: string, fragment?: string} $parsedUrl
     */
    private static function rebuildNormalizedUrl(array $parsedUrl, string $scheme, string $host): string
    {
        // Reconstruct the URL using the lowercased scheme and host while preserving
        // original user/pass, port (only if present), path, query and fragment
        $auth = '';
        if (isset($parsedUrl['user'])) {
            $auth = $parsedUrl['user'];
            if (isset($parsedUrl['pass'])) {
                $auth .= ':' . $parsedUrl['pass'];
            }
            $auth .= '@';
        }

        // Wrap IPv6 hosts in brackets when rebuilding the URL. Use the
        // normalized host (without any surrounding brackets) to avoid
        // double-bracketing when parse_url returned a bracketed host.
        $hostForUrl = strpos($host, ':') !== false ? '[' . $host . ']' : $host;

        $port = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $path = $parsedUrl['path'] ?? '';
        $query = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        return $scheme . '://' . $auth . $hostForUrl . $port . $path . $query . $fragment;
    }

    /**
     * @param array{path?: mixed, user?: mixed, pass?: mixed, port?: mixed, query?: mixed, fragment?: mixed} $parsedUrl
     */
    private static function assertAllowedLocalDevelopmentMockUrl(array $parsedUrl, string $host): void
    {
        $path = $parsedUrl['path'] ?? null;

        if (
            $host !== self::LOCAL_DEVELOPMENT_MOCK_HOST
            || isset($parsedUrl['user'])
            || isset($parsedUrl['pass'])
            || isset($parsedUrl['port'])
            || isset($parsedUrl['query'])
            || isset($parsedUrl['fragment'])
            || ! is_string($path)
            || ! self::isAllowedLocalDevelopmentMockPath($path)
        ) {
            throw new InvalidArgumentException(self::TRANSPORT_SECURITY_MESSAGE);
        }
    }

    private static function isAllowedLocalDevelopmentMockPath(string $path): bool
    {
        $segments = explode('/', $path);

        if (count($segments) !== self::LOCAL_DEVELOPMENT_MOCK_PATH_SEGMENT_COUNT) {
            return false;
        }

        [$leadingSlash, $uploadId, $chunkSegment, $chunkIndex] = $segments;

        if (
            $leadingSlash !== ''
            || $chunkSegment !== self::LOCAL_DEVELOPMENT_MOCK_CHUNK_SEGMENT
            || preg_match(self::LOCAL_DEVELOPMENT_MOCK_CHUNK_INDEX_PATTERN, $chunkIndex) !== 1
        ) {
            return false;
        }

        return self::isValidMockUploadId($uploadId);
    }

    private static function isValidMockUploadId(string $uploadId): bool
    {
        return UploadId::isValid($uploadId);
    }

    private static function isAllowedLocalDevelopmentUrl(string $scheme, string $host): bool
    {
        if ($scheme !== 'http') {
            return false;
        }

        return in_array($host, self::ALLOWED_LOCAL_DEVELOPMENT_HOSTS, true);
    }
}
