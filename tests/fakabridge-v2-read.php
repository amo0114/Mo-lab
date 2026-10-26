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
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$db->setAsGlobal();
$db->bootEloquent();
$app->instance('db', $db->getDatabaseManager());
$app->bind('db.schema', fn () => $db->getConnection()->getSchemaBuilder());
Facade::setFacadeApplication($app);
$repo = new DatabaseMigrationRepository($db->getDatabaseManager(), 'migrations');
$app->instance('migrator', new Migrator($repo, $db->getDatabaseManager(), $app['files'], $app['events']));

$checks = 0;
function check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAILED: ' . $name);
    $checks++;
}

// The actual core PluginManager discovers and runs both plugin migrations.
$manager = new PluginManager();
$migrate = new ReflectionMethod($manager, 'runMigrations');
$migrate->invoke($manager, 'faka_bridge');
check(Schema::hasTable('faka_orders') && Schema::hasTable('faka_protocol_nonces'), 'plugin migration discovery');
$migrate->invoke($manager, 'faka_bridge');
check(DB::table('migrations')->count() === 4, 'migration rerun is idempotent');

// Minimal explicit fixtures for the real core models, not replacement models.
Schema::create('v2_user', function (Blueprint $t) {
    $t->bigInteger('id')->primary(); $t->string('email'); $t->string('password')->default('PRIVATE_PASSWORD');
    $t->string('token')->default('PRIVATE_TOKEN'); $t->bigInteger('plan_id')->nullable(); $t->boolean('banned')->default(false);
    foreach (['transfer_enable', 'u', 'd', 'expired_at', 'next_reset_at', 'reset_count'] as $column) $t->bigInteger($column)->nullable();
});
Schema::create('v2_order', function (Blueprint $t) {
    $t->id(); $t->string('trade_no')->unique(); $t->bigInteger('user_id'); $t->integer('status');
});
Schema::create('v2_plan', function (Blueprint $t) { $t->id(); $t->string('name'); });
DB::table('v2_plan')->insert(['id' => 3, 'name' => '标准套餐']);
$originalId = '9007199254740993';
DB::table('v2_user')->insert(['id' => $originalId, 'email' => 'changed@example.com', 'plan_id' => 3, 'banned' => false,
    'transfer_enable' => '9223372036854775807', 'u' => '9007199254740993', 'd' => 1, 'expired_at' => null, 'reset_count' => 7]);
// The old email is now owned by somebody else. It must never be a fallback.
DB::table('v2_user')->insert(['id' => 2, 'email' => 'old@example.com', 'plan_id' => 3,
    'transfer_enable' => 100, 'u' => 0, 'd' => 0, 'expired_at' => null]);
DB::table('v2_order')->insert(['trade_no' => 'TRADE-42', 'user_id' => $originalId, 'status' => 3]);
DB::table('faka_orders')->insert(['external_order_no' => 'MN-42', 'xboard_trade_no' => 'TRADE-42', 'email' => 'old@example.com',
    'sku' => 'plan-3-monthly', 'plan_id' => 3, 'period' => 'monthly', 'status' => 'completed', 'completed_at' => '2026-10-24 00:00:00']);

$cfg = new class extends FakaBridgeConfig {
    public array $values = ['hmac_secret' => 'test-v2-client', 'enabled' => true, 'v2_read_enabled' => true];
    public function all(): array { return $this->values; }
    public function forV2Request(): FakaBridgeConfig { return clone $this; }
};
$app->instance(FakaBridgeConfig::class, $cfg);
$router = new Router($app['events'], $app);
$router->middlewareGroup('api', []); // Full core HTTP middleware is outside this isolated harness.
$app->instance('router', $router);
require '/www/plugins/FakaBridge/routes/api.php';

function signedRequest(string $action, array $body, ?int $timestamp = null, string $method = 'POST', ?string $wirePath = null): Request {
    $path = $wirePath ?? '/plugin/faka-bridge/v2/' . $action;
    $raw = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $time = $timestamp ?? (int) floor(microtime(true) * 1000);
    $nonce = bin2hex(random_bytes(16));
    $sign = SignedProtocolV2::sign('xboard', 'POST', '/plugin/faka-bridge/v2/' . $action, $time, $nonce, $raw, 'test-v2-client');
    return Request::create($path, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_MONEXUS_SIGNATURE_VERSION' => '2',
        'HTTP_X_MONEXUS_TIMESTAMP' => (string) $time, 'HTTP_X_MONEXUS_NONCE' => $nonce, 'HTTP_X_MONEXUS_SIGNATURE' => $sign,
    ], $raw);
}
function dispatch(Request $request): array {
    global $app, $router;
    $app->instance('request', $request);
    $response = $router->dispatch($request);
    check(str_contains($response->headers->get('cache-control'), 'no-store'), 'private response no-store');
    return [$response->getStatusCode(), json_decode($response->getContent(), true, 16, JSON_THROW_ON_ERROR)];
}
$rid = '00000000-0000-4000-8000-000000000001';
$base = ['request_id' => $rid];
$vectors = json_decode(file_get_contents(__DIR__ . '/fixtures/signed-protocol-v2.json'), true, 16, JSON_THROW_ON_ERROR);
foreach ($vectors['vectors'] as $v) {
    check(SignedProtocolV2::sign($v['audience'], $v['method'], $v['path'], $v['timestampMs'], $v['nonce'], $v['rawBody'], $v['secret']) === $v['signature'], 'shared ' . $v['audience'] . ' vector');
}
$v = $vectors['vectors'][1];
foreach ([-300000, 300000] as $offset) {
    check(SignedProtocolV2::verify($v['method'], $v['path'], $v['timestampMs'], $v['nonce'], $v['rawBody'], $v['signature'], $v['secret'], $v['timestampMs'] + $offset), 'inclusive time boundary');
}
check(!SignedProtocolV2::verify($v['method'], $v['path'], $v['timestampMs'], $v['nonce'], '{} ', $v['signature'], $v['secret'], $v['timestampMs']), 'raw bytes tamper');

[$status, $cap] = dispatch(signedRequest('capabilities', $base));
check($status === 200 && $cap['capabilities']['resolve_by_order'] && !$cap['capabilities']['reset_idempotency'], 'only read capabilities');
check($cap['plugin_version'] === '1.8.0' && strlen($cap['build_id']) === 64, 'version and deterministic build identity');
$same = signedRequest('capabilities', $base);
check(dispatch($same)[0] === 200 && dispatch($same)[0] === 409, 'replay rejected across receiver instances');
foreach (['timestamp' => '01', 'signature-version' => '1', 'signature' => str_repeat('a', 64)] as $key => $value) {
    $r = signedRequest('capabilities', $base); $r->headers->set('x-monexus-' . $key, $value);
    check(dispatch($r)[0] === 401, 'reject malformed ' . $key);
}
$r = signedRequest('capabilities', $base); $r->headers->set('x-monexus-nonce', ['a', 'b']);
check(dispatch($r)[0] === 401, 'duplicate signature headers rejected');
$r = signedRequest('capabilities', $base); $r->headers->set('content-type', 'text/plain');
check(dispatch($r)[0] === 415, 'content type rejected');
check(dispatch(signedRequest('capabilities', $base, (int) floor(microtime(true) * 1000) - 300100))[0] === 401, 'expired packet');
check(dispatch(signedRequest('capabilities', $base, null, 'POST', '/plugin/faka-bridge/v2/capabilities?x=1'))[0] === 401, 'query is rejected');
check(dispatch(signedRequest('capabilities', $base + ['email' => 'someone@example.com']))[0] === 400, 'strict fields');
check(dispatch(signedRequest('capabilities', $base + ['extra' => str_repeat('x', 8200)]))[0] === 413, 'body bound');
check(dispatch(signedRequest('user-subscription', $base + ['user_id' => 2]))[0] === 400, 'numeric IDs rejected');

[$status, $resolved] = dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']));
check($status === 200 && $resolved['account'] === ['user_id' => $originalId, 'email' => 'changed@example.com'], 'historical stable identity after email reuse');
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'missing']))[0] === 409, 'missing history is unresolved');
DB::table('faka_orders')->update(['completed_at' => null]);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 409, 'completion evidence required');
DB::table('faka_orders')->update(['completed_at' => '2026-10-24 00:00:00', 'xboard_trade_no' => 'MISSING-TRADE']);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 409, 'missing core order never falls back to email');
DB::table('faka_orders')->update(['xboard_trade_no' => 'TRADE-42']);
DB::table('faka_orders')->update(['status' => 'pending']);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 409, 'pending task is not ownership evidence');
DB::table('faka_orders')->update(['status' => 'revoked']);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 200, 'revoked successful history remains readable, not grant eligible');
DB::table('v2_order')->update(['user_id' => 999]);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 409, 'no email fallback for deleted original user');
DB::table('v2_order')->update(['user_id' => $originalId, 'status' => 0]);
check(dispatch(signedRequest('user-resolve', $base + ['external_order_no' => 'MN-42']))[0] === 409, 'unpaid core order rejected');
DB::table('v2_order')->update(['status' => 3]);

[$status, $profile] = dispatch(signedRequest('user-subscription', $base + ['user_id' => $originalId]));
check($status === 200 && $profile['subscription']['remaining_bytes'] === '9214364837600034813', 'exact 64 bit traffic');
check($profile['subscription']['status'] === 'active' && $profile['subscription']['expires_at'] === null, 'null expiry stays unlimited');
check(!str_contains(json_encode($profile), 'PRIVATE_'), 'no secrets in whitelist projection');
DB::table('v2_user')->where('id', $originalId)->update(['expired_at' => 0]);
$p = dispatch(signedRequest('user-subscription', $base + ['user_id' => $originalId]))[1]['subscription'];
check($p['status'] === 'expired' && $p['expires_at'] === '0', 'zero expiry stays expired');
DB::table('v2_user')->where('id', $originalId)->update(['u' => '9223372036854775807', 'd' => '9223372036854775807', 'banned' => true]);
$p = dispatch(signedRequest('user-subscription', $base + ['user_id' => $originalId]))[1]['subscription'];
check($p['used_bytes'] === '18446744073709551614' && $p['remaining_bytes'] === '0' && $p['status'] === 'blocked', 'overflow-free sum and blocked status');
check(dispatch(signedRequest('user-subscription', $base + ['user_id' => '999']))[0] === 404, 'missing user');
DB::table('v2_user')->where('id', 2)->update(['plan_id' => null]);
check(dispatch(signedRequest('user-subscription', $base + ['user_id' => '2']))[1]['subscription']['status'] === 'no_plan', 'no plan is distinct from expired');
DB::table('v2_user')->where('id', 2)->update(['u' => -1]);
check(dispatch(signedRequest('user-subscription', $base + ['user_id' => '2']))[1]['error_code'] === 'CORE_DATA_INVALID', 'invalid traffic is unavailable, never zero');
check(DB::table('v2_user')->count() === 2 && DB::table('v2_order')->count() === 1, 'read never creates users/orders');

// Exercise the actual core install/update entrypoints too. Only these core
// plugin metadata migrations are needed by this fixture, no site bootstrap.
(require '/www/database/migrations/2025_01_18_140511_create_plugins_table.php')->up();
(require '/www/database/migrations/2025_07_26_000001_add_type_to_plugins_table.php')->up();
$app->instance(PluginManager::class, $manager);
check($manager->install('faka_bridge'), 'core PluginManager install');
$row = App\Models\Plugin::where('code', 'faka_bridge')->first();
check($row->version === '1.8.0' && json_decode($row->config, true)['v2_read_enabled'] === false, 'new install defaults reads off');
$row->update(['version' => '1.5.0']);
Schema::drop('faka_protocol_nonces');
DB::table('migrations')->where('migration', '2026_10_25_000000_create_faka_protocol_nonces_table')->delete();
check(DB::table('migrations')->count() === 3, 'simulate missing nonce migration');
check($manager->update('faka_bridge'), 'core PluginManager update');
check(App\Models\Plugin::where('code', 'faka_bridge')->first()->version === '1.8.0'
    && DB::table('migrations')->count() === 4 && Schema::hasTable('faka_protocol_nonces'), 'upgrade discovers new migration and preserves applied migration');
// Production config path: warm the singleton and PluginManager caches, then
// change only the DB. A v2 request must observe disable/rotation immediately.
$row = App\Models\Plugin::where('code', 'faka_bridge')->first();
$row->update(['config' => json_encode($cfg->values)]);
$manager->enable('faka_bridge');
$realConfig = new FakaBridgeConfig();
check($realConfig->get('v2_read_enabled') === true, 'warm real singleton config');
$app->instance(FakaBridgeConfig::class, $realConfig);
check(dispatch(signedRequest('capabilities', $base))[1]['capabilities']['resolve_by_order'], 'real persisted config enables reads');
$values = $cfg->values; $values['v2_read_enabled'] = false;
$row->update(['config' => json_encode($values)]);
check($realConfig->get('v2_read_enabled') === true, 'old singleton remains warm for regression setup');
check(!dispatch(signedRequest('capabilities', $base))[1]['capabilities']['resolve_by_order'], 'v2 sees DB disable despite singleton cache');
check(dispatch(signedRequest('user-subscription', $base + ['user_id' => $originalId]))[0] === 503, 'disabled reads rejected');
$values['hmac_secret'] = 'test-only-rotated-secret'; $row->update(['config' => json_encode($values)]);
check(dispatch(signedRequest('capabilities', $base))[0] === 401, 'old key rejected after rotation despite singleton cache');
$row->update(['config' => json_encode($cfg->values)]);
Schema::drop('faka_protocol_nonces');
check(dispatch(signedRequest('capabilities', $base))[1]['error_code'] === 'REPLAY_STORE_UNAVAILABLE', 'missing replay migration fails closed');

echo json_encode(['ok' => true, 'checks' => $checks, 'laravel' => Illuminate\Foundation\Application::VERSION,
    'php' => PHP_VERSION, 'database' => 'isolated in-memory sqlite', 'full_core_bootstrap' => false], JSON_THROW_ON_ERROR), "\n";
