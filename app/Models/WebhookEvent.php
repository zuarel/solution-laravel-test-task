<?php

namespace App\Models;

use App\Support\DateTimeFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNSUPPORTED = 'unsupported';

    public const STATUS_FAILED = 'failed';

    public $timestamps = false;

    protected $dateFormat = DateTimeFormat::DATABASE;

    protected $fillable = [
        'event_id',
        'order_id',
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
            'order_id' => 'integer',
            'received_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'validation_errors' => 'array',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
