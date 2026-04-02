<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class AssetsTableLifecycleTest extends BaseAssetsTableTest
{
    #[Test]
    #[DataProvider('provideValidLifecycleRows')]
    public function itReturnsPersistedLifecycleStateWhenRowIsValid(string $rowType): void
    {
        // Arrange & Act
        $this->withTemporarySchema(function (PDO $connection) use ($rowType): void {
            $row = match ($rowType) {
                'pending' => $this->validPendingRow(),
                'failed' => $this->validFailedRow(),
                'uploaded' => $this->validUploadedRow(),
                default => throw new \UnexpectedValueException('Unknown lifecycle row type'),
            };

            $this->insertAssetRow($connection, $row);

            // Assert
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
        });
    }

    /**
     * @param array<string,int|string|null> $overrides
     */
    #[Test]
    #[DataProvider('provideInvalidLifecycleCases')]
    public function itThrowsConstraintViolationWhenRowInvalid(array $overrides, string $message, ?string $baseRowName = null, ?string $expectedSqlState = '23000', ?string $expectedMessageFragment = null, bool $preInsertBaseRow = false): void
    {
        // Arrange & Act
        $this->withTemporarySchema(function (PDO $connection) use ($overrides, $message, $baseRowName, $expectedSqlState, $expectedMessageFragment, $preInsertBaseRow): void {
            $baseRow = $baseRowName === null ? null : match ($baseRowName) {
                'pending' => $this->validPendingRow(),
                'failed' => $this->validFailedRow(),
                'uploaded' => $this->validUploadedRow(),
                default => null,
            };

            if ($preInsertBaseRow && $baseRow !== null) {
                $this->insertAssetRow($connection, $baseRow);
            }

            $this->assertInsertFails(
                $connection,
                $overrides,
                $message,
                $baseRow,
                $expectedSqlState,
                $expectedMessageFragment,
            );
        });
    }

    /** @return list<array{string}> */
    public static function provideValidLifecycleRows(): array
    {
        return [
            ['pending'],
            ['failed'],
            ['uploaded'],
        ];
    }

    /**
     * @return array<string, array{array<string,int|string|null>, string, ?string, ?string, ?string, bool}>
     */
    public static function provideInvalidLifecycleCases(): array
    {
        return [
            'invalid status' => [
                ['status' => 'PROCESSING'],
                'Invalid status values must be rejected.',
                'pending',
                null,
                'chk_assets_',
                false,
            ],
            'chunk_count below one' => [
                ['chunk_count' => 0],
                'chunk_count values below 1 must be rejected.',
                'pending',
                null,
                'chk_assets_chunk_count_positive',
                false,
            ],
            'pending with completion proof' => [
                ['completion_proof' => 'etag-pending-row'],
                'Non-uploaded pending assets must not persist a completion proof.',
                'pending',
                null,
                'chk_assets_completion_proof_matches_status',
                false,
            ],
            'failed with completion proof' => [
                ['completion_proof' => 'etag-failed-row'],
                'Non-uploaded failed assets must not persist a completion proof.',
                'failed',
                null,
                'chk_assets_completion_proof_matches_status',
                false,
            ],
            'uploaded without completion proof' => [
                ['completion_proof' => null],
                'Uploaded assets must persist a completion proof.',
                'uploaded',
                null,
                'chk_assets_completion_proof_matches_status',
                false,
            ],
            'uploaded with whitespace completion proof' => [
                ['completion_proof' => " \t\n "],
                'Whitespace-only completion proof values must be rejected for uploaded assets.',
                'uploaded',
                null,
                'chk_assets_completion_proof_matches_status',
                false,
            ],
            'updated_at earlier than created_at' => [
                [
                    'created_at' => '2026-04-01 12:00:01.000000',
                    'updated_at' => '2026-04-01 12:00:00.000000',
                ],
                'updated_at values earlier than created_at must be rejected.',
                'pending',
                null,
                'chk_assets_updated_at_not_before_created_at',
                false,
            ],
            'duplicate upload_id' => [
                [
                    'id' => '11111111-1111-4111-8111-111111111112',
                    'file_name' => str_repeat('replacement-file-name-', 16) . '.png',
                ],
                'Duplicate upload_id values must be rejected.',
                'pending',
                '23000',
                null,
                true,
            ],
        ];
    }
}
