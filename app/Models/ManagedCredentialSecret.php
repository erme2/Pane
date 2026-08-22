<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $managed_credential_secret_id
 * @property string $organization_id
 * @property string|null $connection_id
 * @property string $purpose
 * @property string $status
 * @property array<string, mixed> $envelope
 * @property array<string, mixed> $redacted_envelope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ManagedCredentialSecret extends Model
{
    public const string PURPOSE_DATABASE_CREDENTIALS = 'database_credentials';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_ROTATED = 'rotated';

    public const string STATUS_REVOKED = 'revoked';

    protected $primaryKey = 'managed_credential_secret_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'managed_credential_secret_id',
        'organization_id',
        'connection_id',
        'purpose',
        'status',
        'envelope',
    ];

    protected $casts = [
        'envelope' => 'array',
    ];

    protected $hidden = [
        'envelope',
    ];

    protected $appends = [
        'redacted_envelope',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS);
    }

    protected static function booted(): void
    {
        static::creating(function (ManagedCredentialSecret $secret): void {
            if (blank($secret->managed_credential_secret_id)) {
                $secret->managed_credential_secret_id = (string) Str::uuid();
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
     * @return array<string, mixed>
     */
    public function getRedactedEnvelopeAttribute(): array
    {
        return [
            'version' => $this->envelope['version'] ?? null,
            'algorithm' => $this->envelope['algorithm'] ?? null,
            'key_id' => $this->envelope['key_id'] ?? null,
            'ciphertext_configured' => isset($this->envelope['ciphertext']),
        ];
    }
}
