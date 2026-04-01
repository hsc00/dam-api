<?php

declare(strict_types=1);

namespace App\Domain\Asset;

enum UploadCompletionProofSource: string
{
    case RESPONSE_HEADER = 'RESPONSE_HEADER';
}
