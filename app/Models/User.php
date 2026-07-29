<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Class User
 * This is the user model, it is here because it is the default model for the authentication
 * system that comes with Laravel. I am not sure what we will do with it yet.
 *
 * @property string $name
 * @property string $email
 * @property string $password
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string|null $workos_id
 * @property string|null $workos_organization_id
 * @property array<string, mixed>|null $details
 * @property int $user_type_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const int PANE_ADMINISTRATOR_USER_TYPE_ID = 1;

    public const int STANDARD_USER_TYPE_ID = 2;

    protected $primaryKey = 'user_id';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = (env('DB_TABLE_PREFIX')).AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['users'];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_type_id',
        'name',
        'email',
        'password',
        'workos_id',
        'workos_organization_id',
        'details',
        'settings',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'details' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class, 'user_id', 'user_id');
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function activeOrganizationMemberships(): HasMany
    {
        return $this->organizationMemberships()->where('status', OrganizationMembership::STATUS_ACTIVE);
    }

    public function isPaneAdministrator(): bool
    {
        return (int) $this->user_type_id === self::PANE_ADMINISTRATOR_USER_TYPE_ID
            && (bool) $this->is_active;
    }
}
