<?php
// Targeted contract harness: real image Laravel/Eloquent/PluginManager, isolated
// in-memory SQLite fixtures; no production bootstrap, credentials or network.
require '/www/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use App\Services\Plugin\PluginManager;
use Plugin\FakaBridge\Services\FakaBridgeConfig;
use Plugin\FakaBridge\Services\SignedProtocolV2;

$app = new Application('/www');
$app->instance('config', new Repository());
$app->instance('events', new Dispatcher($app));
$app->instance('files', new Filesystem());
$app->instance('log', new Psr\Log\NullLogger());
$db = new Capsule($app);
$mysqlSocket=getenv('FAKABRIDGE_MYSQL_SOCKET');
$sqliteFile=getenv('FAKABRIDGE_SQLITE_FILE') ?: null;
if ($sqliteFile) touch($sqliteFile);
if ($sqliteFile) require __DIR__.'/sqlite-writer-barrier.php';
$db->addConnection($mysqlSocket ? ['driver'=>'mysql','unix_socket'=>$mysqlSocket,'database'=>'faka_reset_test','username'=>'b2_test','password'=>'b2_test',
    'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','strict'=>true] : ['driver'=>'sqlite','database'=>$sqliteFile ?: ':memory:',
    'foreign_key_constraints'=>true,'journal_mode'=>$sqliteFile?'WAL':null,'synchronous'=>'NORMAL','busy_timeout'=>2000]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($app);
$repo = new DatabaseMigrationRepository($db->getDatabaseManager(), 'migrations');
$app->instance('migrator', new Migrator($repo, $db->getDatabaseManager(), $app['files'], $app['events']));


use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Plugin\FakaBridge\Services\TrafficResetService;
use App\Services\Plugin\HookManager;
use Plugin\FakaBridge\Services\PaidResetService;
require '/tmp/atomic-reset.php';
if (!$sqliteFile) $app->instance('fakabridge.isolated-contract-test', true);
$db->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.array', ['driver'=>'array']);
$app['config']->set('app.timezone', 'UTC');
$app->singleton('cache', fn () => new CacheManager($app));
$app->instance(Illuminate\Contracts\Bus\Dispatcher::class, new Illuminate\Bus\Dispatcher($app));
// Core helpers read settings from the container; no production settings loader.
function admin_setting($key, $default = null) { return $default; }
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAILED: '.$name); $checks++; }
$manager = new PluginManager();
(new ReflectionMethod($manager, 'runMigrations'))->invoke($manager, 'faka_bridge');
Schema::create('v2_user', function (Blueprint $t) {
    $t->bigInteger('id')->primary(); $t->string('email')->unique(); $t->string('token')->default('token');
    $t->bigInteger('plan_id')->nullable(); $t->boolean('banned')->default(false);
    foreach (['transfer_enable','u','d','expired_at','next_reset_at','last_reset_at','reset_count','created_at','updated_at',
        'group_id','speed_limit','device_limit','invite_user_id'] as $c) $t->bigInteger($c)->nullable();
    $t->integer('balance')->default(0); $t->integer('discount')->default(0);
});
Schema::create('v2_plan', function (Blueprint $t) {
    $t->id(); $t->string('name'); $t->integer('reset_traffic_method')->nullable(); $t->text('prices');
    $t->boolean('show')->default(true); $t->boolean('sell')->default(true); $t->boolean('renew')->default(true);
    $t->integer('capacity_limit')->nullable();
    foreach (['group_id','speed_limit','device_limit'] as $c) $t->integer($c)->nullable();
});
Schema::create('v2_order', function (Blueprint $t) {
    $t->id(); $t->string('trade_no',64)->unique(); $t->bigInteger('user_id'); $t->bigInteger('plan_id');
    $t->integer('status')->default(0); $t->integer('type'); $t->string('period');
    foreach (['total_amount','balance_amount','surplus_credit','surplus_amount','discount_amount','commission_balance'] as $c) $t->integer($c)->default(0);
    foreach (['invite_user_id','paid_at','created_at','updated_at'] as $c) $t->bigInteger($c)->nullable();
    $t->string('callback_no')->nullable(); $t->text('surplus_order_ids')->nullable();
});
require '/www/database/migrations/2025_06_21_000002_create_traffic_reset_logs_table.php';
(new CreateTrafficResetLogsTable())->up();
DB::table('v2_plan')->insert(['id'=>3,'name'=>'standard','reset_traffic_method'=>1,
    'prices'=>json_encode(['monthly'=>10,'reset_traffic'=>5]),'capacity_limit'=>0]);
function createUser(string $id, string $email): void {
    DB::table('v2_user')->insert(['id'=>$id,'email'=>$email,'plan_id'=>3,'transfer_enable'=>1000,'u'=>100,'d'=>20,
        'expired_at'=>null,'reset_count'=>0]);
}
$accountId='9007199254740993';
createUser($accountId, 'current@example.com'); createUser('2', 'old@example.com');
createUser('3', 'false@example.com'); createUser('4', 'veto@example.com'); createUser('5', 'crash@example.com');
$cfg = new class extends FakaBridgeConfig {
    public array $values = ['hmac_secret'=>'test-v2-client','enabled'=>true,'v2_read_enabled'=>true,'v2_paid_reset_enabled'=>true,'v2_client_id'=>'monexus'];
    public function all(): array { return $this->values; }
    public function forV2Request(): FakaBridgeConfig { return clone $this; }
};
$app->instance(FakaBridgeConfig::class, $cfg);
$router = new Router($app['events'], $app);
$router->middlewareGroup('api', []); $app->instance('router', $router);
require '/www/plugins/FakaBridge/routes/api.php';
function dispatch(string $action, array $body): array {
    global $app,$router;
    $path='/plugin/faka-bridge/v2/'.$action; $time=(int)floor(microtime(true)*1000); $nonce=bin2hex(random_bytes(16));
    $raw=json_encode(['request_id'=>'00000000-0000-4000-8000-000000000001']+$body, JSON_THROW_ON_ERROR);
    $signature=SignedProtocolV2::sign('xboard','POST',$path,$time,$nonce,$raw,'test-v2-client');
    $r=Request::create($path,'POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_X_MONEXUS_SIGNATURE_VERSION'=>'2',
        'HTTP_X_MONEXUS_TIMESTAMP'=>(string)$time,'HTTP_X_MONEXUS_NONCE'=>$nonce,'HTTP_X_MONEXUS_SIGNATURE'=>$signature],$raw);
    $app->instance('request',$r); $response=$router->dispatch($r);
    check(str_contains($response->headers->get('cache-control'),'no-store'),'no-store');
    $result=json_decode($response->getContent(),true,16,JSON_THROW_ON_ERROR);
    return [$response->getStatusCode(),$result];
}
function payload(string $id, string $no): array {
    return ['order_no'=>$no,'user_id'=>$id,'sku'=>'plan-3-reset_traffic','expected_plan_id'=>'3','period'=>'reset_traffic'];
}
$v=json_decode(file_get_contents(__DIR__.'/fixtures/paid-reset-v2.json'),true,16,JSON_THROW_ON_ERROR);
check(PaidResetService::payloadHash($v['payload'],$v['clientId'])===$v['payloadHash'],'cross-language semantic hash');
check(!dispatch('capabilities',[])[1]['capabilities']['reset_idempotency'],'paid enabled does not enable free');
if (!$mysqlSocket) {
    $app->offsetUnset('fakabridge.isolated-contract-test');
    check(dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id']===(bool)$sqliteFile,'only durable WAL SQLite advertises ordinary paid writes');
    if (!$sqliteFile) $app->instance('fakabridge.isolated-contract-test', true);
}
check(dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'],'exact native and order core advertise paid capability');
$p=payload($accountId,'MN-paid-1');
check(dispatch('paid-reset-preview',['user_id'=>$accountId,'sku'=>$p['sku']])[1]['preview']['eligible'],'full capacity does not prevent existing-account reset');
check(dispatch('paid-reset-preview',['user_id'=>$accountId,'sku'=>'plan-3-monthly'])[0]===400,'normal subscription SKU denied');
check(dispatch('paid-reset',$p+['email'=>'old@example.com'])[0]===400,'no email identity accepted');
check(dispatch('paid-reset',$p+['extra'=>true])[0]===400,'strict fields');
$bad=$p; $bad['user_id']=9007199254740993;
check(dispatch('paid-reset',$bad)[0]===400,'numeric identity denied');
$hookCount=0;
HookManager::register('traffic.reset.after',function ($user) use (&$hookCount) {
    check(DB::transactionLevel()===0,'native hook waits for commercial commit');
    check(DB::table('faka_orders')->where('xboard_user_id',(string)$user->id)->whereNotNull('result_payload')->exists(),'hook observes committed bridge evidence');
    $hookCount++;
});
[$code,$done]=dispatch('paid-reset',$p);
check($code===200 && $done['operation']['status']==='completed','real createFromRequest + paid + dispatchSync + open succeeds');
check($done['operation']['user_id']===$accountId && $done['operation']['before']['u']==='100' && $done['operation']['after']['reset_count']==='1','stable ID and immutable before/after');
check(DB::table('v2_user')->where('id',2)->value('u')===100 && DB::table('v2_user')->count()===5,'old email owner unchanged, no user creation');
check(DB::table('v2_order')->count()===1 && DB::table('v2_traffic_reset_logs')->count()===1 && $hookCount===1,'one commercial order, log and hook');
$coreOrder=DB::table('v2_order')->first();
check((string)$coreOrder->user_id===$accountId && $coreOrder->total_amount===500 && $coreOrder->type===4 && $coreOrder->status===3,'native commercial semantics preserved');
DB::table('v2_user')->where('id',$accountId)->update(['email'=>'renamed@example.com','u'=>8]);
check(dispatch('paid-reset',$p)[1]['operation']===$done['operation'],'replay original result after email/usage change');
check(dispatch('paid-reset-status',$p)[1]['operation']===$done['operation'],'status returns recorded evidence');
check(DB::table('v2_user')->where('id',$accountId)->value('u')===8,'replay leaves fresh usage');
foreach (['user_id'=>'2','sku'=>'plan-4-reset_traffic','expected_plan_id'=>'4'] as $key=>$value) {
    $changed=$p; $changed[$key]=$value;
    check(dispatch('paid-reset',$changed)[0]===409 && dispatch('paid-reset-revoke',$changed)[0]===409,'changed frozen semantics denied');
}
$cfg->values['v2_client_id']='other';
check(dispatch('paid-reset-status',$p)[0]===409,'client rotation cannot take over old order');
$cfg->values['v2_client_id']='monexus';
$cancel=payload('2','MN-cancel');
check(dispatch('paid-reset-status',$cancel)[1]['operation']['status']==='not_found','missing is explicitly nonterminal');
check(dispatch('paid-reset-revoke',$cancel)[1]['operation']['status']==='revoked','unknown revoke records tombstone');
check(dispatch('paid-reset',$cancel)[1]['operation']['status']==='revoked','late execute cannot bypass tombstone');
$revoked=dispatch('paid-reset-revoke',$p)[1]['operation'];
check($revoked['status']==='revoked' && $revoked['before']===$done['operation']['before'] && $revoked['after']===$done['operation']['after'],'revoke preserves reset history');
check(DB::table('v2_user')->where('id',$accountId)->value('u')===8 && DB::table('v2_user')->where('id',$accountId)->value('expired_at')===null,'refund never expires original subscription');
check(dispatch('paid-reset',$p)[1]['operation']===$revoked,'refunded order cannot execute again');
$cfg->values['v2_paid_reset_enabled']=false;
check(!dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'],'paid gate disabled');
check(dispatch('paid-reset-status',$p)[1]['operation']===$revoked,'status independent of write gate');
check(dispatch('paid-reset-revoke',payload('2','MN-off-cancel'))[1]['operation']['status']==='revoked','tombstone independent of write gate');
check(dispatch('paid-reset',payload('2','MN-off'))[0]===503,'disabled rejects new execution');
$cfg->values['v2_paid_reset_enabled']=true;
// A commercial open that ignores native false must roll back its own apparent success.
$app->instance(App\Services\TrafficResetService::class,new class extends App\Services\TrafficResetService {
    public function performReset(App\Models\User $user,string $triggerSource='manual'): bool { return false; }
});
$false=payload('3','MN-false');
check(dispatch('paid-reset',$false)[0]===503,'native false cannot complete commercial reset');
check(DB::table('v2_order')->count()===1 && DB::table('v2_traffic_reset_logs')->count()===1,'native false rolls back commercial order and logs');
check(!DB::table('faka_orders')->where('external_order_no','MN-false')->exists(),'native false rolls back bridge claim');
$app->offsetUnset(App\Services\TrafficResetService::class);
App\Models\User::updating(fn () => false);
check(dispatch('paid-reset',payload('4','MN-veto'))[0]===503,'Eloquent save veto is not success');
App\Models\User::flushEventListeners();
check(DB::table('v2_order')->count()===1 && DB::table('v2_user')->where('id',4)->value('reset_count')===0,'save veto rolls back all effects');
$crash=true;
DB::listen(function ($event) use (&$crash) {
    if ($crash && str_contains(str_replace(['"','`'],'',$event->sql),'update faka_orders') && str_contains($event->sql,'result_payload')) throw new RuntimeException('before outer commit');
});
$crashPayload=payload('5','MN-crash');
check(dispatch('paid-reset',$crashPayload)[0]===503,'post-core crash does not report success');
check(DB::table('v2_order')->count()===1 && DB::table('v2_traffic_reset_logs')->count()===1 && $hookCount===1,'crash rolls back order/native log, no external native hook');
check(DB::table('v2_user')->where('id',5)->value('u')===100 && DB::table('v2_user')->where('id',5)->value('reset_count')===0,'crash rolls back usage and count');
$crash=false;
check(dispatch('paid-reset',$crashPayload)[1]['operation']['status']==='completed','same request safely retries after rollback');
// Business rejection is durable, unlike an unknown transport result.
DB::table('v2_user')->where('id',3)->update(['expired_at'=>0]);
check(dispatch('paid-reset',$false)[1]['operation']['error_code']==='SUBSCRIPTION_INELIGIBLE','expired target rejected durably');
DB::table('v2_user')->where('id',3)->update(['expired_at'=>null]);
check(dispatch('paid-reset',$false)[1]['operation']['status']==='failed','rejected order cannot later execute after account changes');
DB::table('v2_plan')->where('id',3)->update(['prices'=>json_encode(['reset_traffic'=>0])]);
check(dispatch('paid-reset-preview',['user_id'=>'2','sku'=>'plan-3-reset_traffic'])[1]['preview']['block_reason']==='RESET_NOT_FOR_SALE','missing paid price denied');
DB::table('v2_plan')->where('id',3)->update(['prices'=>json_encode(['reset_traffic'=>5])]);
// Cross-protocol collisions cannot downgrade to email based handling.
$legacy=new Plugin\FakaBridge\Services\FakaOrderService($cfg);
foreach (['revoke','paid'] as $method) {
    $rejected=false;
    try { $method==='revoke' ? $legacy->revokeOrder($p['order_no']) : $legacy->handleOrder($p['order_no'],'old@example.com',$p['sku']); }
    catch (App\Exceptions\ApiException) { $rejected=true; }
    check($rejected,'v1 '.$method.' cannot handle v2 order');
}
DB::table('faka_orders')->insert(['external_order_no'=>'MN-legacy','email'=>'old@example.com','sku'=>'plan-3-monthly',
    'plan_id'=>3,'period'=>'monthly','status'=>'completed','xboard_trade_no'=>'old-trade']);
check(dispatch('paid-reset',payload('2','MN-legacy'))[0]===409,'v2 cannot claim existing v1 number');
DB::table('faka_orders')->insert(['external_order_no'=>'MN-legacy-reset','email'=>'old@example.com','sku'=>'plan-3-reset_traffic',
    'plan_id'=>3,'period'=>'reset_traffic','status'=>'completed','xboard_trade_no'=>'old-reset-trade']);
check($legacy->revokeOrder('MN-legacy-reset')['expired_user']===false && DB::table('v2_user')->where('id',2)->value('expired_at')===null,'legacy reset refund also leaves subscription active');

if ($mysqlSocket || $sqliteFile) {
    function race(array $commands, ?int $leader = null): array {
        global $sqliteFile;
        if ($sqliteFile && $leader === null) $leader = 0;
        $tag=bin2hex(random_bytes(8)); $paths=[]; $pids=[];
        DB::disconnect();
        foreach ($commands as $i => [$action,$body]) {
            $path='/tmp/paid-race-'.$tag.'-'.$i; $paths[]=$path;
            $pid=pcntl_fork();
            if ($pid===-1) throw new RuntimeException('fork unavailable');
            if ($pid===0) {
                DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
                if ($leader !== null) {
                    if ($i===$leader) {
                        DB::listen(function ($event) use ($tag,$body,$sqliteFile) {
                            $sql=str_replace(['"','`'],'',$event->sql);
                            if ((str_contains($sql,'insert ignore into faka_orders') || str_contains($sql,'insert or ignore into faka_orders')) && in_array($body['order_no'],$event->bindings,true)) {
                                if ($sqliteFile) sqliteWriterBarrier('/tmp/paid-race-'.$tag);
                                else { file_put_contents('/tmp/paid-race-'.$tag.'.ready','1'); usleep(250000); }
                            }
                        });
                    } else {
                        if ($sqliteFile) sqliteContender('/tmp/paid-race-'.$tag);
                        else {
                            $ready=false;
                            for ($wait=0;$wait<500;$wait++) {
                                if (file_exists('/tmp/paid-race-'.$tag.'.ready')) { $ready=true; break; }
                                usleep(10000);
                            }
                            if (!$ready) exit(2);
                        }
                    }
                }
                try { file_put_contents($path,json_encode(dispatch($action,$body),JSON_THROW_ON_ERROR)); exit(0); }
                catch (\Throwable $e) { file_put_contents($path,json_encode(['failure'=>$e::class])); exit(1); }
            }
            $pids[]=$pid;
        }
        foreach ($pids as $pid) { pcntl_waitpid($pid,$status); check(pcntl_wexitstatus($status)===0,'independent paid process completes'); }
        DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
        $results=array_map(fn ($p)=>json_decode(file_get_contents($p),true,16,JSON_THROW_ON_ERROR),$paths);
        foreach ($paths as $p) unlink($p);
        if ($sqliteFile) {
            check(is_file('/tmp/paid-race-'.$tag.'.contended'),'SQLite competing paid connection observed a held write lock');
            sqliteBarrierCleanup('/tmp/paid-race-'.$tag);
        } else @unlink('/tmp/paid-race-'.$tag.'.ready');
        return $results;
    }
    foreach ([6,7,8] as $id) createUser((string)$id,'race'.$id.'@example.com');
    foreach ([0=>6,1=>7] as $leader=>$id) {
        $payload=payload((string)$id,'MN-race-'.$id);
        $r=race([['paid-reset',$payload],['paid-reset-revoke',$payload]],$leader);
        check($r[0][0]===200 && $r[1][0]===200,'execute/revoke race completes both commands');
        check($r[0][1]['operation']['status']===($leader===0?'completed':'revoked') && $r[1][1]['operation']['status']==='revoked','row lock determines winning execution');
        check(DB::table('v2_traffic_reset_logs')->where('user_id',$id)->count()===($leader===0?1:0),'execute/revoke winning effect exactly once');
        check(DB::table('v2_user')->where('id',$id)->value('expired_at')===null,'concurrent revoke keeps original subscription');
        $op=dispatch('paid-reset-status',$payload)[1]['operation'];
        check($op['status']==='revoked' && ($op['after']!==null)===($leader===0),'status preserves evidence only when execution won');
    }
    $payload=payload('8','MN-race-duplicate');
    $r=race([['paid-reset',$payload],['paid-reset',$payload]]);
    check($r[0][0]===200 && $r[1][0]===200 && $r[0][1]['operation']===$r[1][1]['operation'],'duplicate execute returns the same committed evidence');
    check(DB::table('v2_order')->where('user_id',8)->count()===1 && DB::table('v2_traffic_reset_logs')->where('user_id',8)->count()===1,'duplicate execute creates one commercial order and reset');
    // Changing an engine must remove capability, rather than weakening rollback.
    if ($mysqlSocket) {
        DB::statement('ALTER TABLE v2_order ENGINE=MyISAM');
        check(!dispatch('capabilities',[])[1]['capabilities']['paid_reset_by_user_id'],'nontransactional commercial table disables writes');
    }
}
if ($sqliteFile) require __DIR__.'/sqlite-reset-concurrency.php';
echo json_encode(['ok'=>true,'checks'=>$checks,'patched_native_core'=>true,'real_commercial_core'=>true,
    'database'=>DB::getDriverName(),'full_core_bootstrap'=>false],JSON_THROW_ON_ERROR),"\n";
