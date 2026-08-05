<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $membership_id
 * @property string $organization_id
 * @property int $user_id
 * @property string $role
 * @property string $status
 * @property int|null $invited_by_user_id
 * @property Carbon|null $accepted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $suspended_at
 */
class OrganizationMembership extends Model
{
    public const string ROLE_ADMINISTRATOR = 'organization_administrator';

    public const string ROLE_USER = 'organization_user';

    public const array ROLES = [
        self::ROLE_ADMINISTRATOR,
        self::ROLE_USER,
    ];

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    protected $primaryKey = 'membership_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'membership_id',
        'organization_id',
        'user_id',
        'role',
        'status',
        'invited_by_user_id',
        'accepted_at',
        'suspended_at',
        'details',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'suspended_at' => 'datetime',
        'details' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::ORGANIZATION_MEMBERSHIPS);
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationMembership $membership): void {
            if (blank($membership->membership_id)) {
                $membership->membership_id = (string) Str::uuid();
            }
        });
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id', 'user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }

    public function versionTag(): string
    {
        return hash('sha256', implode('|', [
            $this->getKey(),
            $this->role,
            $this->status,
            $this->updated_at?->toJSON(),
        ]));
    }
}
