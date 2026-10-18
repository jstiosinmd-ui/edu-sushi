"""Build the versioned taxonomy and retag manifest from the supplied local map.

Usage: python tools/build_taxonomy_v3.py <PLEQuestionUniverse directory>
Only taxonomy and existing Sushi metadata enter the bundle, never source wording.
"""
import collections
import csv
import hashlib
import json
from pathlib import Path
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
SOURCE = Path(sys.argv[1])
OUT = ROOT / "database/data/v3"
OUT.mkdir(parents=True, exist_ok=True)
BANKS = ["bank.json", "subjects-20261014-v1.1.json", "basic-sciences-20261014-v1.0.json",
         "physiology-microbiology-20261014-v1.0.json", "ple-top-up-20261016-v2.8.json"]
items = {q["id"]: q for f in BANKS for q in json.loads((ROOT / "database/data" / f).read_text(encoding="utf-8"))["items"]}
mapped = list(csv.DictReader((SOURCE / "build/sushi_mapped.csv").open(encoding="utf-8-sig")))
source_files = list(csv.DictReader((SOURCE / "build/catalog_files.csv").open(encoding="utf-8-sig")))
school_names = {r["path"].replace("\\", "/").split("/")[-1].lower() for r in source_files if r["class"] in ["samplex_school","samplex_image","lecture"]}
assert len(items) == len(mapped) == 6000 and set(items) == {r["id"] for r in mapped}
subjects, topics, subtopics = {}, {}, {}
days = dict.fromkeys(["PHY", "LGM", "PTH"], 1) | dict.fromkeys(["BCH", "ANA", "MPA"], 2) | dict.fromkeys(["OBG", "PED", "PRV"], 3) | dict.fromkeys(["PHA", "SUR", "IM"], 4)
for line in (SOURCE / "build/taxonomy.txt").read_text(encoding="utf-8").splitlines():
    if line.startswith("@"):
        code, name = line[1:].split(" ", 1)
        display = next(q["subject"] for q in items.values() if q["id"].startswith(code + "-"))
        subjects[code] = dict(code=code, name=display, prc_name=name, exam_day=days[code],
                              items_per_exam=None, display_order=len(subjects)+1)
    elif re.match(r"# [A-Z]+\.\d{2} ", line):
        head, rx = line[2:].split("||")
        topic, name = head.strip().split(" ", 1)
        topics[topic] = dict(code=topic, subject_code=code, name=name.strip(),
                             display_order=int(topic.split(".")[1]), label_pattern=rx.strip(),
                             blueprint_weight=None, target_items=None)
    elif line.startswith("- "):
        name, rx = line[2:].split("||")
        sub = f"{topic}.{sum(s['topic_code'] == topic for s in subtopics.values())+1:02d}"
        subtopics[sub] = dict(code=sub, topic_code=topic, name=name.strip(), keywords=rx.strip(),
                              target_items=None, status="draft")
assert (len(subjects),len(topics),len(subtopics)) == (12,175,790)
override_path = ROOT / "tools/taxonomy_v3_overrides.json"
overrides = json.loads(override_path.read_text()) if override_path.exists() else {}
rows = {}
for r in mapped:
    q = items[r["id"]]
    assert r["label"] == q["topic"]
    hits = [t["code"] for t in topics.values() if t["subject_code"] == r["subject"]
            and q["topic"].lower() not in ["human physiology", "systemic pathology"]
            and re.search(t["label_pattern"], q["topic"], re.I)]
    topic = hits[0] if len(hits) == 1 else r["topic"]
    method = "label-preserved" if topic != r["topic"] else r["crosswalk"]
    override = overrides.get(q["id"]) or overrides.get(r["subject"] + "|" + q["topic"])
    if override:
        topic, method = override["topic_code"], "reviewed-override"
    sub = r["subtopic"] if topic == r["topic"] else None
    if override:
        sub = override.get("subtopic_code")
    if topic:
        assert topic in topics
    if sub:
        assert sub in subtopics and subtopics[sub]["topic_code"] == topic
    # Guard against silently applying a mapping to changed clinical content.
    clinical = {k:q[k] for k in ["id","subject","tier","stem","options","key","rationale","expl"]}
    digest = hashlib.sha256(json.dumps(clinical,ensure_ascii=False,separators=(",",":")).encode()).hexdigest()
    locator = q["raw"]["Source Locator"].lower()
    origin = "authority_authored" if "no source question" in locator else ("school_conversion" if any(n and n in locator for n in school_names) else "sushi_conversion")
    rows[q["id"]] = dict(subject_code=topics[topic]["subject_code"] if topic else r["subject"], topic_code=topic, subtopic_code=sub or None,
                          legacy_subject=q["subject"], origin=origin,
                          legacy_topic=q["topic"], mapping_method=method, source_topic_code=r["topic"] or None,
                          source_subtopic_code=r["subtopic"] or None, clinical_hash=digest,
                          note=override.get("note","") if override else "")
tax = dict(schema_version=3, source_date="2026-10-18", subjects=list(subjects.values()),
           topics=list(topics.values()), subtopics=list(subtopics.values()))
assert all(r["topic_code"] for r in rows.values()), "Every existing question needs a reviewed topic assignment."
for name,data in [("taxonomy.json",tax),("retag.json",rows)]:
    (OUT/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
schema = json.loads((SOURCE/"20261018_EDU_SushiQuestionSchema_v1.0.json").read_text())
schema["properties"].update(schema_version={"type":"integer","enum":[3]}, status={"enum":["Draft","Active","Held","Retired"]}, source_review_status={"type":["string","null"]})
schema["$defs"]["reference"]["properties"]["verification_status"] = {"enum":["legacy_unverified","verified"]}
(OUT/"question-content.schema.json").write_text(json.dumps(schema,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
sources = [SOURCE/"build/taxonomy.txt", SOURCE/"build/sushi_mapped.csv", SOURCE/"build/catalog_files.csv",
           SOURCE/"20261018_EDU_SushiDataModel_v1.0.sql", SOURCE/"20261018_EDU_SushiQuestionSchema_v1.0.json"]
(OUT/"source-manifest.json").write_text(json.dumps(dict(
    built_from=[dict(file=p.name,sha256=hashlib.sha256(p.read_bytes()).hexdigest()) for p in sources],
    banks=[dict(file=f,sha256=hashlib.sha256((ROOT/"database/data"/f).read_bytes()).hexdigest()) for f in BANKS],
    questions=6000, mapping_methods=dict(collections.Counter(r["mapping_method"] for r in rows.values())),
    overrides_sha256=hashlib.sha256(override_path.read_bytes()).hexdigest(),
    builder_sha256=hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
    missing_topics=sum(not r["topic_code"] for r in rows.values()),
    unassigned_subtopics=sum(not r["subtopic_code"] for r in rows.values()),
),indent=2)+"\n",encoding="utf-8")
print(json.dumps(json.loads((OUT/"source-manifest.json").read_text()),indent=2))
for id,r in rows.items():
    if not r["topic_code"]:
        print(id, r["legacy_topic"], ":", items[id]["stem"])
