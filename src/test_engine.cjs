const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const E=require('./engine.js'),ROOT=path.resolve(__dirname,'..'),bank=JSON.parse(fs.readFileSync(path.join(ROOT,'.tmp/bank.json'),'utf8'));
let checks=0;function check(ok,msg){assert.ok(ok,msg);checks++;}
const defaults={count:80,seed:20261013,subjects:bank.subjects,difficulty:'All',balance:true,topics:[]};
for(let seed=0;seed<50;seed++){
 const qs=E.draw(bank.items,{...defaults,seed});
 check(qs.length===80,'exact count');check(new Set(qs.map(q=>q.id)).size===80,'no duplicate IDs');check(new Set(qs.map(q=>E.norm(q.stem))).size===80,'no duplicate stems');
 check(bank.subjects.every(s=>qs.filter(q=>q.subject===s).length===20),'balanced subjects');
 check(JSON.stringify(qs)===JSON.stringify(E.draw(bank.items,{...defaults,seed})),'repeatable draw');
}
for(const difficulty of ['Easy','Average','Difficult']){const qs=E.draw(bank.items,{...defaults,difficulty,count:20});check(qs.every(q=>q.tier===difficulty),'difficulty respected');check(bank.subjects.every(s=>qs.some(q=>q.subject===s)),'every subject represented');}
for(const subject of bank.subjects){const qs=E.draw(bank.items,{...defaults,subjects:[subject],count:10});check(qs.every(q=>q.subject===subject),'subject respected');}
for(const q of [...new Map(bank.items.map(q=>[E.topicKey(q),q])).values()]){const qs=E.draw(bank.items,{...defaults,count:1,subjects:[q.subject],topics:[E.topicKey(q)]});check(qs[0].subject===q.subject&&qs[0].topic===q.topic,'specific topic respected');}
for(const settings of [{count:0},{count:1.5},{count:1048},{seed:-1},{seed:4294967296},{subjects:[]},{count:3},{difficulty:'Difficult',count:80}]){assert.throws(()=>E.draw(bank.items,{...defaults,...settings}));checks++;}
const all=E.draw(bank.items,{...defaults,count:1046});check(all.length===1046,'full distinct-stem pool');check(!all.some(q=>q.id==='SUR-Q0270')||!all.some(q=>q.id==='SUR-Q0295'),'duplicate stem pair never co-occurs');
const qs=E.draw(bank.items,{...defaults,count:4});check(E.score(qs,Object.fromEntries(qs.map(q=>[q.id,q.key]))).percent===100,'all-correct score');check(E.score(qs,{}).correct===0,'unanswered score');
const original=JSON.stringify(bank);E.draw(bank.items,defaults);check(original===JSON.stringify(bank),'source object unchanged');
for(const subjects of [[bank.subjects[0]],bank.subjects.slice(1,3),bank.subjects]){
 let seen=[];for(let seed=0;seed<15;seed++){
  const fresh=E.freshDraw(bank.items,subjects,seen,seed);
  check(fresh.length===10,'fresh exactly ten');check(fresh.every(q=>subjects.includes(q.subject)),'fresh subject filter');
  check(fresh.every(q=>!seen.includes(q.id)),'fresh never repeats an answered item');check(new Set(fresh.map(q=>E.norm(q.stem))).size===10,'fresh distinct stems');seen.push(...fresh.map(q=>q.id));
 }
}
check(JSON.stringify(E.freshDraw(bank.items,bank.subjects,[],1))!==JSON.stringify(E.freshDraw(bank.items,bank.subjects,[],2)),'fresh varies with random seed');
check(!E.freshPool(bank.items,bank.subjects,['SUR-Q0270']).some(q=>q.id==='SUR-Q0295'),'answered duplicate stem excludes sibling across sets');
for(const left of [0,1,9]){assert.throws(()=>E.freshDraw(bank.items,bank.subjects,bank.items.slice(left).map(q=>q.id),1),new RegExp(`Only ${left} unanswered`));checks++;}
check(E.freshDraw(bank.items,bank.subjects,bank.items.slice(10).map(q=>q.id),1).length===10,'exactly ten remaining works');
assert.throws(()=>E.freshDraw(bank.items,[],[],1),/Choose at least one subject/);checks++;
for(const [count,index] of [[0,0],[9,0],[10,1],[49,1],[50,2],[149,2],[150,3],[349,3],[350,4],[699,4],[700,5],[1047,5]]){
 const answers=Object.fromEntries(bank.items.slice(0,count).map(q=>[q.id,{answer:q.key,attempts:1}])),rank=E.achievement(answers);
 check(rank.index===index&&rank.count===count,'tier boundary '+count);check(rank.percent>=0&&rank.percent<=100,'tier progress bounded '+count);
 for(const r of Object.values(answers))r.attempts=100;check(E.achievement(answers).index===index,'repeat attempts never inflate tier '+count);
}
fs.writeFileSync(path.join(ROOT,'.tmp/engine-results.json'),JSON.stringify({checks,passed:checks},null,2));console.log(JSON.stringify({checks,passed:checks}));
