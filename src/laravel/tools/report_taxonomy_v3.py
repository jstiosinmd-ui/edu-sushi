"""Write a reviewable mapping ledger and exact source-change manifest."""
from pathlib import Path
import collections
import csv
import datetime
import hashlib
import json
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
repo = root.parents[1]
output = Path(sys.argv[1]).resolve()
output.mkdir(parents=True, exist_ok=True)
mapping = json.loads((root/"database/data/v3/retag.json").read_text(encoding="utf-8"))
taxonomy = json.loads((root/"database/data/v3/taxonomy.json").read_text(encoding="utf-8"))
subjects = {r["code"]:r["name"] for r in taxonomy["subjects"]}
topics = {r["code"]:r["name"] for r in taxonomy["topics"]}
rows = []
for id, r in sorted(mapping.items()):
    rows.append(dict(question_id=id, old_subject=r["legacy_subject"], old_topic=r["legacy_topic"],
                     subject_code=r["subject_code"], subject=subjects[r["subject_code"]],
                     topic_code=r["topic_code"], topic=topics[r["topic_code"]],
                     subtopic_code=r["subtopic_code"] or "", subtopic_review="provisional" if r["subtopic_code"] else "unassigned",
                     mapping_method=r["mapping_method"], source_topic_code=r["source_topic_code"] or "",
                     origin=r["origin"], note=r["note"]))
for name, records in [
    ("20261018_EDU_SushiRetagLedger_v3.0.csv", rows),
    ("20261018_EDU_SushiSubjectReassignments_v3.0.csv", [r for r in rows if r["old_subject"] != r["subject"]]),
]:
    with (output/name).open("w", newline="", encoding="utf-8-sig") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(records)
status = subprocess.check_output(["git", "status", "--porcelain=v1", "-z", "--untracked-files=all"], cwd=repo).decode().split("\0")
files = []
for entry in status:
    if not entry:
        continue
    path = entry[3:]
    file = repo/path
    if file.is_file():
        files.append(dict(path=path, change=entry[:2].strip(), bytes=file.stat().st_size,
                          sha256=hashlib.sha256(file.read_bytes()).hexdigest()))
manifest = dict(built_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                base_commit=subprocess.check_output(["git","rev-parse","HEAD"],cwd=repo,text=True).strip(),
                deployed=False, pushed=False, questions=len(rows),
                subject_reassignments=sum(r["old_subject"]!=r["subject"] for r in rows),
                topic_changes_from_supplied_map=sum(r["topic_code"]!=r["source_topic_code"] for r in rows),
                provisional_subtopics=sum(bool(r["subtopic_code"]) for r in rows),
                unassigned_subtopics=sum(not r["subtopic_code"] for r in rows),
                subjects=dict(collections.Counter(r["subject_code"] for r in rows)),
                source_manifest=json.loads((root/"database/data/v3/source-manifest.json").read_text()),
                files=files)
(output/"20261018_EDU_SushiImplementationManifest_v3.0.json").write_text(json.dumps(manifest,indent=2)+"\n",encoding="utf-8")
print(json.dumps({k:v for k,v in manifest.items() if k not in ["source_manifest","files"]},indent=2))
print("Changed files:",len(files))
