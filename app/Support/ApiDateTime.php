<?php

namespace App\Support;

use DateTimeInterface;

final class ApiDateTime
{
    public static function format(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeFormat::API);
    }
}
