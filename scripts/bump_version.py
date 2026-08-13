#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
"""Release helper: read the module's declared version, bump the patch, write it
back into the module, and emit the tag for CI.

The **module config is the single source of truth** for the version: the base
version is read from `scmorderuntil.php` (`$this->version`), falling back to
`config.xml`. We increment the patch component, optionally write the new value
back into both files (`--write`), and print the resulting `vMAJOR.MINOR.PATCH`
tag. Git tags are NOT consulted — the module owns its version.

Examples:
    1.2.0  (in scmorderuntil.php)  ->  v1.2.1
    1.3.0  (in scmorderuntil.php)  ->  v1.3.1

Usage:
    python scripts/bump_version.py           # dry-run: print the next version
    python scripts/bump_version.py --write    # also write it into the module
"""
from __future__ import annotations

import os
import re
import sys
from pathlib import Path

# `$this->version = '1.2.0';` — capture the quote + value + closing quote.
PHP_VERSION_RE = re.compile(r"""(\$this->version\s*=\s*['"])([^'"]+)(['"])""")
# `<version><![CDATA[1.2.0]]></version>`
XML_CDATA_VERSION_RE = re.compile(r"(<version><!\[CDATA\[)([^\]]+)(\]\]></version>)")
# `<version>1.2.0</version>`
XML_PLAIN_VERSION_RE = re.compile(r"(<version>)([^<]+)(</version>)")


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
    """Read the module's declared version from scmorderuntil.php, then config.xml."""
    php_path = module_dir / "scmorderuntil.php"
    if php_path.exists():
        match = PHP_VERSION_RE.search(php_path.read_text(encoding="utf-8"))
        if match:
            return match.group(2)

    xml_path = module_dir / "config.xml"
    if xml_path.exists():
        content = xml_path.read_text(encoding="utf-8")
        match = XML_CDATA_VERSION_RE.search(content)
        if match is None:
            match = XML_PLAIN_VERSION_RE.search(content)
        if match:
            return match.group(2)

    return "1.0.0"


def set_version_in_php(php_path: Path, new_version: str) -> bool:
    """Rewrite `$this->version` in scmorderuntil.php. Returns True if changed."""
    content = php_path.read_text(encoding="utf-8")
    new_content, count = PHP_VERSION_RE.subn(
        lambda m: m.group(1) + new_version + m.group(3), content, count=1
    )
    if count:
        php_path.write_text(new_content, encoding="utf-8")
    return count > 0


def set_version_in_xml(xml_path: Path, new_version: str) -> bool:
    """Rewrite `<version>` in config.xml (CDATA or plain). Returns True if changed."""
    content = xml_path.read_text(encoding="utf-8")
    new_content, count = XML_CDATA_VERSION_RE.subn(
        lambda m: m.group(1) + new_version + m.group(3), content, count=1
    )
    if count == 0:
        new_content, count = XML_PLAIN_VERSION_RE.subn(
            lambda m: m.group(1) + new_version + m.group(3), content, count=1
        )
    if count:
        xml_path.write_text(new_content, encoding="utf-8")
    return count > 0


def write_module_version(module_dir: Path, new_version: str) -> list[Path]:
    """Write new_version into scmorderuntil.php and config.xml. Returns updated files."""
    updated: list[Path] = []
    php_path = module_dir / "scmorderuntil.php"
    if php_path.exists() and set_version_in_php(php_path, new_version):
        updated.append(php_path)
    xml_path = module_dir / "config.xml"
    if xml_path.exists() and set_version_in_xml(xml_path, new_version):
        updated.append(xml_path)
    return updated


def main(argv: list[str]) -> int:
    write = "--write" in argv
    root_dir = Path(__file__).resolve().parent.parent

    base_version = get_base_version(root_dir)
    bumped = bump_patch(parse_version(base_version))
    new_version = format_version(bumped, prefix="")  # e.g. "1.3.1"
    tag_name = "v" + new_version

    print(f"Module version (source of truth): {base_version}")
    print(f"Next version: {new_version} (tag {tag_name})")

    if write:
        updated = write_module_version(root_dir, new_version)
        for path in updated:
            print(f"  updated {path.relative_to(root_dir)} -> {new_version}")
        if not updated:
            print("  WARNING: no version strings were updated", file=sys.stderr)
            return 1

    github_output = os.environ.get("GITHUB_OUTPUT")
    if github_output:
        with open(github_output, "a", encoding="utf-8") as f:
            f.write(f"tag_name={tag_name}\n")
            f.write(f"version={new_version}\n")
            f.write(f"previous_version={base_version}\n")

    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
