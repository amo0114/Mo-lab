#!/usr/bin/env python3
"""Build-time adapter for the pinned Xboard core; refuses unknown source bytes."""
import argparse
import hashlib
from pathlib import Path

ORIGINAL_SHA256 = '147f535d7cb3a5cefb2569d6e03102be0ab71b89f21ea4d6ca79c683fe18a26d'

def prepare(source: bytes) -> bytes:
    if hashlib.sha256(source).hexdigest() != ORIGINAL_SHA256:
        raise ValueError('Unsupported Xboard TrafficResetService source; re-audit before applying')
    text = source.decode()
    old = '''    if (!$user->shouldResetTraffic()) {
      return false;
    }

    return $this->performReset($user, $triggerSource);'''
    new = '''    return DB::transaction(function () use ($user, $triggerSource) {
      // SQLite FOR UPDATE is a no-op. A zero-row DML obtains the database
      // writer lock before reading a snapshot, without changing any user row.
      if (DB::getDriverName() === 'sqlite') {
        DB::table('v2_user')->whereRaw('0 = 1')->update(['id' => DB::raw('id')]);
      }
      // Re-evaluate the schedule on a locked, current model. All reset sources
      // use the same user row, including a delayed cron/user-access callback.
      $current = User::whereKey($user->getKey())->lockForUpdate()->first();
      if (!$current || !$current->shouldResetTraffic()) return false;
      $user->setRawAttributes($current->getAttributes(), true);
      $user->unsetRelation('plan');
      return $this->performReset($user, $triggerSource);
    });'''
    assert old in text
    text = text.replace(old, new, 1)
    old = '      return DB::transaction(function () use ($user, $triggerSource) {\n        $oldUpload'
    new = '''      return DB::transaction(function () use ($user, $triggerSource) {
        if (DB::getDriverName() === 'sqlite') {
          DB::table('v2_user')->whereRaw('0 = 1')->update(['id' => DB::raw('id')]);
        }
        // Preserve unsaved purchase changes (plan/expiry/limits), but read
        // usage/counters from the database after acquiring the shared lock.
        $dirty = array_diff_key($user->getDirty(), array_flip([
          'u', 'd', 'last_reset_at', 'reset_count', 'next_reset_at'
        ]));
        $current = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
        $user->setRawAttributes($current->getAttributes(), true);
        $user->forceFill($dirty);
        $user->unsetRelation('plan');
        $oldUpload'''
    assert old in text
    text = text.replace(old, new, 1)
    old = '''        $this->clearUserCache($user);
        HookManager::call('traffic.reset.after', $user);'''
    new = '''        // Database logs/counters participate in the caller's outer
        // transaction; irreversible hooks must wait for that outer commit.
        DB::afterCommit(function () use ($user) {
          try {
            $this->clearUserCache($user);
            HookManager::call('traffic.reset.after', $user);
          } catch (\\Throwable $e) {
            // A post-commit callback failure cannot turn a committed reset
            // into a retryable native failure or expose account data.
            Log::error('traffic reset post-commit callback failed', ['exception' => $e::class]);
          }
        });'''
    assert old in text
    text = text.replace(old, new, 1)
    return text.encode()

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    result = prepare(args.source.read_bytes())
    args.output.write_bytes(result)
    print(hashlib.sha256(result).hexdigest())
