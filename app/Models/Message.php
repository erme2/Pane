<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One outgoing message in the queue. Pane features add rows (MessageQueue::push)
 * and the worker (`messages:send`) sends them through the row's channel. The
 * body can contain credentials (an invitation link), so it is encrypted at
 * rest, hidden from serialization and never logged.
 *
 * @property string $message_id
 * @property string $channel
 * @property string $recipient
 * @property string|null $subject
 * @property string $body
 * @property string $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $reserved_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 * @property string|null $source_type
 * @property string|null $source_id
 */
class Message extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_SENDING = 'sending';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'channel', 'recipient', 'subject', 'body', 'status', 'attempts', 'available_at',
        'reserved_at', 'sent_at', 'last_error', 'source_type', 'source_id',
    ];

    protected $hidden = ['body'];

    protected $casts = [
        'body' => 'encrypted',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'reserved_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MESSAGES);
    }

    protected static function booted(): void
    {
        static::creating(function (Message $message): void {
            if (blank($message->message_id)) {
                $message->message_id = (string) Str::uuid();
            }
        });
    }
}
