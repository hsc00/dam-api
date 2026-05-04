<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Domain\Asset\ValueObject\AssetId;

final readonly class AssetProcessingJobPayload
{
    private const ASSET_ID_FIELD = 'assetId';
    private const INITIAL_RETRY_COUNT = 0;
    private const RETRY_COUNT_FIELD = 'retryCount';

    private ?string $assetId;
    private int $retryCount;

    public function __construct(?string $assetId, int $retryCount)
    {
        if ($retryCount < 0) {
            throw new \InvalidArgumentException('Retry count must be zero or greater.');
        }
        $this->assetId = $assetId === null ? null : trim($assetId);
        $this->retryCount = $retryCount;
    }

    public static function initial(AssetId $assetId): self
    {
        return new self((string) $assetId, self::INITIAL_RETRY_COUNT);
    }

    public static function fromJson(string $payload): ?self
    {
        try {
            $decodedPayload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return null;
        }

        if (! is_array($decodedPayload)) {
            return null;
        }

        $rawAssetId = $decodedPayload[self::ASSET_ID_FIELD] ?? null;
        $assetId = null;

        if (is_string($rawAssetId)) {
            $trimmed = trim($rawAssetId);
            $assetId = $trimmed === '' ? null : $trimmed;
        }

        $retryCount = $decodedPayload[self::RETRY_COUNT_FIELD] ?? null;

        return is_int($retryCount) && $retryCount >= 0
            ? new self($assetId, $retryCount)
            : null;
    }

    public function assetId(): ?string
    {
        return $this->assetId;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): self
    {
        return new self($this->assetId, $this->retryCount + 1);
    }

    public function toAssetId(): ?AssetId
    {
        if ($this->assetId === null) {
            return null;
        }

        try {
            return new AssetId($this->assetId);
        } catch (\InvalidArgumentException $exception) {
            return null;
        }
    }

    public function toJson(): string
    {
        return json_encode([
            self::ASSET_ID_FIELD => $this->assetId,
            self::RETRY_COUNT_FIELD => $this->retryCount,
        ], JSON_THROW_ON_ERROR);
    }
}
