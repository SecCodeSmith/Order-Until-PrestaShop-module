#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
"""Script to parse version tags or module version and bump the patch version.

Examples:
    1.2.0 -> v1.2.1
    v1.2.3 -> v1.2.4
"""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path


def parse_version(version_str: str) -> tuple[int, int, int]:
    """Extract major, minor, patch integers from a version string (e.g. 'v1.2.3' or '1.2.3')."""
    match = re.search(r"(\d+)\.(\d+)\.(\d+)", version_str)
    if not match:
        raise ValueError(f"Invalid version string: '{version_str}'")
    return int(match.group(1)), int(match.group(2)), int(match.group(3))


def bump_patch(version: tuple[int, int, int]) -> tuple[int, int, int]:
    """Increment the patch version (3rd number)."""
    major, minor, patch = version
    return major, minor, patch + 1


def format_version(version: tuple[int, int, int], prefix: str = "v") -> str:
    """Format a version tuple into a string with optional prefix."""
    return f"{prefix}{version[0]}.{version[1]}.{version[2]}"


def get_base_version(module_dir: Path) -> str:
    """Read version from scmorderuntil.php or config.xml."""
    php_path = module_dir / "scmorderuntil.php"
    if php_path.exists():
        content = php_path.read_text(encoding="utf-8")
        match = re.search(r'\$this->version\s*=\s*["\']([^"\']+)["\']', content)
        if match:
            return match.group(1)

    xml_path = module_dir / "config.xml"
    if xml_path.exists():
        content = xml_path.read_text(encoding="utf-8")
        match = re.search(r"<version><!\[CDATA\[([^\]]+)\]\]></version>", content)
        if not match:
            match = re.search(r"<version>([^<]+)</version>", content)
        if match:
            return match.group(1)

    return "1.2.0"


def calculate_next_tag(latest_tag: str | None, base_version: str, prefix: str = "v") -> str:
    """Determine the next tag by incrementing the patch number.

    If latest_tag is provided (e.g. 'v1.2.5'), increments its patch ('v1.2.6').
    Otherwise, increments base_version ('1.2.0' -> 'v1.2.1').
    """
    if latest_tag and latest_tag.strip():
        target_str = latest_tag.strip()
        pfx = "v" if target_str.startswith("v") else ""
    else:
        target_str = base_version
        pfx = "v" if (base_version.startswith("v") or prefix == "v") else ""

    parsed = parse_version(target_str)
    bumped = bump_patch(parsed)
    return format_version(bumped, prefix=pfx)


def main(argv: list[str]) -> int:
    latest_tag = argv[0] if argv else os.environ.get("LATEST_TAG")
    root_dir = Path(__file__).resolve().parent.parent
    base_version = get_base_version(root_dir)

    next_tag = calculate_next_tag(latest_tag, base_version)
    print(f"Calculated next tag: {next_tag}")

    github_output = os.environ.get("GITHUB_OUTPUT")
    if github_output:
        with open(github_output, "a", encoding="utf-8") as f:
            f.write(f"tag_name={next_tag}\n")
            f.write(f"version={next_tag.lstrip('v')}\n")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
