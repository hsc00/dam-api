<?php

declare(strict_types=1);

namespace App\Application\Asset;

use App\Domain\Asset\Asset;

interface AssetProcessorInterface
{
    /**
     * @throws \App\Application\Asset\Exception\RetryableAssetProcessingException
     * @throws \App\Application\Asset\Exception\TerminalAssetProcessingException
     */
    public function process(Asset $asset): void;
}
