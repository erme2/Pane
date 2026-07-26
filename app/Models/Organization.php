<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property int $database_limit
 */
class Organization extends Model
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_SUSPENDED = 'suspended';

    public const string STATUS_CLOSED = 'closed';

    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_CLOSED,
    ];

    protected $primaryKey = 'organization_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'status',
        'database_limit',
        'details',
        'settings',
    ];

    protected $casts = [
        'database_limit' => 'integer',
        'details' => 'array',
        'settings' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::ORGANIZATIONS);
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            if (blank($organization->organization_id)) {
                $organization->organization_id = (string) Str::uuid();
            }
        });
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'organization_id', 'organization_id');
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', OrganizationMembership::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function activeMembershipFor(User $user): ?OrganizationMembership
    {
        $membership = $this->activeMemberships()
            ->where('user_id', $user->getKey())
            ->first();

        return $membership instanceof OrganizationMembership ? $membership : null;
    }
}
