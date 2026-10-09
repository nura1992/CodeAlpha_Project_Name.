<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;

/** Server-side input validation. Every public endpoint must validate with these helpers. */
final class Validator
{
    public static function fail(string $field, string $message): never
    {
        throw new ApiException(422, 'VALIDATION_FAILED', $message, ['field' => $field]);
    }

    public static function str(mixed $value, string $field, int $min, int $max, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                self::fail($field, "The {$field} field is required.");
            }
            return null;
        }
        if (!is_string($value) && !is_numeric($value)) {
            self::fail($field, "The {$field} field must be text.");
        }
        $value = trim((string) $value);
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            self::fail($field, "The {$field} field must be between {$min} and {$max} characters.");
        }
        return $value;
    }

    public static function int(mixed $value, string $field, int $min, int $max): int
    {
        if (!is_numeric($value) || (float) $value !== (float) (int) $value) {
            self::fail($field, "The {$field} field must be a whole number.");
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            self::fail($field, "The {$field} field must be between {$min} and {$max}.");
        }
        return $int;
    }

    public static function in(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::fail($field, "The {$field} field has an invalid value.");
        }
        return $value;
    }

    public static function email(mixed $value): string
    {
        $email = is_string($value) ? strtolower(trim($value)) : '';
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('email', 'Enter a valid email address.');
        }
        return $email;
    }

    /** Normalises to +countrycode digits (E.164-style). */
    public static function phone(mixed $value): string
    {
        $raw = is_string($value) ? preg_replace('/[\s\-().]/', '', $value) : '';
        if (!is_string($raw) || !preg_match('/^\+?[0-9]{8,15}$/', $raw)) {
            self::fail('phone', 'Enter a valid phone number including the country code.');
        }
        return '+' . ltrim($raw, '+');
    }

    public static function username(mixed $value): string
    {
        $u = is_string($value) ? strtolower(trim($value)) : '';
        if (!preg_match('/^[a-z0-9_]{3,30}$/', $u)) {
            self::fail('username', 'Username must be 3–30 characters: letters, numbers or underscores.');
        }
        return $u;
    }

    public static function password(mixed $value): string
    {
        $p = is_string($value) ? $value : '';
        if (strlen($p) < 10 || strlen($p) > 200) {
            self::fail('password', 'Password must be between 10 and 200 characters.');
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) {
            self::fail('password', 'Password must contain at least one letter and one number.');
        }
        return $p;
    }

    public static function date(mixed $value, string $field): DateTimeImmutable
    {
        $d = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$d || $d->format('Y-m-d') !== $value) {
            self::fail($field, "The {$field} must be a valid date in YYYY-MM-DD format.");
        }
        return $d;
    }

    public static function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
