<?php

declare(strict_types=1);

namespace App\Domain\Asset;

enum RequiredTextField: string
{
    case FILE_NAME = 'fileName';
    case MIME_TYPE = 'mimeType';
}
