#!/usr/bin/env python3
"""Build an install ZIP from an explicit allowlist; never package bot config/data."""

import argparse
import hashlib
import zipfile
from pathlib import Path

NAME = "astrbot_plugin_monexus_xboard"
FILES = (
    "__init__.py",
    "main.py",
    "client.py",
    "service.py",
    "state.py",
    "render.py",
    "metadata.yaml",
    "_conf_schema.json",
    "requirements.txt",
    "README.md",
)


def package(output: Path):
    source = Path(__file__).resolve().parents[1] / "astrbot-plugins" / NAME
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for filename in FILES:
            path = source / filename
            if path.is_symlink() or not path.is_file():
                raise ValueError("incomplete plugin source")
            info = zipfile.ZipInfo(
                NAME + "/" + filename, date_time=(2026, 10, 25, 0, 0, 0)
            )
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    print(hashlib.sha256(output.read_bytes()).hexdigest())


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    package(parser.parse_args().output)
