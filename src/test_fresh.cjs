const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const fs=require('fs'),path=require('path'),{pathToFileURL}=require('url');
const ROOT=path.resolve(__dirname,'..'),OUT=path.join(ROOT,'.tmp/fresh-qa');fs.mkdirSync(OUT,{recursive:true});
const checks=[];function check(ok,name){checks.push({ok,name});if(!ok)throw Error(name);}
(async()=>{const browser=await chromium.launch({headless:true,executablePath:process.env.BROWSER_PATH,downloadsPath:path.join(OUT,'downloads')});try{
 const ctx=await browser.newContext({viewport:{width:390,height:844},hasTouch:true,isMobile:true,reducedMotion:'reduce',acceptDownloads:true});await ctx.setOffline(true);
 const p=await ctx.newPage(),errors=[],requests=[];p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(/^https?:/.test(r.url()))requests.push(r.url());});
 await p.goto(pathToFileURL(path.join(ROOT,'20261013_EDU_SushiClerksExamStudio_v1.3.html')).href);await p.waitForFunction(()=>SushiStudio.ready);
 const bank=await p.evaluate(()=>SushiStudio.bank),q=bank.items;
 check(await p.locator('#stickyRankName').innerText()==='Rice Rookie','starts at Rice Rookie');
 check(await p.locator('.mode-card').count()===4,'four illustrated modes');
 check(await p.locator('[data-art]').evaluateAll(es=>es.every(e=>e.complete&&e.naturalWidth>0)),'all character assets load offline');
 await p.locator('[data-mode=fresh]').tap();check(await p.locator('#studyMode').inputValue()==='fresh','Fresh Catch card selects new mode');
 check(await p.locator('#freshSetup').isVisible()&&await p.locator('#customSetup').isHidden(),'Fresh Catch shows dedicated subject controls');
 for(const input of await p.locator('#freshSubjects input').all())await input.uncheck();
 check(await p.locator('#startStudy').isDisabled(),'empty subject selection disabled');
 check((await p.locator('#studyAvailable').innerText()).includes('Choose at least one subject'),'empty selection explanation');
 await p.locator('#freshSubjects input[value="Pediatrics"]').check();
 await p.locator('#freshSubjects input[value="OB-GYN"]').check();
 check((await p.locator('#studyAvailable').innerText()).startsWith('433 '),'counts match selected subjects');
 await p.locator('#freshSetup').scrollIntoViewIfNeeded();await p.screenshot({path:path.join(ROOT,'20261013_EDU_SushiFreshCatchMobile_v1.3.png')});
 await p.locator('#startStudy').tap();let ids=await p.evaluate(()=>SushiStudio.session.ids);
 check(ids.length===10&&new Set(ids).size===10,'new mode starts exactly ten');
 check(ids.every(id=>['Pediatrics','OB-GYN'].includes(q.find(x=>x.id===id).subject)),'fresh draw honors multiple subjects');
 check(await p.locator('#sessionTimer').innerText()==='Untimed','fresh session has no timer');
 check(await p.locator('#sessionModeName').innerText()==='Fresh Catch 10','active session has its own mode label');
 for(let i=0;i<10;i++){
  const item=q.find(x=>x.id===ids[i]),answer=i===0?'ABCD'.split('').find(x=>x!==item.key):item.key;
  await p.locator(`input[name=answer][value=${answer}]`).check();
  if(i===0){check(await p.evaluate(id=>SushiStudio.progress.answeredIds.includes(id),ids[0]),'selection immediately excludes from fresh pool');check(await p.evaluate(()=>Object.keys(SushiStudio.progress.answers).length)===0,'selection alone earns no achievement credit');}
  if(i<9){await p.locator('#checkAnswer').tap();if(i===0)check((await p.locator('#questionFeedback').innerText()).includes('Review this one.'),'fresh mode supplies tutor feedback for wrong answer');await p.locator('#nextQuestion').tap();}
 }
 await p.locator('#finishStudy').tap();await p.locator('#confirmYes').tap();
 check((await p.locator('#sessionResults').innerText()).includes('9 / 10'),'fresh set scores correctly');
 check(await p.evaluate(()=>Object.keys(SushiStudio.progress.answers).length)===10,'finish commits unchecked answers');
 await p.evaluate(()=>SushiStudio.go('home'));check(await p.locator('#stickyRankName').innerText()==='Maki Explorer','first ten unlock Maki Explorer');
 await p.locator('#achievementButton').tap();check(await p.locator('.tier-card').count()===6,'all six tiers present');
 check(await p.locator('.tier-card.current-tier').count()===1,'only current tier highlighted');
 await p.locator('#tierGrid').scrollIntoViewIfNeeded();await p.screenshot({path:path.join(ROOT,'20261013_EDU_SushiPassportMobile_v1.3.png')});
 await p.reload();await p.waitForFunction(()=>SushiStudio.ready);check(await p.locator('#stickyRankName').innerText()==='Maki Explorer','tier survives reload');
 await p.evaluate(()=>SushiStudio.go('practice'));check(await p.locator('#studyMode').inputValue()==='fresh','fresh session resumes its mode');
 check((await p.locator('#freshSubjects input:checked').evaluateAll(es=>es.map(e=>e.value))).join('|')==='OB-GYN|Pediatrics','subject selection persists');
 await p.locator('#startStudy').tap();const second=await p.evaluate(()=>SushiStudio.session.ids);
 check(second.every(id=>!ids.includes(id)),'second serving excludes every answered item including wrong answers');
 await p.locator('input[name=answer][value=A]').check();const abandoned=second[0];
 await p.locator('#studyMode').selectOption('tutor');await p.locator('#studyCount').fill('1');await p.locator('#startStudy').tap();await p.locator('#confirmNo').tap();
 check(await p.evaluate(id=>SushiStudio.session.ids[0]===id,abandoned),'cancel replacement keeps active set');
 await p.locator('#startStudy').tap();await p.locator('#confirmYes').tap();
 check(await p.evaluate(id=>SushiStudio.progress.answeredIds.includes(id),abandoned),'abandoned unchecked answer stays excluded');
 await p.locator('input[name=answer][value=B]').check();let tutorId=await p.evaluate(()=>SushiStudio.session.ids[0]);
 await p.reload();await p.waitForFunction(()=>SushiStudio.ready);await p.evaluate(()=>SushiStudio.go('practice'));
 check(await p.evaluate(id=>SushiStudio.progress.answeredIds.includes(id),tutorId),'tutor unchecked answer history persists');
 await p.locator('#studyMode').selectOption('exam');await p.locator('#startStudy').tap();await p.locator('#confirmYes').tap();await p.locator('input[name=answer][value=C]').check();const examId=await p.evaluate(()=>SushiStudio.session.ids[0]);
 check(await p.locator('#questionFeedback').isHidden(),'Wasabi Rush still hides unsubmitted feedback');
 await p.locator('#studyMode').selectOption('fresh');await p.locator('#startStudy').tap();await p.locator('#confirmYes').tap();
 check(await p.evaluate(ids=>SushiStudio.session.ids.every(id=>!ids.includes(id)),[abandoned,tutorId,examId]),'fresh excludes unchecked answers across all modes');
 // Backup includes the never-answer-again ledger and remains portable.
 await p.evaluate(()=>SushiStudio.go('about'));let event=p.waitForEvent('download');await p.locator('#exportProgress').tap();const dl=await event;const backup=path.join(OUT,'backup.json');await dl.saveAs(backup);const saved=JSON.parse(fs.readFileSync(backup));
 check(saved.version===2&&saved.answeredIds.includes(abandoned),'v2 backup carries fresh history');
 await p.locator('#resetProgress').tap();await p.locator('#confirmYes').tap();await p.locator('#importProgress').setInputFiles(backup);await p.locator('#confirmYes').tap();
 check(await p.evaluate(id=>SushiStudio.progress.answeredIds.includes(id),abandoned),'backup restores unchecked history');
 const legacy={version:1,bankHash:bank.hash,answers:Object.fromEntries(q.slice(0,50).map(x=>[x.id,{answer:x.key,attempts:20}])),bookmarks:[q[0].id]};
 await p.locator('#importProgress').setInputFiles({name:'legacy.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify(legacy))});await p.locator('#confirmYes').tap();
 check(await p.evaluate(()=>SushiStudio.progress.version===2&&SushiStudio.progress.answeredIds.length===50),'v1 backup migrates completed items into history');
 await p.evaluate(()=>SushiStudio.go('home'));check(await p.locator('#stickyRankName').innerText()==='Nigiri Navigator','v1 backup retroactively unlocks tier without repeat inflation');
 async function loadProgress(data,active=null){await p.evaluate(({data,active})=>{localStorage.setItem('sushi-clerks-progress-v1',JSON.stringify(data));if(active)localStorage.setItem('sushi-clerks-session-v1',JSON.stringify(active));else localStorage.removeItem('sushi-clerks-session-v1');},{data,active});await p.reload();await p.waitForFunction(()=>SushiStudio.ready);}
 for(const left of [9,0]){await loadProgress({version:2,bankHash:bank.hash,answers:{},bookmarks:[],answeredIds:q.slice(left).map(x=>x.id),freshSubjects:bank.subjects});await p.locator('[data-mode=fresh]').tap();check(await p.locator('#startStudy').isDisabled(),left+' remaining cannot repeat-fill a serving');check((await p.locator('#studyAvailable').innerText()).startsWith(left+' unanswered'),left+' remaining accurately reported');}
 await loadProgress({version:2,bankHash:bank.hash,answers:{},bookmarks:[],answeredIds:q.slice(10).map(x=>x.id),freshSubjects:bank.subjects});await p.locator('[data-mode=fresh]').tap();check(await p.locator('#startStudy').isEnabled(),'last exact ten can start');await p.locator('#startStudy').tap();check(await p.evaluate(()=>SushiStudio.session.ids.length)===10,'last exact ten delivered');
 const oldSession={version:1,bankHash:bank.hash,ids:[q[55].id],answers:{[q[55].id]:'A'},checked:{},index:0,mode:'tutor',finished:false,deadline:null};
 await loadProgress(legacy,oldSession);check(await p.evaluate(id=>SushiStudio.progress.answeredIds.includes(id),q[55].id),'legacy active unchecked answer migrates');
 const bad=await p.evaluate(()=>{try{SushiStudio.validateProgress({...SushiStudio.progress,answeredIds:['UNKNOWN']});return false;}catch{return true;}});check(bad,'malformed fresh history rejected');
 await loadProgress({version:1,bankHash:bank.hash,answers:{},bookmarks:[]});
 for(const width of [320,360,390,430,768,1024,1440]){await p.setViewportSize({width,height:900});await p.evaluate(()=>SushiStudio.go('home'));await p.locator('#achievementButton').click();check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'expanded tiers fit '+width);await p.locator('#closeAchievements').click();await p.locator('[data-mode=fresh]').click();check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'fresh controls fit '+width);check(await p.locator('#freshSubjects label').evaluateAll(es=>es.every(e=>e.getBoundingClientRect().height>=44)),'fresh touch targets '+width);}
 await p.setViewportSize({width:1440,height:1000});await p.evaluate(()=>SushiStudio.go('home'));await p.locator('#achievementButton').click();await p.screenshot({path:path.join(ROOT,'20261013_EDU_SushiAchievementDesktop_v1.3.png'),fullPage:true});
 check(errors.length===0,'new modes have no runtime errors');check(requests.length===0,'new modes have no external dependencies');
 fs.writeFileSync(path.join(OUT,'results.json'),JSON.stringify({checks,errors,requests},null,2));console.log(JSON.stringify({checks:checks.length,passed:checks.filter(c=>c.ok).length}));
 }catch(e){fs.writeFileSync(path.join(OUT,'results.json'),JSON.stringify({checks,error:e.message},null,2));throw e;}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
