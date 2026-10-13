(function(root){
 'use strict';
 const norm=s=>String(s).trim().toLocaleLowerCase().replace(/\s+/g,' ');
 function random(seed){let a=Number(seed)>>>0;return()=>{a+=0x6D2B79F5;let t=a;t=Math.imul(t^t>>>15,t|1);t^=t+Math.imul(t^t>>>7,t|61);return((t^t>>>14)>>>0)/4294967296;};}
 function shuffle(array,rng){const a=[...array];for(let i=a.length-1;i>0;i--){const j=Math.floor(rng()*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a;}
 function topicKey(q){return q.subject+'\u001f'+q.topic;}
 function pool(items,settings){
  return items.filter(q=>(!settings.subjects?.length||settings.subjects.includes(q.subject))&&(!settings.topics?.length||settings.topics.includes(topicKey(q)))&&(!settings.difficulty||settings.difficulty==='All'||q.tier===settings.difficulty));
 }
 function unique(items){const seen=new Set();return items.filter(q=>{const key=norm(q.stem);if(seen.has(key))return false;seen.add(key);return true;});}
 function draw(items,settings){
  const count=Number(settings.count),seed=Number(settings.seed);
  if(!Number.isInteger(count)||count<1||count>1047)throw Error('Enter a whole-number question count from 1 to 1,047.');
  if(!Number.isInteger(seed)||seed<0||seed>4294967295)throw Error('Use a whole-number seed from 0 to 4,294,967,295.');
  if(!settings.subjects?.length)throw Error('Select at least one subject.');
  const rng=random(seed),eligible=unique(shuffle(pool(items,settings),rng));
  if(count>eligible.length)throw Error(`Only ${eligible.length} distinct question stems match these filters. Reduce the count or broaden your selection.`);
  let chosen=[];
  if(settings.balance){
   const groups=settings.topics?.length?settings.topics:settings.subjects;
   if(count<groups.length)throw Error(`Choose at least ${groups.length} questions to represent every selected ${settings.topics?.length?'topic':'subject'}.`);
   const buckets=groups.map(group=>eligible.filter(q=>(settings.topics?.length?topicKey(q):q.subject)===group));
   const empty=buckets.findIndex(b=>!b.length);
   if(empty!==-1)throw Error(`No matching questions for ${groups[empty].replace('\u001f',' / ')}. Change the difficulty or selection.`);
   while(chosen.length<count){for(const b of buckets){if(b.length&&chosen.length<count)chosen.push(b.pop());}}
   chosen=shuffle(chosen,rng);
  }else chosen=eligible.slice(0,count);
  return chosen;
 }
 function code(questions,seed){let h=2166136261;for(const c of questions.map(q=>q.id).join('|')+'|'+seed){h=Math.imul(h^c.charCodeAt(0),16777619);}return(h>>>0).toString(36).toUpperCase().padStart(7,'0');}
 function score(questions,answers){const correct=questions.filter(q=>answers[q.id]===q.key).length,answered=questions.filter(q=>['A','B','C','D'].includes(answers[q.id])).length;return{correct,answered,total:questions.length,percent:questions.length?Math.round(correct/questions.length*100):0};}
 const tiers=[
  {name:'Rice Rookie',at:0,art:'logo',motto:'Every roll starts with one grain.'},
  {name:'Maki Explorer',at:10,art:'maki',motto:'Your first ten bites of knowledge.'},
  {name:'Nigiri Navigator',at:50,art:'logo',motto:'Finding your way around the menu.'},
  {name:'Temaki Trailblazer',at:150,art:'temaki',motto:'Rolling through new territory.'},
  {name:'Wasabi Warrior',at:350,art:'wasabi',motto:'Bringing the heat, one question at a time.'},
  {name:'Omakase Legend',at:700,art:'chef',motto:'A study platter worth celebrating.'}
 ];
 function achievement(answers){
  const count=Object.keys(answers).length;
  const index=tiers.reduce((best,t,i)=>count>=t.at?i:best,0),current=tiers[index],next=tiers[index+1]||null;
  return {count,index,current,next,remaining:next?next.at-count:0,percent:next?Math.min(100,Math.floor((count-current.at)/(next.at-current.at)*100)):100};
 }
 function freshPool(items,subjects,answeredIds){
  const answered=new Set(answeredIds),usedStems=new Set(items.filter(q=>answered.has(q.id)).map(q=>norm(q.stem)));
  return items.filter(q=>subjects.includes(q.subject)&&!answered.has(q.id)&&!usedStems.has(norm(q.stem)));
 }
 function freshDraw(items,subjects,answeredIds,seed){
  if(!subjects.length)throw Error('Choose at least one subject for your Fresh Catch.');
  const available=freshPool(items,subjects,answeredIds),count=unique(available).length;
  if(count<10)throw Error(`Only ${count} unanswered questions remain in this selection. Fresh Catch needs 10. Add a subject, or use Maki Mentor to review the remaining questions.`);
  return draw(available,{count:10,seed,subjects,balance:false});
 }
 const api={draw,pool,unique,shuffle,random,topicKey,score,code,norm,tiers,achievement,freshPool,freshDraw};root.SushiEngine=api;if(typeof module!=='undefined')module.exports=api;
})(typeof globalThis!=='undefined'?globalThis:this);
