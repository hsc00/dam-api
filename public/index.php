<?php

declare(strict_types=1);

use App\Application\Asset\AssetStatusCacheInterface;
use App\Application\Asset\CompleteUploadService;
use App\Application\Asset\GetAssetService;
use App\Application\Asset\StartUploadService;
use App\Application\Exception\SuppressedFailure;
use App\GraphQL\Resolver\CompleteUploadResolver;
use App\GraphQL\Resolver\GetAssetResolver;
use App\GraphQL\Resolver\StartUploadBatchResolver;
use App\GraphQL\Resolver\StartUploadResolver;
use App\GraphQL\SchemaFactory;
use App\Http\Exception\MissingEnvironmentVariableException;
use App\Http\GraphQLHandler;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use App\Infrastructure\Persistence\MySQLOutboxRepository;
use App\Infrastructure\Persistence\PDOTransactionManager;
use App\Infrastructure\Processing\NullAssetStatusCache;
use App\Infrastructure\Processing\RedisAssetStatusCache;
use App\Infrastructure\Storage\MockStorageAdapter;
use App\Infrastructure\Upload\LocalUploadGrantIssuer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

\call_user_func([SuppressedFailure::class, 'clearAcknowledgements']);

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
    $envValue = $_ENV[$name] ?? null;
    $val = is_string($envValue) ? $envValue : getenv($name);

    if ($val === false || $val === '') {
        throw MissingEnvironmentVariableException::forName($name);
    }

    return $val;
}

/**
 * Retrieve an optional environment variable.
 */
function optionalEnv(string $name): ?string
{
    $envValue = $_ENV[$name] ?? null;
    $val = is_string($envValue) ? $envValue : getenv($name);

    if ($val === false) {
        return null;
    }

    $normalizedValue = trim((string) $val);

    return $normalizedValue === '' ? null : $normalizedValue;
}

function assetStatusCache(LoggerInterface $logger): AssetStatusCacheInterface
{
    $host = optionalEnv('REDIS_HOST');
    $port = optionalEnv('REDIS_PORT');

    if ($host === null || $port === null) {
        return new NullAssetStatusCache();
    }

    $normalizedPort = filter_var($port, FILTER_VALIDATE_INT);

    if (! is_int($normalizedPort) || $normalizedPort <= 0) {
        return new NullAssetStatusCache();
    }

    $cache = new NullAssetStatusCache();

    try {
        $cache = RedisAssetStatusCache::fromConnectionConfiguration(
            $host,
            $normalizedPort,
            optionalEnv('REDIS_PASSWORD'),
        );
    } catch (\Throwable $suppressed) {
        try {
            $logger->error(
                'Failed to initialize Redis asset status cache; falling back to null cache.',
                [
                    'redis_host' => $host,
                    'redis_port' => $normalizedPort,
                    'exception' => $suppressed,
                ],
            );
        } catch (\Throwable $loggingFailure) {
            SuppressedFailure::acknowledge($loggingFailure);
        }

        SuppressedFailure::acknowledge($suppressed);
    }

    return $cache;
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
    $assetStatusCache = assetStatusCache($logger);
    $startUploadService = new StartUploadService(
        $assetRepository,
        new MockStorageAdapter(),
        $uploadGrantIssuer,
        $assetStatusCache,
    );
    $getAssetService = new GetAssetService(
        $assetRepository,
        $assetStatusCache,
    );
    $transactionManager = new PDOTransactionManager($pdo);
    $outboxRepository = new MySQLOutboxRepository($pdo);
    $completeUploadService = new CompleteUploadService(
        $assetRepository,
        $uploadGrantIssuer,
        $transactionManager,
        $outboxRepository,
        $assetStatusCache,
    );
    $schemaFactory = new SchemaFactory(
        new GetAssetResolver($getAssetService),
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
        SuppressedFailure::acknowledge($suppressed);
    }

    $response = internalServerErrorResponse();
}

http_response_code($response['status']);

foreach ($response['headers'] as $headerName => $headerValue) {
    header($headerName . ': ' . $headerValue);
}

echo $response['body'];
