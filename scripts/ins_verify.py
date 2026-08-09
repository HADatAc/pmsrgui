#!/usr/bin/env python3
"""
INS workbook verifier.

Single-command usage:
    python3 scripts/ins_verify.py mts/INS-PMSR-V2.xlsx

Outputs:
- Pass/fail on stdout
- JSON report file
- Markdown report file

Exit code:
- 0: PASS (no ERROR findings)
- 1: FAIL (one or more ERROR findings)
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import zipfile
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Dict, List, Tuple, Any


ROW_RE = re.compile(r'<row\b[^>]*r="(\d+)"[^>]*>(.*?)</row>', re.S)
CELL_RE = re.compile(r'<c\b[^>]*r="([A-Z]+)(\d+)"[^>]*>(.*?)</c>', re.S)


REQUIRED_SHEETS = [
    "Instruments",
    "SlotElements",
    "ComponentStems",
    "Components",
    "CodeBooks",
    "CodeBookSlots",
    "ResponseOptions",
]


@dataclass
class Finding:
    code: str
    severity: str  # ERROR | WARN
    message: str
    count: int
    samples: List[Any]


def col_to_num(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n


def xml_unesc(s: str) -> str:
    return (
        s.replace("&lt;", "<")
        .replace("&gt;", ">")
        .replace("&amp;", "&")
        .replace("&quot;", '"')
        .replace("&#39;", "'")
    )


def parse_sheet(xml: str) -> Tuple[Dict[int, Dict[str, str]], List[str], Dict[str, int]]:
    rows: Dict[int, Dict[str, str]] = {}
    for rm in ROW_RE.finditer(xml):
        rnum = int(rm.group(1))
        body = rm.group(2)
        cells: Dict[str, str] = {}
        for cm in CELL_RE.finditer(body):
            col = cm.group(1)
            cbody = cm.group(3)
            mv = re.search(r"<v>(.*?)</v>", cbody, re.S)
            mt = re.search(r"<t>(.*?)</t>", cbody, re.S)
            val = ""
            if mv is not None:
                val = xml_unesc(mv.group(1)).strip()
            elif mt is not None:
                val = xml_unesc(mt.group(1)).strip()
            cells[col] = val
        rows[rnum] = cells

    header = rows.get(1, {})
    cols = sorted(header.keys(), key=col_to_num)
    idx = {header.get(c, ""): i for i, c in enumerate(cols)}
    return rows, cols, idx


def sheet_path(sheet_name: str, workbook_xml: str, rels_xml: str) -> str | None:
    rel_map = {
        m.group(1): m.group(2)
        for m in re.finditer(
            r'<Relationship[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"', rels_xml
        )
    }
    for m in re.finditer(r'<sheet[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"', workbook_xml):
        if m.group(1) != sheet_name:
            continue
        target = rel_map.get(m.group(2), "")
        if target.startswith("worksheets/"):
            return "xl/" + target
        return "xl/worksheets/" + target.split("/")[-1]
    return None


def get_val(data: Dict[str, Any], sheet: str, row: Dict[str, str], header: str, default: str = "") -> str:
    idx = data[sheet]["idx"]
    if header not in idx:
        return default
    col = data[sheet]["cols"][idx[header]]
    return row.get(col, default)


def count_has_uri(data: Dict[str, Any], sheet: str) -> int:
    total = 0
    for rn, row in data[sheet]["rows"].items():
        if rn == 1:
            continue
        if get_val(data, sheet, row, "hasURI", "") == "":
            continue
        total += 1
    return total


def add_finding(findings: List[Finding], code: str, severity: str, message: str, items: List[Any], sample_size: int) -> None:
    findings.append(
        Finding(
            code=code,
            severity=severity,
            message=message,
            count=len(items),
            samples=items[:sample_size],
        )
    )


def verify(workbook_path: Path, sample_size: int, strict: bool) -> Dict[str, Any]:
    findings: List[Finding] = []

    with zipfile.ZipFile(workbook_path, "r") as zf:
        workbook_xml = zf.read("xl/workbook.xml").decode("utf-8")
        rels_xml = zf.read("xl/_rels/workbook.xml.rels").decode("utf-8")

        data: Dict[str, Any] = {}
        missing_required = []
        for sheet in REQUIRED_SHEETS:
            p = sheet_path(sheet, workbook_xml, rels_xml)
            if p is None:
                missing_required.append(sheet)
                continue
            xml = zf.read(p).decode("utf-8")
            rows, cols, idx = parse_sheet(xml)
            data[sheet] = {"rows": rows, "cols": cols, "idx": idx}

    if missing_required:
        add_finding(
            findings,
            "SHEET_MISSING",
            "ERROR",
            "Required sheets are missing.",
            missing_required,
            sample_size,
        )
        # Cannot continue safely without core sheets.
        return {
            "workbook": str(workbook_path),
            "pass": False,
            "sheet_counts": {},
            "findings": [f.__dict__ for f in findings],
        }

    sheet_counts = {s: count_has_uri(data, s) for s in REQUIRED_SHEETS}

    # Build indexes.
    instruments = []
    for rn, row in data["Instruments"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "Instruments", row, "hasURI", "")
        if uri == "":
            continue
        instruments.append(
            {
                "row": rn,
                "uri": uri,
                "label": get_val(data, "Instruments", row, "rdfs:label", ""),
                "first": get_val(data, "Instruments", row, "vstoi:hasFirst", ""),
            }
        )

    component_stems = set()
    for rn, row in data["ComponentStems"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "ComponentStems", row, "hasURI", "")
        if uri:
            component_stems.add(uri)

    codebooks = set()
    for rn, row in data["CodeBooks"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "CodeBooks", row, "hasURI", "")
        if uri:
            codebooks.add(uri)

    response_options = set()
    for rn, row in data["ResponseOptions"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "ResponseOptions", row, "hasURI", "")
        if uri:
            response_options.add(uri)

    components = []
    comp_by_uri = {}
    for rn, row in data["Components"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "Components", row, "hasURI", "")
        if uri == "":
            continue
        rec = {
            "row": rn,
            "uri": uri,
            "label": get_val(data, "Components", row, "rdfs:label", ""),
            "stem": get_val(data, "Components", row, "vstoi:hasComponentStem", ""),
            "codebook": get_val(data, "Components", row, "vstoi:hasCodebook", ""),
            "instrument": get_val(data, "Components", row, "vstoi:isAttributeOf", ""),
        }
        components.append(rec)
        comp_by_uri[uri] = rec

    slots = []
    slot_uris = set()
    slot_by_uri = {}
    slots_by_instrument = defaultdict(list)
    for rn, row in data["SlotElements"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "SlotElements", row, "hasURI", "")
        if uri == "":
            continue
        rec = {
            "row": rn,
            "uri": uri,
            "belongs": get_val(data, "SlotElements", row, "vstoi:belongsTo", ""),
            "component": get_val(data, "SlotElements", row, "vstoi:hasComponent", ""),
            "next": get_val(data, "SlotElements", row, "vstoi:hasNext", ""),
            "prev": get_val(data, "SlotElements", row, "vstoi:hasPrevious", ""),
            "priority": get_val(data, "SlotElements", row, "vstoi:hasPriority", ""),
        }
        slots.append(rec)
        slot_uris.add(uri)
        slot_by_uri[uri] = rec
        if rec["belongs"] != "":
            slots_by_instrument[rec["belongs"]].append(rec)

    cb_slots = []
    cb_slots_by_codebook = defaultdict(list)
    cb_priority_seen = defaultdict(set)
    cb_priority_dupes = []
    for rn, row in data["CodeBookSlots"]["rows"].items():
        if rn == 1:
            continue
        uri = get_val(data, "CodeBookSlots", row, "hasURI", "")
        if uri == "":
            continue
        rec = {
            "row": rn,
            "uri": uri,
            "belongs": get_val(data, "CodeBookSlots", row, "vstoi:belongsTo", ""),
            "response": get_val(data, "CodeBookSlots", row, "vstoi:hasResponseOption", ""),
            "priority": get_val(data, "CodeBookSlots", row, "vstoi:hasPriority", ""),
        }
        cb_slots.append(rec)
        cb_slots_by_codebook[rec["belongs"]].append(rec)
        pri = rec["priority"]
        belongs = rec["belongs"]
        if pri in cb_priority_seen[belongs]:
            cb_priority_dupes.append(rec)
        else:
            cb_priority_seen[belongs].add(pri)

    # Core integrity rules.
    missing_instr_first = [i for i in instruments if i["first"] == ""]
    first_not_slot = [i for i in instruments if i["first"] != "" and i["first"] not in slot_uris]
    instr_without_slots = [i for i in instruments if len(slots_by_instrument.get(i["uri"], [])) == 0]

    comp_missing_stem = [c for c in components if c["stem"] == ""]
    comp_bad_stem = [c for c in components if c["stem"] != "" and c["stem"] not in component_stems]
    comp_missing_codebook = [c for c in components if c["codebook"] == ""]
    comp_bad_codebook = [c for c in components if c["codebook"] != "" and c["codebook"] not in codebooks]
    comp_missing_instrument = [c for c in components if c["instrument"] == ""]

    slot_missing_belongs = [s for s in slots if s["belongs"] == ""]
    slot_missing_component = [s for s in slots if s["component"] == ""]
    slot_bad_component = [s for s in slots if s["component"] != "" and s["component"] not in comp_by_uri]
    slot_bad_next = [s for s in slots if s["next"] != "" and s["next"] not in slot_uris]
    slot_bad_prev = [s for s in slots if s["prev"] != "" and s["prev"] not in slot_uris]
    slot_priority_missing = [s for s in slots if s["priority"] == ""]

    slot_next_cross_instrument = []
    slot_prev_cross_instrument = []
    slot_next_not_reciprocal = []
    slot_prev_not_reciprocal = []
    for s in slots:
        if s["next"] != "" and s["next"] in slot_by_uri:
            n = slot_by_uri[s["next"]]
            if n["belongs"] != s["belongs"]:
                slot_next_cross_instrument.append(
                    {
                        "slot": s["uri"],
                        "belongs": s["belongs"],
                        "next": n["uri"],
                        "next_belongs": n["belongs"],
                    }
                )
            if n["prev"] != s["uri"]:
                slot_next_not_reciprocal.append(
                    {
                        "slot": s["uri"],
                        "next": n["uri"],
                        "expected_prev": s["uri"],
                        "actual_prev": n["prev"],
                    }
                )

        if s["prev"] != "" and s["prev"] in slot_by_uri:
            p = slot_by_uri[s["prev"]]
            if p["belongs"] != s["belongs"]:
                slot_prev_cross_instrument.append(
                    {
                        "slot": s["uri"],
                        "belongs": s["belongs"],
                        "prev": p["uri"],
                        "prev_belongs": p["belongs"],
                    }
                )
            if p["next"] != s["uri"]:
                slot_prev_not_reciprocal.append(
                    {
                        "slot": s["uri"],
                        "prev": p["uri"],
                        "expected_next": s["uri"],
                        "actual_next": p["next"],
                    }
                )

    instr_first_wrong_owner = []
    instr_cycle = []
    instr_multiple_starts = []
    instr_no_start = []
    instr_disconnected = []
    instr_unreachable = []
    slot_priority_nonint = []
    instr_priority_dupes = []
    instr_priority_noncontinuous = []

    for i in instruments:
        first_uri = i["first"]
        if first_uri != "" and first_uri in slot_by_uri:
            owner = slot_by_uri[first_uri]["belongs"]
            if owner != i["uri"]:
                instr_first_wrong_owner.append(
                    {
                        "instrument": i["uri"],
                        "first": first_uri,
                        "first_belongs": owner,
                    }
                )

        inst_slots = slots_by_instrument.get(i["uri"], [])
        if len(inst_slots) == 0:
            continue

        inst_map = {s["uri"]: s for s in inst_slots}

        starts = []
        for s in inst_slots:
            prev = s["prev"]
            if prev == "":
                starts.append(s["uri"])
                continue
            if prev in inst_map:
                continue
            starts.append(s["uri"])

        if len(starts) == 0:
            instr_no_start.append({"instrument": i["uri"]})
        if len(starts) > 1:
            instr_multiple_starts.append(
                {
                    "instrument": i["uri"],
                    "start_count": len(starts),
                    "starts": starts,
                }
            )

        visited = set()
        for start_uri in starts:
            cur = start_uri
            local_seen = set()
            while cur in inst_map:
                if cur in local_seen:
                    instr_cycle.append({"instrument": i["uri"], "slot": cur})
                    break
                if cur in visited:
                    break
                local_seen.add(cur)
                visited.add(cur)
                nxt = inst_map[cur]["next"]
                if nxt == "":
                    break
                if nxt not in inst_map:
                    break
                cur = nxt

        if len(starts) > 0:
            unreachable = sorted([u for u in inst_map if u not in visited])
            if len(unreachable) > 0:
                instr_unreachable.append(
                    {
                        "instrument": i["uri"],
                        "unreachable_count": len(unreachable),
                        "unreachable": unreachable,
                    }
                )

        # Weak connectivity check using in-instrument next/prev links.
        neighbors = defaultdict(set)
        for s in inst_slots:
            u = s["uri"]
            if s["next"] in inst_map:
                v = s["next"]
                neighbors[u].add(v)
                neighbors[v].add(u)
            if s["prev"] in inst_map:
                v = s["prev"]
                neighbors[u].add(v)
                neighbors[v].add(u)

        remaining = set(inst_map.keys())
        components_count = 0
        while len(remaining) > 0:
            components_count += 1
            root = next(iter(remaining))
            stack = [root]
            seen = {root}
            while len(stack) > 0:
                node = stack.pop()
                for nb in neighbors[node]:
                    if nb in seen:
                        continue
                    seen.add(nb)
                    stack.append(nb)
            remaining = remaining - seen

        if components_count > 1:
            instr_disconnected.append(
                {
                    "instrument": i["uri"],
                    "components": components_count,
                    "slot_count": len(inst_slots),
                }
            )

        # Priority checks per instrument.
        pri_vals = []
        for s in inst_slots:
            p = s["priority"].strip()
            if p == "":
                continue
            if re.fullmatch(r"\d+", p) is None:
                slot_priority_nonint.append(
                    {
                        "instrument": i["uri"],
                        "slot": s["uri"],
                        "priority": p,
                    }
                )
                continue
            pri_vals.append(int(p))

        if len(pri_vals) > 0:
            pri_counter = Counter(pri_vals)
            dupes = [
                {"instrument": i["uri"], "priority": k, "count": v}
                for k, v in pri_counter.items()
                if v > 1
            ]
            instr_priority_dupes.extend(dupes)

        expected = list(range(1, len(inst_slots) + 1))
        if len(pri_vals) == len(inst_slots):
            actual = sorted(pri_vals)
            if actual != expected:
                missing = [x for x in expected if x not in set(actual)]
                extra = [x for x in sorted(set(actual)) if x not in set(expected)]
                instr_priority_noncontinuous.append(
                    {
                        "instrument": i["uri"],
                        "expected": expected,
                        "actual": actual,
                        "missing": missing,
                        "extra": extra,
                    }
                )

    cbslot_missing_belongs = [s for s in cb_slots if s["belongs"] == ""]
    cbslot_missing_response = [s for s in cb_slots if s["response"] == ""]
    cbslot_bad_response = [
        s for s in cb_slots if s["response"] != "" and s["response"] not in response_options
    ]
    codebook_without_slots = [cb for cb in sorted(codebooks) if len(cb_slots_by_codebook.get(cb, [])) == 0]

    # Warnings.
    labels = [c["label"].strip().lower() for c in components if c["label"].strip() != ""]
    dup_labels = [
        {"label": k, "count": v}
        for k, v in Counter(labels).items()
        if v > 1
    ]

    detector_count = sum(1 for c in components if "detector" in c["label"].lower())
    actuator_count = sum(1 for c in components if "actuator" in c["label"].lower())

    # ERROR findings.
    add_finding(findings, "INSTR_FIRST_MISSING", "ERROR", "Instrument missing vstoi:hasFirst.", missing_instr_first, sample_size)
    add_finding(findings, "INSTR_FIRST_INVALID", "ERROR", "Instrument vstoi:hasFirst points to nonexistent SlotElement.", first_not_slot, sample_size)
    add_finding(findings, "INSTR_NO_SLOTS", "ERROR", "Instrument has no SlotElements.", instr_without_slots, sample_size)

    add_finding(findings, "COMP_STEM_MISSING", "ERROR", "Component missing vstoi:hasComponentStem.", comp_missing_stem, sample_size)
    add_finding(findings, "COMP_STEM_INVALID", "ERROR", "Component stem reference not found in ComponentStems.", comp_bad_stem, sample_size)
    add_finding(findings, "COMP_CODEBOOK_MISSING", "ERROR", "Component missing vstoi:hasCodebook.", comp_missing_codebook, sample_size)
    add_finding(findings, "COMP_CODEBOOK_INVALID", "ERROR", "Component codebook reference not found in CodeBooks.", comp_bad_codebook, sample_size)
    add_finding(findings, "COMP_INSTRUMENT_MISSING", "ERROR", "Component missing vstoi:isAttributeOf.", comp_missing_instrument, sample_size)

    add_finding(findings, "SLOT_BELONGS_MISSING", "ERROR", "SlotElement missing vstoi:belongsTo.", slot_missing_belongs, sample_size)
    add_finding(findings, "SLOT_COMPONENT_MISSING", "ERROR", "SlotElement missing vstoi:hasComponent.", slot_missing_component, sample_size)
    add_finding(findings, "SLOT_COMPONENT_INVALID", "ERROR", "SlotElement references nonexistent Component.", slot_bad_component, sample_size)
    add_finding(findings, "SLOT_NEXT_INVALID", "ERROR", "SlotElement has invalid vstoi:hasNext reference.", slot_bad_next, sample_size)
    add_finding(findings, "SLOT_PREV_INVALID", "ERROR", "SlotElement has invalid vstoi:hasPrevious reference.", slot_bad_prev, sample_size)
    add_finding(findings, "SLOT_PRIORITY_MISSING", "ERROR", "SlotElement missing vstoi:hasPriority.", slot_priority_missing, sample_size)
    add_finding(findings, "SLOT_PRIORITY_NONINT", "ERROR", "SlotElement priority is not an integer.", slot_priority_nonint, sample_size)
    add_finding(findings, "SLOT_NEXT_CROSS_INSTR", "ERROR", "SlotElement hasNext points to a SlotElement in a different instrument.", slot_next_cross_instrument, sample_size)
    add_finding(findings, "SLOT_PREV_CROSS_INSTR", "ERROR", "SlotElement hasPrevious points to a SlotElement in a different instrument.", slot_prev_cross_instrument, sample_size)
    add_finding(findings, "SLOT_NEXT_PREV_MISMATCH", "ERROR", "SlotElement hasNext pointer is not reciprocated by target hasPrevious.", slot_next_not_reciprocal, sample_size)
    add_finding(findings, "SLOT_PREV_NEXT_MISMATCH", "ERROR", "SlotElement hasPrevious pointer is not reciprocated by source hasNext.", slot_prev_not_reciprocal, sample_size)

    add_finding(findings, "INSTR_FIRST_WRONG_OWNER", "ERROR", "Instrument vstoi:hasFirst points to a slot owned by another instrument.", instr_first_wrong_owner, sample_size)
    add_finding(findings, "INSTR_SLOT_CYCLE", "ERROR", "Instrument SlotElement chain contains a cycle.", instr_cycle, sample_size)
    add_finding(findings, "INSTR_SLOT_NO_START", "ERROR", "Instrument SlotElement chain has no start slot.", instr_no_start, sample_size)
    add_finding(findings, "INSTR_SLOT_MULTI_START", "ERROR", "Instrument SlotElement chain has multiple start slots.", instr_multiple_starts, sample_size)
    add_finding(findings, "INSTR_SLOT_DISCONNECTED", "ERROR", "Instrument SlotElement graph is not a single connected chain.", instr_disconnected, sample_size)
    add_finding(findings, "INSTR_SLOT_UNREACHABLE", "ERROR", "Instrument has unreachable SlotElements from start chain traversal.", instr_unreachable, sample_size)
    add_finding(findings, "INSTR_SLOT_PRIORITY_DUP", "ERROR", "Instrument has duplicate SlotElement priorities.", instr_priority_dupes, sample_size)
    add_finding(findings, "INSTR_SLOT_PRIORITY_NONCONTIG", "ERROR", "Instrument SlotElement priorities are not continuous from 1..N.", instr_priority_noncontinuous, sample_size)

    add_finding(findings, "CBSLOT_BELONGS_MISSING", "ERROR", "CodeBookSlot missing vstoi:belongsTo.", cbslot_missing_belongs, sample_size)
    add_finding(findings, "CBSLOT_RESPONSE_MISSING", "ERROR", "CodeBookSlot missing vstoi:hasResponseOption.", cbslot_missing_response, sample_size)
    add_finding(findings, "CBSLOT_RESPONSE_INVALID", "ERROR", "CodeBookSlot references nonexistent ResponseOption.", cbslot_bad_response, sample_size)
    add_finding(findings, "CODEBOOK_NO_SLOTS", "ERROR", "CodeBook has no CodeBookSlots.", codebook_without_slots, sample_size)

    # WARN findings.
    add_finding(findings, "CBSLOT_PRIORITY_DUP", "WARN", "Duplicate CodeBookSlot priority within same CodeBook.", cb_priority_dupes, sample_size)
    add_finding(findings, "COMP_LABEL_DUP", "WARN", "Duplicate component labels detected.", dup_labels, sample_size)

    # Optional strict check: require detector/actuator parity.
    if strict:
        parity_items: List[Any] = []
        if detector_count != actuator_count:
            parity_items.append(
                {
                    "detector_count": detector_count,
                    "actuator_count": actuator_count,
                }
            )
        add_finding(
            findings,
            "DET_ACT_PARITY",
            "WARN",
            "Detector/Actuator count mismatch.",
            parity_items,
            sample_size,
        )

    # Keep only non-empty findings.
    findings = [f for f in findings if f.count > 0]
    error_count = sum(1 for f in findings if f.severity == "ERROR")
    warn_count = sum(1 for f in findings if f.severity == "WARN")

    return {
        "workbook": str(workbook_path),
        "pass": error_count == 0,
        "summary": {
            "errors": error_count,
            "warnings": warn_count,
            "instruments": len(instruments),
            "components": len(components),
            "slots": len(slots),
            "codebooks": len(codebooks),
            "codebook_slots": len(cb_slots),
            "response_options": len(response_options),
            "detector_count": detector_count,
            "actuator_count": actuator_count,
        },
        "sheet_counts": sheet_counts,
        "findings": [f.__dict__ for f in findings],
    }


def render_markdown(result: Dict[str, Any]) -> str:
    lines = []
    lines.append("# INS Verification Report")
    lines.append("")
    lines.append(f"Workbook: {result['workbook']}")
    lines.append("")
    verdict = "PASS" if result["pass"] else "FAIL"
    lines.append(f"## Verdict: {verdict}")
    lines.append("")

    summary = result["summary"]
    lines.append("## Summary")
    lines.append(f"- Errors: {summary['errors']}")
    lines.append(f"- Warnings: {summary['warnings']}")
    lines.append(f"- Instruments: {summary['instruments']}")
    lines.append(f"- Components: {summary['components']}")
    lines.append(f"- SlotElements: {summary['slots']}")
    lines.append(f"- CodeBooks: {summary['codebooks']}")
    lines.append(f"- CodeBookSlots: {summary['codebook_slots']}")
    lines.append(f"- ResponseOptions: {summary['response_options']}")
    lines.append("")

    lines.append("## Sheet Counts")
    for sheet, count in result["sheet_counts"].items():
        lines.append(f"- {sheet}: {count}")
    lines.append("")

    if not result["findings"]:
        lines.append("## Findings")
        lines.append("- No findings.")
        return "\n".join(lines)

    lines.append("## Findings")
    for finding in result["findings"]:
        lines.append("")
        lines.append(f"### {finding['severity']} {finding['code']}")
        lines.append(f"- Message: {finding['message']}")
        lines.append(f"- Count: {finding['count']}")
        if finding["samples"]:
            lines.append("- Samples:")
            for sample in finding["samples"]:
                lines.append(f"  - {sample}")

    return "\n".join(lines)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Verify an INS workbook and generate JSON + Markdown reports.")
    parser.add_argument("workbook", help="Path to INS workbook (.xlsx)")
    parser.add_argument(
        "--out-dir",
        default=".",
        help="Output directory for reports (default: current directory)",
    )
    parser.add_argument(
        "--prefix",
        default=None,
        help="Output file prefix (default: <workbook_stem>.ins_verify)",
    )
    parser.add_argument(
        "--sample-size",
        type=int,
        default=10,
        help="Max samples per finding (default: 10)",
    )
    parser.add_argument(
        "--no-strict",
        action="store_true",
        help="Disable strict warning checks (e.g., detector/actuator parity warning)",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    workbook = Path(args.workbook)
    if not workbook.exists():
        print(f"FAIL: Workbook not found: {workbook}", file=sys.stderr)
        return 1

    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=True)

    prefix = args.prefix if args.prefix else f"{workbook.stem}.ins_verify"
    json_path = out_dir / f"{prefix}.json"
    md_path = out_dir / f"{prefix}.md"

    result = verify(workbook, sample_size=args.sample_size, strict=(not args.no_strict))

    with json_path.open("w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=2)

    with md_path.open("w", encoding="utf-8") as fh:
        fh.write(render_markdown(result))

    verdict = "PASS" if result["pass"] else "FAIL"
    print(f"{verdict}: {workbook}")
    print(f"JSON: {json_path}")
    print(f"Markdown: {md_path}")
    print(f"Errors: {result['summary']['errors']} | Warnings: {result['summary']['warnings']}")

    return 0 if result["pass"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
