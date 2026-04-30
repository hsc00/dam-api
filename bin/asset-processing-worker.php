<?php

declare(strict_types=1);

use App\Application\Asset\HandleAssetProcessingJobService;
use App\Application\Asset\HandleAssetProcessingRetryExhaustionService;
use App\Http\Exception\MissingEnvironmentVariableException;
use App\Infrastructure\Persistence\MySQLAssetRepository;
use App\Infrastructure\Processing\AssetProcessingJobWorker;
use App\Infrastructure\Processing\AssetProcessingWorkerLoop;
use App\Infrastructure\Processing\PassThroughAssetProcessor;
use App\Infrastructure\Processing\RedisAssetTerminalStatusCache;
use App\Infrastructure\Processing\RedisJobQueueConsumer;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PDO;

require_once __DIR__ . '/../vendor/autoload.php';

$logger = new Logger('asset-processing-worker');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));

$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . '/.env';

if (is_file($envFile)) {
    \Dotenv\Dotenv::createImmutable($projectRoot)->safeLoad();
}

/**
 * @throws MissingEnvironmentVariableException
 */
function requireEnv(string $name): string
{
    $val = $_ENV[$name] ?? getenv($name);

    if ($val === false || $val === null || $val === '') {
        throw MissingEnvironmentVariableException::forName($name);
    }

    return (string) $val;
}

function optionalEnv(string $name): ?string
{
    $val = $_ENV[$name] ?? getenv($name);

    if ($val === false || $val === null) {
        return null;
    }

    $normalizedValue = trim((string) $val);

    return $normalizedValue === '' ? null : $normalizedValue;
}

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            requireEnv('DB_HOST'),
            requireEnv('DB_PORT'),
            requireEnv('DB_DATABASE'),
        ),
        requireEnv('DB_USER'),
        requireEnv('DB_PASSWORD'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ],
    );

    $assets = new MySQLAssetRepository($pdo);
    $assetTerminalStatusCache = RedisAssetTerminalStatusCache::fromConnectionConfiguration(
        requireEnv('REDIS_HOST'),
        (int) requireEnv('REDIS_PORT'),
        optionalEnv('REDIS_PASSWORD'),
    );
    $service = new HandleAssetProcessingJobService(
        $assets,
        new PassThroughAssetProcessor(),
        $assetTerminalStatusCache,
    );
    $retryExhaustionService = new HandleAssetProcessingRetryExhaustionService($assets, $assetTerminalStatusCache);
    $worker = new AssetProcessingJobWorker($service, $retryExhaustionService, $logger);
    $consumer = RedisJobQueueConsumer::fromConnectionConfiguration(
        requireEnv('REDIS_HOST'),
        (int) requireEnv('REDIS_PORT'),
        optionalEnv('REDIS_PASSWORD'),
    );
} catch (\Throwable $exception) {
    $logger->error('Asset processing worker failed to start.', ['exception' => $exception]);

    exit(1);
}

$loop = new AssetProcessingWorkerLoop($consumer, $worker, $logger);

$logger->info('Asset processing worker started.');

try {
    while (true) {
        $loop->runOnce();
        gc_collect_cycles();
    }
} catch (\Throwable $exception) {
    $logger->critical('Asset processing worker stopped unexpectedly.', ['exception' => $exception]);

    exit(1);
}
