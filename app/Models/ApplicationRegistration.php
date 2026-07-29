<?php

namespace App\Models;

use App\Support\PaneTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $application_id
 * @property string|null $organization_id
 * @property string $name
 * @property string $kind
 * @property string $trusted_origin
 * @property array<int, string> $redirect_uris
 * @property string $status
 * @property array<string, mixed>|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApplicationRegistration extends Model
{
    public const string KIND_LATTE = 'latte';

    public const string KIND_BURRO = 'burro';

    public const array KINDS = [
        self::KIND_LATTE,
        self::KIND_BURRO,
    ];

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DISABLED = 'disabled';

    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
    ];

    protected $primaryKey = 'application_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'application_id',
        'organization_id',
        'name',
        'kind',
        'trusted_origin',
        'redirect_uris',
        'status',
        'details',
    ];

    protected $casts = [
        'redirect_uris' => 'array',
        'details' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::APPLICATIONS);
    }

    protected static function booted(): void
    {
        static::creating(function (ApplicationRegistration $application): void {
            if (blank($application->application_id)) {
                $application->application_id = (string) Str::uuid();
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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLatte(): bool
    {
        return $this->kind === self::KIND_LATTE;
    }

    public function isBurro(): bool
    {
        return $this->kind === self::KIND_BURRO;
    }
}
