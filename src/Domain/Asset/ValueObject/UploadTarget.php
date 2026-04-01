<?php

declare(strict_types=1);

namespace App\Domain\Asset\ValueObject;

use App\Domain\Asset\UploadHttpMethod;
use DateTimeImmutable;

final readonly class UploadTarget
{
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
            throw new \InvalidArgumentException('Upload target signed headers must be a list');
        }

        $normalizedSignedHeaders = [];

        foreach ($signedHeaders as $signedHeader) {
            if (! $signedHeader instanceof UploadParameter) {
                throw new \InvalidArgumentException('Upload target signed headers must contain only UploadParameter instances');
            }

            $normalizedSignedHeaders[] = $signedHeader;
        }

        return $normalizedSignedHeaders;
    }

    private static function normalizeUrl(string $url): string
    {
        $normalizedUrl = trim($url);

        if ($normalizedUrl === '') {
            throw new \InvalidArgumentException('Upload target URL cannot be empty');
        }

        $parsedUrl = parse_url($normalizedUrl);

        if (
            ! is_array($parsedUrl)
            || ! isset($parsedUrl['scheme'], $parsedUrl['host'])
            || trim($parsedUrl['host']) === ''
        ) {
            throw new \InvalidArgumentException('Upload target URL must be an absolute URL with a host');
        }

        if (filter_var($normalizedUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Upload target URL must be a valid absolute URL');
        }

        $scheme = strtolower($parsedUrl['scheme']);
        $rawHost = $parsedUrl['host'];
        $host = strtolower(trim($rawHost, '[]'));

        if ($scheme !== 'https' && ! self::isAllowedLocalDevelopmentUrl($scheme, $host)) {
            throw new \InvalidArgumentException(
                'Upload target URL must use HTTPS, except http://localhost, http://127.0.0.1, or http://[::1] for local development'
            );
        }

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

    private static function isAllowedLocalDevelopmentUrl(string $scheme, string $host): bool
    {
        if ($scheme !== 'http') {
            return false;
        }

        return in_array(trim($host, '[]'), ['localhost', '127.0.0.1', '::1'], true);
    }
}
