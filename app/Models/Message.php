<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One outgoing message in the queue. Pane adds rows (MessageQueue::push) and
 * the worker sends them. The payload holds what the template needs and can
 * contain credentials (e.g. an invitation link), so it is encrypted at rest,
 * hidden from serialization and never logged. The subject is stored without
 * any environment prefix; the worker adds `[DEV]` / `[STAGE]` when sending.
 *
 * @property string $message_id
 * @property string $type
 * @property string|null $purpose
 * @property string $channel
 * @property string $recipient
 * @property string $subject
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $reserved_at
 * @property Carbon|null $sent_at
 * @property string|null $last_error
 * @property string|null $organization_id
 * @property string|null $source_type
 * @property string|null $source_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Message extends Model
{
    public const string TYPE_SYSTEM_EMAIL = 'system_email';

    public const string TYPE_MARKETING_EMAIL = 'marketing_email';

    /** Extend this list to add types; the column is a plain string, not a DB enum. */
    public const array TYPES = [self::TYPE_SYSTEM_EMAIL, self::TYPE_MARKETING_EMAIL];

    public const string CHANNEL_EMAIL = 'email';

    public const array CHANNELS = [self::CHANNEL_EMAIL];

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_SENDING = 'sending';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    public const array STATUSES = [self::STATUS_PENDING, self::STATUS_SENDING, self::STATUS_SENT, self::STATUS_FAILED];

    public const string PURPOSE_INVITATION = 'invitation';

    protected $primaryKey = 'message_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'message_id', 'type', 'purpose', 'channel', 'recipient', 'subject', 'payload', 'status', 'attempts',
        'available_at', 'reserved_at', 'sent_at', 'last_error', 'organization_id', 'source_type', 'source_id',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'encrypted:array',
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
