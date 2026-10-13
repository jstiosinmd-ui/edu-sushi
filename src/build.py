from pathlib import Path
import base64, csv, hashlib, json, re, shutil
from datetime import datetime, timezone
from collections import Counter, defaultdict
from fontTools import subset

ROOT=Path(__file__).resolve().parent.parent
WORKSPACE=next(p for p in ROOT.parents if p.name=='Claude Cowork OS')
SOURCE=WORKSPACE/'19 Board Review and Comprehensive Exam/99 Claude Outputs/20261012_EDU_MedBoardQuestionBank/CSV'
REFERENCE=WORKSPACE/'08 Pediatrics Sample Exams/99 Codex Outputs/20261006_PED_PGHDLSNelsonsTools/.tmp/pgh-pedia-nelsons-qbank/exam-maker.html'
def sha(data):return hashlib.sha256(data).hexdigest()
def encode(path):return base64.b64encode(path.read_bytes()).decode()
def safejson(data):return json.dumps(data,ensure_ascii=False,separators=(',',':')).replace('<','\\u003c').replace('\u2028','\\u2028').replace('\u2029','\\u2029')

items=[];sources=[];raw=[];ids=set();duplicates=defaultdict(list)
for subject in ['InternalMedicine','OBGYN','Pediatrics','Surgery']:
    path=SOURCE/f'20261013_EDU_{subject}QuestionBank_v1.0.csv'
    rows=list(csv.DictReader(path.open(encoding='utf-8-sig',newline='')))
    sources.append({'file':path.name,'sha256':sha(path.read_bytes()),'rows':len(rows)})
    for row in rows:
        assert None not in row and all(v is not None for v in row.values()),row
        assert row['Question ID'] not in ids,row['Question ID']
        ids.add(row['Question ID']);raw.append(row)
        assert row['Correct Answer'] in list('ABCD')
        assert row['Difficulty'] in ['Easy','Average','Difficult']
        assert all(row[k].strip() for k in ['Question','Topic','Rationale','Answer Source']+['Option '+x for x in 'ABCD'])
        assert len(set(row['Option '+x].strip().casefold() for x in 'ABCD'))==4,row['Question ID']
        item={'id':row['Question ID'],'subject':row['Section Title'],'topic':row['Topic'],'tier':row['Difficulty'],
          'stem':row['Question'],'options':[row['Option '+x] for x in 'ABCD'],'key':row['Correct Answer'],
          'rationale':row['Rationale'],'expl':[row['Explanation '+x] for x in 'ABCD'],
          'status':'Released','raw':row}
        items.append(item);duplicates[row['Question'].strip().casefold()].append(row['Question ID'])
assert len(items)==1047
bank={'items':items,'subjects':list(dict.fromkeys(q['subject'] for q in items)),
      'hash':sha(safejson(raw).encode()),'sources':sources,'date':'2026-10-13',
      'counts':dict(Counter(q['subject'] for q in items)),
      'duplicateStems':[v for v in duplicates.values() if len(v)>1]}
(ROOT/'.tmp/bank.json').write_text(safejson(bank),encoding='utf8')
ref=REFERENCE.read_text(encoding='utf8')
scripts=re.findall(r'<script\b([^>]*)>([\s\S]*?)</script>',ref)
libs=[body for tag,body in scripts if len(body)>100000 and not tag.strip()]
assert len(libs)==2
licenses=json.loads(next(body for tag,body in scripts if 'thirdPartyLicenses' in tag))
assets={}
chars=''.join(chr(x) for x in range(32,127))+safejson(bank)+'·≥≤°µαβγδ⁺₂₃–—’“”…'
for role,name in [('regular','calibri.ttf'),('bold','calibrib.ttf')]:
    font=subset.load_font(str(Path('C:/Windows/Fonts')/name),subset.Options())
    opts=subset.Options();opts.layout_features=['*'];opts.name_IDs=['*'];opts.name_languages=['*']
    sub=subset.Subsetter(options=opts);sub.populate(text=chars);sub.subset(font)
    target=ROOT/'.tmp'/name;font.save(target);assets[role]=encode(target)
for role,file in [('logo','20261013_EDU_SushiBrandLogo_v1.0.png'),('banner','20261013_EDU_SushiBrandBanner_v1.0.png')]:
    assets[role]='data:image/png;base64,'+encode(ROOT/'assets'/file)
for role in ['maki','temaki','wasabi','chef']:
    assets[role]='data:image/png;base64,'+encode(ROOT/'assets'/f'20261013_EDU_SushiCharacter{role.title()}_v1.1.png')
for role,part in [('subjectIM','IM'),('subjectPedia','Pediatrics'),('subjectSurgery','Surgery'),('subjectOB','OBGYN')]:
    assets[role]='data:image/png;base64,'+encode(ROOT/'assets'/f'20261013_EDU_SushiSubject{part}_v1.2.png')
template=(ROOT/'src/template.html').read_text(encoding='utf8')
replacements={'STYLE':(ROOT/'src/style.css').read_text(encoding='utf8'),
              'BANK':safejson(bank),'ASSETS':safejson(assets),'LICENSES':safejson(licenses),
              'LIBRARIES':'\n'.join('<script>'+s+'</script>' for s in libs),
              'ENGINE':(ROOT/'src/engine.js').read_text(encoding='utf8'),
              'PDF':(ROOT/'src/pdf.js').read_text(encoding='utf8'),
              'APP':(ROOT/'src/app.js').read_text(encoding='utf8')}
for key,value in replacements.items():template=template.replace('@@'+key+'@@',value)
assert not re.search(r'@@[A-Z]+@@',template)
target=ROOT/'20261013_EDU_SushiClerksExamStudio_v1.3.html'
target.write_text(template,encoding='utf8',newline='\n')
manifest={'built':datetime.now(timezone.utc).isoformat(),'source_files':sources,'bank_hash':bank['hash'],
 'rows':len(items),'subjects':bank['counts'],'topics':len(set((q['subject'],q['topic']) for q in items)),
 'difficulty':dict(Counter(q['tier'] for q in items)),'review_status':dict(Counter(q['status'] for q in items)),
 'duplicate_stem_groups':bank['duplicateStems'],'content_changes':'Question content unchanged. All 33 CSV columns retained; display status Released per owner instruction.',
 'software_scope':'Presentation, study workflows, and exam assembly. No new clinical source review.',
 'html_sha256':sha(target.read_bytes()),'library_source':str(REFERENCE.relative_to(WORKSPACE)),
 'build_files':{p.name:sha(p.read_bytes()) for p in (ROOT/'src').iterdir() if p.is_file()}}
(ROOT/'20261013_EDU_SushiSourceManifest_v1.3.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf8')
print(json.dumps({k:manifest[k] for k in ['rows','subjects','topics','difficulty','duplicate_stem_groups']},ensure_ascii=False))
print(str(target))
