"""
Fixture-driven display-name tests.

The existing tests in test_display_name.py set one catalog field at a time, so none of
them can reproduce the class of bug an audit found on 2026-07-29: star 7301 carries both
`gj` and `hip`, and PHP and the API disagree about which wins. This module drives the
shared table at tests/fixtures/display-names.json, which every stack reads, so the three
implementations are held to one set of expectations instead of three private ones.

The fixture is mounted at /fixtures by the Makefile.
"""
import json
import os
import pytest

from app.schemas import StarBase, StarDetail

FIXTURE_PATH = os.environ.get("DISPLAY_NAME_FIXTURE", "/fixtures/display-names.json")


def load_cases():
    if not os.path.exists(FIXTURE_PATH):
        return []
    with open(FIXTURE_PATH) as fh:
        return json.load(fh)["cases"]


CASES = load_cases()

# Fields a star row may carry; anything absent from a fixture case defaults to None so
# each case only has to state what it is actually exercising.
STAR_FIELDS = {
    "id": 0, "proper": None, "bayer": None, "flam": None, "con": None,
    "spect": None, "absmag": None, "mag": None, "dist": None,
    "x": 0.0, "y": 0.0, "z": 0.0,
    "hip": None, "hd": None, "hr": None, "gj": None,
    "cns5": None, "gaia": None, "tyc": None, "name": "",
}


def build(case: dict) -> dict:
    """
    Build a row the way the database actually delivers one.

    Note the world_id handling. `get_stars` selects
    `COALESCE(f.name, '') AS name` with `f.world_id = :world_id`, so the fictional name
    is only ever populated when a world was requested — the schema keys off `name` being
    non-empty rather than taking world_id itself. This harness reproduces that
    precondition so the fixture's world_id cases mean the same thing here as they do in
    production.

    Worth knowing: PHP is stricter, checking `$fic_names > 0 && !empty($row['name'])`.
    So if anything ever populates `name` without a world being selected, the API would
    show the fictional name and PHP would not. The query is the only thing preventing
    that today.
    """
    row = {**STAR_FIELDS, **case["star"]}
    if not case.get("world_id"):
        row["name"] = ""
    return row


def _xfail_if_known_broken(case, key):
    """
    Turn a documented current-behaviour mismatch into an expected failure.

    The alternative is a permanently red suite, which nobody watches. xfail keeps the
    fixture honest AND reports loudly (as XPASS) the moment the implementation is fixed,
    which is what DISPLAY-NAME-CANON will do.
    """
    broken = case.get("known_broken", {})
    if key in broken:
        pytest.xfail(f"{case['name']}: {broken['why']} (currently {broken[key]!r})")


@pytest.mark.skipif(not CASES, reason=f"shared fixture not mounted at {FIXTURE_PATH}")
@pytest.mark.parametrize("case", CASES, ids=lambda c: c["name"])
def test_star_base_display_name_matches_fixture(case):
    """StarBase must produce the name the shared fixture expects."""
    _xfail_if_known_broken(case, "api_base")
    star = StarBase(**build(case))
    assert star.display_name == case["expected"], (
        f"{case['name']}: StarBase said {star.display_name!r}, "
        f"fixture expects {case['expected']!r}"
    )


@pytest.mark.skipif(not CASES, reason=f"shared fixture not mounted at {FIXTURE_PATH}")
@pytest.mark.parametrize("case", CASES, ids=lambda c: c["name"])
def test_star_detail_agrees_with_star_base(case):
    """
    StarDetail duplicates StarBase's chain by hand, and the copies have already drifted:
    StarBase checks `hr` and StarDetail does not. A list row and a detail row for the
    same star must never be named differently.
    """
    _xfail_if_known_broken(case, "api_detail")
    row = build(case)
    base = StarBase(**row)
    detail = StarDetail(**row)

    assert detail.display_name == base.display_name, (
        f"{case['name']}: StarDetail said {detail.display_name!r} but StarBase said "
        f"{base.display_name!r} for the same row"
    )
