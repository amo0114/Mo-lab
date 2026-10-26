<?php
namespace Plugin\FakaBridge\Services;

use App\Models\{User,Plan,TrafficResetLog};
use App\Services\TrafficResetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exact, audited core adapter. Unknown core bytes never get write capability. */
class NativeResetAdapter
{
    public const CORE_SHA256 = '75feffb645e22def3f2afb8789b141cb848e5b970e9bc468d5f704993c5798c9';
    public function supported(): bool
    {
        if (!class_exists(TrafficResetService::class)) return false;
        $file = (new \ReflectionClass(TrafficResetService::class))->getFileName();
        if (!$file || hash_file('sha256', $file) !== self::CORE_SHA256) return false;
        if (!Schema::hasColumns('v2_user', ['last_reset_at', 'reset_count', 'next_reset_at'])
            || !Schema::hasTable('v2_traffic_reset_logs')) return false;
        if (!self::transactionalTables(['v2_user','v2_plan','v2_traffic_reset_logs','faka_traffic_reset_operations','faka_traffic_reset_consumptions'])) return false;
        foreach ([new User,new Plan,new TrafficResetLog] as $model) {
            if ($model->getConnection() !== DB::connection()) return false;
        }
        return true;
    }

    /** All effects must participate in one durable database transaction. */
    public static function transactionalTables(array $tables): bool
    {
        try {
            if (DB::getDriverName() === 'mysql') {
                $rows = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())
                    ->whereIn('table_name', $tables)->get([DB::raw('ENGINE AS engine')]);
                return $rows->count() === count($tables) && !$rows->contains(fn ($r) => strtolower($r->engine) !== 'innodb');
            }
            if (DB::getDriverName() !== 'sqlite') return false;
            $databases = DB::select('PRAGMA database_list');
            $main = null;
            foreach ($databases as $database) {
                if (!in_array($database->name, ['main','temp'], true)) return false;
                if ($database->name === 'main') $main = $database;
            }
            if (!$main) return false;
            // The in-memory exemption remains private to isolated contract tests.
            if ($main->file === '') {
                return app()->bound('fakabridge.isolated-contract-test')
                    && app()->make('fakabridge.isolated-contract-test') === true;
            }
            if (!is_file($main->file)
                || strtolower((string) DB::selectOne('PRAGMA main.journal_mode')->journal_mode) !== 'wal'
                || (int) DB::selectOne('PRAGMA main.synchronous')->synchronous < 1
                || (int) DB::selectOne('PRAGMA query_only')->query_only !== 0) return false;
            $parameters = implode(',', array_fill(0, count($tables), '?'));
            // TEMP tables can shadow main even when model connection names match.
            if (DB::select('SELECT name FROM sqlite_temp_master WHERE name IN ('.$parameters.')', $tables)) return false;
            return count(DB::select("SELECT name FROM main.sqlite_master WHERE type='table' AND name IN (".$parameters.')', $tables)) === count($tables);
        } catch (\Throwable) {
            return false;
        }
    }

    public function reset(User $user): void
    {
        if (!(app(TrafficResetService::class)->performReset($user, 'manual'))) {
            throw new \RuntimeException('native reset failed');
        }
    }
}
