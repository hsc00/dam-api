<?php

declare(strict_types=1);

use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\StartUploadService;
use App\GraphQL\Resolver\CompleteUploadResolver;
use App\GraphQL\Resolver\StartUploadBatchResolver;
use App\GraphQL\Resolver\StartUploadResolver;
use App\GraphQL\SchemaFactory;
use App\Http\Exception\MissingEnvironmentVariableException;
use App\Http\GraphQLHandler;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use App\Infrastructure\Processing\RedisJobQueuePublisher;
use App\Infrastructure\Storage\MockStorageAdapter;
use App\Infrastructure\Upload\LocalUploadGrantIssuer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;

require_once __DIR__ . '/../vendor/autoload.php';

$logger = new Logger('public');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::ERROR));

$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . '/.env';

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function internalServerErrorResponse(): array
{
    return [
        'status' => 500,
        'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
        'body' => json_encode(
            [
                'errors' => [
                    [
                        'message' => 'Internal server error',
                        'extensions' => ['code' => 'INTERNAL_SERVER_ERROR'],
                    ],
                ],
            ],
            JSON_THROW_ON_ERROR,
        ),
    ];
}

if (is_file($envFile)) {
    \Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
}

/**
 * Retrieve an environment variable or throw if missing.
 * Do not expose default credential values in code.
 *
 * @param string $name
 * @return string
 */
function requireEnv(string $name): string
{
    $val = $_ENV[$name] ?? getenv($name);
    if ($val === false || $val === null || $val === '') {
        throw MissingEnvironmentVariableException::forName($name);
    }

    return (string) $val;
}

/**
 * Retrieve an optional environment variable.
 */
function optionalEnv(string $name): ?string
{
    $val = $_ENV[$name] ?? getenv($name);

    if ($val === false || $val === null) {
        return null;
    }

    $normalizedValue = trim((string) $val);

    return $normalizedValue === '' ? null : $normalizedValue;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if (php_sapi_name() === 'cli-server') {
    $resolved = realpath(__DIR__ . $requestPath);
    if ($resolved !== false
        && str_starts_with($resolved, __DIR__ . DIRECTORY_SEPARATOR)
        && is_file($resolved)
    ) {
        return false;
    }
}

if ($requestPath !== '/graphql') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DAM-style PHP — GraphQL endpoint placeholder\n";
    echo "Send GraphQL POST requests to /graphql\n";

    return;
}

try {
    $host = requireEnv('DB_HOST');
    $port = requireEnv('DB_PORT');
    $database = requireEnv('DB_DATABASE');
    $user = requireEnv('DB_USER');
    $password = requireEnv('DB_PASSWORD');
    $redisHost = requireEnv('REDIS_HOST');
    $redisPort = (int) requireEnv('REDIS_PORT');
    $redisPassword = optionalEnv('REDIS_PASSWORD');

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database,
        ),
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );

    $assetRepository = new MySQLAssetRepository($pdo);
    $uploadGrantIssuer = new LocalUploadGrantIssuer(requireEnv('UPLOAD_GRANT_SECRET'));
    $startUploadService = new StartUploadService(
        $assetRepository,
        new MockStorageAdapter(),
        $uploadGrantIssuer,
    );
    $completeUploadService = new CompleteUploadService(
        $assetRepository,
        $uploadGrantIssuer,
        RedisJobQueuePublisher::fromConnectionConfiguration($redisHost, $redisPort, $redisPassword),
    );
    $schemaFactory = new SchemaFactory(
        new StartUploadResolver($startUploadService),
        new StartUploadBatchResolver($startUploadService),
        new CompleteUploadResolver($completeUploadService),
    );
    $localAccountId = requireEnv('LOCAL_ACCOUNT_ID');
    $handler = new GraphQLHandler(
        $schemaFactory,
        $localAccountId,
        $logger,
    );
    $response = $handler->handle(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        $requestPath,
        (string) (file_get_contents('php://input') ?: ''),
    );
} catch (\Throwable $exception) {
    try {
        $logger->error($exception->getMessage(), ['exception' => $exception]);
    } catch (\Throwable $suppressed) {
        // Record suppressed logger failures so they are not lost during triage.
        try {
            if (isset($logger) && $logger instanceof \Psr\Log\LoggerInterface) {
                $logger->error('Suppressed exception while logging primary error', ['suppressed' => $suppressed]);
            } else {
                error_log((string) $suppressed);
            }
        } catch (\Throwable $inner) {
            // Last-resort fallback to PHP error log to ensure the suppressed
            // exception details are preserved somewhere.
            error_log((string) $suppressed);
        }
    }

    $response = internalServerErrorResponse();
}

http_response_code($response['status']);

foreach ($response['headers'] as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo $response['body'];
