#!/usr/bin/env python3
"""Build pinned Xboard reset artifacts; check explicitly named containers without application writes."""

import argparse
import base64
import hashlib
import importlib.util
import json
import re
import shutil
import subprocess
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RELEASE = ROOT / "release/xboard-reset"
CORE_RESET = "/www/app/Services/TrafficResetService.php"
CORE_ORDER = "/www/app/Services/OrderService.php"


class ReleaseError(Exception):
    pass


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def encode(value):
    # Match PHP JSON_UNESCAPED_SLASHES (including its default unicode escaping).
    return json.dumps(value, ensure_ascii=True, separators=(",", ":")).encode()


def run(argv, *, data=None, timeout=120):
    try:
        result = subprocess.run(
            argv,
            input=data,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise ReleaseError("TOOL_UNAVAILABLE_OR_TIMEOUT") from None
    if result.returncode:
        raise ReleaseError("TOOL_STEP_FAILED")
    return result.stdout


def plugin_snapshot(source, version):
    if source.is_symlink() or not source.is_dir():
        raise ReleaseError("INVALID_PLUGIN_SOURCE")
    files, all_files = {}, {}
    for path in sorted(source.rglob("*")):
        if path.is_symlink():
            raise ReleaseError("PLUGIN_SYMLINK_REJECTED")
        if path.is_file():
            relative = "/" + path.relative_to(source).as_posix()
            if not relative.isascii():
                raise ReleaseError("UNSUPPORTED_PLUGIN_PATH")
            if path.suffix not in {".php", ".json"} and path != source / "README.md":
                raise ReleaseError("UNEXPECTED_PLUGIN_FILE")
            all_files[relative] = digest(path.read_bytes())
            if path.suffix in {".php", ".json"}:
                # Runtime capability hash intentionally includes only PHP/JSON.
                files[relative] = all_files[relative]
    metadata = json.loads((source / "config.json").read_bytes())
    constant = re.search(
        r"const\s+VERSION\s*=\s*['\"]([^'\"]+)['\"]",
        (source / "Plugin.php").read_text(),
    )
    if metadata.get("version") != version or not constant or constant[1] != version:
        raise ReleaseError("PLUGIN_VERSION_MISMATCH")
    if metadata.get("config", {}).get("hmac_secret", {}).get("default") != "":
        raise ReleaseError("PLUGIN_CONTAINS_DEFAULT_SECRET")
    for name in ["v2_read_enabled", "v2_reset_enabled", "v2_paid_reset_enabled"]:
        if metadata.get("config", {}).get(name, {}).get("default") is not False:
            raise ReleaseError("PLUGIN_WRITE_OR_QUERY_DEFAULT_ENABLED")
    return {
        "version": version,
        "buildId": digest(encode(files)),
        "files": files,
        "allFiles": all_files,
    }


def make_context(context, source, original, order, lock):
    if (
        digest(original) != lock["originalResetSha256"]
        or digest(order) != lock["orderServiceSha256"]
    ):
        raise ReleaseError("UNSUPPORTED_CORE_BYTES")
    spec = importlib.util.spec_from_file_location(
        "atomic_reset_patch", ROOT / "patches/prepare-atomic-reset.py"
    )
    patch = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(patch)
    patched = patch.prepare(original)
    if digest(patched) != lock["patchedResetSha256"]:
        raise ReleaseError("PATCHED_CORE_MISMATCH")
    dockerfile = (RELEASE / "Dockerfile").read_bytes()
    if dockerfile.decode().splitlines()[0] != "FROM " + lock["baseImage"]:
        raise ReleaseError("BASE_LOCK_MISMATCH")
    plugin = plugin_snapshot(source, lock["pluginVersion"])
    manifest = {
        "schemaVersion": 1,
        "baseImage": lock["baseImage"],
        "baseRevision": lock["baseRevision"],
        "plugin": plugin,
        "core": {CORE_ORDER: digest(order), CORE_RESET: digest(patched)},
    }
    shutil.copytree(source, context / "FakaBridge")
    # Detect a source edit racing the snapshot/copy. Docker sees only this frozen context.
    if plugin_snapshot(context / "FakaBridge", lock["pluginVersion"]) != plugin:
        raise ReleaseError("PLUGIN_SOURCE_CHANGED")
    (context / "Dockerfile").write_bytes(dockerfile)
    (context / "TrafficResetService.php").write_bytes(patched)
    (context / "manifest.json").write_bytes(encode(manifest))
    shutil.copyfile(RELEASE / "verify-artifacts.php", context / "verify-artifacts.php")
    return manifest


def build(args):
    if not re.fullmatch(
        r"[a-z0-9][a-z0-9./_-]*:[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}", args.tag
    ):
        raise ReleaseError("INVALID_LOCAL_IMAGE_TAG")
    output = args.output_dir.absolute()
    if output.exists():
        raise ReleaseError("OUTPUT_DIRECTORY_ALREADY_EXISTS")
    lock = json.loads((RELEASE / "base-lock.json").read_bytes())
    if not re.fullmatch(
        r"ghcr.io/cedar2025/xboard@sha256:[a-f0-9]{64}", lock["baseImage"]
    ):
        raise ReleaseError("UNPINNED_BASE_IMAGE")
    # Extraction bypasses the normal entrypoint: no Xboard/DB initialization.
    original = run(
        [
            "docker",
            "run",
            "--rm",
            "--network",
            "none",
            "--entrypoint",
            "cat",
            lock["baseImage"],
            CORE_RESET,
        ]
    )
    order = run(
        [
            "docker",
            "run",
            "--rm",
            "--network",
            "none",
            "--entrypoint",
            "cat",
            lock["baseImage"],
            CORE_ORDER,
        ]
    )
    with tempfile.TemporaryDirectory(prefix="monexus-reset-build-") as directory:
        context = Path(directory)
        manifest = make_context(
            context, ROOT / "xboard-plugins/FakaBridge", original, order, lock
        )
        run(
            [
                "docker",
                "build",
                "--pull=false",
                "--network=none",
                "--tag",
                args.tag,
                str(context),
            ],
            timeout=300,
        )
        image_id = json.loads(
            run(["docker", "image", "inspect", "--format", "{{json .Id}}", args.tag])
        )
        result = {
            "schemaVersion": 1,
            "manifestSha256": digest(encode(manifest)),
            "imageId": image_id,
            "localTag": args.tag,
            "registryDigest": None,
            "coverage": "built_artifact_only",
        }
        output.mkdir(parents=True, exist_ok=False)
        shutil.copyfile(context / "manifest.json", output / "manifest.json")
        (output / "release.json").write_bytes(encode(result))
        # This records local content identity; no registry push/digest is implied.
        print(json.dumps(result))


def bundle(args):
    if args.output.exists():
        raise ReleaseError("OUTPUT_FILE_ALREADY_EXISTS")
    lock = json.loads((RELEASE / "base-lock.json").read_bytes())
    plugin_snapshot(ROOT / "xboard-plugins/FakaBridge", lock["pluginVersion"])
    paths = [
        ROOT / "scripts/xboard-reset-release.py",
        ROOT / "patches/prepare-atomic-reset.py",
        *[
            RELEASE / name
            for name in [
                "Dockerfile",
                "base-lock.json",
                "verify-artifacts.php",
                "README.md",
            ]
        ],
        *[p for p in (ROOT / "xboard-plugins/FakaBridge").rglob("*") if p.is_file()],
    ]
    files = {}
    for path in sorted(paths):
        if path.is_symlink() or not path.is_file():
            raise ReleaseError("INVALID_BUNDLE_SOURCE")
        files[path.relative_to(ROOT).as_posix()] = path.read_bytes()
    # Revalidate exactly the captured plugin bytes rather than trusting a live tree after capture.
    with tempfile.TemporaryDirectory(prefix="monexus-reset-bundle-") as directory:
        frozen = Path(directory) / "FakaBridge"
        for name, raw in files.items():
            if name.startswith("xboard-plugins/FakaBridge/"):
                target = frozen / name.removeprefix("xboard-plugins/FakaBridge/")
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(raw)
        plugin_snapshot(frozen, lock["pluginVersion"])
    files["BUNDLE-SHA256.json"] = encode(
        {name: digest(raw) for name, raw in files.items()}
    )
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with (
        args.output.open("xb") as handle,
        zipfile.ZipFile(handle, "w", compression=zipfile.ZIP_DEFLATED) as archive,
    ):
        for name, raw in sorted(files.items()):
            info = zipfile.ZipInfo(
                "monexus-xboard-reset/" + name, date_time=(2026, 10, 25, 0, 0, 0)
            )
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, raw)
    print(
        json.dumps(
            {
                "ok": True,
                "coverage": "source_bundle_only",
                "sha256": digest(args.output.read_bytes()),
                "entries": len(files),
            }
        )
    )


def check(args):
    manifest_raw = (args.release_dir / "manifest.json").read_bytes()
    release = json.loads((args.release_dir / "release.json").read_bytes())
    if release.get("schemaVersion") != 1 or release.get("manifestSha256") != digest(
        manifest_raw
    ):
        raise ReleaseError("RELEASE_MANIFEST_MISMATCH")
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", release.get("imageId", "")):
        raise ReleaseError("INVALID_EXPECTED_IMAGE_ID")
    if args.web == args.worker:
        raise ReleaseError("DISTINCT_WEB_AND_WORKER_REQUIRED")
    for name in [args.web, args.worker]:
        if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}", name):
            raise ReleaseError("INVALID_CONTAINER_REFERENCE")
    expected = base64.b64encode(manifest_raw).decode()
    probe = (RELEASE / "verify-artifacts.php").read_text().removeprefix("<?php")
    rows = []
    for role, name in [("web", args.web), ("worker", args.worker)]:
        # Select only immutable image identity/running state, never inspect Config.Env.
        state = json.loads(
            run(
                [
                    "docker",
                    "container",
                    "inspect",
                    "--format",
                    '{"id":{{json .Id}},"imageId":{{json .Image}},"running":{{json .State.Running}}}',
                    name,
                ]
            )
        )
        row = {
            "role": role,
            "imageMatches": state["imageId"] == release["imageId"],
            "running": state["running"],
            "filesMatch": False,
        }
        row["containerId"] = state["id"]
        if row["running"]:
            # Use the local trusted probe, not a potentially mounted/stale container script.
            try:
                report = json.loads(
                    run(
                        [
                            "docker",
                            "exec",
                            state["id"],
                            "php",
                            "-r",
                            probe,
                            "--",
                            expected,
                        ],
                        timeout=30,
                    )
                )
                row["filesMatch"] = (
                    report.get("ok") is True
                    and report.get("coverage") == "artifact_files_only"
                )
            except (ReleaseError, ValueError):
                pass
        # A rolling replacement must not mix one container's identity with
        # another container's files. Probe by immutable ID, then check the name binding.
        try:
            after = json.loads(
                run(
                    [
                        "docker",
                        "container",
                        "inspect",
                        "--format",
                        '{"id":{{json .Id}},"imageId":{{json .Image}},"running":{{json .State.Running}}}',
                        name,
                    ]
                )
            )
            row["stableContainer"] = after == state
        except (ReleaseError, ValueError):
            row["stableContainer"] = False
        rows.append(row)
    distinct = rows[0]["containerId"] != rows[1]["containerId"]
    ok = distinct and all(
        row["running"]
        and row["imageMatches"]
        and row["filesMatch"]
        and row["stableContainer"]
        for row in rows
    )
    print(
        json.dumps(
            {
                "ok": ok,
                "coverage": "running_container_artifact_files_only",
                "productionReady": False,
                "distinctContainers": distinct,
                "containers": rows,
                "notVerified": [
                    "loaded_worker_code_and_opcache",
                    "plugin_install_and_migrations",
                    "database_engines_and_configuration",
                    "signed_capabilities",
                    "Monexus_history_preflight",
                    "real_QQ_acceptance",
                ],
            }
        )
    )
    return 0 if ok else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    builder = sub.add_parser("build")
    builder.add_argument("--tag", required=True)
    builder.add_argument("--output-dir", type=Path, required=True)
    packer = sub.add_parser("bundle")
    packer.add_argument("--output", type=Path, required=True)
    checker = sub.add_parser("check")
    checker.add_argument("--release-dir", type=Path, required=True)
    checker.add_argument("--web", required=True)
    checker.add_argument("--worker", required=True)
    args = parser.parse_args()
    try:
        if args.command == "build":
            return build(args)
        if args.command == "bundle":
            return bundle(args)
        return check(args)
    except ReleaseError as error:
        print(json.dumps({"ok": False, "error": str(error)}))
        return 1
    except (ValueError, OSError, KeyError, TypeError, AttributeError):
        # Do not print subprocess stderr, envs, host paths or arbitrary file contents.
        print(json.dumps({"ok": False, "error": "RELEASE_COMMAND_FAILED"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
