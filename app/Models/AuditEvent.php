<?php

namespace App\Models;

use App\Support\PaneTable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $audit_event_id
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property int|null $real_actor_user_id
 * @property int|null $effective_user_id
 * @property string|null $organization_id
 * @property string $action
 * @property string $outcome
 * @property array<string, mixed>|null $resource_ids
 * @property string|null $request_id
 * @property array<string, mixed>|null $client_metadata
 * @property string|null $impersonation_session_id
 * @property string|null $connection_id
 * @property string|null $table_name
 * @property string|null $row_key
 * @property array<int, string>|null $changed_columns
 * @property array<string, mixed>|null $metadata
 */
class AuditEvent extends Model
{
    public const string OUTCOME_SUCCESS = 'success';

    public const string OUTCOME_DENIED = 'denied';

    public const string OUTCOME_FAILURE = 'failure';

    public const array OUTCOMES = [
        self::OUTCOME_SUCCESS,
        self::OUTCOME_DENIED,
        self::OUTCOME_FAILURE,
    ];

    protected $primaryKey = 'audit_event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'audit_event_id',
        'occurred_at',
        'real_actor_user_id',
        'effective_user_id',
        'organization_id',
        'action',
        'outcome',
        'resource_ids',
        'request_id',
        'client_metadata',
        'impersonation_session_id',
        'connection_id',
        'table_name',
        'row_key',
        'changed_columns',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resource_ids' => 'array',
        'client_metadata' => 'array',
        'changed_columns' => 'array',
        'metadata' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = PaneTable::name(PaneTable::AUDIT_EVENTS);
    }

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            if (blank($event->audit_event_id)) {
                $event->audit_event_id = (string) Str::uuid();
            }
        });

        static::updating(function (): void {
            throw new DomainException('Audit events are append-only.');
        });

        static::deleting(function (): void {
            throw new DomainException('Audit events are append-only.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function realActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'real_actor_user_id', 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function effectiveUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'effective_user_id', 'user_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'organization_id');
    }
}
