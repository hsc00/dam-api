<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load test environment variables from .env.testing if it exists,
// falling back to .env for local development convenience.
$envFile = file_exists(__DIR__ . '/../.env.testing') ? '.env.testing' : '.env';

if (file_exists(__DIR__ . '/../' . $envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', $envFile);
    $dotenv->safeLoad();
}
