# SPDX-License-Identifier: MIT
"""Unit tests for version parsing and patch increment logic in scripts/bump_version.py."""
import sys
from pathlib import Path

# Add root directory to sys.path so scripts can be imported cleanly
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import pytest

from scripts.bump_version import (
    bump_patch,
    calculate_next_tag,
    format_version,
    get_base_version,
    parse_version,
)


def test_parse_version_valid():
    assert parse_version("1.2.0") == (1, 2, 0)
    assert parse_version("v1.2.3") == (1, 2, 3)
    assert parse_version("v2.0.15") == (2, 0, 15)


def test_parse_version_invalid():
    with pytest.raises(ValueError, match="Invalid version string"):
        parse_version("invalid")
    with pytest.raises(ValueError, match="Invalid version string"):
        parse_version("1.2")


def test_bump_patch():
    assert bump_patch((1, 2, 0)) == (1, 2, 1)
    assert bump_patch((1, 2, 9)) == (1, 2, 10)
    assert bump_patch((2, 0, 0)) == (2, 0, 1)


def test_format_version():
    assert format_version((1, 2, 1), prefix="v") == "v1.2.1"
    assert format_version((1, 2, 1), prefix="") == "1.2.1"


def test_calculate_next_tag_with_latest_tag():
    assert calculate_next_tag("v1.2.0", "1.2.0") == "v1.2.1"
    assert calculate_next_tag("v1.2.9", "1.2.0") == "v1.2.10"
    assert calculate_next_tag("1.2.5", "1.2.0") == "1.2.6"


def test_calculate_next_tag_without_latest_tag():
    assert calculate_next_tag(None, "1.2.0") == "v1.2.1"
    assert calculate_next_tag("", "1.2.0") == "v1.2.1"


def test_get_base_version_php(tmp_path: Path):
    php_file = tmp_path / "scmorderuntil.php"
    php_file.write_text("$this->version = '1.3.4';\n", encoding="utf-8")
    assert get_base_version(tmp_path) == "1.3.4"


def test_get_base_version_xml(tmp_path: Path):
    xml_file = tmp_path / "config.xml"
    xml_file.write_text("<module><version><![CDATA[1.4.0]]></version></module>\n", encoding="utf-8")
    assert get_base_version(tmp_path) == "1.4.0"
