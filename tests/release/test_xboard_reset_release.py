"""Focused release-input checks; no databases, running services or credentials."""

import argparse
import contextlib
import importlib.util
import io
import json
import shutil
import tempfile
import unittest
import zipfile
from unittest.mock import patch
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "reset_release", ROOT / "scripts/xboard-reset-release.py"
)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseInputs(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.source = Path(self.directory.name) / "FakaBridge"
        shutil.copytree(ROOT / "xboard-plugins/FakaBridge", self.source)

    def test_source_identity_changes_when_php_is_added_or_edited(self):
        baseline = release.plugin_snapshot(self.source, "1.8.0")
        self.assertEqual(baseline, release.plugin_snapshot(self.source, "1.8.0"))
        extra = self.source / "extra.php"
        extra.write_text("<?php // added\n")
        added = release.plugin_snapshot(self.source, "1.8.0")
        self.assertNotEqual(baseline["buildId"], added["buildId"])
        extra.write_text("<?php // changed\n")
        self.assertNotEqual(
            added["buildId"], release.plugin_snapshot(self.source, "1.8.0")["buildId"]
        )

    def test_symlink_and_unexpected_files_cannot_enter_build_context(self):
        link = self.source / "outside.php"
        link.symlink_to(ROOT / "scripts/package-astrbot-xboard.py")
        with self.assertRaisesRegex(release.ReleaseError, "SYMLINK"):
            release.plugin_snapshot(self.source, "1.8.0")
        link.unlink()
        (self.source / ".env").write_text("must never package\n")
        with self.assertRaisesRegex(release.ReleaseError, "UNEXPECTED_PLUGIN_FILE"):
            release.plugin_snapshot(self.source, "1.8.0")

    def test_version_and_default_secret_or_write_switch_drift_are_rejected(self):
        config = self.source / "config.json"
        original = json.loads(config.read_bytes())
        for change in ["version", "secret", "write"]:
            body = json.loads(json.dumps(original))
            if change == "version":
                body["version"] = "1.9.0"
            elif change == "secret":
                body["config"]["hmac_secret"]["default"] = "test-only-reject"
            else:
                body["config"]["v2_paid_reset_enabled"]["default"] = True
            config.write_text(json.dumps(body))
            with self.assertRaises(release.ReleaseError):
                release.plugin_snapshot(self.source, "1.8.0")

    def test_unknown_core_is_rejected_before_patch_or_docker_build(self):
        lock = json.loads((release.RELEASE / "base-lock.json").read_bytes())
        with self.assertRaisesRegex(release.ReleaseError, "UNSUPPORTED_CORE_BYTES"):
            release.make_context(
                Path(self.directory.name),
                self.source,
                b"unknown source",
                b"unknown order",
                lock,
            )

    def test_non_runtime_file_drift_changes_file_manifest_without_changing_capability_id(
        self,
    ):
        before = release.plugin_snapshot(self.source, "1.8.0")
        (self.source / "README.md").write_text("changed documentation\n")
        after = release.plugin_snapshot(self.source, "1.8.0")
        self.assertEqual(before["buildId"], after["buildId"])
        self.assertNotEqual(before["allFiles"], after["allFiles"])

    def test_source_bundle_is_reproducible_allowlisted_and_self_described(self):
        first, second = (
            Path(self.directory.name) / "one.zip",
            Path(self.directory.name) / "two.zip",
        )
        with contextlib.redirect_stdout(io.StringIO()):
            release.bundle(argparse.Namespace(output=first))
            release.bundle(argparse.Namespace(output=second))
        self.assertEqual(first.read_bytes(), second.read_bytes())
        with zipfile.ZipFile(first) as archive:
            prefix = "monexus-xboard-reset/"
            manifest = json.loads(archive.read(prefix + "BUNDLE-SHA256.json"))
            self.assertEqual(
                set(archive.namelist()),
                {prefix + name for name in manifest} | {prefix + "BUNDLE-SHA256.json"},
            )
            for name, expected in manifest.items():
                self.assertEqual(release.digest(archive.read(prefix + name)), expected)
            self.assertIn("scripts/xboard-reset-release.py", manifest)
            self.assertFalse(
                any(
                    ".env" in name or "prod-verify" in name or "__pycache__" in name
                    for name in manifest
                )
            )

    def test_container_replacement_cannot_mix_old_image_identity_with_new_files(self):
        directory = Path(self.directory.name) / "release"
        directory.mkdir()
        manifest = b'{"schemaVersion":1}'
        (directory / "manifest.json").write_bytes(manifest)
        image = "sha256:" + "a" * 64
        (directory / "release.json").write_bytes(
            release.encode(
                {
                    "schemaVersion": 1,
                    "manifestSha256": release.digest(manifest),
                    "imageId": image,
                }
            )
        )
        calls = []
        seen_web = 0

        def fake_run(argv, **_kwargs):
            nonlocal seen_web
            calls.append(argv)
            if argv[1] == "exec":
                self.assertIn(argv[2], {"web-id", "worker-id"})
                return release.encode({"ok": True, "coverage": "artifact_files_only"})
            name = argv[-1]
            if name == "web":
                seen_web += 1
                return release.encode(
                    {
                        "id": "web-id" if seen_web == 1 else "replacement-id",
                        "imageId": image,
                        "running": True,
                    }
                )
            return release.encode(
                {"id": "worker-id", "imageId": image, "running": True}
            )

        with (
            patch.object(release, "run", side_effect=fake_run),
            contextlib.redirect_stdout(io.StringIO()),
        ):
            result = release.check(
                argparse.Namespace(release_dir=directory, web="web", worker="worker")
            )
        self.assertEqual(result, 1)
        self.assertTrue(any(argv[:3] == ["docker", "exec", "web-id"] for argv in calls))


if __name__ == "__main__":
    unittest.main()
