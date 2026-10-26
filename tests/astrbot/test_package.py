"""Only distributable plugin source enters the deterministic installation ZIP."""

import hashlib
import importlib.util
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class Package(unittest.TestCase):
    def test_zip_is_reproducible_complete_and_excludes_runtime_secrets(self):
        spec = importlib.util.spec_from_file_location(
            "packager", ROOT / "scripts/package-astrbot-xboard.py"
        )
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        with tempfile.TemporaryDirectory() as directory:
            first, second = (
                Path(directory) / "first.zip",
                Path(directory) / "second.zip",
            )
            builder.package(first)
            builder.package(second)
            self.assertEqual(
                hashlib.sha256(first.read_bytes()).digest(),
                hashlib.sha256(second.read_bytes()).digest(),
            )
            with zipfile.ZipFile(first) as archive:
                files = set(archive.namelist())
                self.assertEqual(
                    files, {builder.NAME + "/" + filename for filename in builder.FILES}
                )
                self.assertFalse(
                    any(
                        ".env" in name or "__pycache__" in name or "sqlite" in name
                        for name in files
                    )
                )
                for name in files:
                    if name.endswith(".py"):
                        compile(archive.read(name), name, "exec")
                schema = json.loads(archive.read(builder.NAME + "/_conf_schema.json"))
                self.assertFalse(schema["query_enabled"]["default"])


if __name__ == "__main__":
    unittest.main()
