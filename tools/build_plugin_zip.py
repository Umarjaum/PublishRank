#!/usr/bin/env python3
"""Build a clean WordPress.org-style PublishRank plugin ZIP from this repository."""
from __future__ import annotations

import argparse
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OMIT_TOP_LEVEL = {
    ".git",
    ".github",
    ".gitignore",
    "tests",
    "tools",
    "vendor",
    "vendor-bin",
    "dist",
    "composer.json",
    "composer.lock",
    "README.md",
    "CONTRIBUTING.md",
    "phpunit.xml.dist",
    "phpstan.neon",
}


def plugin_version() -> str:
    source = (ROOT / "publishrank.php").read_text(encoding="utf-8")
    match = re.search(r"^\s*\* Version:\s*([0-9]+(?:\.[0-9]+)+)\s*$", source, re.M)
    if not match:
        raise SystemExit("Could not determine plugin header version")
    return match.group(1)


def main() -> None:
    version = plugin_version()
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "output",
        nargs="?",
        default=str(ROOT / "dist" / f"PublishRank-v{version}.zip"),
        help="output ZIP path (default: dist/PublishRank-v<VERSION>.zip)",
    )
    args = parser.parse_args()
    output = Path(args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)

    files = []
    for path in ROOT.rglob("*"):
        if not path.is_file():
            continue
        relative = path.relative_to(ROOT)
        if relative.parts[0] in OMIT_TOP_LEVEL:
            continue
        if any(part.startswith(".") or part == "__pycache__" for part in relative.parts) or path.suffix == ".pyc":
            continue
        if path == output:
            continue
        files.append((path, relative))

    if not any(relative.as_posix() == "publishrank.php" for _, relative in files):
        raise SystemExit("Plugin bootstrap missing from package file list")

    with zipfile.ZipFile(output, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path, relative in sorted(files, key=lambda pair: pair[1].as_posix()):
            archive.write(path, f"publishrank/{relative.as_posix()}")

    print(f"Built {output} ({len(files)} files), PublishRank {version}")


if __name__ == "__main__":
    main()
