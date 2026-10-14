// Store only an unsent selection in this tab, scoped to the signed-in learner.
// Question text, explanations, and authentication credentials are never stored.
export function readPending(storage,key,now=Date.now()){
    try{
        const row=JSON.parse(storage.getItem(key)||'null');
        if(!row)return null;
        if(!['answer','rate'].includes(row.action)||!row.payload||typeof row.payload!=='object'||!Number.isFinite(row.at)||row.at>now||now-row.at>86400000){
            storage.removeItem(key);return null;
        }
        return row;
    }catch{return null}
}
export function writePending(storage,key,value){
    try{if(value)storage.setItem(key,JSON.stringify(value));else storage.removeItem(key);return true}catch{return false}
}
export function answerKey(event){
    if(event.repeat||event.altKey||event.ctrlKey||event.metaKey||event.shiftKey||event.target?.closest?.('input,textarea,select,button,a,[contenteditable="true"],dialog'))return null;
    const k=event.key.toUpperCase();
    return /^[1-5]$/.test(k)?Number(k):/^[A-D]$/.test(k)?k.charCodeAt(0)-64:null;
}
