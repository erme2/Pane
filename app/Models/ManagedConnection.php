<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An organization's MySQL or MariaDB database, as Pane connects to it.
 * Secrets (password, certificates) live in ManagedCredentialSecret, never here.
 *
 * @property string $connection_id
 * @property string $organization_id
 * @property string $engine
 * @property string $name
 * @property string|null $description
 * @property string $host
 * @property int $port
 * @property string $database_name
 * @property string $username
 * @property string $tls_mode
 * @property string $status
 * @property string|null $managed_credential_secret_id
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_reachable
 * @property bool|null $last_test_privileges_valid
 * @property int|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagedConnection extends Model
{
    public const array ENGINES = ['mysql', 'mariadb'];

    public const string TLS_DISABLED = 'disabled';

    public const string TLS_REQUIRED = 'required';

    public const string TLS_VERIFY_CA = 'verify_ca';

    public const string TLS_VERIFY_IDENTITY = 'verify_identity';

    public const array TLS_MODES = [self::TLS_DISABLED, self::TLS_REQUIRED, self::TLS_VERIFY_CA, self::TLS_VERIFY_IDENTITY];

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DISABLED = 'disabled';

    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_DISABLED];

    protected $primaryKey = 'connection_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'connection_id', 'organization_id', 'engine', 'name', 'description', 'host', 'port',
        'database_name', 'username', 'tls_mode', 'status', 'managed_credential_secret_id',
        'last_tested_at', 'last_test_reachable', 'last_test_privileges_valid', 'created_by_user_id',
    ];

    protected $casts = [
        'port' => 'integer',
        'last_tested_at' => 'datetime',
        'last_test_reachable' => 'boolean',
        'last_test_privileges_valid' => 'boolean',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MANAGED_CONNECTIONS);
    }

    protected static function booted(): void
    {
        static::creating(function (ManagedConnection $connection): void {
            if (blank($connection->connection_id)) {
                $connection->connection_id = (string) Str::uuid();
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
     * @return BelongsTo<ManagedCredentialSecret, $this>
     */
    public function secret(): BelongsTo
    {
        return $this->belongsTo(ManagedCredentialSecret::class, 'managed_credential_secret_id', 'managed_credential_secret_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function versionTag(): string
    {
        return hash('sha256', implode('|', [
            $this->getKey(), $this->engine, $this->name, $this->description, $this->host, $this->port, $this->database_name,
            $this->username, $this->tls_mode, $this->status, $this->managed_credential_secret_id,
            $this->updated_at?->toJSON(),
        ]));
    }
}
