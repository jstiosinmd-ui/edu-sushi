(function(){
  'use strict';
  const {PDFDocument,rgb}=globalThis.PDFLib;
  const clean=s=>String(s??'').replace(/\*\*/g,'').replace(/[\u2011\u2013\u2014\u2212]/g,'-').replace(/\t/g,' ').replace(/\r/g,'');
  const bytes=b64=>Uint8Array.from(atob(b64),c=>c.charCodeAt(0));
  const color=hex=>rgb(parseInt(hex.slice(1,3),16)/255,parseInt(hex.slice(3,5),16)/255,parseInt(hex.slice(5,7),16)/255);
  const navy=color('#9D3656'), ink=color('#452735'), muted=color('#755765'), line=color('#E9C9CD');
  async function create(exam,kind,assets,onProgress=()=>{}){
    const doc=await PDFDocument.create();doc.registerFontkit(globalThis.fontkit);
    // Embed the build-time font subset in full to preserve Unicode extraction and glyph identity.
    const fontOptions={features:{liga:false,clig:false}};
    const regular=await doc.embedFont(bytes(assets.regular),fontOptions),bold=await doc.embedFont(bytes(assets.bold),fontOptions);
    const fonts={regular,bold};const glyphs={regular:new Set(regular.getCharacterSet()),bold:new Set(bold.getCharacterSet())};
    const logo=await doc.embedPng(assets.logo);
    const W=595.276,H=841.89,M=72,RIGHT=W-M,BOTTOM=74,TOP=H-86;
    let page,y,number=0;
    function checkGlyphs(text,f){for(const ch of text)if(ch!=='\n'&&!glyphs[f].has(ch.codePointAt(0)))throw Error('Unsupported character '+JSON.stringify(ch)+' in the selected text.');}
    function wrap(text,width,size=11,f='regular'){
      text=clean(text);checkGlyphs(text,f);const out=[];
      for(const para of text.split('\n')){
        if(!para.trim()){out.push('');continue;}
        let current='';
        for(const word of para.trim().split(/\s+/)){
          const candidate=current?current+' '+word:word;
          if(fonts[f].widthOfTextAtSize(candidate,size)<=width){current=candidate;continue;}
          if(current){out.push(current);current='';}
          if(fonts[f].widthOfTextAtSize(word,size)<=width){current=word;continue;}
          for(const ch of word){if(current&&fonts[f].widthOfTextAtSize(current+ch,size)>width){out.push(current);current='';}current+=ch;}
        }
        if(current)out.push(current);
      }
      return out;
    }
    function textAt(text,x,baseline,size=11,f='regular',c=ink){if(!text)return;page.drawText(text,{x,y:baseline,size,font:fonts[f],color:c});}
    function newPage(){
      page=doc.addPage([W,H]);number++;y=TOP;
      textAt("SUSHI / CLERKS COMPREHENSIVE REVIEW",M,H-43,7.5,'bold',navy);
      const label=(kind==='key'?'ANSWER KEY':'EXAM PAPER')+'  ·  '+exam.code;
      textAt(label,RIGHT-regular.widthOfTextAtSize(label,8),H-43,8,'regular',muted);
      page.drawLine({start:{x:M,y:H-54},end:{x:RIGHT,y:H-54},thickness:.5,color:line});
    }
    function ensure(height){if(y-height<BOTTOM)newPage();}
    function block(text,{size=11,f='regular',x=M,width=RIGHT-x,leading=size*1.35,gap=7,c=ink,keep=0}={}){
      const lines=wrap(text,width,size,f);ensure(Math.min(lines.length*leading+gap+keep,TOP-BOTTOM));
      for(let i=0;i<lines.length;i++){
        if(y-leading<BOTTOM)newPage();
        textAt(lines[i],x,y,size,f,c);y-=leading;
      }
      y-=gap;
    }
    function heading(t){ensure(60);block(t,{size:14,f:'bold',c:navy,gap:11,keep:30});}
    function questionHeight(q,index){
      const stem=wrap(`${index+1}. ${q.stem}`,RIGHT-M,11,'bold').length*14.85+7;
      return stem+q.options.reduce((sum,t)=>sum+wrap(t,RIGHT-M-26,11).length*14.85+4,0)+12;
    }
    function keyHeight(q,index){
      const height=(t,size,f,gap)=>wrap(t,RIGHT-M,size,f).length*size*1.35+gap;
      let h=height(`${index+1}. ${q.stem}`,11,'bold',7)+height(`Correct answer: ${q.key}. ${q.options[q.key.charCodeAt(0)-65]}`,10.5,'bold',9)+height(q.rationale,10.5,'regular',9);
      for(let j=0;j<4;j++)if(j!==q.key.charCodeAt(0)-65&&q.expl[j])h+=height(`${String.fromCharCode(65+j)}. ${q.options[j]}\n${q.expl[j]}`,10,'regular',7);
      h+=height(`${q.id} | ${q.subject} | ${q.topic} | ${q.tier}`,8.5,'regular',7);
      h+=height('Source: '+q.raw['Answer Source'],8.5,'regular',5);
      if(q.raw['Source URLs'])h+=height(q.raw['Source URLs'],8.5,'regular',5);
      h+=height('Learning objective: '+q.raw['Learning Objective'],8.5,'regular',5);
      h+=height('Original location: '+q.raw['Source Locator'],8.5,'regular',5);
      if(q.raw['Conversion Note'])h+=height('Source conversion note: '+q.raw['Conversion Note'],8.5,'regular',5);
      return h+13;
    }
    newPage();
    const logoH=58,logoW=logo.width/logo.height*logoH;
    page.drawImage(logo,{x:M,y:y-logoH,width:logoW,height:logoH});
    textAt('SUSHI',M+105,y-17,16,'bold',navy);
    textAt('Clerks Comprehensive Exam Reviewer',M+105,y-36,11,'bold',navy);y-=78;
    block(exam.settings.title,{size:20,f:'bold',c:navy,gap:9});
    block(kind==='key'?'Answer key and examiner record':'Question paper',{size:12,f:'bold',c:navy,gap:12});
    block(`Exam code: ${exam.code}    |    Date: ${exam.settings.date}    |    ${exam.questions.length} questions    |    ${exam.settings.minutes} minutes`,{size:9.5,gap:12});
    if(kind==='paper'){
      block('Name: __________________________________________________________',{size:11,gap:9});
      block('Training level / Section: ____________________   Score: ______________',{size:11,gap:14});
      block('Instructions: Choose the single best answer for each question. Mark A, B, C, or D on the answer sheet. Each question carries one point.',{size:10,gap:16});
      for(let i=0;i<exam.questions.length;i++){
        const q=exam.questions[i], h=questionHeight(q,i);ensure(Math.min(h,TOP-BOTTOM));
        block(`${i+1}. ${q.stem}`,{f:'bold',gap:7});
        for(let j=0;j<4;j++){
          const ls=wrap(q.options[j],RIGHT-M-26,11);ensure(ls.length*14.85+4);
          textAt(String.fromCharCode(65+j)+'.',M+9,y,11);
          block(q.options[j],{x:M+26,width:RIGHT-M-26,gap:4});
        }
        y-=11;
        if(i%25===0){onProgress(`Laying out exam question ${i+1} of ${exam.questions.length}…`);await new Promise(r=>setTimeout(r,0));}
      }
      newPage();heading('Answer sheet');
      block(`Name: ____________________________________    Exam code: ${exam.code}`,{size:10,gap:15});
      block('Circle one letter per question.',{size:10,gap:15});
      const columns=3,colWidth=(RIGHT-M)/columns;
      for(let start=0;start<exam.questions.length;start+=columns){
        ensure(25);
        for(let col=0;col<columns;col++){
          const i=start+col;if(i>=exam.questions.length)break;
          textAt(`${String(i+1).padStart(3,' ')}.   A    B    C    D`,M+col*colWidth,y,10);
        }
        y-=25;
      }
    }else{
      const counts={};for(const q of exam.questions)counts[q.tier]=(counts[q.tier]||0)+1;
      block(`Difficulty: ${['Easy','Average','Difficult'].map(t=>`${counts[t]||0} ${t}`).join(' / ')}\nSeed: ${exam.settings.seed} | Single-best-answer questions`,{size:10,gap:10});
      block('SUSHI practice bank, released October 13, 2026. Questions and keys are retained from the supplied CSV files. Difficulty labels are editorial estimates.',{size:9,c:muted,gap:13});
      heading('Topic coverage');
      const coverage=new Map();
      for(const q of exam.questions){const k=exam.settings.topics.length?q.subject+' / '+q.topic:q.subject;coverage.set(k,(coverage.get(k)||0)+1);}
      for(const [topic,count] of coverage)block(`${topic}: ${count}`,{size:10,gap:3});
      y-=10;heading('Answer grid');
      const columns=5,colWidth=(RIGHT-M)/columns;
      for(let start=0;start<exam.questions.length;start+=columns){
        ensure(24);
        for(let col=0;col<columns;col++){
          const i=start+col;if(i>=exam.questions.length)break;
          textAt(`${i+1}.  ${exam.questions[i].key}`,M+col*colWidth,y,11,'bold');
        }
        y-=24;
      }
      if(exam.settings.rationales){
        newPage();heading('Answers, explanations, and sources');
        for(let i=0;i<exam.questions.length;i++){
          const q=exam.questions[i];ensure(Math.min(keyHeight(q,i),TOP-BOTTOM));
          block(`${i+1}. ${q.stem}`,{f:'bold',gap:7,keep:40});
          block(`Correct answer: ${q.key}. ${q.options[q.key.charCodeAt(0)-65]}`,{f:'bold',size:10.5,c:navy,gap:9});
          block(q.rationale,{size:10.5,gap:9});
          for(let j=0;j<4;j++)if(j!==q.key.charCodeAt(0)-65&&q.expl[j])block(`${String.fromCharCode(65+j)}. ${q.options[j]}\n${q.expl[j]}`,{size:10,gap:7});
          block(`${q.id} | ${q.subject} | ${q.topic} | ${q.tier}`,{size:8.5,c:muted,gap:7});
          block('Source: '+q.raw['Answer Source'],{size:8.5,c:muted,gap:5});
          if(q.raw['Source URLs'])block(q.raw['Source URLs'],{size:8.5,c:muted,gap:5});
          block('Learning objective: '+q.raw['Learning Objective'],{size:8.5,c:muted,gap:5});
          block('Original location: '+q.raw['Source Locator'],{size:8.5,c:muted,gap:5});
          if(q.raw['Conversion Note'])block('Source conversion note: '+q.raw['Conversion Note'],{size:8.5,c:muted,gap:5});
          y-=13;
          if(i%20===0){onProgress(`Laying out answer ${i+1} of ${exam.questions.length}…`);await new Promise(r=>setTimeout(r,0));}
        }
      }else{
        ensure(90);y-=12;heading('Question identifiers');
        for(let i=0;i<exam.questions.length;i++){const q=exam.questions[i];block(`${i+1}. ${q.id} | ${q.key} | ${q.topic} | ${q.tier}`,{size:9,gap:4});}
      }
      ensure(70);y-=10;block('Bank fingerprint: '+exam.bankHash,{size:8,c:muted,gap:5});
      block('Generated: '+exam.built+' | SUSHI Clerks Comprehensive Exam Reviewer v1.2',{size:8,c:muted,gap:5});
    }
    const pages=doc.getPages();
    for(let i=0;i<pages.length;i++){
      const p=pages[i];p.drawLine({start:{x:M,y:60},end:{x:RIGHT,y:60},thickness:.5,color:line});
      p.drawText((kind==='paper'?'Exam paper':'Answer key - examiner copy')+' | '+exam.code,{x:M,y:45,size:8,font:regular,color:muted});
      const credit="(c) jstiosin 2026 | Jon Bryan S. Tiosin, MD";
      p.drawText(credit,{x:(W-regular.widthOfTextAtSize(credit,8))/2,y:32,size:8,font:regular,color:muted});
      const count=`Page ${i+1} of ${pages.length}`;
      p.drawText(count,{x:RIGHT-regular.widthOfTextAtSize(count,8),y:45,size:8,font:regular,color:muted});
    }
    doc.setTitle(exam.settings.title+(kind==='key'?' - Answer Key':' - Exam Paper'));
    doc.setAuthor('Jon Bryan S. Tiosin, MD');doc.setCreator("SUSHI Clerks Comprehensive Exam Reviewer v1.2");
    doc.setSubject('Exam '+exam.code);doc.setCreationDate(new Date(exam.built));doc.setModificationDate(new Date(exam.built));
    onProgress('Preparing download…');return doc.save();
  }
  globalThis.SushiPdf={create};
})();
