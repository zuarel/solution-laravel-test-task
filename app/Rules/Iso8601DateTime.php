<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

class Iso8601DateTime implements ValidationRule
{
    private const DATETIME_PATTERN = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match(self::DATETIME_PATTERN, $value)) {
            $fail('The :attribute must be an ISO 8601 datetime with a timezone.');

            return;
        }

        $format = str_contains($value, '.')
            ? 'Y-m-d\TH:i:s.uP'
            : 'Y-m-d\TH:i:sP';

        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $fail('The :attribute must be a valid datetime.');
        }
    }
}
