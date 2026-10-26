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
require '/tmp/atomic-reset.php';
if (!$sqliteFile) $app->instance('fakabridge.isolated-contract-test', true);
$db->getConnection()->setTransactionManager(new DatabaseTransactionsManager());
$app['config']->set('cache.default', 'array');
$app['config']->set('cache.stores.array', ['driver'=>'array']);
$app['config']->set('app.timezone', 'UTC');
$app->singleton('cache', fn () => new CacheManager($app));
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException('FAILED: '.$name); $checks++; }
$manager = new PluginManager();
(new ReflectionMethod($manager, 'runMigrations'))->invoke($manager, 'faka_bridge');
Schema::create('v2_user', function (Blueprint $t) {
    $t->bigInteger('id')->primary(); $t->string('email'); $t->string('token')->default('token');
    $t->bigInteger('plan_id')->nullable(); $t->boolean('banned')->default(false);
    foreach (['transfer_enable','u','d','expired_at','next_reset_at','last_reset_at','reset_count','created_at','updated_at'] as $c) $t->bigInteger($c)->nullable();
});
Schema::create('v2_plan', function (Blueprint $t) { $t->id(); $t->string('name'); $t->integer('reset_traffic_method'); });
Schema::create('v2_order', function (Blueprint $t) { $t->id(); $t->string('trade_no'); $t->bigInteger('user_id'); $t->integer('status'); });
require '/www/database/migrations/2025_06_21_000002_create_traffic_reset_logs_table.php';
(new CreateTrafficResetLogsTable())->up();
DB::table('v2_plan')->insert(['id'=>3,'name'=>'standard','reset_traffic_method'=>2]);
for ($id=1;$id<=3;$id++) DB::table('v2_user')->insert(['id'=>$id,'email'=>'user'.$id.'@example.com','plan_id'=>3,
    'transfer_enable'=>1000,'u'=>100,'d'=>20,'expired_at'=>null,'reset_count'=>0]);
$cfg = new class extends FakaBridgeConfig {
    public array $values = ['hmac_secret'=>'test-v2-client','enabled'=>true,'v2_read_enabled'=>true,'v2_reset_enabled'=>true,'v2_client_id'=>'monexus','v2_reset_plan_ids'=>'3'];
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
    return [$response->getStatusCode(),json_decode($response->getContent(),true,16,JSON_THROW_ON_ERROR)];
}
function payload(string $id, string $actor, string $suffix): array {
    $p=dispatch('traffic-reset-preview',['user_id'=>$id])[1]['preview'];
    return ['operation_id'=>'00000000-0000-4000-8000-'.str_pad($suffix,12,'0',STR_PAD_LEFT),'actor_ref'=>$actor,'user_id'=>$id,
        'benefit_code'=>'welcome_traffic_reset_v1','expected_plan_id'=>'3','subscription_fingerprint'=>$p['fingerprint']];
}
if (!$mysqlSocket) {
    $app->offsetUnset('fakabridge.isolated-contract-test');
    check(dispatch('capabilities',[])[1]['capabilities']['reset_idempotency']===(bool)$sqliteFile,'only durable WAL SQLite advertises ordinary write capability');
    if (!$sqliteFile) $app->instance('fakabridge.isolated-contract-test', true);
}
check(dispatch('capabilities',[])[1]['capabilities']['reset_idempotency'],'patched core advertises reset');
$vector=json_decode(file_get_contents(__DIR__.'/fixtures/traffic-reset-v2.json'),true,16,JSON_THROW_ON_ERROR);
check(TrafficResetService::payloadHash($vector['payload'])===$vector['payloadHash'],'independent cross-language payload hash');
check(dispatch('traffic-reset-preview',['user_id'=>'1'])[1]['preview']['fingerprint']===$vector['fingerprint'],'independent subscription fingerprint');
$hookCount=0;
HookManager::register('traffic.reset.after', function ($u) use (&$hookCount) {
    check(DB::transactionLevel()===0, 'hook runs after outer commit');
    check(DB::table('faka_traffic_reset_operations')->where('status','succeeded')->where('user_id',(string)$u->id)->exists(), 'hook observes durable operation');
    $hookCount++;
});
$app->instance(App\Services\TrafficResetService::class, new class extends App\Services\TrafficResetService {
    public function performReset(App\Models\User $user, string $triggerSource = 'manual'): bool { return false; }
});
$nativeFalse=payload('3','88','89');
check(dispatch('traffic-reset',$nativeFalse)[0]===503,'native false aborts outer transaction');
check(DB::table('faka_traffic_reset_consumptions')->count()===0 && DB::table('faka_traffic_reset_operations')->count()===0,'native false persists no operation or consumption');
$app->offsetUnset(App\Services\TrafficResetService::class);
// Eloquent event veto must not consume the grant or report success.
App\Models\User::updating(fn () => false);
$veto=payload('3','99','90');
check(dispatch('traffic-reset',$veto)[0]===503,'native save veto is not success');
check(DB::table('faka_traffic_reset_consumptions')->count()===0 && DB::table('v2_traffic_reset_logs')->count()===0,'veto rolls back consumption and native log');
App\Models\User::flushEventListeners();
$a=payload('1','11','1');
[$code,$done]=dispatch('traffic-reset',$a);
check($code===200 && $done['operation']['status']==='succeeded','native execute succeeds');
check($done['operation']['before']['u']==='100' && $done['operation']['after']['u']==='0','native snapshot');
check(DB::table('v2_traffic_reset_logs')->count()===1 && $hookCount===1,'one native log and hook');
check(DB::table('v2_user')->where('id',1)->value('transfer_enable')===1000 && DB::table('v2_user')->where('id',1)->value('expired_at')===null,'subscription unchanged');
DB::table('v2_user')->where('id',1)->update(['u'=>8]);
check(dispatch('traffic-reset',$a)[1]['operation']===$done['operation'],'replay returns original result');
check(DB::table('v2_user')->where('id',1)->value('u')===8,'replay does not clear new traffic');
DB::table('v2_user')->where('id',1)->update(['next_reset_at'=>time()-1]);
$stale=App\Models\User::find(1);
DB::table('v2_user')->where('id',1)->update(['next_reset_at'=>time()+86400]);
check(!app(App\Services\TrafficResetService::class)->checkAndReset($stale),'delayed cron refreshes schedule under user lock');
check(DB::table('v2_user')->where('id',1)->value('u')===8,'stale schedule does not clear new usage');
check(dispatch('traffic-reset',$a+['extra'=>'bad'])[0]===400,'strict DTO');
$changed=$a; $changed['actor_ref']='12';
check(dispatch('traffic-reset',$changed)[0]===409,'same operation different semantics');
check(dispatch('traffic-reset-cancel',$a)[1]['operation']['status']==='succeeded','cancel cannot undo success');
check(dispatch('traffic-reset',payload('1','12','2'))[1]['operation']['error_code']==='BENEFIT_ALREADY_CONSUMED','remote user lifetime bound');
check(dispatch('traffic-reset',payload('2','11','3'))[1]['operation']['error_code']==='BENEFIT_ALREADY_CONSUMED','actor lifetime across accounts');
$b=payload('2','22','4');
check(dispatch('traffic-reset-status',$b)[1]['operation']['status']==='not_found','missing is not cancellation');
check(dispatch('traffic-reset-cancel',$b)[1]['operation']['status']==='cancelled','cancel creates tombstone');
check(dispatch('traffic-reset',$b)[1]['operation']['status']==='cancelled','late execute cannot pass tombstone');
check(DB::table('v2_user')->where('id',2)->value('u')===100,'cancel leaves traffic');
$c=payload('2','22','5');
DB::table('v2_user')->where('id',2)->update(['reset_count'=>1]);
check(dispatch('traffic-reset',$c)[1]['operation']['error_code']==='SUBSCRIPTION_CHANGED','auto reset fingerprint invalidates old intent');
DB::table('v2_user')->where('id',2)->update(['u'=>0,'d'=>0]);
check(dispatch('traffic-reset-preview',['user_id'=>'2'])[1]['preview']['block_reason']==='NOTHING_TO_RESET','zero usage not consumed');

// Crash AFTER core writes and operation success SQL, BEFORE outer commit.
$crash=true;
DB::listen(function ($event) use (&$crash) {
    if ($crash && str_contains(str_replace(['"','`'],'',$event->sql),'update faka_traffic_reset_operations') && in_array('succeeded',$event->bindings,true)) throw new RuntimeException('injected before outer commit');
});
$d=payload('3','33','6');
check(dispatch('traffic-reset',$d)[0]===503,'post-core crash refuses success');
check(DB::table('v2_user')->where('id',3)->value('u')===100 && DB::table('v2_user')->where('id',3)->value('reset_count')===0,'crash rolls back core usage and counter');
check(DB::table('v2_traffic_reset_logs')->count()===1 && $hookCount===1,'crash rolls back log and defers hook');
check(dispatch('traffic-reset-status',$d)[1]['operation']['status']==='not_found','crash rolls back operation');
check(DB::table('faka_traffic_reset_consumptions')->count()===1,'crash rolls back consumption');
$crash=false;
check(dispatch('traffic-reset',$d)[1]['operation']['status']==='succeeded','same operation can safely retry after full rollback');
check(DB::table('v2_traffic_reset_logs')->count()===2 && $hookCount===2,'retry exactly one new effect');
$cfg->values['v2_reset_enabled']=false;
check(!dispatch('capabilities',[])[1]['capabilities']['reset_idempotency'],'execution flag disables new writes');
check(dispatch('traffic-reset-status',$d)[1]['operation']['status']==='succeeded','status stays readable when writes off');
$e=$b; $e['operation_id']='00000000-0000-4000-8000-000000000007';
check(dispatch('traffic-reset-cancel',$e)[1]['operation']['status']==='cancelled','cancel stays available when writes off');
check(DB::table('v2_order')->count()===0 && DB::table('v2_user')->count()===3,'free path creates no commercial orders or users');

if ($mysqlSocket || $sqliteFile) {
    $cfg->values['v2_reset_enabled']=true;
    function race(array $commands, ?int $leader = null): array {
        global $db,$sqliteFile;
        if ($sqliteFile && $leader === null) $leader = 0;
        $tag=bin2hex(random_bytes(8)); $paths=[]; $pids=[];
        DB::disconnect();
        foreach ($commands as $i => [$action,$body]) {
            $path='/tmp/reset-race-'.$tag.'-'.$i; $paths[]=$path;
            $pid=pcntl_fork();
            if ($pid===-1) throw new RuntimeException('fork unavailable');
            if ($pid===0) {
                DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
                if ($leader !== null) {
                    if ($i===$leader) {
                        DB::listen(function ($event) use ($tag,$body,$sqliteFile) {
                            $sql=str_replace(['"','`'],'',$event->sql);
                            if ((str_contains($sql,'insert ignore into faka_traffic_reset_operations') || str_contains($sql,'insert or ignore into faka_traffic_reset_operations')) && in_array($body['operation_id'],$event->bindings,true)) {
                                if ($sqliteFile) sqliteWriterBarrier('/tmp/reset-race-'.$tag);
                                else { file_put_contents('/tmp/reset-race-'.$tag.'.ready','1'); usleep(250000); }
                            }
                        });
                    } else {
                        if ($sqliteFile) sqliteContender('/tmp/reset-race-'.$tag);
                        else {
                            for ($wait=0;$wait<500 && !file_exists('/tmp/reset-race-'.$tag.'.ready');$wait++) usleep(10000);
                            if (!file_exists('/tmp/reset-race-'.$tag.'.ready')) exit(2);
                        }
                    }
                }
                try { file_put_contents($path,json_encode(dispatch($action,$body),JSON_THROW_ON_ERROR)); exit(0); }
                catch (\Throwable $e) { file_put_contents($path,json_encode(['failure'=>$e::class])); exit(1); }
            }
            $pids[]=$pid;
        }
        foreach ($pids as $pid) { pcntl_waitpid($pid,$status); check(pcntl_wexitstatus($status)===0,'independent worker process completes'); }
        DB::reconnect(); DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
        $results=array_map(fn ($p)=>json_decode(file_get_contents($p),true,16,JSON_THROW_ON_ERROR),$paths);
        foreach ($paths as $p) unlink($p);
        if ($sqliteFile) {
            check(is_file('/tmp/reset-race-'.$tag.'.contended'),'SQLite competing connection observed a held write lock');
            sqliteBarrierCleanup('/tmp/reset-race-'.$tag);
        } else @unlink('/tmp/reset-race-'.$tag.'.ready');
        return $results;
    }
    for ($id=4;$id<=9;$id++) DB::table('v2_user')->insert(['id'=>$id,'email'=>'race'.$id.'@example.com','plan_id'=>3,
        'transfer_enable'=>1000,'u'=>100,'d'=>20,'expired_at'=>null,'reset_count'=>0]);
    foreach ([0=>4,1=>5] as $leader=>$id) {
        $p=payload((string)$id,(string)($id+100),(string)($id+100));
        $results=race([['traffic-reset',$p],['traffic-reset-cancel',$p]],$leader);
        $expected=$leader===0?'succeeded':'cancelled';
        check($results[0][0]===200 && $results[1][0]===200 && $results[0][1]['operation']['status']===$expected && $results[1][1]['operation']['status']===$expected,'execute/cancel locked winner is consistent');
        check(DB::table('v2_traffic_reset_logs')->where('user_id',$id)->count()===($leader===0?1:0),'execute/cancel has exactly the winning effect');
    }
    $p=payload('6','600','106'); $q=payload('7','600','107');
    $results=race([['traffic-reset',$p],['traffic-reset',$q]]);
    $states=[$results[0][1]['operation']['status'],$results[1][1]['operation']['status']]; sort($states);
    check($states===['failed','succeeded'],'same actor cannot consume across accounts concurrently');
    check(DB::table('faka_traffic_reset_consumptions')->where('actor_ref','600')->count()===1,'actor unique consumption persisted once');
    $p=payload('8','800','108'); $q=payload('8','801','109');
    $results=race([['traffic-reset',$p],['traffic-reset',$q]]);
    $states=[$results[0][1]['operation']['status'],$results[1][1]['operation']['status']]; sort($states);
    check($states===['failed','succeeded'] && DB::table('v2_traffic_reset_logs')->where('user_id',8)->count()===1,'same remote user cannot consume under two actors concurrently');
    $p=payload('9','900','110');
    $results=race([['traffic-reset',$p],['traffic-reset',$p]]);
    check($results[0][0]===200 && $results[1][0]===200 && $results[0][1]['operation']===$results[1][1]['operation'],'duplicate free execution returns identical committed evidence');
    check(DB::table('v2_traffic_reset_logs')->where('user_id',9)->count()===1 && DB::table('v2_user')->where('id',9)->value('reset_count')===1,'duplicate free execution creates one native effect');
}
echo json_encode(['ok'=>true,'checks'=>$checks,'patched_core'=>true,'database'=>DB::getDriverName(),'full_core_bootstrap'=>false],JSON_THROW_ON_ERROR),"\n";
