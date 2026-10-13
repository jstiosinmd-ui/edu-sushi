const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright'),fs=require('fs'),path=require('path'),{pathToFileURL}=require('url');
const R=path.resolve(__dirname,'..'),OUT=path.join(R,'.tmp/subject-qa');fs.mkdirSync(OUT,{recursive:true});
const checks=[];function check(ok,name){checks.push({ok,name});if(!ok)throw Error(name);}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_PATH});try{
 const context=await browser.newContext({viewport:{width:1440,height:1100},reducedMotion:'reduce',hasTouch:true});await context.setOffline(true);const p=await context.newPage(),errors=[],requests=[];
 p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(/^https?:/.test(r.url()))requests.push(r.url());});
 await p.goto(pathToFileURL(path.join(R,'20261013_EDU_SushiClerksExamStudio_v1.3.html')).href);await p.waitForFunction(()=>SushiStudio.ready);
 const crew={'Internal Medicine':'Miso Medic','Pediatrics':'Pip the Pedia Roll','Surgery':'Suture Salmon','OB-GYN':'Nori Nurture'};
 for(const [subject,name] of Object.entries(crew)){
  const card=p.locator(`#subjectCards [data-subject="${subject}"]`);
  check((await card.innerText()).includes(name),'home character name: '+subject);
  check(await card.locator('img').getAttribute('data-subject-art')===subject,'home matching art: '+subject);
  await card.tap();check(await p.locator('#studySubject').inputValue()===subject,'rotation shortcut filter: '+subject);
  check(await p.locator('#studyCrew img').count()===1&&await p.locator('#studyCrew img').getAttribute('data-subject-art')===subject,'practice setup follows subject: '+subject);
  await p.locator('#studyCount').fill('1');await p.locator('#startStudy').tap();
  check(await p.locator('#questionMeta img').getAttribute('data-subject-art')===subject,'question matching art: '+subject);
  check((await p.locator('#questionMeta').innerText()).includes(name),'question character name: '+subject);
  const key=await p.evaluate(()=>SushiStudio.bank.items.find(q=>q.id===SushiStudio.session.ids[0]).key);await p.locator(`input[name=answer][value=${key}]`).check();await p.locator('#checkAnswer').tap();
  check((await p.locator('#questionFeedback').innerText()).includes('Correct. Nicely done.'),'answer works with subject art: '+subject);
  await p.locator('#questionMeta').evaluate(e=>window.scrollTo(0,e.getBoundingClientRect().top+scrollY-110));
  await p.screenshot({path:path.join(OUT,subject.replace(/[^A-Za-z]/g,'')+'-practice.png')});
  await p.locator('#finishStudy').tap();await p.locator('#confirmYes').tap();check(await p.locator('#sessionResults [data-subject-art]').getAttribute('data-subject-art')===subject,'results matching art: '+subject);
  await p.evaluate(()=>SushiStudio.go('library'));await p.locator('#librarySubject').selectOption(subject);
  check(await p.locator('#libraryCrew img').count()===1&&await p.locator('#libraryCrew img').getAttribute('data-subject-art')===subject,'library header matches filter: '+subject);
  check(await p.locator('#libraryList [data-subject-art]').evaluateAll((es,s)=>es.length>0&&es.every(e=>e.dataset.subjectArt===s),subject),'library cards match filter: '+subject);
  await p.evaluate(()=>SushiStudio.go('home'));
 }
 await p.waitForFunction(()=>!document.getElementById('toast').textContent);
 await p.locator('#subjectCards').screenshot({path:path.join(R,'20261013_EDU_SushiSubjectCrew_v1.3.png')});
 await p.evaluate(()=>SushiStudio.go('home'));await p.screenshot({path:path.join(R,'20261013_EDU_SushiLandingDesktop_v1.3.png'),fullPage:true});
 await p.locator('[data-mode=fresh]').tap();check(await p.locator('#freshSubjects img').count()===4,'Fresh Catch has four subject characters');
 for(const label of await p.locator('#freshSubjects label').all()){check(await label.locator('input').inputValue()===await label.locator('img').getAttribute('data-subject-art'),'Fresh Catch portrait matches checkbox');}
 for(const el of await p.locator('#freshSubjects input').all())await el.uncheck();check(await p.locator('#studyCrew img').count()===0,'empty selection shows no unrelated crew');
 await p.locator('#freshSubjects input[value="Pediatrics"]').check();await p.locator('#freshSubjects input[value="Surgery"]').check();check(await p.locator('#studyCrew img').count()===2,'Fresh Catch preview follows selection');
 for(const el of await p.locator('#freshSubjects input').all())await el.check();await p.locator('#startStudy').tap();
 const ids=await p.evaluate(()=>SushiStudio.session.ids),subjects=await p.evaluate(()=>SushiStudio.session.ids.map(id=>SushiStudio.bank.items.find(q=>q.id===id).subject));
 for(let i=0;i<ids.length;i++){await p.locator(`#questionMap [data-index="${i}"]`).tap();check(await p.locator('#questionMeta img').getAttribute('data-subject-art')===subjects[i],'mixed set question '+(i+1)+' follows its subject');}
 await p.evaluate(()=>SushiStudio.go('maker'));
 for(const label of await p.locator('#examSubjects label').all())check(await label.locator('input').inputValue()===await label.locator('img').getAttribute('data-subject-art'),'Bento portrait matches checkbox');
 await p.locator('#examCount').fill('4');await p.locator('#generateExam').tap();check(await p.locator('#examSummary img').count()===4,'exam summary includes four selected subject mascots');
 const record=await p.evaluate(()=>SushiStudio.exam);check((await p.locator('#examPreview img').evaluateAll(es=>es.map(e=>e.dataset.subjectArt))).join('|')===record.questions.map(q=>q.subject).join('|'),'paper preview uses each question subject');
 for(const width of [320,360,390,430,768,1024,1440]){await p.setViewportSize({width,height:900});for(const view of ['home','practice','library','maker','about']){await p.evaluate(v=>SushiStudio.go(v),view);check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'subject art fits '+view+' at '+width);}}
 await p.setViewportSize({width:390,height:1100});await p.evaluate(()=>SushiStudio.go('home'));await p.locator('#subjectCards').evaluate(e=>window.scrollTo(0,e.previousElementSibling.getBoundingClientRect().top+scrollY-94));await p.screenshot({path:path.join(R,'20261013_EDU_SushiSubjectMobile_v1.3.png')});
 await p.evaluate(()=>SushiStudio.go('practice'));await p.locator('#questionMap [data-index="0"]').tap();await p.locator('#sessionArea').evaluate(e=>window.scrollTo(0,e.getBoundingClientRect().top+scrollY-90));await p.screenshot({path:path.join(R,'20261013_EDU_SushiPracticeMobile_v1.3.png')});
 await p.evaluate(()=>SushiStudio.go('library'));await p.locator('#librarySubject').selectOption('OB-GYN');await p.screenshot({path:path.join(R,'20261013_EDU_SushiLibraryMobile_v1.3.png')});
 await p.evaluate(()=>SushiStudio.go('maker'));await p.locator('#examSubjects').evaluate(e=>window.scrollTo(0,e.getBoundingClientRect().top+scrollY-110));await p.screenshot({path:path.join(R,'20261013_EDU_SushiBentoMobile_v1.3.png')});
 check(await p.locator('#examSubjects label').evaluateAll(es=>es.every(e=>e.getBoundingClientRect().height>=44)),'subject selectors retain touch target size');
 check(await p.locator('[data-subject-art]').evaluateAll(es=>es.every(e=>e.complete&&e.naturalWidth>0)),'all visible and retained subject images decode offline');
 await p.setViewportSize({width:390,height:844});await p.evaluate(()=>SushiStudio.go('home'));await p.screenshot({path:path.join(R,'20261013_EDU_SushiLandingMobile_v1.3.png')});
 check(errors.length===0,'no runtime errors');check(requests.length===0,'no network dependencies');
 fs.writeFileSync(path.join(OUT,'results.json'),JSON.stringify({checks,errors,requests},null,2));console.log(JSON.stringify({checks:checks.length,passed:checks.filter(c=>c.ok).length}));
 }catch(e){fs.writeFileSync(path.join(OUT,'results.json'),JSON.stringify({checks,error:e.message},null,2));throw e;}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
