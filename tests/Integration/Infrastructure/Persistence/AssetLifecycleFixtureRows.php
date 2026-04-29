<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

trait AssetLifecycleFixtureRows
{
    /** @return array<string, int|string|null> */
    protected function validPendingRow(): array
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

    /** @return array<string, int|string|null> */
    protected function validFailedRow(): array
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

    /** @return array<string, int|string|null> */
    protected function validProcessingRow(): array
    {
        return array_replace(
            $this->validPendingRow(),
            [
                'id' => '77777777-7777-4777-8777-777777777777',
                'upload_id' => '88888888-8888-4888-8888-888888888888',
                'status' => 'PROCESSING',
                'chunk_count' => 3,
                'completion_proof' => str_repeat('etag-processing-', 40),
                'updated_at' => '2026-04-01 12:04:00.000000',
            ],
        );
    }

    /** @return array<string, int|string|null> */
    protected function validUploadedRow(): array
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
}
