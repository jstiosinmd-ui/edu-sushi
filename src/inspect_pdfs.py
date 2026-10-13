from pathlib import Path
import json,re,fitz
from PIL import Image,ImageDraw
ROOT=Path(__file__).resolve().parent.parent
OUT=ROOT/'.tmp/pdf-qa';OUT.mkdir(exist_ok=True)
record=json.loads((ROOT/'.tmp/browser-qa/sample-record.json').read_text(encoding='utf8'))
checks=[]
def check(ok,label):
    checks.append({'ok':bool(ok),'name':label})
    assert ok,label
def clean(s):
    return re.sub(r'\s+',' ',s.replace('**','').translate(str.maketrans({'\u2011':'-','\u2013':'-','\u2014':'-','\u2212':'-'}))).strip()
docs={'exam':fitz.open(ROOT/'.tmp/pdf-smoke/20261013_EDU_SushiSampleExam_v1.3.pdf'),'key':fitz.open(ROOT/'.tmp/pdf-smoke/20261013_EDU_SushiSampleAnswerKey_v1.3.pdf')}
text={k:clean(' '.join(p.get_text() for p in d)) for k,d in docs.items()}
for name,doc in docs.items():
    for i,page in enumerate(doc):
        t=page.get_text()
        check('(c) jstiosin 2026' in t and 'Jon Bryan S. Tiosin, MD' in t,f'{name} {i+1}: full credit')
        check(f'Page {i+1} of {len(doc)}' in t,f'{name} {i+1}: numbering')
        check('SUSHI / CLERKS COMPREHENSIVE REVIEW' in t,f'{name} {i+1}: brand header')
        check(not re.search(r'\bDraft\b',t,re.I),f'{name} {i+1}: released without Draft label')
        check(abs(page.rect.width-595.276)<.1 and abs(page.rect.height-841.89)<.1,f'{name} {i+1}: A4')
        spans=[s for b in page.get_text('dict')['blocks'] for l in b.get('lines',[]) for s in l['spans']]
        check(all(s['bbox'][0]>=71 and s['bbox'][2]<=524 and s['bbox'][1]>=27 and s['bbox'][3]<=813 for s in spans),f'{name} {i+1}: text within margins')
        check(all('Calibri' in font[3] for font in page.get_fonts()),f'{name} {i+1}: Calibri fonts')
        page.get_pixmap(matrix=fitz.Matrix(1.25,1.25)).save(OUT/f'{name}-{i+1:02d}.png')
    thumbs=[]
    for i in range(len(doc)):
        im=Image.open(OUT/f'{name}-{i+1:02d}.png').convert('RGB');im.thumbnail((290,411));thumbs.append(im)
    for start in range(0,len(thumbs),12):
        batch=thumbs[start:start+12];sheet=Image.new('RGB',(4*310,((len(batch)+3)//4)*440),'#e6dfdf');draw=ImageDraw.Draw(sheet)
        for k,im in enumerate(batch):
            x=(k%4)*310+10;y=(k//4)*440;draw.text((x,y+4),f'{name} / page {start+k+1}',fill='#452735');sheet.paste(im,(x,y+23))
        sheet.save(OUT/f'{name}-contact-{start//12+1}.png')
for i,q in enumerate(record['questions']):
    check(clean(f'{i+1}. '+q['stem']) in text['exam'],f'question {i+1}: stem parity')
    for option in q['options']:check(clean(option) in text['exam'],f'question {i+1}: option parity')
    check(clean('Correct answer: '+q['key']+'. '+q['options']['ABCD'.index(q['key'])]) in text['key'],f'question {i+1}: key parity')
    check(clean(q['rationale']) in text['key'],f'question {i+1}: rationale parity')
    check(clean(q['raw']['Answer Source']) in text['key'],f'question {i+1}: source parity')
    check(q['id'] in text['key'],f'question {i+1}: original ID')
    for j,why in enumerate(q['expl']):
        if why and 'ABCD'[j]!=q['key']:check(clean(why) in text['key'],f'question {i+1}: distractor explanation parity')
check('Correct answer:' not in text['exam'],'paper contains no keyed disclosures')
check('Answer sheet' in text['exam'],'blank answer sheet included')
check(all(record['code'] in t for t in text.values()),'paper and key share exam code')
result={'checks':checks,'pages':{k:len(v) for k,v in docs.items()}}
(OUT/'results.json').write_text(json.dumps(result,indent=2)+'\n',encoding='utf8')
print(json.dumps({'checks':len(checks),'passed':sum(c['ok'] for c in checks),'pages':result['pages']}))
