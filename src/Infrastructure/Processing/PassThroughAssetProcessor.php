<?php

declare(strict_types=1);

namespace App\Infrastructure\Processing;

use App\Application\Asset\AssetProcessorInterface;
use App\Application\Asset\Exception\TerminalAssetProcessingException;
use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetStatus;

final class PassThroughAssetProcessor implements AssetProcessorInterface
{
    public function process(Asset $asset): void
    {
        if ($asset->getStatus() !== AssetStatus::PROCESSING) {
            throw new TerminalAssetProcessingException('Only processing assets can be processed.');
        }

        if ($asset->getCompletionProof() === null) {
            throw new TerminalAssetProcessingException('Processing assets must include a completion proof.');
        }
    }
}
