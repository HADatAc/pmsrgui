#!/usr/bin/env python3
"""
DP2 workbook verifier.

Single-command usage:
    python3 scripts/dp2_verify.py mts/DP2-PMSR-V2.xlsx

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
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
from xml.etree import ElementTree as ET


NS = {
    "a": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
    "r": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "pr": "http://schemas.openxmlformats.org/package/2006/relationships",
}

REQUIRED_SHEETS = [
    "InfoSheet",
    "Namespace",
    "Deployments",
    "ComponentDeployments",
    "Platforms",
    "PlatformInstances",
    "FieldsOfView",
    "InstrumentInstances",
    "ComponentInstances",
    "SensingPerspective",
]

EXPECTED_INFOSHEET_KEYS = [
    "hasDependencies",
    "Deployments",
    "ComponentDeployments",
    "Platforms",
    "PlatformInstances",
    "FieldsOfView",
    "InstrumentInstances",
    "ComponentInstances",
    "SensingPerspective",
]


@dataclass
class Finding:
    code: str
    severity: str
    message: str
    count: int
    samples: List[Any]


def add_finding(
    findings: List[Finding],
    code: str,
    severity: str,
    message: str,
    items: List[Any],
    sample_size: int,
) -> None:
    findings.append(
        Finding(
            code=code,
            severity=severity,
            message=message,
            count=len(items),
            samples=items[:sample_size],
        )
    )


def col_to_num(col: str) -> int:
    n = 0
    for ch in col:
        n = n * 26 + (ord(ch) - 64)
    return n


def parse_shared_strings(zf: zipfile.ZipFile) -> List[str]:
    if "xl/sharedStrings.xml" not in zf.namelist():
        return []
    root = ET.fromstring(zf.read("xl/sharedStrings.xml"))
    out: List[str] = []
    for si in root.findall("a:si", NS):
        txt = "".join(t.text or "" for t in si.findall(".//a:t", NS))
        out.append(txt)
    return out


def cell_text(cell: ET.Element, sst: List[str]) -> str:
    ctype = cell.get("t")
    if ctype == "s":
        v = cell.find("a:v", NS)
        if v is None or v.text is None:
            return ""
        try:
            idx = int(v.text)
        except ValueError:
            return ""
        return sst[idx] if 0 <= idx < len(sst) else ""

    if ctype == "inlineStr":
        return "".join(t.text or "" for t in cell.findall(".//a:t", NS)).strip()

    v = cell.find("a:v", NS)
    if v is None or v.text is None:
        return ""
    return v.text.strip()


def get_sheet_paths(zf: zipfile.ZipFile) -> Dict[str, str]:
    wb = ET.fromstring(zf.read("xl/workbook.xml"))
    rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))

    relmap = {
        rel.get("Id"): rel.get("Target")
        for rel in rels.findall("pr:Relationship", NS)
        if rel.get("Id")
    }

    out: Dict[str, str] = {}
    sheets = wb.find("a:sheets", NS)
    if sheets is None:
        return out

    for s in sheets.findall("a:sheet", NS):
        name = s.get("name", "")
        rid = s.get("{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id", "")
        target = relmap.get(rid, "")
        if target.startswith("worksheets/"):
            out[name] = "xl/" + target
        elif target:
            out[name] = "xl/worksheets/" + target.split("/")[-1]
    return out


def parse_sheet(zf: zipfile.ZipFile, path: str, sst: List[str]) -> Dict[str, Any]:
    root = ET.fromstring(zf.read(path))
    sd = root.find("a:sheetData", NS)
    rows = sd.findall("a:row", NS) if sd is not None else []

    headers_by_col: Dict[str, str] = {}
    ordered_cols: List[str] = []
    if rows:
        for c in rows[0].findall("a:c", NS):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            headers_by_col[col] = cell_text(c, sst).strip()
        ordered_cols = sorted(headers_by_col.keys(), key=col_to_num)

    records: List[Dict[str, str]] = []
    raw_rows: Dict[int, Dict[str, str]] = {}

    for row in rows[1:]:
        rnum = int(row.get("r", "0") or "0")
        by_col: Dict[str, str] = {}
        for c in row.findall("a:c", NS):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            by_col[col] = cell_text(c, sst)
        raw_rows[rnum] = by_col

        rec: Dict[str, str] = {}
        for col in ordered_cols:
            rec[headers_by_col[col]] = by_col.get(col, "")
        records.append(rec)

    headers = [headers_by_col[c] for c in ordered_cols]

    non_empty_rows = 0
    for rec in records:
        if any(v.strip() for v in rec.values()):
            non_empty_rows += 1

    return {
        "headers": headers,
        "records": records,
        "raw_rows": raw_rows,
        "total_rows": len(rows),
        "data_rows": len(rows) - 1 if rows else 0,
        "non_empty_rows": non_empty_rows,
    }


def norm_uri(s: str) -> str:
    v = (s or "").strip()
    if not v:
        return ""
    if v.startswith("<") and v.endswith(">") and len(v) > 2:
        return v[1:-1].strip()
    return v


def has_ins_like_uri(uri: str) -> bool:
    v = norm_uri(uri)
    if not v:
        return False
    return "/INS" in v or ":INS" in v


def looks_like_uri(value: str) -> bool:
    v = norm_uri(value)
    if not v:
        return False
    return re.match(r"^[A-Za-z][A-Za-z0-9+.-]*:", v) is not None


def verify(
    workbook_path: Path,
    sample_size: int,
    strict: bool,
    ii_model_policy: str,
    ins_workbook: Optional[Path],
) -> Dict[str, Any]:
    findings: List[Finding] = []

    with zipfile.ZipFile(workbook_path, "r") as zf:
        sst = parse_shared_strings(zf)
        sheet_paths = get_sheet_paths(zf)

        missing_sheets = [s for s in REQUIRED_SHEETS if s not in sheet_paths]
        add_finding(
            findings,
            "SHEET_MISSING",
            "ERROR",
            "Required sheets are missing.",
            missing_sheets,
            sample_size,
        )

        if missing_sheets:
            return {
                "workbook": str(workbook_path),
                "pass": False,
                "summary": {"errors": 1, "warnings": 0},
                "sheet_counts": {},
                "findings": [f.__dict__ for f in findings if f.count > 0],
            }

        data: Dict[str, Any] = {
            name: parse_sheet(zf, path, sst)
            for name, path in sheet_paths.items()
            if name in REQUIRED_SHEETS
        }

    slot_owner_by_slot: Dict[str, str] = {}
    ins_slot_validation_enabled = False
    if ins_workbook is not None:
        if not ins_workbook.exists():
            add_finding(
                findings,
                "INS_WORKBOOK_MISSING",
                "ERROR",
                "INS workbook was provided but not found.",
                [{"ins_workbook": str(ins_workbook)}],
                sample_size,
            )
        else:
            try:
                with zipfile.ZipFile(ins_workbook, "r") as ins_zf:
                    ins_sst = parse_shared_strings(ins_zf)
                    ins_paths = get_sheet_paths(ins_zf)
                    if "SlotElements" not in ins_paths:
                        add_finding(
                            findings,
                            "INS_SLOT_SHEET_MISSING",
                            "ERROR",
                            "INS workbook is missing required SlotElements sheet for slot validation.",
                            [{"ins_workbook": str(ins_workbook)}],
                            sample_size,
                        )
                    else:
                        ins_slot_validation_enabled = True
                        slot_data = parse_sheet(ins_zf, ins_paths["SlotElements"], ins_sst)
                        for rec in slot_data["records"]:
                            slot_uri = norm_uri(rec.get("hasURI", ""))
                            belongs_to = norm_uri(rec.get("vstoi:belongsTo", ""))
                            if slot_uri and belongs_to:
                                slot_owner_by_slot[slot_uri] = belongs_to
            except Exception as exc:
                add_finding(
                    findings,
                    "INS_WORKBOOK_PARSE_FAILED",
                    "ERROR",
                    "Failed to parse INS workbook for slot validation.",
                    [{"ins_workbook": str(ins_workbook), "error": str(exc)}],
                    sample_size,
                )

    # Header checks (minimal but semantic).
    required_headers: Dict[str, List[str]] = {
        "InfoSheet": ["Attribute", "Value"],
        "Namespace": ["hasPrefix", "hasNameSpace"],
        "Deployments": [
            "hasURI",
            "a",
            "rdfs:label",
            "vstoi:hasPlatformInstance",
            "vstoi:hasInstrumentInstance",
            "vstoi:designedAtTime",
            "prov:startedAtTime",
        ],
        "PlatformInstances": ["hasURI", "a", "rdfs:label"],
        "InstrumentInstances": ["hasURI", "a", "rdfs:label", "vstoi:hasSerialNumber"],
        "ComponentInstances": ["hasURI", "a", "rdfs:label"],
    }

    missing_headers: List[Dict[str, Any]] = []
    for sheet, expected in required_headers.items():
        present = set(data[sheet]["headers"])
        for h in expected:
            if h not in present:
                missing_headers.append({"sheet": sheet, "header": h})

    # ComponentDeployments accepts either legacy user-facing headers
    # or V3 ontology-column headers.
    cd_headers = set(data["ComponentDeployments"]["headers"])
    cd_header_aliases = {
        "Deployment URI": ["Deployment URI", "hasco:hascoDeployment"],
        "Instrument Slot URI": ["Instrument Slot URI", "hasco:hasInstrumentSlot"],
        "Component Instance URI": ["Component Instance URI", "hasco:hasComponentInstance"],
    }
    for semantic_header, aliases in cd_header_aliases.items():
        if not any(alias in cd_headers for alias in aliases):
            missing_headers.append({"sheet": "ComponentDeployments", "header": semantic_header})

    add_finding(
        findings,
        "HEADER_MISSING",
        "ERROR",
        "Required headers are missing.",
        missing_headers,
        sample_size,
    )

    # Deployment component-link column compatibility.
    dep_headers = set(data["Deployments"]["headers"])
    component_link_col = ""
    if "vstoi:hasComponentInstance" in dep_headers:
        component_link_col = "vstoi:hasComponentInstance"
    elif "vstoi:hasDetectorInstance" in dep_headers:
        component_link_col = "vstoi:hasDetectorInstance"

    if component_link_col == "":
        add_finding(
            findings,
            "DEPLOY_COMPONENT_COL_MISSING",
            "WARN",
            "Deployments sheet has neither vstoi:hasComponentInstance nor vstoi:hasDetectorInstance.",
            [{"sheet": "Deployments"}],
            sample_size,
        )

    # InfoSheet key checks.
    infosheet_records = data["InfoSheet"]["records"]
    info_map: Dict[str, str] = {}
    for rec in infosheet_records:
        k = rec.get("Attribute", "").strip()
        v = rec.get("Value", "").strip()
        if k:
            info_map[k] = v

    missing_keys = [k for k in EXPECTED_INFOSHEET_KEYS if k not in info_map]
    add_finding(
        findings,
        "INFOSHEET_KEY_MISSING",
        "ERROR",
        "InfoSheet missing expected DP2 keys.",
        missing_keys,
        sample_size,
    )

    # Recommended map values (warning only).
    bad_values: List[Dict[str, str]] = []
    for k in EXPECTED_INFOSHEET_KEYS:
        if k not in info_map:
            continue
        if k == "hasDependencies":
            if info_map[k] not in ("#Namespace", "#Namespaces"):
                bad_values.append({"key": k, "value": info_map[k]})
        else:
            if info_map[k] != f"#{k}":
                bad_values.append({"key": k, "value": info_map[k], "expected": f"#{k}"})

    add_finding(
        findings,
        "INFOSHEET_MAPPING_NONCANON",
        "WARN",
        "InfoSheet values are non-canonical for one or more keys.",
        bad_values,
        sample_size,
    )

    # Build URI sets.
    def uri_set(sheet: str) -> Set[str]:
        out: Set[str] = set()
        for rec in data[sheet]["records"]:
            u = norm_uri(rec.get("hasURI", ""))
            if u:
                out.add(u)
        return out

    platform_uris = uri_set("PlatformInstances")
    instrument_instance_uris = uri_set("InstrumentInstances")
    component_instance_uris = uri_set("ComponentInstances")

    # Row checks for DP2 core sheets.
    row_missing_hasuri: List[Dict[str, Any]] = []
    for sheet in [
        "Deployments",
        "PlatformInstances",
        "InstrumentInstances",
        "ComponentInstances",
        "Platforms",
        "FieldsOfView",
        "SensingPerspective",
    ]:
        for idx, rec in enumerate(data[sheet]["records"], start=2):
            if not any(v.strip() for v in rec.values()):
                continue
            if norm_uri(rec.get("hasURI", "")) == "":
                row_missing_hasuri.append({"sheet": sheet, "row": idx})

    add_finding(
        findings,
        "ROW_HASURI_MISSING",
        "ERROR",
        "Populated row is missing hasURI.",
        row_missing_hasuri,
        sample_size,
    )

    # ComponentDeployments semantic checks.
    deployment_uris = uri_set("Deployments")
    cd_missing_required: List[Dict[str, Any]] = []
    cd_bad_deployment: List[Dict[str, Any]] = []
    cd_bad_component: List[Dict[str, Any]] = []
    cd_bad_slot: List[Dict[str, Any]] = []
    cd_slot_instrument_mismatch: List[Dict[str, Any]] = []

    instrument_model_by_instance: Dict[str, str] = {}
    for rec in data["InstrumentInstances"]["records"]:
        ii_uri = norm_uri(rec.get("hasURI", ""))
        model_uri = norm_uri(rec.get("a", ""))
        if ii_uri and model_uri:
            instrument_model_by_instance[ii_uri] = model_uri

    deployment_instrument_model: Dict[str, str] = {}
    for rec in data["Deployments"]["records"]:
        d_uri = norm_uri(rec.get("hasURI", ""))
        ii_uri = norm_uri(rec.get("vstoi:hasInstrumentInstance", ""))
        if d_uri and ii_uri and ii_uri in instrument_model_by_instance:
            deployment_instrument_model[d_uri] = instrument_model_by_instance[ii_uri]

    for idx, rec in enumerate(data["ComponentDeployments"]["records"], start=2):
        dep_uri = norm_uri(rec.get("Deployment URI", "") or rec.get("hasco:hascoDeployment", ""))
        slot_uri = norm_uri(rec.get("Instrument Slot URI", "") or rec.get("hasco:hasInstrumentSlot", ""))
        comp_uri = norm_uri(rec.get("Component Instance URI", "") or rec.get("hasco:hasComponentInstance", ""))

        if dep_uri == "" and slot_uri == "" and comp_uri == "":
            continue

        missing_fields: List[str] = []
        if dep_uri == "":
            missing_fields.append("Deployment URI")
        if slot_uri == "":
            missing_fields.append("Instrument Slot URI")
        if comp_uri == "":
            missing_fields.append("Component Instance URI")

        if missing_fields:
            cd_missing_required.append({"row": idx, "missing": missing_fields})
            continue

        if dep_uri not in deployment_uris:
            cd_bad_deployment.append({"row": idx, "deployment": dep_uri})

        if comp_uri not in component_instance_uris:
            cd_bad_component.append({"row": idx, "component": comp_uri})

        if ins_slot_validation_enabled:
            slot_owner = slot_owner_by_slot.get(slot_uri, "")
            if slot_owner == "":
                cd_bad_slot.append({"row": idx, "slot": slot_uri})
            else:
                dep_model = deployment_instrument_model.get(dep_uri, "")
                if dep_model != "" and dep_model != slot_owner:
                    cd_slot_instrument_mismatch.append(
                        {
                            "row": idx,
                            "deployment": dep_uri,
                            "slot": slot_uri,
                            "slotBelongsTo": slot_owner,
                            "deploymentInstrumentModel": dep_model,
                        }
                    )

    add_finding(
        findings,
        "COMP_DEPLOYMENT_REQUIRED_MISSING",
        "ERROR",
        "ComponentDeployments row is missing one or more required fields.",
        cd_missing_required,
        sample_size,
    )

    add_finding(
        findings,
        "COMP_DEPLOYMENT_DEPLOYMENT_INVALID",
        "ERROR",
        "ComponentDeployments row references unknown Deployment URI.",
        cd_bad_deployment,
        sample_size,
    )

    add_finding(
        findings,
        "COMP_DEPLOYMENT_COMPONENT_INVALID",
        "ERROR",
        "ComponentDeployments row references unknown Component Instance URI.",
        cd_bad_component,
        sample_size,
    )

    if ins_slot_validation_enabled:
        add_finding(
            findings,
            "COMP_DEPLOYMENT_SLOT_INVALID",
            "ERROR",
            "ComponentDeployments row references unknown Instrument Slot URI in INS SlotElements.",
            cd_bad_slot,
            sample_size,
        )

        add_finding(
            findings,
            "COMP_DEPLOYMENT_SLOT_INSTRUMENT_MISMATCH",
            "ERROR",
            "ComponentDeployments slot owner model does not match the deployment instrument model.",
            cd_slot_instrument_mismatch,
            sample_size,
        )

    # Deployment link checks.
    dep_bad_platform: List[Dict[str, Any]] = []
    dep_bad_instrument: List[Dict[str, Any]] = []
    dep_bad_component: List[Dict[str, Any]] = []
    dep_component_nonuri: List[Dict[str, Any]] = []
    dep_missing_times: List[Dict[str, Any]] = []

    for idx, rec in enumerate(data["Deployments"]["records"], start=2):
        d_uri = norm_uri(rec.get("hasURI", ""))
        if d_uri == "":
            continue

        p = norm_uri(rec.get("vstoi:hasPlatformInstance", ""))
        i = norm_uri(rec.get("vstoi:hasInstrumentInstance", ""))

        if p == "" or p not in platform_uris:
            dep_bad_platform.append({"row": idx, "deployment": d_uri, "platform": p})

        if i == "" or i not in instrument_instance_uris:
            dep_bad_instrument.append({"row": idx, "deployment": d_uri, "instrument": i})

        if component_link_col:
            raw = rec.get(component_link_col, "").strip()
            if raw:
                parts = [norm_uri(x) for x in re.split(r"[;|,]", raw) if norm_uri(x)]
                uri_parts = [x for x in parts if looks_like_uri(x)]
                non_uri_parts = [x for x in parts if not looks_like_uri(x)]

                if non_uri_parts and not uri_parts:
                    dep_component_nonuri.append(
                        {
                            "row": idx,
                            "deployment": d_uri,
                            "column": component_link_col,
                            "value": raw,
                        }
                    )

                for c_uri in parts:
                    if not looks_like_uri(c_uri):
                        continue
                    if c_uri not in component_instance_uris:
                        dep_bad_component.append(
                            {
                                "row": idx,
                                "deployment": d_uri,
                                "component": c_uri,
                                "column": component_link_col,
                            }
                        )

        if strict:
            designed = rec.get("vstoi:designedAtTime", "").strip()
            started = rec.get("prov:startedAtTime", "").strip()
            if designed == "" or started == "":
                dep_missing_times.append(
                    {
                        "row": idx,
                        "deployment": d_uri,
                        "designedAtTime": designed,
                        "startedAtTime": started,
                    }
                )

    add_finding(
        findings,
        "DEPLOY_PLATFORM_INVALID",
        "ERROR",
        "Deployment has missing/invalid platform instance reference.",
        dep_bad_platform,
        sample_size,
    )
    add_finding(
        findings,
        "DEPLOY_INSTRUMENT_INVALID",
        "ERROR",
        "Deployment has missing/invalid instrument instance reference.",
        dep_bad_instrument,
        sample_size,
    )
    add_finding(
        findings,
        "DEPLOY_COMPONENT_INVALID",
        "ERROR",
        "Deployment has invalid component-instance reference.",
        dep_bad_component,
        sample_size,
    )
    add_finding(
        findings,
        "DEPLOY_COMPONENT_NONURI_VALUE",
        "ERROR",
        "Deployment component link column contains non-URI value(s).",
        dep_component_nonuri,
        sample_size,
    )

    if strict:
        add_finding(
            findings,
            "DEPLOY_TIME_MISSING",
            "WARN",
            "Deployment missing designed/start time fields.",
            dep_missing_times,
            sample_size,
        )

    # Instrument instance model checks.
    ii_model_missing: List[Dict[str, Any]] = []
    ii_model_nonins: List[Dict[str, Any]] = []
    ii_model_inslike: List[Dict[str, Any]] = []
    for idx, rec in enumerate(data["InstrumentInstances"]["records"], start=2):
        u = norm_uri(rec.get("hasURI", ""))
        if u == "":
            continue
        model = norm_uri(rec.get("a", ""))
        if model == "":
            ii_model_missing.append({"row": idx, "instrumentInstance": u})
        elif has_ins_like_uri(model):
            ii_model_inslike.append({"row": idx, "instrumentInstance": u, "model": model})
        else:
            ii_model_nonins.append({"row": idx, "instrumentInstance": u, "model": model})

    add_finding(
        findings,
        "II_MODEL_MISSING",
        "ERROR",
        "InstrumentInstance is missing model URI in 'a'.",
        ii_model_missing,
        sample_size,
    )

    if ii_model_policy == "ins-only":
        add_finding(
            findings,
            "II_MODEL_POLICY_VIOLATION",
            "ERROR",
            "InstrumentInstance model URI is not INS-like under ins-only policy.",
            ii_model_nonins,
            sample_size,
        )
    elif ii_model_policy == "non-ins-required":
        add_finding(
            findings,
            "II_MODEL_NONINS_REQUIRED_MISSING",
            "ERROR",
            "No non-INS model URIs found in InstrumentInstances.a under non-ins-required policy.",
            [] if len(ii_model_nonins) > 0 else [{"instrument_instances": len(instrument_instance_uris)}],
            sample_size,
        )

    # Usage stats warnings.
    dep_rows = len([r for r in data["Deployments"]["records"] if norm_uri(r.get("hasURI", ""))])
    ci_rows = len([r for r in data["ComponentInstances"]["records"] if norm_uri(r.get("hasURI", ""))])
    dep_rows_with_component_link = 0
    if component_link_col:
        dep_rows_with_component_link = len(
            [
                r
                for r in data["Deployments"]["records"]
                if norm_uri(r.get("hasURI", "")) and r.get(component_link_col, "").strip()
            ]
        )

    if dep_rows > 0 and ci_rows == 0:
        add_finding(
            findings,
            "COMP_INSTANCE_UNPOPULATED",
            "WARN",
            "ComponentInstances sheet is unpopulated while deployments exist.",
            [{"deployments": dep_rows, "component_instances": ci_rows}],
            sample_size,
        )

    if dep_rows > 0 and component_link_col and dep_rows_with_component_link == 0:
        add_finding(
            findings,
            "DEPLOY_COMPONENT_LINK_EMPTY",
            "WARN",
            "No deployment rows carry component-instance linkage values.",
            [{"deployments": dep_rows, "linked_rows": dep_rows_with_component_link, "column": component_link_col}],
            sample_size,
        )

    # Keep only non-empty findings.
    findings = [f for f in findings if f.count > 0]
    error_count = sum(1 for f in findings if f.severity == "ERROR")
    warn_count = sum(1 for f in findings if f.severity == "WARN")

    summary = {
        "errors": error_count,
        "warnings": warn_count,
        "ins_workbook": str(ins_workbook) if ins_workbook is not None else "",
        "ins_slot_validation_enabled": ins_slot_validation_enabled,
        "ins_slot_count": len(slot_owner_by_slot),
        "ii_model_policy": ii_model_policy,
        "ii_model_nonins_count": len(ii_model_nonins),
        "ii_model_inslike_count": len(ii_model_inslike),
        "deployments": dep_rows,
        "platform_instances": len(platform_uris),
        "instrument_instances": len(instrument_instance_uris),
        "component_instances": len(component_instance_uris),
        "deployment_component_link_column": component_link_col,
        "deployment_rows_with_component_link": dep_rows_with_component_link,
    }

    sheet_counts = {
        s: {
            "data_rows": data[s]["data_rows"],
            "non_empty_rows": data[s]["non_empty_rows"],
        }
        for s in REQUIRED_SHEETS
    }

    return {
        "workbook": str(workbook_path),
        "pass": error_count == 0,
        "summary": summary,
        "sheet_counts": sheet_counts,
        "findings": [f.__dict__ for f in findings],
    }


def render_markdown(result: Dict[str, Any]) -> str:
    lines: List[str] = []
    lines.append("# DP2 Verification Report")
    lines.append("")
    lines.append(f"- Workbook: {result['workbook']}")
    lines.append(f"- Result: {'PASS' if result['pass'] else 'FAIL'}")

    summary = result.get("summary", {})
    lines.append(f"- Errors: {summary.get('errors', 0)}")
    lines.append(f"- Warnings: {summary.get('warnings', 0)}")
    lines.append("")

    lines.append("## Summary")
    for k, v in summary.items():
        lines.append(f"- {k}: {v}")
    lines.append("")

    lines.append("## Sheet Counts")
    for sheet, info in result.get("sheet_counts", {}).items():
        lines.append(f"- {sheet}: data_rows={info['data_rows']}, non_empty_rows={info['non_empty_rows']}")
    lines.append("")

    lines.append("## Findings")
    if not result.get("findings"):
        lines.append("- No findings.")
    else:
        for finding in result["findings"]:
            lines.append("")
            lines.append(f"### {finding['severity']} {finding['code']} ({finding['count']})")
            lines.append(f"- {finding['message']}")
            if finding.get("samples"):
                lines.append("- Samples:")
                for sample in finding["samples"]:
                    lines.append(f"  - {sample}")

    return "\n".join(lines)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Verify a DP2 workbook and generate JSON + Markdown reports.")
    parser.add_argument("workbook", help="Path to DP2 workbook (.xlsx)")
    parser.add_argument(
        "--out-dir",
        default=".",
        help="Output directory for reports (default: current directory)",
    )
    parser.add_argument(
        "--prefix",
        default=None,
        help="Output file prefix (default: <workbook_stem>.dp2_verify)",
    )
    parser.add_argument(
        "--sample-size",
        type=int,
        default=10,
        help="Max samples per finding (default: 10)",
    )
    parser.add_argument(
        "--strict",
        action="store_true",
        help="Enable stricter warning checks (deployment time presence).",
    )
    parser.add_argument(
        "--ii-model-policy",
        choices=["ins-only", "ins-or-external", "non-ins-required"],
        default="ins-or-external",
        help=(
            "Policy for InstrumentInstances.a model URIs (default: ins-or-external). "
            "ins-or-external accepts both; ins-only requires INS-like URIs; "
            "non-ins-required requires at least one non-INS URI."
        ),
    )
    parser.add_argument(
        "--ins-workbook",
        default=None,
        help=(
            "Path to INS workbook (.xlsx) used for slot validation in ComponentDeployments "
            "(checks Instrument Slot URI existence and slot-owner/deployment-instrument consistency)."
        ),
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

    prefix = args.prefix if args.prefix else f"{workbook.stem}.dp2_verify"
    json_path = out_dir / f"{prefix}.json"
    md_path = out_dir / f"{prefix}.md"

    result = verify(
        workbook,
        sample_size=args.sample_size,
        strict=args.strict,
        ii_model_policy=args.ii_model_policy,
        ins_workbook=Path(args.ins_workbook) if args.ins_workbook else None,
    )

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
