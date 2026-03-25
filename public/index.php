<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (php_sapi_name() === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $resolved    = realpath(__DIR__ . $requestPath);
    if ($resolved !== false
        && str_starts_with($resolved, __DIR__ . DIRECTORY_SEPARATOR)
        && is_file($resolved)
    ) {
        return false;
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "DAM-style PHP — GraphQL endpoint placeholder\n";
echo "Send GraphQL POST requests to /graphql (not implemented yet)\n";
