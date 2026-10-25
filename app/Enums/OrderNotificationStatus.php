<?php

namespace App\Enums;

enum OrderNotificationStatus: string
{
    case Pending = 'pending';

    // Попытка зарезервирована; повторная отправка запрещена.
    case Sending = 'sending';

    case Sent = 'sent';
    case Skipped = 'skipped';

    // Результат доставки неизвестен, требуется проверка.
    case Uncertain = 'uncertain';
}
