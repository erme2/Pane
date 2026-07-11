<?php

namespace App\Models;

use App\Mappers\AbstractMapper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Class User
 * This is the user model, it is here because it is the default model for the authentication
 * system that comes with Laravel. I am not sure what we will do with it yet.
 *
 * @package App\Models
 */

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $primaryKey = 'user_id';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['users'];
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
}
