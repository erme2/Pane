<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One device a member can be sent web push notifications on. The device keys
 * are encrypted at rest and hidden from serialization.
 *
 * @property string $subscription_id
 * @property int $user_id
 * @property string $kind
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $device_label
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_sent_at
 * @property Carbon|null $created_at
 */
class PushSubscription extends Model
{
    public const string KIND_WEB = 'web';

    protected $primaryKey = 'subscription_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'kind', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device_label', 'expires_at', 'last_sent_at',
    ];

    protected $hidden = ['endpoint', 'endpoint_hash', 'p256dh', 'auth'];

    protected $casts = [
        'user_id' => 'integer',
        'p256dh' => 'encrypted',
        'auth' => 'encrypted',
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::PUSH_SUBSCRIPTIONS);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    protected static function booted(): void
    {
        static::creating(function (PushSubscription $subscription): void {
            if (blank($subscription->subscription_id)) {
                $subscription->subscription_id = (string) Str::uuid();
            }
        });
    }
}
