<?php
// v1 revoke tombstone: an unknown order_no revoked by MoNexus must block a late order-paid.
require '/www/vendor/autoload.php';

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use App\Exceptions\ApiException;
use App\Services\Plugin\PluginManager;
use Plugin\FakaBridge\Services\FakaBridgeConfig;
use Plugin\FakaBridge\Services\FakaOrderService;

$app = new Application('/www');
$app->instance('config', new Repository());
$app->instance('events', new Dispatcher($app));
$app->instance('files', new Filesystem());
$app->instance('log', new Psr\Log\NullLogger());
$db = new Capsule($app);
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($app);
$repo = new DatabaseMigrationRepository($db->getDatabaseManager(), 'migrations');
$app->instance('migrator', new Migrator($repo, $db->getDatabaseManager(), $app['files'], $app['events']));
(new ReflectionMethod(new PluginManager(), 'runMigrations'))->invoke(new PluginManager(), 'faka_bridge');

Schema::create('v2_plan', function (Blueprint $t) { $t->id(); $t->string('name'); });
Schema::create('v2_user', function (Blueprint $t) {
    $t->id(); $t->string('email'); $t->bigInteger('plan_id')->nullable(); $t->bigInteger('expired_at')->nullable();
});
DB::table('v2_plan')->insert(['id' => 3, 'name' => 'basic']);
DB::table('v2_user')->insert(['id' => 1, 'email' => 'other@example.com', 'plan_id' => 3, 'expired_at' => null]);

$checks = 0;
function check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $checks++;
}

$service = new FakaOrderService(new FakaBridgeConfig());

$r = $service->revokeOrder('MN-1', 'gave up');
check($r['status'] === 'revoked' && $r['trade_no'] === null && ($r['tombstone'] ?? false) === true, 'unknown order writes tombstone');
check(DB::table('faka_orders')->where('external_order_no', 'MN-1')->value('status') === 'revoked', 'tombstone persisted');

try {
    $service->handleOrder('MN-1', 'late@example.com', 'plan-3-monthly', 'monthly');
    check(false, 'late order-paid rejected');
} catch (ApiException $e) {
    check(str_contains($e->getMessage(), '已撤销'), 'late order-paid rejected');
}
check(DB::table('v2_user')->where('email', 'late@example.com')->doesntExist(), 'late order-paid created no user');

$again = $service->revokeOrder('MN-1');
check($again['status'] === 'revoked' && !($again['tombstone'] ?? false), 'repeat revoke idempotent');

// A failed claim that never created an Xboard order must not expire a same-plan user.
DB::table('faka_orders')->insert(['external_order_no' => 'MN-2', 'email' => 'other@example.com', 'sku' => 'plan-3-monthly',
    'plan_id' => 3, 'period' => 'monthly', 'status' => 'failed']);
$failed = $service->revokeOrder('MN-2');
check($failed['status'] === 'revoked' && $failed['expired_user'] === false, 'failed row revoked without expiring user');
check(DB::table('v2_user')->where('id', 1)->value('expired_at') === null, 'same-plan user untouched');

echo "fakabridge v1 tombstone: {$checks} checks passed\n";
