#!/usr/bin/env python3
"""Regenerate WKF Tasks sheets with varied 10-15 task topologies.

This script updates the `Tasks` worksheet of every `WKF-*.xlsx` in pmsr/wkf
by replacing task rows (keeping header row) with process-specific models.
"""

import glob
import os
import re
import tempfile
import zipfile
from collections import defaultdict
from xml.sax.saxutils import escape

WKF_DIR = "/opt/homebrew/var/www/drupal/web/modules/custom/pmsrgui/wkf"

TASK_COUNTS = {
    "WKF-ADMIN_MEDICACAO.xlsx": 10,
    "WKF-AVALIACAO_INICIAL.xlsx": 10,
    "WKF-BARR_COMUNICACAO.xlsx": 11,
    "WKF-COLHEITA_AMOSTRAS.xlsx": 11,
    "WKF-CUIDADOS_DISPOSIT.xlsx": 12,
    "WKF-CUIDADOS_IV_INFUSA.xlsx": 13,
    "WKF-DETERIORACAO_PRE.xlsx": 13,
    "WKF-FERIDA_E_PENSO.xlsx": 12,
    "WKF-GERIATRIA_FRAGIL.xlsx": 12,
    "WKF-HIGIENE_MOB_QUEDA.xlsx": 11,
    "WKF-ISOLAMENTO_CTRL.xlsx": 12,
    "WKF-OXIGENIO_VIA_AEREA.xlsx": 13,
    "WKF-PEDIATRIA_CLIN.xlsx": 14,
    "WKF-PRIOR_RECURSOS.xlsx": 14,
    "WKF-REG_EDUC_COMUNICA.xlsx": 10,
    "WKF-SINAIS_E_EXAME.xlsx": 15,
}

SPECIFIC_TASKS = {
    "WKF-ADMIN_MEDICACAO.xlsx": [
        "Conferir prescricao eletrónica",
        "Validar alergias e interacoes",
        "Executar dupla verificação dos 5 certos",
        "Monitorizar reacao adversa imediata",
    ],
    "WKF-AVALIACAO_INICIAL.xlsx": [
        "Aplicar escala de risco inicial",
        "Priorizar queixa principal",
        "Definir necessidade de observacao imediata",
        "Registar baseline clinico estruturado",
    ],
    "WKF-BARR_COMUNICACAO.xlsx": [
        "Avaliar barreira linguistica predominante",
        "Selecionar estratégia de comunicação alternativa",
        "Validar entendimento por teach-back",
        "Escalar para suporte de comunicação assistida",
    ],
    "WKF-COLHEITA_AMOSTRAS.xlsx": [
        "Confirmar identificacao positiva de amostra",
        "Executar assepsia dirigida ao local",
        "Coletar amostra no volume prescrito",
        "Garantir rotulagem e rastreabilidade imediata",
    ],
    "WKF-CUIDADOS_DISPOSIT.xlsx": [
        "Inspecionar ponto de insercao do dispositivo",
        "Assegurar permeabilidade funcional do dispositivo",
        "Aplicar protocolo de manutenção do dispositivo",
        "Documentar eventos de manipulacao do dispositivo",
    ],
    "WKF-CUIDADOS_IV_INFUSA.xlsx": [
        "Programar bomba de infusao conforme prescricao",
        "Verificar compatibilidade da solucao intravenosa",
        "Reavaliar local de acesso durante infusao",
        "Ajustar taxa de infusao por resposta clinica",
    ],
    "WKF-DETERIORACAO_PRE.xlsx": [
        "Calcular score de alerta precoce",
        "Detectar tendencia de deterioracao hemodinamica",
        "Acionar resposta rapida com SBAR",
        "Preparar escalacao para nivel critico",
    ],
    "WKF-FERIDA_E_PENSO.xlsx": [
        "Classificar tipo e profundidade da ferida",
        "Escolher penso conforme exsudado",
        "Executar tecnica limpa de cobertura",
        "Reavaliar evolucao tecidual e dor",
    ],
    "WKF-GERIATRIA_FRAGIL.xlsx": [
        "Avaliar fragilidade funcional global",
        "Rastrear delirium e risco cognitivo",
        "Ajustar plano para polifarmacia",
        "Definir medidas de prevenção de sindromes geriátricas",
    ],
    "WKF-HIGIENE_MOB_QUEDA.xlsx": [
        "Avaliar risco de queda na mobilizacao",
        "Planear higiene com suporte seguro",
        "Executar transferencia assistida",
        "Reforcar orientacoes de prevenção de queda",
    ],
    "WKF-ISOLAMENTO_CTRL.xlsx": [
        "Classificar tipo de isolamento indicado",
        "Confirmar paramentacao adequada",
        "Controlar fluxo de entrada e saida do quarto",
        "Realizar desparamentacao sem contaminação",
    ],
    "WKF-OXIGENIO_VIA_AEREA.xlsx": [
        "Selecionar interface de oxigenoterapia",
        "Ajustar FiO2 alvo e fluxo",
        "Avaliar permeabilidade de via aérea",
        "Escalar suporte respiratorio se refratario",
    ],
    "WKF-PEDIATRIA_CLIN.xlsx": [
        "Calcular dose por peso pediatrico",
        "Aplicar avaliação pediatrica primária",
        "Ajustar abordagem comunicacional à idade",
        "Reavaliar sinais de gravidade pediátrica",
    ],
    "WKF-PRIOR_RECURSOS.xlsx": [
        "Classificar prioridade clinica operacional",
        "Alocar recurso critico por urgencia",
        "Rebalancear equipa por carga assistencial",
        "Repriorizar fila com gatilhos de risco",
    ],
    "WKF-REG_EDUC_COMUNICA.xlsx": [
        "Registar plano educacional do utente",
        "Aplicar ensino estruturado por objetivos",
        "Validar compreensão com retorno demonstrado",
        "Documentar plano de comunicação de alta",
    ],
    "WKF-SINAIS_E_EXAME.xlsx": [
        "Executar avaliacao primaria ABCDE",
        "Recolher sinais vitais em série",
        "Correlacionar achados de exame fisico",
        "Sinalizar alerta por alteração crítica",
    ],
}

COMMON_TASKS = [
    "Iniciar cenário e validar contexto",
    "Preparar ambiente, equipa e segurança",
    "Executar intervenção central",
    "Verificar resposta clínica imediata",
    "Ajustar plano por evidência observada",
    "Registar dados e comunicar resultados",
    "Encerrar cenário com revisão final",
]

OBJECTIVES = [
    "Segurança clínica",
    "Comunicação",
    "Qualidade",
    "Intervenção",
    "Avaliação",
    "Tomada de decisão",
]

COMPONENTS = [
    "PRESSURE_CHANNEL",
    "FLOW_CHANNEL",
    "TEMP_CHANNEL",
    "SPO2_CHANNEL",
    "ALARM_MODULE",
    "DOSING_MODULE",
    "WAVEFORM_MODULE",
    "EVENT_LOGGER",
]


def col_ref(idx: int) -> str:
    chars = ""
    while idx > 0:
        idx, rem = divmod(idx - 1, 26)
        chars = chr(65 + rem) + chars
    return chars


def cell_inline(ref: str, text: str) -> str:
    return f'<c r="{ref}" t="inlineStr"><is><t>{escape(text)}</t></is></c>'


def build_topology(n_tasks: int, pattern: int):
    # Node 0 is root; nodes 1..n-1 are operational tasks.
    parent = {0: None}
    temporal = defaultdict(list)

    if n_tasks <= 1:
        return parent, temporal

    if pattern == 0:
        # 3 main branches from root, each with short chain.
        branches = [1, 2, 3]
        for b in branches:
            if b < n_tasks:
                parent[b] = 0
        cursor = 4
        for b in branches:
            prev = b
            while cursor < n_tasks and cursor % 3 == b % 3:
                parent[cursor] = prev
                temporal[cursor].append(prev)
                prev = cursor
                cursor += 1
        while cursor < n_tasks:
            anchor = branches[cursor % len(branches)] if branches[cursor % len(branches)] < n_tasks else 1
            parent[cursor] = anchor
            temporal[cursor].append(anchor)
            cursor += 1
    elif pattern == 1:
        # Deep chain + side branches.
        parent[1] = 0
        for i in range(2, n_tasks):
            if i in (4, 7, 10):
                parent[i] = max(1, i - 3)
            else:
                parent[i] = i - 1
                temporal[i].append(i - 1)
    elif pattern == 2:
        # Parallel fan-out then consolidation.
        fan = min(5, n_tasks - 1)
        for i in range(1, fan + 1):
            parent[i] = 0
        merge_anchor = fan if fan > 0 else 1
        for i in range(fan + 1, n_tasks):
            if i % 2 == 0:
                parent[i] = merge_anchor
            else:
                parent[i] = max(1, i - 2)
            temporal[i].append(max(1, i - 1))
    else:
        # Dual trunks with cross-link dependencies.
        trunk_a = 1
        trunk_b = 2 if n_tasks > 2 else 1
        parent[trunk_a] = 0
        if trunk_b != trunk_a:
            parent[trunk_b] = 0
        for i in range(3, n_tasks):
            if i % 2 == 0:
                parent[i] = i - 2
            else:
                parent[i] = i - 1
            temporal[i].append(max(1, i - 1))
            if i > 4 and i % 3 == 0:
                temporal[i].append(max(1, i - 3))

    for i in range(1, n_tasks):
        if i not in parent:
            parent[i] = max(0, i - 1)
            temporal[i].append(max(0, i - 1))

    return parent, temporal


def build_labels(filename: str, n_tasks: int):
    specific = SPECIFIC_TASKS.get(filename, [])
    slug = filename.replace("WKF-", "").replace(".xlsx", "").replace("_", " ")

    labels = [slug.title()]
    labels.extend(specific[:4])

    i = 0
    while len(labels) < n_tasks:
        labels.append(f"{COMMON_TASKS[i % len(COMMON_TASKS)]} ({slug})")
        i += 1

    return labels[:n_tasks]


def build_rows(base_uri: str, filename: str, n_tasks: int, pattern: int):
    ids = ["0100"] + [f"{110 + i:04d}" for i in range(n_tasks - 1)]
    uris = [f"{base_uri}/TSK/{task_id}" for task_id in ids]
    labels = build_labels(filename, n_tasks)

    parent, temporal = build_topology(n_tasks, pattern)

    children = defaultdict(list)
    for child, par in parent.items():
        if par is not None:
            children[par].append(child)

    sensor_base = f"https://pmsr.net/ont/INS/{filename.replace('.xlsx', '')}-MONITOR/COMP"

    rows_xml = []
    for idx in range(n_tasks):
        row_num = idx + 2
        vals = {
            "A": uris[idx],
            "B": "vstoi:AbstractTask" if idx == 0 else ("vstoi:InteractionTask" if idx % 3 == 0 else "vstoi:UserTask"),
            "C": "vstoi:Task",
            "D": labels[idx],
            "E": f"Tarefa do processo {filename.replace('.xlsx', '')} com foco operacional específico.",
            "F": "vstoi:Current",
            "G": "pt-PT",
            "H": "1.0",
            "K": "equipa@pmsr.net",
            "L": "equipa@pmsr.net",
            "M": uris[parent[idx]] if parent.get(idx) is not None else "",
            "N": " ; ".join(uris[c] for c in children.get(idx, [])),
            "O": " ; ".join(uris[t] for t in temporal.get(idx, [])),
            "P": f"{sensor_base}/{COMPONENTS[idx % len(COMPONENTS)]}",
            "T": OBJECTIVES[idx % len(OBJECTIVES)],
        }

        cells = []
        for col_num in range(1, 21):
            col = col_ref(col_num)
            text = vals.get(col, "")
            if text:
                cells.append(cell_inline(f"{col}{row_num}", text))
        rows_xml.append(f'<row r="{row_num}">' + "".join(cells) + "</row>")

    return "".join(rows_xml)


def replace_sheet_data(sheet_xml: str, header_row_xml: str, generated_rows_xml: str) -> str:
    new_sheet_data = f"<sheetData>{header_row_xml}{generated_rows_xml}</sheetData>"
    return re.sub(r"<sheetData>.*?</sheetData>", new_sheet_data, sheet_xml, count=1, flags=re.DOTALL)


def get_tasks_target(workbook_xml: str, rels_xml: str) -> str:
    m_sheet = re.search(r'<sheet[^>]*name="Tasks"[^>]*r:id="([^"]+)"', workbook_xml)
    if not m_sheet:
        return ""
    rid = m_sheet.group(1)
    m_rel = re.search(rf'<Relationship[^>]*Id="{re.escape(rid)}"[^>]*Target="([^"]+)"', rels_xml)
    return m_rel.group(1) if m_rel else ""


def process_file(path: str, index: int):
    filename = os.path.basename(path)
    n_tasks = TASK_COUNTS.get(filename, 10)
    pattern = index % 4

    with zipfile.ZipFile(path, "r") as zin:
        names = zin.namelist()
        wb_xml = zin.read("xl/workbook.xml").decode("utf-8")
        rels_xml = zin.read("xl/_rels/workbook.xml.rels").decode("utf-8")
        target = get_tasks_target(wb_xml, rels_xml)
        if not target:
            raise RuntimeError(f"Tasks sheet not found in {filename}")

        sheet_name = f"xl/{target}"
        sheet_xml = zin.read(sheet_name).decode("utf-8")

        m_header = re.search(r'(<row r="1">.*?</row>)', sheet_xml, flags=re.DOTALL)
        if not m_header:
            raise RuntimeError(f"Header row not found in Tasks sheet for {filename}")
        header_row = m_header.group(1)

        m_base = re.search(r'https://pmsr\.net/ont/[^<]+/TSK/0100', sheet_xml)
        if m_base:
            base_uri = m_base.group(0).rsplit('/TSK/', 1)[0]
        else:
            slug = filename.replace(".xlsx", "")
            base_uri = f"https://pmsr.net/ont/{slug}-20260811"

        generated_rows = build_rows(base_uri, filename, n_tasks, pattern)
        new_sheet_xml = replace_sheet_data(sheet_xml, header_row, generated_rows)

        fd, tmp_path = tempfile.mkstemp(suffix=".xlsx")
        os.close(fd)
        try:
            with zipfile.ZipFile(tmp_path, "w", compression=zipfile.ZIP_DEFLATED) as zout:
                for n in names:
                    data = zin.read(n)
                    if n == sheet_name:
                        data = new_sheet_xml.encode("utf-8")
                    zout.writestr(n, data)
            os.replace(tmp_path, path)
        finally:
            if os.path.exists(tmp_path):
                os.unlink(tmp_path)


def main():
    files = sorted(glob.glob(os.path.join(WKF_DIR, "WKF-*.xlsx")))
    if not files:
        print("No WKF files found")
        return

    for idx, path in enumerate(files):
        process_file(path, idx)
        print(f"Updated {os.path.basename(path)}")


if __name__ == "__main__":
    main()
