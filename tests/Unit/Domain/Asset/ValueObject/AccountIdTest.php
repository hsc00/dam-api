<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Asset\ValueObject;

use App\Domain\Asset\ValueObject\AccountId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountIdTest extends TestCase
{
    private const ACCOUNT_ID = '123';

    #[Test]
    public function itTrimsAndStoresNonEmptyValue(): void
    {
        // Arrange
        $value = '  ' . self::ACCOUNT_ID . '  ';

        // Act
        $accountId = new AccountId($value);

        // Assert
        self::assertSame(self::ACCOUNT_ID, $accountId->value);
    }

    #[Test]
    public function itReturnsStringValueWhenCastToString(): void
    {
        // Arrange
        $accountId = new AccountId(self::ACCOUNT_ID);

        // Act
        $value = (string) $accountId;

        // Assert
        self::assertSame(self::ACCOUNT_ID, $value);
    }

    #[Test]
    #[DataProvider('invalidAccountIdProvider')]
    public function itThrowsInvalidArgumentExceptionWhenValueIsEmptyAfterTrim(string $value): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('AccountId cannot be empty');

        // Act
        $this->createAccountId($value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidAccountIdProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
        ];
    }

    private function createAccountId(string $value): AccountId
    {
        return new AccountId($value);
    }
}
