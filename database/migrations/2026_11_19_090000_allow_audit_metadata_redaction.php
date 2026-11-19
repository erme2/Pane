<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Personal-data erasure (Pane#173) must be able to redact the email kept in an
 * audit event's `metadata`. The audit table stays append-only otherwise: rows
 * can't be deleted, and an update may change `metadata` and nothing else.
 */
return new class extends Migration
{
    private const string APPEND_ONLY_MESSAGE = 'Audit events are append-only.';

    /** Every audit column except `metadata`: these never change after the insert. */
    private const array IMMUTABLE_COLUMNS = [
        'audit_event_id',
        'occurred_at',
        'real_actor_user_id',
        'effective_user_id',
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
    ];

    public function up(): void
    {
        $this->replaceUpdateTrigger(redactionAllowed: true);
    }

    public function down(): void
    {
        $this->replaceUpdateTrigger(redactionAllowed: false);
    }

    private function replaceUpdateTrigger(bool $redactionAllowed): void
    {
        $driver = DB::connection()->getDriverName();
        $table = $this->quoteIdentifier(DB::connection()->getTablePrefix().PaneTable::name(PaneTable::AUDIT_EVENTS));
        $message = "'".self::APPEND_ONLY_MESSAGE."'";

        // The name the trigger got when the table was created, and the one it keeps
        // on an install whose table was renamed under the prefix later.
        foreach ($this->triggerNames() as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quoteIdentifier($name));
        }

        $trigger = $this->quoteIdentifier($this->triggerNames()[0]);

        if ($driver === 'sqlite') {
            $when = $redactionAllowed ? 'WHEN NOT ('.$this->unchangedCondition('IS').') ' : '';
            DB::unprepared("CREATE TRIGGER $trigger BEFORE UPDATE ON $table {$when}BEGIN SELECT RAISE(ABORT, $message); END");

            return;
        }

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $message;";
            $body = $redactionAllowed
                ? 'IF NOT ('.$this->unchangedCondition('<=>')." ) THEN $signal END IF;"
                : $signal;
            DB::unprepared("CREATE TRIGGER $trigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN $body END");

            return;
        }

        throw new RuntimeException("Unsupported audit append-only trigger driver [$driver].");
    }

    private function unchangedCondition(string $operator): string
    {
        return implode(' AND ', array_map(
            fn (string $column): string => "OLD.$column $operator NEW.$column",
            self::IMMUTABLE_COLUMNS,
        ));
    }

    /**
     * @return array<int, string>
     */
    private function triggerNames(): array
    {
        $prefixed = DB::connection()->getTablePrefix().PaneTable::name(PaneTable::AUDIT_EVENTS);

        return array_values(array_unique([
            $prefixed.'_prevent_update',
            PaneTable::AUDIT_EVENTS.'_prevent_update',
        ]));
    }

    private function quoteIdentifier(string $identifier): string
    {
        $quote = DB::connection()->getDriverName() === 'sqlite' ? '"' : '`';

        return $quote.str_replace($quote, $quote.$quote, $identifier).$quote;
    }
};
