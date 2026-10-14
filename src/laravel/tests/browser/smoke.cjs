const {chromium,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'.tmp/browser-output');
const url=process.env.SUSHI_URL||'http://127.0.0.1:8137';
const qaPassword='Sushi-browser-test-2026!';
fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.SUSHI_CHROME?{executablePath:process.env.SUSHI_CHROME}:{})});
 const context=await browser.newContext({viewport:{width:1440,height:1000}});
 const page=await context.newPage(),errors=[],checks=[];
 page.on('pageerror',e=>errors.push(e.message));
 async function snap(name,width=1440){await page.setViewportSize({width,height:width===1440?1000:844});await page.evaluate(()=>window.scrollTo(0,0));await page.waitForTimeout(250);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});checks.push(name+' no overflow');}
 async function login(username){
   await page.goto(url+'/login');await page.getByLabel('Username',{exact:true}).fill(username);await page.getByLabel('Password',{exact:true}).fill('password');await page.getByRole('button',{name:"Let's roll"}).click();
   await page.waitForFunction(()=>location.pathname!=='/login'||document.querySelector('.notice.error'));
   if(page.url().endsWith('/login')) {await page.getByLabel('Password',{exact:true}).fill(qaPassword);await page.getByRole('button',{name:"Let's roll"}).click();}
   await page.waitForURL(u=>!u.pathname.endsWith('/login'));
   if(page.url().endsWith('/password')){
     await page.getByLabel('Current password',{exact:true}).fill('password');await page.getByLabel('New password',{exact:true}).fill(qaPassword);await page.getByLabel('Confirm new password',{exact:true}).fill(qaPassword);
     await page.getByRole('button',{name:'Save password',exact:true}).click();await page.waitForURL(u=>!u.pathname.endsWith('/password'));
   }
   checks.push(username+' sign-in');
 }
 try{
   await page.goto(url+'/login');await snap('login-desktop');await snap('login-mobile',390);await page.setViewportSize({width:1440,height:1000});
   await login('powquanico');await expect(page.getByRole('heading',{name:'Fresh Catch 10',exact:true})).toBeVisible();await snap('counter-desktop');
   await page.getByRole('button',{name:'Practice ⌄',exact:true}).click();await page.getByRole('link',{name:'Maki Mentor',exact:true}).click();await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByLabel('Custom count',{exact:true}).fill('3');await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByRole('button',{name:'Start round',exact:true}).click();await page.waitForURL(/\/attempts\/\d+$/);
   await page.locator('.options button').first().click();await expect(page.getByRole('button',{name:'Check answer',exact:true})).toBeEnabled();await page.getByRole('button',{name:'Check answer',exact:true}).click();await expect(page.locator('.feedback')).toBeVisible();await expect(page.locator('.rank small')).toContainText(/\d+ completed/);
   await snap('practice-mobile',390);await page.reload();await expect(page.locator('.feedback')).toBeVisible();checks.push('answer persisted after reload');
   await page.locator('summary').filter({hasText:'Finish this round'}).click();await page.getByRole('button',{name:'Submit round'}).click();await expect(page.getByRole('heading',{name:'Round complete'})).toBeVisible();
   await page.getByRole('link',{name:'Memory Maki',exact:true}).click();await page.getByLabel('Range: last question').fill('3');await page.getByRole('button',{name:'Preview question range'}).click();await expect(page.locator('.deck-question-list input')).toHaveCount(3);
   await page.getByRole('button',{name:'Start this deck'}).click();await page.waitForURL(/\/flashcards\/decks\/\d+$/);await expect(page.locator('.deck-stats strong').first()).toHaveText('3');
   await page.locator('.options button').first().click();await expect(page.locator('.feedback')).toBeVisible();await page.locator('.confidence button').first().click();await expect(page.locator('.deck-stats>div').nth(1).locator('strong')).toHaveText('1');await snap('memory-maki-mobile',390);
   await page.reload();await expect(page.locator('.deck-stats>div').nth(1).locator('strong')).toHaveText('1');checks.push('flashcard feedback, ratings, distribution, and resume');
   await page.evaluate(()=>window.scrollTo(0,600));await page.waitForTimeout(100);const bars=await page.evaluate(()=>({header:document.querySelector('.sticky').getBoundingClientRect().bottom,counts:document.querySelector('.deck-stats').getBoundingClientRect().top}));expect(bars.counts).toBeGreaterThanOrEqual(bars.header-1);await page.screenshot({path:path.join(out,'memory-maki-sticky-mobile.png')});checks.push('sticky familiarity bar clears navigation');
   await page.setViewportSize({width:1440,height:1000});await snap('memory-maki-desktop');
   await page.getByRole('link',{name:'My Progress',exact:true}).click();await expect(page.getByRole('heading',{name:'My Progress',exact:true})).toBeVisible();await snap('progress-mobile',390);
   const denied=await context.request.get(url+'/admin');expect(denied.status()).toBe(403);checks.push('student denied admin access');
   await page.setViewportSize({width:1440,height:1000});await page.getByRole('button',{name:'Account menu',exact:true}).click();await page.getByRole('button',{name:'Sign Out',exact:true}).click();await login('jstiosin');await expect(page.getByRole('heading',{name:'Head Chef',exact:true})).toBeVisible();await snap('admin-desktop');await snap('admin-mobile',390);
   await page.getByRole('link',{name:'Manage questions and imports'}).click();await expect(page.getByRole('heading',{name:'Question kitchen'})).toBeVisible();checks.push('admin question catalog');
   await page.getByRole('button',{name:'Practice ⌄',exact:true}).click();await page.getByRole('link',{name:'Bento Builder',exact:true}).click();const examTitle='SUSHI QA '+Date.now();await page.getByLabel('Exam title',{exact:true}).fill(examTitle);await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByLabel('Custom count',{exact:true}).fill('12');await page.getByRole('button',{name:'Continue',exact:true}).click();await page.getByRole('button',{name:'Preview questions',exact:true}).click();await page.getByRole('button',{name:'Save this bento',exact:true}).click();await expect(page.getByRole('heading',{name:examTitle,exact:true})).toBeVisible();
   const newestExam=page.locator('article.panel').filter({has:page.getByRole('heading',{name:examTitle,exact:true})});
   await newestExam.getByRole('button',{name:'Prepare PDF set',exact:true}).click();
   await newestExam.getByRole('link',{name:'Exam paper PDF',exact:true}).first().waitFor({state:'visible',timeout:120000});
   for(const [label,name] of [['Exam paper PDF','paper'],['Answer sheet PDF','sheet'],['Answer key PDF','key']]){
     const href=await newestExam.getByRole('link',{name:label,exact:true}).first().getAttribute('href');const response=await context.request.get(url+href);expect(response.status()).toBe(200);const bytes=await response.body();expect(bytes.subarray(0,5).toString()).toBe('%PDF-');fs.writeFileSync(path.join(out,name+'.pdf'),bytes);
   }
   checks.push('all three authorized PDF downloads');
   for(const width of [320,390,768,1440]){await page.setViewportSize({width,height:900});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true)}
   expect(errors).toEqual([]);fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({status:'passed',checks,consoleErrors:errors},null,2));console.log(JSON.stringify({status:'passed',checks,consoleErrors:errors}));
 }catch(e){await page.screenshot({path:path.join(out,'failure.png'),fullPage:true});console.error(JSON.stringify({url:page.url(),errors,body:(await page.locator('body').innerText()).slice(0,2500)}));throw e}
 finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
