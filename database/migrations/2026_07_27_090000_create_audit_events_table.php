<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string APPEND_ONLY_MESSAGE = 'Audit events are append-only.';

    public function up(): void
    {
        $tableName = PaneTable::name(PaneTable::AUDIT_EVENTS);

        Schema::create($tableName, function (Blueprint $table) {
            $table->uuid('audit_event_id')->primary();
            $table->dateTimeTz('occurred_at')->index();
            $table->unsignedInteger('real_actor_user_id')->nullable()->index();
            $table->unsignedInteger('effective_user_id')->nullable()->index();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('action')->index();
            $table->string('outcome')->index();
            $table->json('resource_ids')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->json('client_metadata')->nullable();
            $table->uuid('impersonation_session_id')->nullable()->index();
            $table->uuid('connection_id')->nullable()->index();
            $table->string('table_name')->nullable();
            $table->string('row_key')->nullable();
            $table->json('changed_columns')->nullable();
            $table->json('metadata')->nullable();
        });

        $this->createAppendOnlyTriggers($tableName);
    }

    public function down(): void
    {
        $tableName = PaneTable::name(PaneTable::AUDIT_EVENTS);

        $this->dropAppendOnlyTriggers($tableName);

        Schema::dropIfExists($tableName);
    }

    private function createAppendOnlyTriggers(string $tableName): void
    {
        $driver = DB::connection()->getDriverName();
        $physicalTableName = $this->physicalTableName($tableName);
        $table = $this->quoteIdentifier($physicalTableName);
        $updateTrigger = $this->quoteIdentifier($this->triggerName($physicalTableName, 'prevent_update'));
        $deleteTrigger = $this->quoteIdentifier($this->triggerName($physicalTableName, 'prevent_delete'));
        $message = $this->quoteLiteral(self::APPEND_ONLY_MESSAGE);

        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER $updateTrigger BEFORE UPDATE ON $table BEGIN SELECT RAISE(ABORT, $message); END");
            DB::unprepared("CREATE TRIGGER $deleteTrigger BEFORE DELETE ON $table BEGIN SELECT RAISE(ABORT, $message); END");

            return;
        }

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::unprepared("CREATE TRIGGER $updateTrigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $message; END");
            DB::unprepared("CREATE TRIGGER $deleteTrigger BEFORE DELETE ON $table FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = $message; END");

            return;
        }

        throw new RuntimeException("Unsupported audit append-only trigger driver [$driver].");
    }

    private function dropAppendOnlyTriggers(string $tableName): void
    {
        $physicalTableName = $this->physicalTableName($tableName);

        foreach (['prevent_update', 'prevent_delete'] as $suffix) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$this->quoteIdentifier($this->triggerName($physicalTableName, $suffix)));
        }
    }

    private function physicalTableName(string $tableName): string
    {
        return DB::connection()->getTablePrefix().$tableName;
    }

    private function triggerName(string $tableName, string $suffix): string
    {
        return $tableName.'_'.$suffix;
    }

    private function quoteIdentifier(string $identifier): string
    {
        $quote = DB::connection()->getDriverName() === 'sqlite' ? '"' : '`';

        return $quote.str_replace($quote, $quote.$quote, $identifier).$quote;
    }

    private function quoteLiteral(string $literal): string
    {
        return "'".str_replace("'", "''", $literal)."'";
    }
};
