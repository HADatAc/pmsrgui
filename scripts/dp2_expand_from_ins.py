#!/usr/bin/env python3
"""
Expand DP2 component instances and deployment component links from INS source-of-truth.

Reads:
- INS workbook (models/components)
- DP2 workbook (instrument instances/deployments/component instances)

Writes:
- DP2 workbook in-place (with backup)

The INS workbook is never modified.
"""

from __future__ import annotations

import argparse
import re
import shutil
import zipfile
from collections import defaultdict
from pathlib import Path
from typing import Dict, List, Tuple
from xml.etree import ElementTree as ET

NS_MAIN = "http://schemas.openxmlformats.org/spreadsheetml/2006/main"
NS_REL_DOC = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
NS_REL_PKG = "http://schemas.openxmlformats.org/package/2006/relationships"

ET.register_namespace("", NS_MAIN)
ET.register_namespace("r", NS_REL_DOC)

ns = {"a": NS_MAIN, "r": NS_REL_DOC, "pr": NS_REL_PKG}


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
    for si in root.findall("a:si", ns):
        txt = "".join(t.text or "" for t in si.findall(".//a:t", ns))
        out.append(txt)
    return out


def cell_text(cell: ET.Element, sst: List[str]) -> str:
    ctype = cell.get("t")
    if ctype == "s":
        v = cell.find("a:v", ns)
        if v is None or v.text is None:
            return ""
        try:
            idx = int(v.text)
        except ValueError:
            return ""
        return sst[idx] if 0 <= idx < len(sst) else ""

    if ctype == "inlineStr":
        return "".join(t.text or "" for t in cell.findall(".//a:t", ns)).strip()

    v = cell.find("a:v", ns)
    if v is None or v.text is None:
        return ""
    return v.text.strip()


def sheet_paths(zf: zipfile.ZipFile) -> Dict[str, str]:
    wb = ET.fromstring(zf.read("xl/workbook.xml"))
    rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))

    relmap = {
        rel.get("Id"): rel.get("Target")
        for rel in rels.findall("pr:Relationship", ns)
        if rel.get("Id")
    }

    out: Dict[str, str] = {}
    sheets = wb.find("a:sheets", ns)
    if sheets is None:
        return out

    for s in sheets.findall("a:sheet", ns):
        name = s.get("name", "")
        rid = s.get(f"{{{NS_REL_DOC}}}id", "")
        tgt = relmap.get(rid, "")
        if tgt.startswith("worksheets/"):
            out[name] = "xl/" + tgt
        elif tgt:
            out[name] = "xl/worksheets/" + tgt.split("/")[-1]
    return out


def parse_sheet_records(zf: zipfile.ZipFile, path: str, sst: List[str]) -> List[Dict[str, str]]:
    root = ET.fromstring(zf.read(path))
    sd = root.find("a:sheetData", ns)
    rows = sd.findall("a:row", ns) if sd is not None else []

    headers_by_col: Dict[str, str] = {}
    ordered_cols: List[str] = []
    if rows:
        for c in rows[0].findall("a:c", ns):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            headers_by_col[col] = cell_text(c, sst).strip()
        ordered_cols = sorted(headers_by_col.keys(), key=col_to_num)

    records: List[Dict[str, str]] = []
    for row in rows[1:]:
        vals: Dict[str, str] = {}
        for c in row.findall("a:c", ns):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            vals[col] = cell_text(c, sst)

        rec: Dict[str, str] = {}
        for col in ordered_cols:
            rec[headers_by_col[col]] = vals.get(col, "")
        records.append(rec)

    return records


def parse_sheet_records_from_bytes(xml_bytes: bytes, sst: List[str]) -> List[Dict[str, str]]:
    root = ET.fromstring(xml_bytes)
    sd = root.find("a:sheetData", ns)
    rows = sd.findall("a:row", ns) if sd is not None else []

    headers_by_col: Dict[str, str] = {}
    ordered_cols: List[str] = []
    if rows:
        for c in rows[0].findall("a:c", ns):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            headers_by_col[col] = cell_text(c, sst).strip()
        ordered_cols = sorted(headers_by_col.keys(), key=col_to_num)

    records: List[Dict[str, str]] = []
    for row in rows[1:]:
        vals: Dict[str, str] = {}
        for c in row.findall("a:c", ns):
            ref = c.get("r", "")
            col = "".join(ch for ch in ref if ch.isalpha())
            vals[col] = cell_text(c, sst)

        rec: Dict[str, str] = {}
        for col in ordered_cols:
            rec[headers_by_col[col]] = vals.get(col, "")
        records.append(rec)

    return records


def header_map_from_root(root: ET.Element, sst: List[str]) -> Dict[str, str]:
    sd = root.find("a:sheetData", ns)
    rows = sd.findall("a:row", ns) if sd is not None else []
    out: Dict[str, str] = {}
    if not rows:
        return out
    for c in rows[0].findall("a:c", ns):
        ref = c.get("r", "")
        col = "".join(ch for ch in ref if ch.isalpha())
        out[col] = cell_text(c, sst).strip()
    return out


def get_or_create_row(sd: ET.Element, rnum: int) -> ET.Element:
    for r in sd.findall("a:row", ns):
        if int(r.get("r", "0")) == rnum:
            return r

    nr = ET.Element(f"{{{NS_MAIN}}}row", {"r": str(rnum)})
    inserted = False
    for idx, ex in enumerate(sd.findall("a:row", ns)):
        if int(ex.get("r", "0")) > rnum:
            sd.insert(idx, nr)
            inserted = True
            break
    if not inserted:
        sd.append(nr)
    return nr


def set_inline_cell(row: ET.Element, col: str, rnum: int, text: str) -> None:
    cref = f"{col}{rnum}"
    target = None
    for c in row.findall("a:c", ns):
        if c.get("r") == cref:
            target = c
            break

    if target is None:
        target = ET.Element(f"{{{NS_MAIN}}}c", {"r": cref, "t": "inlineStr"})
        current = row.findall("a:c", ns)
        inserted = False
        cnum = col_to_num(col)
        for idx, ex in enumerate(current):
            ecol = "".join(ch for ch in ex.get("r", "") if ch.isalpha())
            if ecol and col_to_num(ecol) > cnum:
                row.insert(idx, target)
                inserted = True
                break
        if not inserted:
            row.append(target)

    for child in list(target):
        target.remove(child)
    target.set("t", "inlineStr")
    isel = ET.SubElement(target, f"{{{NS_MAIN}}}is")
    t = ET.SubElement(isel, f"{{{NS_MAIN}}}t")
    t.text = text


def nz(v: str) -> str:
    return (v or "").strip()


def local_token(uri: str, max_len: int = 28) -> str:
    v = nz(uri)
    if not v:
        return "NA"
    if ":" in v:
        v = v.split(":", 1)[1]
    if "/" in v:
        v = v.rsplit("/", 1)[-1]
    v = re.sub(r"[^A-Za-z0-9]+", "", v)
    if not v:
        v = "NA"
    return v[:max_len]


def ensure_unique_uri(base_uri: str, used: set) -> str:
    if base_uri not in used:
        return base_uri
    idx = 2
    while True:
        cand = f"{base_uri}_{idx}"
        if cand not in used:
            return cand
        idx += 1


def main() -> int:
    ap = argparse.ArgumentParser(description="Expand DP2 component instances and deployment component links from INS.")
    ap.add_argument("ins", help="Path to INS workbook")
    ap.add_argument("dp2", help="Path to DP2 workbook to update in-place")
    ap.add_argument("--backup", default=None, help="Optional backup path for DP2 workbook")
    args = ap.parse_args()

    ins_path = Path(args.ins)
    dp2_path = Path(args.dp2)

    if not ins_path.exists():
        print(f"FAIL: INS workbook not found: {ins_path}")
        return 1
    if not dp2_path.exists():
        print(f"FAIL: DP2 workbook not found: {dp2_path}")
        return 1

    # Read INS source-of-truth.
    with zipfile.ZipFile(ins_path, "r") as zf:
        sst = parse_shared_strings(zf)
        paths = sheet_paths(zf)
        if "Components" not in paths:
            print("FAIL: INS workbook missing Components sheet")
            return 1
        ins_components = parse_sheet_records(zf, paths["Components"], sst)

    comp_by_model: Dict[str, List[Tuple[str, str]]] = defaultdict(list)
    for rec in ins_components:
        c_uri = nz(rec.get("hasURI", ""))
        model = nz(rec.get("vstoi:isAttributeOf", ""))
        label = nz(rec.get("rdfs:label", ""))
        if c_uri and model:
            comp_by_model[model].append((c_uri, label))

    # Prepare backup for DP2.
    backup_path = Path(args.backup) if args.backup else dp2_path.with_name(dp2_path.stem + ".pre-ins-expansion.xlsx")
    if not backup_path.exists():
        shutil.copy2(dp2_path, backup_path)

    with zipfile.ZipFile(dp2_path, "r") as zf:
        files = {name: zf.read(name) for name in zf.namelist()}
        sst = parse_shared_strings(zf)
        paths = sheet_paths(zf)

    for req in ["InstrumentInstances", "ComponentInstances", "Deployments", "ComponentDeployments"]:
        if req not in paths:
            print(f"FAIL: DP2 workbook missing required sheet: {req}")
            return 1

    ii_records = parse_sheet_records_from_bytes(files[paths["InstrumentInstances"]], sst)

    comp_root = ET.fromstring(files[paths["ComponentInstances"]])
    dep_root = ET.fromstring(files[paths["Deployments"]])
    comp_sd = comp_root.find("a:sheetData", ns)
    dep_sd = dep_root.find("a:sheetData", ns)
    if comp_sd is None or dep_sd is None:
        print("FAIL: invalid DP2 worksheet XML")
        return 1

    comp_hmap = header_map_from_root(comp_root, sst)
    dep_hmap = header_map_from_root(dep_root, sst)
    comp_col_by_header = {h: c for c, h in comp_hmap.items() if h}
    dep_col_by_header = {h: c for c, h in dep_hmap.items() if h}

    for h in ["hasURI", "a", "rdfs:label"]:
        if h not in comp_col_by_header:
            print(f"FAIL: ComponentInstances missing header: {h}")
            return 1
    if "vstoi:hasSerialNumber" not in comp_col_by_header:
        print("FAIL: ComponentInstances missing header: vstoi:hasSerialNumber")
        return 1
    for h in ["hasURI", "vstoi:hasInstrumentInstance"]:
        if h not in dep_col_by_header:
            print(f"FAIL: Deployments missing header: {h}")
            return 1

    link_header = "vstoi:hasComponentInstance" if "vstoi:hasComponentInstance" in dep_col_by_header else "vstoi:hasDetectorInstance"
    if link_header not in dep_col_by_header:
        print("FAIL: Deployments missing component link column")
        return 1

    # Existing component instance URIs.
    existing_ci: set = set()
    existing_ci_by_uri: set = set()
    max_comp_row = 1
    blank_comp_rows: List[int] = []
    for r in comp_sd.findall("a:row", ns):
        rn = int(r.get("r", "0"))
        max_comp_row = max(max_comp_row, rn)
        if rn == 1:
            continue
        vals: Dict[str, str] = {}
        for c in r.findall("a:c", ns):
            col = "".join(ch for ch in c.get("r", "") if ch.isalpha())
            vals[col] = cell_text(c, sst).strip()
        uri_val = vals.get(comp_col_by_header["hasURI"], "")
        if uri_val:
            existing_ci.add(uri_val)
            existing_ci_by_uri.add(uri_val)
        if not any((v or "").strip() for v in vals.values()):
            blank_comp_rows.append(rn)

    # Build required component instances per instrument instance.
    # Key: instrument instance URI -> list of generated component instance URIs.
    ci_by_instrument: Dict[str, List[str]] = defaultdict(list)

    rows_to_write: List[Tuple[str, str, str, str]] = []
    planned_new_uris: set = set()
    # tuple: (ci_uri, component_model_uri, label, serial)

    for rec in ii_records:
        inst_uri = nz(rec.get("hasURI", ""))
        model_uri = nz(rec.get("a", ""))
        inst_label = nz(rec.get("rdfs:label", ""))
        inst_serial = nz(rec.get("vstoi:hasSerialNumber", ""))
        if not inst_uri:
            continue

        templates = comp_by_model.get(model_uri, [])
        for comp_uri, comp_label in templates:
            inst_tok = local_token(inst_uri, 20)
            comp_tok = local_token(comp_uri, 20)
            base = f"pmsr:CPI-{inst_tok}-{comp_tok}"
            is_existing = base in existing_ci_by_uri
            if is_existing:
                ci_uri = base
            else:
                ci_uri = ensure_unique_uri(base, existing_ci)
                existing_ci_by_uri.add(ci_uri)
                existing_ci.add(ci_uri)

            label_core = comp_label if comp_label else comp_tok
            label = f"{label_core} for {inst_label if inst_label else inst_uri}"
            serial = f"{inst_serial}-{comp_tok}" if inst_serial else f"AUTO-{inst_tok}-{comp_tok}"

            if (not is_existing) and (ci_uri not in planned_new_uris):
                rows_to_write.append((ci_uri, comp_uri, label, serial))
                planned_new_uris.add(ci_uri)

            # If URI already exists from prior runs, we reuse it and only relink deployments.
            ci_by_instrument[inst_uri].append(ci_uri)

    # Write ComponentInstances rows (fill blanks first, then append).
    write_rows = sorted(blank_comp_rows)
    next_row = max_comp_row + 1
    widx = 0
    for ci_uri, comp_model_uri, label, serial in rows_to_write:
        if widx < len(write_rows):
            rnum = write_rows[widx]
            widx += 1
        else:
            rnum = next_row
            next_row += 1

        row = get_or_create_row(comp_sd, rnum)
        set_inline_cell(row, comp_col_by_header["hasURI"], rnum, ci_uri)
        set_inline_cell(row, comp_col_by_header["a"], rnum, comp_model_uri)
        set_inline_cell(row, comp_col_by_header["rdfs:label"], rnum, label)
        set_inline_cell(row, comp_col_by_header["vstoi:hasSerialNumber"], rnum, serial)

    # Update Deployments link field for every deployment row with known instrument instance mapping.
    dep_uri_col = dep_col_by_header["hasURI"]
    dep_inst_col = dep_col_by_header["vstoi:hasInstrumentInstance"]
    dep_link_col = dep_col_by_header[link_header]

    dep_updated = 0
    for r in dep_sd.findall("a:row", ns):
        rn = int(r.get("r", "0"))
        if rn == 1:
            continue
        vals: Dict[str, str] = {}
        for c in r.findall("a:c", ns):
            col = "".join(ch for ch in c.get("r", "") if ch.isalpha())
            vals[col] = cell_text(c, sst).strip()

        dep_uri = nz(vals.get(dep_uri_col, ""))
        inst_uri = nz(vals.get(dep_inst_col, ""))
        if not dep_uri or not inst_uri:
            continue

        links = ci_by_instrument.get(inst_uri, [])
        if links:
            set_inline_cell(r, dep_link_col, rn, ";".join(links))
            dep_updated += 1

    # Serialize workbook with changes to DP2 only.
    files[paths["ComponentInstances"]] = ET.tostring(comp_root, encoding="utf-8", xml_declaration=True)
    files[paths["Deployments"]] = ET.tostring(dep_root, encoding="utf-8", xml_declaration=True)

    tmp_out = dp2_path.with_suffix(".xlsx.tmp")
    with zipfile.ZipFile(tmp_out, "w", compression=zipfile.ZIP_DEFLATED) as zout:
        for name, content in files.items():
            zout.writestr(name, content)

    tmp_out.replace(dp2_path)

    print("INS source workbook (read-only):", ins_path)
    print("DP2 updated workbook:", dp2_path)
    print("Backup:", backup_path)
    print("INS component templates mapped models:", len(comp_by_model))
    print("ComponentInstances rows written:", len(rows_to_write))
    print("Deployments rows updated with component links:", dep_updated)
    print("Deployment link header used:", link_header)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
