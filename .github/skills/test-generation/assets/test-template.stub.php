<?php

declare(strict_types=1);

namespace Tests\Unit\{{Namespace}};

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use {{FullyQualifiedClassName}};
// use {{FullyQualifiedDependency}};

final class {{ClassName}}Test extends TestCase
{
    // Declare mocks as typed properties for IDE support
    // private MockObject&{{DependencyInterface}} $repository;

    protected function setUp(): void
    {
        // Only instantiate mocks here — keep logic out of setUp()
        // $this->repository = $this->createMock({{DependencyInterface}}::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_{{happy_path_description}}(): void
    {
        // Arrange
        // $sut = new {{ClassName}}($this->repository);
        // $command = new {{CommandOrInput}}(...);

        // Act
        // $result = $sut->handle($command);

        // Assert
        // self::assertSame('expected', $result->someField);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_when_{{invalid_condition}}(): void
    {
        // Arrange
        // $sut = new {{ClassName}}($this->repository);

        // Assert (expectation declared before act for exceptions)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('{{expected message fragment}}');

        // Act
        // new {{ValueObject}}('');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('provideValidInputs')]
    public function it_accepts_valid_{{inputs_description}}(
        string $input,
        string $expectedValue,
    ): void {
        // Arrange + Act
        // $vo = new {{ValueObject}}($input);

        // Assert
        // self::assertSame($expectedValue, $vo->value);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideValidInputs(): array
    {
        return [
            'example case' => ['input-value', 'expected-value'],
            // Add more cases
        ];
    }
}
