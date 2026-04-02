<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\Test;

final class AssetsTableSchemaTest extends BaseAssetsTableTestCase
{
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
}
