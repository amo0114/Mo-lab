<?php
use Illuminate\Support\Facades\DB;

function sqliteWaitFor(string $path): void {
    for ($n=0; $n<500; $n++) {
        if (is_file($path)) return;
        usleep(10000);
    }
    throw new RuntimeException('SQLite writer barrier timed out');
}
function sqliteWriterBarrier(string $prefix): void {
    file_put_contents($prefix.'.ready','1');
    sqliteWaitFor($prefix.'.attempted');
}
function sqliteContender(string $prefix): void {
    sqliteWaitFor($prefix.'.ready');
    DB::statement('PRAGMA busy_timeout=0');
    $blocked = false;
    try { DB::statement('UPDATE v2_user SET id=id WHERE 0=1'); }
    catch (Illuminate\Database\QueryException $error) { $blocked = in_array($error->errorInfo[1] ?? null,[5,6,517],true); }
    if (!$blocked) throw new RuntimeException('leader did not hold the SQLite writer lock');
    DB::statement('PRAGMA busy_timeout=2000');
    file_put_contents($prefix.'.contended','1');
    // This callback runs immediately before PDO executes the real contender's
    // first DML. The leader cannot commit until this exact point is reached.
    $marked = false;
    DB::connection()->beforeExecuting(function ($sql) use ($prefix,&$marked) {
        if (!$marked && preg_match('/^(insert|update) /i',$sql)) {
            $marked = true; file_put_contents($prefix.'.attempted','1');
        }
    });
}
function sqliteBarrierCleanup(string $prefix): void {
    foreach (['ready','attempted','contended'] as $suffix) @unlink($prefix.'.'.$suffix);
}
