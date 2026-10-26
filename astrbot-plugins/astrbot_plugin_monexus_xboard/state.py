"""Small durable bot context, outside the install directory. No confirmation codes."""

import asyncio
import json
import os
import sqlite3
import threading
from pathlib import Path


class StateStore:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        self._lock = threading.Lock()
        self._db = sqlite3.connect(path, check_same_thread=False, timeout=5)
        os.chmod(path, 0o600)
        self._db.execute(
            "CREATE TABLE IF NOT EXISTS contexts (sender TEXT PRIMARY KEY, data TEXT NOT NULL)"
        )
        self._db.commit()

    def _read(self, sender):
        with self._lock:
            row = self._db.execute(
                "SELECT data FROM contexts WHERE sender=?", (sender,)
            ).fetchone()
            return json.loads(row[0]) if row else {}

    def _write(self, sender, data):
        with self._lock, self._db:
            if data:
                self._db.execute(
                    "INSERT INTO contexts VALUES (?,?) ON CONFLICT(sender) DO UPDATE SET data=excluded.data",
                    (sender, json.dumps(data, separators=(",", ":"))),
                )
            else:
                self._db.execute("DELETE FROM contexts WHERE sender=?", (sender,))

    async def read(self, sender):
        return await asyncio.to_thread(self._read, sender)

    async def write(self, sender, data):
        await asyncio.to_thread(self._write, sender, data)

    async def close(self):
        await asyncio.to_thread(self._db.close)
