<?php
// Included only by the disposable file-WAL fixture, after its commercial tests.
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use Plugin\FakaBridge\Services\NativeResetAdapter;
use App\Services\Plugin\HookManager;

check(!app()->bound('fakabridge.isolated-contract-test'), 'file SQLite uses production gates without a test exemption');
check(DB::selectOne('PRAGMA main.journal_mode')->journal_mode === 'wal', 'real fixture uses production WAL mode');
HookManager::remove('traffic.reset.after');
$sqliteHooks = 0;
HookManager::register('traffic.reset.after', function ($user) use (&$sqliteHooks) {
    check(DB::transactionLevel() === 0, 'SQLite hooks wait for the outer commit');
    check(DB::table('v2_traffic_reset_logs')->where('user_id', $user->id)->exists(), 'SQLite hook observes committed log');
    $sqliteHooks++;
});
$cfg->values['v2_reset_enabled'] = true;
$cfg->values['v2_reset_plan_ids'] = '3';

function freePayload(string $id, string $actor): array {
    $p = dispatch('traffic-reset-preview', ['user_id'=>$id])[1]['preview'];
    return ['operation_id'=>sprintf('00000000-0000-4000-8000-%012d', (int)$id), 'actor_ref'=>$actor,
        'user_id'=>$id, 'benefit_code'=>'welcome_traffic_reset_v1', 'expected_plan_id'=>'3',
        'subscription_fingerprint'=>$p['fingerprint']];
}
function sqlitePair(array $jobs, int $leader, bool $busyResponse = false): array {
    $prefix = '/tmp/sqlite-pair-'.bin2hex(random_bytes(8)); $pids = [];
    DB::disconnect();
    foreach ($jobs as $i=>$job) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
            if ($i === $leader) {
                $paused = false;
                DB::listen(function ($event) use ($prefix, &$paused) {
                    if (!$paused && DB::transactionLevel() > 0 && preg_match('/^(insert|update) /i', $event->sql)) {
                        $paused = true; sqliteWriterBarrier($prefix);
                    }
                });
            } else {
                if ($busyResponse) sqliteWaitFor($prefix.'.ready');
                else sqliteContender($prefix);
            }
            try {
                $result = $job();
                if ($busyResponse && $i !== $leader) file_put_contents($prefix.'.attempted','1');
                file_put_contents($prefix.'.'.$i, json_encode($result, JSON_THROW_ON_ERROR)); exit(0);
            }
            catch (Throwable $e) { file_put_contents($prefix.'.'.$i, json_encode(['failure'=>$e::class])); exit(1); }
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'independent SQLite writer finishes');
    }
    DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    $results = [];
    foreach (array_keys($jobs) as $i) { $results[] = json_decode(file_get_contents($prefix.'.'.$i), true, 16, JSON_THROW_ON_ERROR); unlink($prefix.'.'.$i); }
    if (!$busyResponse) check(is_file($prefix.'.contended'), 'SQLite pair proved write-lock contention');
    sqliteBarrierCleanup($prefix);
    return $results;
}

// Paid-first invalidates the free fingerprint; free-first does not prevent a
// separately purchased reset. The total must match both committed operations.
foreach ([0=>20, 1=>21] as $leader=>$id) {
    createUser((string)$id, 'mixed'.$id.'@example.com');
    $free = freePayload((string)$id, (string)$id); $paid = payload((string)$id, 'MN-mixed-'.$id);
    $r = sqlitePair([fn()=>dispatch('traffic-reset', $free), fn()=>dispatch('paid-reset', $paid)], $leader);
    check($r[0][0] === 200 && $r[1][0] === 200 && $r[1][1]['operation']['status'] === 'completed', 'mixed protocols both finish');
    check($r[0][1]['operation']['status'] === ($leader === 0 ? 'succeeded' : 'failed'), 'mixed writer order freezes free eligibility');
    check(DB::table('v2_user')->where('id',$id)->value('reset_count') === ($leader === 0 ? 2 : 1), 'mixed resets lose no counter updates');
    check(DB::table('v2_traffic_reset_logs')->where('user_id',$id)->count() === ($leader === 0 ? 2 : 1), 'mixed native logs match committed resets');
    check(DB::table('faka_traffic_reset_consumptions')->where('user_id',(string)$id)->count() === ($leader === 0 ? 1 : 0), 'failed free race consumes no opportunity');
}

// Native scheduled reset and bridge execution contend for the same writer.
createUser('22', 'scheduled@example.com');
DB::table('v2_user')->where('id',22)->update(['next_reset_at'=>time()+86400]);
$free = freePayload('22','22'); $stale = App\Models\User::find(22);
DB::table('v2_user')->where('id',22)->update(['next_reset_at'=>time()-1]);
$r = sqlitePair([fn()=>['applied'=>app(App\Services\TrafficResetService::class)->checkAndReset($stale)],
    fn()=>dispatch('traffic-reset',$free)], 0);
check($r[0]['applied'] && $r[1][1]['operation']['error_code'] === 'SUBSCRIPTION_CHANGED', 'scheduled reset wins before stale free intent');
check(DB::table('v2_user')->where('id',22)->value('reset_count') === 1
    && DB::table('v2_traffic_reset_logs')->where('user_id',22)->count() === 1, 'scheduled race has one actual reset');

createUser('24', 'free-before-cron@example.com');
DB::table('v2_user')->where('id',24)->update(['next_reset_at'=>time()+86400]);
$free = freePayload('24','24'); $stale = App\Models\User::find(24);
$stale->next_reset_at = time()-1;
$r = sqlitePair([fn()=>dispatch('traffic-reset',$free),
    fn()=>['applied'=>app(App\Services\TrafficResetService::class)->checkAndReset($stale)]], 0);
check($r[0][1]['operation']['status'] === 'succeeded' && !$r[1]['applied'], 'free reset wins before a stale cron model is revalidated');
check(DB::table('v2_user')->where('id',24)->value('reset_count') === 1
    && DB::table('v2_traffic_reset_logs')->where('user_id',24)->count() === 1, 'free-first scheduled race has one actual reset');

// Two stale native models cannot overwrite each other's counters on SQLite.
createUser('23', 'native@example.com');
$first = App\Models\User::find(23); $second = App\Models\User::find(23);
$r = sqlitePair([fn()=>['applied'=>app(App\Services\TrafficResetService::class)->performReset($first)],
    fn()=>['applied'=>app(App\Services\TrafficResetService::class)->performReset($second)]], 0);
check($r[0]['applied'] && $r[1]['applied'] && DB::table('v2_user')->where('id',23)->value('reset_count') === 2,
    'native writer lock precedes snapshot and preserves both counters');

// Real process death after core+ledger writes, before outer commit. A new
// connection must see no partial reset, then safely execute the original ID.
foreach (['free'=>30, 'paid'=>31] as $kind=>$id) {
    createUser((string)$id, 'killed'.$id.'@example.com');
    $body = $kind === 'free' ? freePayload((string)$id, (string)$id) : payload((string)$id, 'MN-killed-'.$id);
    $action = $kind === 'free' ? 'traffic-reset' : 'paid-reset';
    DB::disconnect(); $pid = pcntl_fork();
    if ($pid === -1) throw new RuntimeException('fork failed');
    if ($pid === 0) {
        DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
        DB::listen(function ($event) use ($kind) {
            $sql = str_replace(['"','`'], '', $event->sql);
            if (($kind === 'free' && str_contains($sql,'update faka_traffic_reset_operations') && in_array('succeeded',$event->bindings,true))
                || ($kind === 'paid' && str_contains($sql,'update faka_orders') && str_contains($sql,'result_payload'))) posix_kill(getmypid(), SIGKILL);
        });
        dispatch($action,$body); exit(3);
    }
    pcntl_waitpid($pid,$status);
    check(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL, 'fixture process is killed inside uncommitted transaction');
    DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
    check(DB::table('v2_user')->where('id',$id)->value('u') === 100 && DB::table('v2_user')->where('id',$id)->value('reset_count') === 0,
        'process death rolls back native traffic and counters');
    check(DB::table('v2_traffic_reset_logs')->where('user_id',$id)->count() === 0
        && DB::table('v2_order')->where('user_id',$id)->count() === 0, 'process death leaves no native log or commercial order');
    check(DB::table('faka_traffic_reset_consumptions')->where('user_id',(string)$id)->count() === 0, 'process death leaves no consumption');
    check(dispatch($kind === 'free' ? 'traffic-reset-status' : 'paid-reset-status',$body)[1]['operation']['status'] === 'not_found', 'process death leaves no committed operation');
    check(dispatch($action,$body)[1]['operation']['status'] === ($kind === 'free' ? 'succeeded' : 'completed'), 'same business ID retries after real process death');
    check(DB::table('v2_user')->where('id',$id)->value('reset_count') === 1, 'post-crash retry applies exactly once');
}

// A busy writer is an unknown/retryable transport result, never an execution
// failure or a second business ID. Give the follower less time than the barrier.
createUser('32', 'busy@example.com');
$busy = payload('32','MN-busy');
$r = sqlitePair([fn()=>DB::transaction(function () {
        DB::table('v2_user')->whereRaw('0 = 1')->update(['id'=>DB::raw('id')]); return ['held'=>true];
    }), function () use ($busy) {
        DB::statement('PRAGMA busy_timeout=20'); return dispatch('paid-reset',$busy);
    }], 0, true);
check($r[1][0] === 503, 'busy database is a retryable 503');
check(dispatch('paid-reset-status',$busy)[1]['operation']['status'] === 'not_found'
    && DB::table('v2_user')->where('id',32)->value('reset_count') === 0, 'busy request commits no execution or terminal failure');
check(dispatch('paid-reset',$busy)[1]['operation']['status'] === 'completed'
    && DB::table('v2_user')->where('id',32)->value('reset_count') === 1, 'same business ID safely retries after writer releases');

// Invalid storage layouts never silently acquire execution capability.
DB::statement("ATTACH DATABASE ':memory:' AS unrelated");
check(!dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'], 'attached databases disable writes');
DB::statement('DETACH DATABASE unrelated');
DB::statement('CREATE TEMP TABLE v2_order (id INTEGER)');
check(!dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'], 'TEMP shadow table disables writes');
DB::statement('DROP TABLE temp.v2_order');
DB::statement('PRAGMA main.synchronous=OFF');
check(!dispatch('capabilities',[])[1]['capabilities']['reset_idempotency'], 'nondurable synchronous OFF disables writes');
DB::statement('PRAGMA main.synchronous=NORMAL');
DB::statement('PRAGMA main.journal_mode=DELETE');
check(!dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'], 'unvalidated non-WAL mode disables writes');
DB::statement('PRAGMA main.journal_mode=WAL');
check((new NativeResetAdapter)->supported(), 'restoring supported storage restores capability');
DB::statement('PRAGMA query_only=ON');
check(!(new NativeResetAdapter)->supported(), 'read-only connection never advertises execution');
DB::statement('PRAGMA query_only=OFF');
