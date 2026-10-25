<?php

namespace App\Support;

final class DateTimeFormat
{
    public const API = 'Y-m-d\TH:i:s.uP';

    public const ISO_8601 = 'Y-m-d\TH:i:sP';

    public const ISO_8601_WITH_MICROSECONDS = 'Y-m-d\TH:i:s.uP';

    public const DATABASE = 'Y-m-d H:i:s.uP';
}
