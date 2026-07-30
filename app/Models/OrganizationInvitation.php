<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $organization_invitation_id
 * @property string $organization_id
 * @property string $email
 * @property string $token_hash
 * @property string $role
 * @property string $status
 * @property int $invited_by_user_id
 * @property int|null $accepted_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationInvitation extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_ACCEPTED = 'accepted';

    public const string STATUS_REVOKED = 'revoked';

    public const string STATUS_EXPIRED = 'expired';

    public const array STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACCEPTED,
        self::STATUS_REVOKED,
        self::STATUS_EXPIRED,
    ];

    protected $primaryKey = 'organization_invitation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_invitation_id',
        'organization_id',
        'email',
        'token_hash',
        'role',
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

        $this->table = PaneTable::name(PaneTable::ORGANIZATION_INVITATIONS);
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation): void {
            if (blank($invitation->organization_invitation_id)) {
                $invitation->organization_invitation_id = (string) Str::uuid();
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

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id', 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id', 'user_id');
    }
}
