<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One signed-in native client (iOS or Android device) of a member. Holds the hashes of its
 * current access and refresh tokens, never the tokens themselves (Pane#130).
 *
 * @property string $device_id
 * @property int $user_id
 * @property string|null $device_label
 * @property string $access_token_hash
 * @property Carbon $access_expires_at
 * @property string $refresh_token_hash
 * @property Carbon $refresh_expires_at
 * @property string|null $workos_session_id
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 */
class DeviceToken extends Model
{
    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'device_label', 'access_token_hash', 'access_expires_at', 'refresh_token_hash',
        'refresh_expires_at', 'workos_session_id', 'last_used_at',
    ];

    protected $hidden = ['access_token_hash', 'refresh_token_hash', 'workos_session_id'];

    protected $casts = [
        'user_id' => 'integer',
        'access_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::DEVICE_TOKENS);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    protected static function booted(): void
    {
        static::creating(function (DeviceToken $device): void {
            if (blank($device->device_id)) {
                $device->device_id = (string) Str::uuid();
            }
        });
    }
}
