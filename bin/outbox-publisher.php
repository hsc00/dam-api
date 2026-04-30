<?php

declare(strict_types=1);

use App\Domain\Asset\ValueObject\AssetId;
use App\Http\Exception\MissingEnvironmentVariableException;
use App\Infrastructure\Processing\RedisJobQueuePublisher;

require_once __DIR__ . '/../vendor/autoload.php';

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

try {
    $host = requireEnv('DB_HOST');
    $port = requireEnv('DB_PORT');
    $database = requireEnv('DB_DATABASE');
    $user = requireEnv('DB_USER');
    $password = requireEnv('DB_PASSWORD');
    $redisHost = requireEnv('REDIS_HOST');
    $redisPort = (int) requireEnv('REDIS_PORT');
    $redisPassword = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: null;

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );

    $publisher = RedisJobQueuePublisher::fromConnectionConfiguration($redisHost, $redisPort, $redisPassword);

    $batchSize = (int) ($_ENV['OUTBOX_BATCH_SIZE'] ?? 10);

    while (true) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT id, `queue`, payload
                 FROM outbox_messages
                 WHERE published_at IS NULL
                 ORDER BY created_at
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED',
            );
            $stmt->bindValue('limit', $batchSize, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) === 0) {
                $pdo->commit();
                usleep(250_000);
                continue;
            }

            foreach ($rows as $row) {
                try {
                    $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
                    if (! isset($payload['assetId']) || ! is_string($payload['assetId'])) {
                        error_log('Invalid outbox payload for id ' . $row['id']);
                        $pdo->prepare('UPDATE outbox_messages SET attempts = attempts + 1 WHERE id = :id')
                            ->execute(['id' => $row['id']]);
                        continue;
                    }

                    $assetId = new AssetId($payload['assetId']);
                    $publisher->dispatch($assetId);

                    $pdo->prepare('UPDATE outbox_messages SET published_at = UTC_TIMESTAMP(6) WHERE id = :id')
                        ->execute(['id' => $row['id']]);
                } catch (Throwable $e) {
                    error_log('Failed to publish outbox message ' . $row['id'] . ': ' . $e->getMessage());
                    $pdo->prepare('UPDATE outbox_messages SET attempts = attempts + 1 WHERE id = :id')
                        ->execute(['id' => $row['id']]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $suppressed) {
                // Suppressed intentionally: the primary loop error $e is already
                // captured and will be logged below. A rollback failure here is
                // a secondary cleanup error and must not replace the root cause.
                unset($suppressed);
            }

            error_log('Outbox loop error: ' . $e->getMessage());
            sleep(1);
        }
    }
} catch (Throwable $exception) {
    error_log('Fatal: ' . $exception->getMessage());
    exit(1);
}
