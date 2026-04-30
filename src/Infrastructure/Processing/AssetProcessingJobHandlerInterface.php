<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

interface AssetProcessingJobHandlerInterface
{
    public function consume(string $payload): AssetProcessingJobHandlingResult;
}
