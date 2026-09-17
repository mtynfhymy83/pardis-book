<?php

declare(strict_types=1);

use App\Shared\Exceptions\ValidationException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyValidatedFields(): void
    {
        $result = Validator::validate(
            ['name' => 'Pardis', 'quantity' => 5, 'ignored' => 'value'],
            ['name' => ['required', 'string', 'min:2'], 'quantity' => ['required', 'integer', 'min:1']]
        );

        self::assertSame(['name' => 'Pardis', 'quantity' => 5], $result);
    }

    public function testCollectsFieldErrors(): void
    {
        try {
            Validator::validate(['quantity' => 0], [
                'name' => ['required', 'string'],
                'quantity' => ['required', 'integer', 'min:1'],
            ]);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('name', $exception->getErrors());
            self::assertArrayHasKey('quantity', $exception->getErrors());
        }
    }
}
