<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'event_id',
        'type',
        'raw_body',
        'status',
        'received_at',
        'queued_at',
        'checked_at',
        'validation_errors',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'validation_errors' => 'array',
        ];
    }
}
