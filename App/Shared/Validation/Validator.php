<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Exceptions\ValidationException;

final class Validator
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,list<string>> $rules
     * @return array<string,mixed>
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $present = array_key_exists($field, $input);
            $value = $input[$field] ?? null;
            $nullable = in_array('nullable', $fieldRules, true);

            if (in_array('required', $fieldRules, true) && (!$present || $value === null || $value === '')) {
                $errors[$field] = 'این فیلد الزامی است.';
                continue;
            }
            if (!$present || ($value === null && $nullable)) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $message = self::validateRule($name, $parameter, $value);
                if ($message !== null) {
                    $errors[$field] = $message;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private static function validateRule(string $rule, ?string $parameter, mixed $value): ?string
    {
        return match ($rule) {
            'required', 'nullable' => null,
            'string' => is_string($value) ? null : 'باید از نوع رشته باشد.',
            'integer' => is_int($value) ? null : 'باید عدد صحیح باشد.',
            'boolean' => is_bool($value) ? null : 'باید مقدار بولی باشد.',
            'array' => is_array($value) ? null : 'باید آرایه باشد.',
            'min' => self::size($value) >= (int) $parameter ? null : "حداقل مقدار مجاز {$parameter} است.",
            'max' => self::size($value) <= (int) $parameter ? null : "حداکثر مقدار مجاز {$parameter} است.",
            'in' => in_array((string) $value, explode(',', (string) $parameter), true) ? null : 'مقدار انتخاب‌شده معتبر نیست.',
            default => throw new \InvalidArgumentException("Unknown validation rule: {$rule}"),
        };
    }

    private static function size(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        }
        if (is_array($value)) {
            return count($value);
        }
        return 0;
    }
}
