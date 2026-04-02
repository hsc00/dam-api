<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetsTableBootstrapTest extends TestCase
{
    private const ACCOUNT_ID_INDEX_NAME = 'idx_assets_account_id';
    private const TABLE_NAME = 'assets';
    private const FILE_NAME_COLLATION = 'utf8mb4_0900_ai_ci';
    private const LONG_TEXT_DATA_TYPE = 'longtext';
    private const MIGRATION_FILE = __DIR__ . '/../../../../migrations/20260401120000_create_assets_table.sql';
    private const VARCHAR_DATA_TYPE = 'varchar';

    /**
     * @var list<string>
     */
    private const REQUIRED_COLUMNS = [
        'id',
        'upload_id',
        'account_id',
        'file_name',
        'mime_type',
        'status',
        'chunk_count',
        'completion_proof',
        'created_at',
        'updated_at',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_NON_NULLABLE_COLUMNS = [
        'id',
        'upload_id',
        'account_id',
        'file_name',
        'mime_type',
        'status',
        'chunk_count',
        'created_at',
        'updated_at',
    ];

    /**
     * @var array<string, string>
     */
    private const WIDENED_TEXT_COLUMNS = [
        'account_id' => self::VARCHAR_DATA_TYPE,
        'file_name' => self::LONG_TEXT_DATA_TYPE,
        'mime_type' => self::LONG_TEXT_DATA_TYPE,
        'completion_proof' => self::LONG_TEXT_DATA_TYPE,
    ];

    /**
     * @var array{host: string, port: int, user: string, password: string}|null
     */
    private ?array $selectedConnection = null;

    #[Test]
    public function itCreatesTheExpectedAssetsTableSchemaAndCanBeReappliedSafely(): void
    {
        // Arrange & Act
        $this->withTemporarySchema(function (PDO $connection): void {
            $firstRunColumns = $this->fetchColumnMetadata($connection);
            $firstRunAccountIdIndex = $this->fetchIndexColumns($connection, self::ACCOUNT_ID_INDEX_NAME);
            $firstRunUploadIdUniqueIndexes = $this->fetchUniqueUploadIdIndexDefinitions($connection);

            $connection->exec($this->migrationSql());
            $secondRunColumns = $this->fetchColumnMetadata($connection);
            $secondRunAccountIdIndex = $this->fetchIndexColumns($connection, self::ACCOUNT_ID_INDEX_NAME);
            $secondRunUploadIdUniqueIndexes = $this->fetchUniqueUploadIdIndexDefinitions($connection);

            // Assert
            $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($firstRunColumns)));
            self::assertSame([], $missing, 'Missing required columns: ' . implode(', ', $missing));

            foreach (self::REQUIRED_NON_NULLABLE_COLUMNS as $columnName) {
                self::assertSame('NO', $firstRunColumns[$columnName]['IS_NULLABLE']);
            }

            foreach (self::WIDENED_TEXT_COLUMNS as $columnName => $expectedDataType) {
                self::assertSame($expectedDataType, $firstRunColumns[$columnName]['DATA_TYPE']);
            }

            self::assertSame('YES', $firstRunColumns['completion_proof']['IS_NULLABLE']);
            self::assertSame(self::FILE_NAME_COLLATION, $firstRunColumns['file_name']['COLLATION_NAME']);
            self::assertSame(['account_id'], $firstRunAccountIdIndex);
            self::assertSame([['upload_id']], $firstRunUploadIdUniqueIndexes);
            self::assertSame($firstRunColumns, $secondRunColumns);
            self::assertSame($firstRunAccountIdIndex, $secondRunAccountIdIndex);
            self::assertSame([['upload_id']], $secondRunUploadIdUniqueIndexes);
        });
    }

    #[Test]
    public function itAcceptsRowsForEveryValidAssetLifecycleState(): void
    {
        // Arrange & Act
        $this->withTemporarySchema(function (PDO $connection): void {
            $validRows = [
                $this->validPendingRow(),
                $this->validFailedRow(),
                $this->validUploadedRow(),
            ];

            foreach ($validRows as $row) {
                $this->insertAssetRow($connection, $row);
            }

            // Assert
            foreach ($validRows as $row) {
                self::assertSame(
                    [
                        'status' => (string) $row['status'],
                        'chunk_count' => (int) $row['chunk_count'],
                        'completion_proof' => $row['completion_proof'] === null ? null : (string) $row['completion_proof'],
                        'created_at' => (string) $row['created_at'],
                        'updated_at' => (string) $row['updated_at'],
                    ],
                    $this->fetchPersistedLifecycleState($connection, (string) $row['upload_id']),
                );
            }
        });
    }

    #[Test]
    public function itRejectsRowsThatViolateAssetLifecycleConstraints(): void
    {
        // Arrange & Act
        $this->withTemporarySchema(function (PDO $connection): void {
            $validPendingRow = $this->validPendingRow();

            $this->assertInsertFails(
                $connection,
                [
                    'status' => 'PROCESSING',
                ],
                'Invalid status values must be rejected.',
                $validPendingRow,
                null,
                'chk_assets_',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'chunk_count' => 0,
                ],
                'chunk_count values below 1 must be rejected.',
                $validPendingRow,
                null,
                'chk_assets_chunk_count_positive',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'completion_proof' => 'etag-pending-row',
                ],
                'Non-uploaded pending assets must not persist a completion proof.',
                $validPendingRow,
                null,
                'chk_assets_completion_proof_matches_status',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'completion_proof' => 'etag-failed-row',
                ],
                'Non-uploaded failed assets must not persist a completion proof.',
                $this->validFailedRow(),
                null,
                'chk_assets_completion_proof_matches_status',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'completion_proof' => null,
                ],
                'Uploaded assets must persist a completion proof.',
                $this->validUploadedRow(),
                null,
                'chk_assets_completion_proof_matches_status',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'completion_proof' => " \t\n ",
                ],
                'Whitespace-only completion proof values must be rejected for uploaded assets.',
                $this->validUploadedRow(),
                null,
                'chk_assets_completion_proof_matches_status',
            );

            $this->assertInsertFails(
                $connection,
                [
                    'created_at' => '2026-04-01 12:00:01.000000',
                    'updated_at' => '2026-04-01 12:00:00.000000',
                ],
                'updated_at values earlier than created_at must be rejected.',
                $validPendingRow,
                null,
                'chk_assets_updated_at_not_before_created_at',
            );

            $this->insertAssetRow($connection, $validPendingRow);

            $this->assertInsertFails(
                $connection,
                [
                    'id' => '11111111-1111-4111-8111-111111111112',
                    'file_name' => str_repeat('replacement-file-name-', 16) . '.png',
                ],
                'Duplicate upload_id values must be rejected.',
                $validPendingRow,
            );
        });
    }

    /**
     * @param callable(PDO): void $assertions
     */
    private function withTemporarySchema(callable $assertions): void
    {
        $serverConnection = $this->createServerConnectionOrSkip();
        $databaseName = 'dam_schema_' . bin2hex(random_bytes(6));
        $this->createDatabase($serverConnection, $databaseName);
        $databaseConnection = null;

        try {
            $databaseConnection = $this->createDatabaseConnection($databaseName);
            $databaseConnection->exec($this->migrationSql());

            $assertions($databaseConnection);
        } finally {
            $databaseConnection = null;
            $this->dropDatabase($serverConnection, $databaseName);
        }
    }

    private function migrationSql(): string
    {
        $migrationSql = file_get_contents(self::MIGRATION_FILE);

        if ($migrationSql === false) {
            self::fail('Failed to read the assets table bootstrap migration.');
        }

        return $migrationSql;
    }

    private function createServerConnectionOrSkip(): PDO
    {
        if (! class_exists(PDO::class)) {
            self::markTestSkipped('PDO is not available in this PHP runtime.');
        }

        $connectionErrors = [];

        foreach ($this->connectionCandidates() as $candidate) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;charset=utf8mb4',
                $candidate['host'],
                $candidate['port'],
            );

            try {
                $connection = $this->createConnection($dsn, $candidate['user'], $candidate['password']);
                $this->selectedConnection = $candidate;

                return $connection;
            } catch (PDOException $exception) {
                $connectionErrors[] = sprintf(
                    '%s:%d (%s)',
                    $candidate['host'],
                    $candidate['port'],
                    $exception->getMessage(),
                );
            }
        }

        self::markTestSkipped(
            'MySQL is not reachable for integration tests. Tried: ' . implode('; ', $connectionErrors),
        );
    }

    private function createDatabaseConnection(string $databaseName): PDO
    {
        if ($this->selectedConnection === null) {
            self::fail('MySQL connection settings were not initialized.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->selectedConnection['host'],
            $this->selectedConnection['port'],
            $databaseName,
        );

        return $this->createConnection($dsn, $this->selectedConnection['user'], $this->selectedConnection['password']);
    }

    private function createConnection(string $dsn, string $user, string $password): PDO
    {
        return new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    private function createDatabase(PDO $connection, string $databaseName): void
    {
        $connection->exec(
            sprintf(
                'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE %s',
                $this->quoteIdentifier($databaseName),
                self::FILE_NAME_COLLATION,
            ),
        );
    }

    private function dropDatabase(PDO $connection, string $databaseName): void
    {
        $connection->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($databaseName));
    }

    /**
     * @return list<array{host: string, port: int, user: string, password: string}>
     */
    private function connectionCandidates(): array
    {
        $user = $this->env('DB_USER', 'root');
        $password = $this->env('DB_PASSWORD', 'root');
        $candidates = [[
            'host' => $this->env('DB_HOST', '127.0.0.1'),
            'port' => $this->envInt('DB_PORT', 3306),
            'user' => $user,
            'password' => $password,
        ]];

        $fallbackCandidate = [
            'host' => '127.0.0.1',
            'port' => $this->envInt('DB_HOST_PORT', 3307),
            'user' => $user,
            'password' => $password,
        ];

        if (
            $fallbackCandidate['host'] !== $candidates[0]['host']
            || $fallbackCandidate['port'] !== $candidates[0]['port']
        ) {
            $candidates[] = $fallbackCandidate;
        }

        return $candidates;
    }

    private function env(string $name, string $defaultValue): string
    {
        $value = getenv($name);

        if ($value === false) {
            return $defaultValue;
        }

        $trimmedValue = trim($value);

        return $trimmedValue === '' ? $defaultValue : $trimmedValue;
    }

    private function envInt(string $name, int $defaultValue): int
    {
        $value = getenv($name);

        if ($value === false) {
            return $defaultValue;
        }

        $trimmedValue = trim($value);

        if ($trimmedValue === '' || ! ctype_digit($trimmedValue)) {
            return $defaultValue;
        }

        return (int) $trimmedValue;
    }

    /**
     * @return array<string, array{IS_NULLABLE: string, COLLATION_NAME: string|null, DATA_TYPE: string}>
     */
    private function fetchColumnMetadata(PDO $connection): array
    {
        $statement = $connection->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLLATION_NAME, DATA_TYPE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :tableName
             ORDER BY ORDINAL_POSITION',
        );
        $statement->execute([
            'tableName' => self::TABLE_NAME,
        ]);

        $columns = [];

        while (($row = $statement->fetch()) !== false) {
            if (! is_array($row)) {
                self::fail('Unexpected column metadata row shape.');
            }

            $columnName = $row['COLUMN_NAME'] ?? null;
            $isNullable = $row['IS_NULLABLE'] ?? null;
            $collationName = $row['COLLATION_NAME'] ?? null;
            $dataType = $row['DATA_TYPE'] ?? null;

            if (
                ! is_string($columnName)
                || ! is_string($isNullable)
                || ($collationName !== null && ! is_string($collationName))
                || ! is_string($dataType)
            ) {
                self::fail('Unexpected column metadata row shape.');
            }

            $columns[$columnName] = [
                'IS_NULLABLE' => $isNullable,
                'COLLATION_NAME' => $collationName,
                'DATA_TYPE' => $dataType,
            ];
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function fetchIndexColumns(PDO $connection, string $indexName): array
    {
        $statement = $connection->prepare(
            'SELECT COLUMN_NAME, SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :tableName
               AND INDEX_NAME = :indexName
             ORDER BY SEQ_IN_INDEX',
        );
        $statement->execute([
            'tableName' => self::TABLE_NAME,
            'indexName' => $indexName,
        ]);

        $indexColumns = [];

        while (($row = $statement->fetch()) !== false) {
            if (! is_array($row)) {
                self::fail('Unexpected index metadata row shape.');
            }

            $columnName = $row['COLUMN_NAME'] ?? null;
            $seqInIndex = $row['SEQ_IN_INDEX'] ?? null;

            if (! is_string($columnName) || ! is_numeric($seqInIndex)) {
                self::fail('Unexpected index metadata row shape.');
            }

            $indexColumns[(int) $seqInIndex] = $columnName;
        }

        ksort($indexColumns);

        return array_values($indexColumns);
    }

    /**
     * @return list<list<string>>
     */
    private function fetchUniqueUploadIdIndexDefinitions(PDO $connection): array
    {
        $statement = $connection->prepare(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :tableName
               AND NON_UNIQUE = 0
                             AND INDEX_NAME <> :primaryIndex
                         ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        );
        $statement->execute([
            'tableName' => self::TABLE_NAME,
            'primaryIndex' => 'PRIMARY',
        ]);

        $indexColumnsByName = [];

        while (($row = $statement->fetch()) !== false) {
            if (! is_array($row)) {
                self::fail('Unexpected index metadata row shape.');
            }

            $indexName = $row['INDEX_NAME'] ?? null;
            $columnName = $row['COLUMN_NAME'] ?? null;
            $seqInIndex = $row['SEQ_IN_INDEX'] ?? null;

            if (! is_string($indexName) || ! is_string($columnName) || ! is_numeric($seqInIndex)) {
                self::fail('Unexpected index metadata row shape.');
            }

            $indexColumnsByName[$indexName][(int) $seqInIndex] = $columnName;
        }

        $uploadIdIndexes = [];

        foreach ($indexColumnsByName as $columns) {
            ksort($columns);
            $orderedColumns = array_values($columns);

            if (in_array('upload_id', $orderedColumns, true)) {
                $uploadIdIndexes[] = $orderedColumns;
            }
        }

        return $uploadIdIndexes;
    }

    /**
     * @param array<string, int|string|null> $row
     */
    private function insertAssetRow(PDO $connection, array $row): void
    {
        $statement = $connection->prepare(
            'INSERT INTO assets (
                id,
                upload_id,
                account_id,
                file_name,
                mime_type,
                status,
                chunk_count,
                completion_proof,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :upload_id,
                :account_id,
                :file_name,
                :mime_type,
                :status,
                :chunk_count,
                :completion_proof,
                :created_at,
                :updated_at
            )',
        );
        $statement->execute($row);
    }

    /**
     * @param array<string, int|string|null> $overrides
     * @param array<string, int|string|null>|null $baseRow
     */
    private function assertInsertFails(
        PDO $connection,
        array $overrides,
        string $message,
        ?array $baseRow = null,
        ?string $expectedSqlState = '23000',
        ?string $expectedMessageFragment = null,
    ): void {
        $row = array_replace($baseRow ?? $this->validPendingRow(), $overrides);

        try {
            $this->insertAssetRow($connection, $row);
        } catch (PDOException $exception) {
            if ($expectedMessageFragment !== null) {
                self::assertStringContainsString($expectedMessageFragment, $exception->getMessage());

                return;
            }

            self::assertSame($expectedSqlState, $exception->getCode());

            return;
        }

        self::fail($message);
    }

    /**
     * @return array{status: string, chunk_count: int, completion_proof: string|null, created_at: string, updated_at: string}
     */
    private function fetchPersistedLifecycleState(PDO $connection, string $uploadId): array
    {
        $statement = $connection->prepare(
            'SELECT status, chunk_count, completion_proof, created_at, updated_at
             FROM assets
             WHERE upload_id = :upload_id',
        );
        $statement->execute([
            'upload_id' => $uploadId,
        ]);

        $row = $statement->fetch();

        if (! is_array($row)) {
            self::fail('Expected a persisted asset row for the supplied upload id.');
        }

        $status = $row['status'] ?? null;
        $chunkCount = $row['chunk_count'] ?? null;
        $completionProof = $row['completion_proof'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;

        if (
            ! is_string($status)
            || ! is_numeric($chunkCount)
            || ($completionProof !== null && ! is_string($completionProof))
            || ! is_string($createdAt)
            || ! is_string($updatedAt)
        ) {
            self::fail('Unexpected persisted asset row shape.');
        }

        return [
            'status' => $status,
            'chunk_count' => (int) $chunkCount,
            'completion_proof' => $completionProof,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function validPendingRow(): array
    {
        return [
            'id' => '11111111-1111-4111-8111-111111111111',
            'upload_id' => '22222222-2222-4222-8222-222222222222',
            'account_id' => str_repeat('account-', 20),
            'file_name' => str_repeat('very-long-file-name-', 18) . '.png',
            'mime_type' => 'application/' . str_repeat('vnd.example.long-subtype-', 12) . 'json',
            'status' => 'PENDING',
            'chunk_count' => 1,
            'completion_proof' => null,
            'created_at' => '2026-04-01 12:00:00.000000',
            'updated_at' => '2026-04-01 12:00:00.000000',
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function validFailedRow(): array
    {
        return array_replace(
            $this->validPendingRow(),
            [
                'id' => '55555555-5555-4555-8555-555555555555',
                'upload_id' => '66666666-6666-4666-8666-666666666666',
                'status' => 'FAILED',
                'chunk_count' => 2,
                'updated_at' => '2026-04-01 12:03:00.000000',
            ],
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function validUploadedRow(): array
    {
        return array_replace(
            $this->validPendingRow(),
            [
                'id' => '33333333-3333-4333-8333-333333333333',
                'upload_id' => '44444444-4444-4444-8444-444444444444',
                'status' => 'UPLOADED',
                'chunk_count' => 3,
                'completion_proof' => str_repeat('etag-', 80),
                'updated_at' => '2026-04-01 12:05:00.000000',
            ],
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
