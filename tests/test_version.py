# SPDX-License-Identifier: MIT
"""Unit tests for version parsing, patch increment and module write-back in
scripts/bump_version.py."""
import sys
from pathlib import Path

# Add root directory to sys.path so scripts can be imported cleanly
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import pytest

from scripts.bump_version import (
    bump_patch,
    format_version,
    get_base_version,
    parse_version,
    set_version_in_php,
    set_version_in_xml,
    write_module_version,
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


def test_get_base_version_php(tmp_path: Path):
    php_file = tmp_path / "scmorderuntil.php"
    php_file.write_text("$this->version = '1.3.4';\n", encoding="utf-8")
    assert get_base_version(tmp_path) == "1.3.4"


def test_get_base_version_xml(tmp_path: Path):
    xml_file = tmp_path / "config.xml"
    xml_file.write_text(
        "<module><version><![CDATA[1.4.0]]></version></module>\n",
        encoding="utf-8",
    )
    assert get_base_version(tmp_path) == "1.4.0"


def test_get_base_version_php_precedence(tmp_path: Path):
    # PHP is the source of truth; config.xml is only a fallback.
    (tmp_path / "scmorderuntil.php").write_text(
        "$this->version = '2.1.0';\n", encoding="utf-8"
    )
    (tmp_path / "config.xml").write_text(
        "<version><![CDATA[9.9.9]]></version>", encoding="utf-8"
    )
    assert get_base_version(tmp_path) == "2.1.0"


def test_set_version_in_php_rewrites_value(tmp_path: Path):
    php_file = tmp_path / "scmorderuntil.php"
    php_file.write_text(
        "        $this->version = '1.3.0';\n", encoding="utf-8"
    )
    assert set_version_in_php(php_file, "1.3.1") is True
    assert "$this->version = '1.3.1';" in php_file.read_text(encoding="utf-8")


def test_set_version_in_xml_cdata(tmp_path: Path):
    xml_file = tmp_path / "config.xml"
    xml_file.write_text(
        "<version><![CDATA[1.3.0]]></version>", encoding="utf-8"
    )
    assert set_version_in_xml(xml_file, "1.3.1") is True
    assert "<![CDATA[1.3.1]]>" in xml_file.read_text(encoding="utf-8")


def test_set_version_in_xml_plain(tmp_path: Path):
    xml_file = tmp_path / "config.xml"
    xml_file.write_text("<version>1.3.0</version>", encoding="utf-8")
    assert set_version_in_xml(xml_file, "1.3.1") is True
    assert "<version>1.3.1</version>" in xml_file.read_text(encoding="utf-8")


def test_write_module_version_updates_both_files(tmp_path: Path):
    (tmp_path / "scmorderuntil.php").write_text(
        "$this->version = '1.3.0';\n", encoding="utf-8"
    )
    (tmp_path / "config.xml").write_text(
        "<version><![CDATA[1.3.0]]></version>", encoding="utf-8"
    )
    updated = write_module_version(tmp_path, "1.3.1")
    names = sorted(p.name for p in updated)
    assert names == ["config.xml", "scmorderuntil.php"]
    assert get_base_version(tmp_path) == "1.3.1"


def test_write_module_version_end_to_end_bump(tmp_path: Path):
    (tmp_path / "scmorderuntil.php").write_text(
        "$this->version = '1.3.0';\n", encoding="utf-8"
    )
    base = get_base_version(tmp_path)
    nxt = format_version(bump_patch(parse_version(base)), prefix="")
    write_module_version(tmp_path, nxt)
    assert get_base_version(tmp_path) == "1.3.1"
