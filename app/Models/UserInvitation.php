<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation to join the installation as a standard user (administrators have their own table).
 *
 * @property string $user_invitation_id
 * @property string $email
 * @property string $token_hash
 * @property string $status
 * @property int $invited_by_user_id
 * @property int|null $accepted_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserInvitation extends Model
{
    public const string STATUS_PENDING = PaneAdminInvitation::STATUS_PENDING;

    public const string STATUS_ACCEPTED = PaneAdminInvitation::STATUS_ACCEPTED;

    public const string STATUS_REVOKED = PaneAdminInvitation::STATUS_REVOKED;

    public const string STATUS_EXPIRED = PaneAdminInvitation::STATUS_EXPIRED;

    protected $primaryKey = 'user_invitation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_invitation_id',
        'email',
        'token_hash',
        'status',
        'invited_by_user_id',
        'accepted_by_user_id',
        'expires_at',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::USER_INVITATIONS);
    }

    protected static function booted(): void
    {
        static::creating(function (UserInvitation $invitation): void {
            if (blank($invitation->user_invitation_id)) {
                $invitation->user_invitation_id = (string) Str::uuid();
            }
        });
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
