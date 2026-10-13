from pathlib import Path
from datetime import datetime, timezone
import csv, hashlib, json, re, zipfile

ROOT=Path(__file__).resolve().parent.parent
WORKSPACE=next(p for p in ROOT.parents if p.name=='Claude Cowork OS')
SOURCE=WORKSPACE/'19 Board Review and Comprehensive Exam/99 Claude Outputs/20261012_EDU_MedBoardQuestionBank/CSV'
PREVIOUS=ROOT.with_name(ROOT.name.replace('v1.3','v1.2'))
def sha(path): return hashlib.sha256(path.read_bytes()).hexdigest()
def bank_at(path):
    return json.loads(re.search(r'<script id="bankData" type="application/json">(.*?)</script>',path.read_text(encoding='utf8'),re.S).group(1))
html_path=ROOT/'20261013_EDU_SushiClerksExamStudio_v1.3.html'
bank=bank_at(html_path)
assert bank==bank_at(PREVIOUS/'20261013_EDU_SushiClerksExamStudio_v1.2.html')
ids={q['id']:q for q in bank['items']}
rows=0
for source in bank['sources']:
    p=SOURCE/source['file']
    assert sha(p)==source['sha256']
    for row in csv.DictReader(p.open(encoding='utf-8-sig',newline='')):
        assert ids[row['Question ID']]['raw']==row
        rows+=1
assert rows==1047
unchanged={}
for name in ['engine.js','pdf.js']:
    assert (ROOT/'src'/name).read_bytes()==(PREVIOUS/'src'/name).read_bytes()
    unchanged[name]=sha(ROOT/'src'/name)
for p in (ROOT/'assets').iterdir():
    assert p.read_bytes()==(PREVIOUS/'assets'/p.name).read_bytes()
source_manifest=json.loads((ROOT/'20261013_EDU_SushiSourceManifest_v1.3.json').read_text(encoding='utf8'))
assert source_manifest['html_sha256']==sha(html_path)
assert all(source_manifest['build_files'][p.name]==sha(p) for p in (ROOT/'src').iterdir() if p.is_file())
qa=json.loads((ROOT/'.tmp/sticky-review.json').read_text(encoding='utf8'))
assert not qa.get('error') and not qa['errors'] and not qa['requests']
assert all(c['ok'] for c in qa['checks'])
manifest_name='20261013_EDU_SushiReleaseManifest_v1.3.json'
zip_name='20261013_EDU_SushiClerksExamStudio_v1.3.zip'
files=sorted(p for p in ROOT.rglob('*') if p.is_file() and not {'.tmp','__pycache__','Archive'}.intersection(p.parts) and p.name not in [manifest_name,zip_name])
manifest={
 'title':'SUSHI Clerks Comprehensive Exam Reviewer','version':'1.3','status':'Released',
 'built':datetime.now(timezone.utc).isoformat(),'owner':'Jon Bryan S. Tiosin, MD','credit':'(c) jstiosin 2026',
 'changes':['Current achievement tier and progress in the top sticky bar on every view','Tap the badge to open all six tiers in a scrollable passport','Passport preserves the active question, view, and scroll position','Creator credit included in the passport'],
 'source_rows_checked':rows,'bank_identical_to':'v1.2','unchanged_code_sha256':unchanged,'all_artwork_unchanged':True,
 'checks_total':len(qa['checks']),'browser_inspection':qa,
 'visual_review':'Desktop header and six-tier passport; mobile sticky header while scrolled and mobile passport. No clipping or horizontal overflow observed.',
 'testing_scope':'Chrome desktop and mobile viewport/touch emulation. No physical phones or Safari testing.',
 'prior_evidence':{'release':'v1.2','checks':705,'note':'Prior results only. Broad engine and PDF regression suites were not repeated for this interface change.'},
 'progress_compatibility':'The existing progress and session formats are retained. Export a backup from the prior HTML, then restore it in v1.3 if the browser associates storage with each file path. Prior HTML paths remain available.',
 'content_scope':'Released supplied questions remain unchanged. No new clinical review was performed.',
 'package':zip_name,
 'files':[{'path':p.relative_to(ROOT).as_posix(),'bytes':p.stat().st_size,'sha256':sha(p)} for p in files]
}
(ROOT/manifest_name).write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
with zipfile.ZipFile(ROOT/zip_name,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
    for p in files+[ROOT/manifest_name]:archive.write(p,p.relative_to(ROOT).as_posix())
with zipfile.ZipFile(ROOT/zip_name) as archive:
    assert archive.testzip() is None
    for p in files+[ROOT/manifest_name]:assert archive.read(p.relative_to(ROOT).as_posix())==p.read_bytes()
print(json.dumps({'checks_passed':len(qa['checks']),'source_rows_unchanged':rows,'files':len(files)+2,'zip_bytes':(ROOT/zip_name).stat().st_size}))
