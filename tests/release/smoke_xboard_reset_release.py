#!/usr/bin/env python3
"""Exercise a built image's artifact checks in disposable offline PHP-only containers."""

import argparse
import importlib.util
import json
import tempfile
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location(
    "release_tool", ROOT / "scripts/xboard-reset-release.py"
)
tool = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)


def smoke(release_dir):
    manifest = json.loads((release_dir / "manifest.json").read_bytes())
    release = json.loads((release_dir / "release.json").read_bytes())
    prefix = "monexus-reset-artifact-" + uuid.uuid4().hex[:12]
    containers = []
    passed = []

    def start(suffix, image, extra=()):
        name = prefix + "-" + suffix
        tool.run(
            [
                "docker",
                "run",
                "--detach",
                "--rm",
                "--name",
                name,
                "--network",
                "none",
                "--read-only",
                "--tmpfs",
                "/tmp",
                *extra,
                "--entrypoint",
                "php",
                image,
                "-r",
                "sleep(600);",
            ]
        )
        containers.append(name)
        return name

    def check(web, worker, directory=release_dir):
        args = argparse.Namespace(web=web, worker=worker, release_dir=directory)
        return tool.check(args)

    def expect(condition, case):
        if not condition:
            raise RuntimeError("ARTIFACT_SMOKE_FAILED: " + case)
        passed.append(case)

    try:
        web = start("web", release["imageId"])
        worker = start("worker", release["imageId"])
        expect(check(web, worker) == 0, "same_image_and_exact_files")
        # Independently invoke the plugin's actual buildId algorithm without Laravel/DB bootstrap.
        php = "require '/www/plugins/FakaBridge/Services/AccountReadService.php'; $r = new ReflectionClass('Plugin\\FakaBridge\\Services\\AccountReadService'); echo $r->getMethod('buildId')->invoke($r->newInstanceWithoutConstructor());"
        actual = tool.run(["docker", "exec", web, "php", "-r", php]).decode()
        expect(
            actual == manifest["plugin"]["buildId"], "actual_plugin_capability_build_id"
        )
        web_id = json.loads(
            tool.run(
                ["docker", "container", "inspect", "--format", "{{json .Id}}", web]
            )
        )
        expect(check(web, web_id) == 1, "same_container_alias_rejected")
        old = start("old", manifest["baseImage"])
        expect(check(web, old) == 1, "old_image_rejected")
        with tempfile.TemporaryDirectory(
            prefix="monexus-reset-artifact-drift-"
        ) as directory:
            drift = Path(directory) / "Plugin.php"
            drift.write_text("<?php // deliberately stale mount, never executed\n")
            mounted = start(
                "mount",
                release["imageId"],
                [
                    "--mount",
                    f"type=bind,src={drift},dst=/www/plugins/FakaBridge/Plugin.php,readonly",
                ],
            )
            expect(check(web, mounted) == 1, "host_mount_plugin_drift_rejected")
            tool.run(["docker", "rm", "--force", mounted])
            containers.remove(mounted)
            drift.write_text("deliberately changed documentation\n")
            static_mount = start(
                "static",
                release["imageId"],
                [
                    "--mount",
                    f"type=bind,src={drift},dst=/www/plugins/FakaBridge/README.md,readonly",
                ],
            )
            expect(
                check(web, static_mount) == 1, "non_runtime_plugin_file_drift_rejected"
            )
            tool.run(["docker", "rm", "--force", static_mount])
            containers.remove(static_mount)
            bad_release = Path(directory) / "bad-release"
            bad_release.mkdir()
            (bad_release / "manifest.json").write_bytes(
                (release_dir / "manifest.json").read_bytes() + b" "
            )
            (bad_release / "release.json").write_bytes(
                (release_dir / "release.json").read_bytes()
            )
            try:
                check(web, worker, bad_release)
            except tool.ReleaseError as error:
                expect(
                    str(error) == "RELEASE_MANIFEST_MISMATCH",
                    "expected_manifest_tamper_rejected",
                )
            else:
                expect(False, "expected_manifest_tamper_rejected")
        # A removed/stopped worker must not be reported as ready.
        tool.run(["docker", "stop", "--time", "1", worker])
        containers.remove(worker)  # --rm removed it after stop.
        try:
            check(web, worker)
        except tool.ReleaseError:
            passed.append("missing_worker_rejected")
        else:
            expect(False, "missing_worker_rejected")
        print(
            json.dumps(
                {
                    "ok": True,
                    "coverage": "offline_image_artifacts_only",
                    "cases": passed,
                }
            )
        )
    finally:
        for name in containers:
            try:
                tool.run(["docker", "rm", "--force", name])
            except tool.ReleaseError:
                pass


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--release-dir", type=Path, required=True)
    arguments = parser.parse_args()
    smoke(arguments.release_dir)
