export function initialQuestionIndex(attempt,savedItem=null){
    if(!attempt.items.length)return 0;
    const missing=attempt.items.findIndex(item=>!item.answer);
    if(attempt.status==='active'&&attempt.mode==='fresh'&&missing<0)return attempt.items.length-1;
    const saved=attempt.items.findIndex(item=>String(item.item_id)===String(savedItem));
    return saved>=0?saved:attempt.status==='active'&&missing>=0?missing:0;
}
export function attemptAction(attempt,index){
    if(attempt.status!=='active')return {kind:'review',label:index===attempt.items.length-1?'Back to results':'Next'};
    if(index<attempt.items.length-1)return {kind:'next',label:'Next'};
    const missing=attempt.items.filter(item=>!item.answer).length;
    if(attempt.mode==='fresh'&&missing)return {kind:'missing',label:'Review '+missing+' unanswered'};
    return {kind:'finish',label:attempt.mode==='fresh'?'Finish catch':'Submit round'};
}
