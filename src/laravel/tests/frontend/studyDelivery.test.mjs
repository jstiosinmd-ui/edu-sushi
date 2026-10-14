import test from 'node:test';
import assert from 'node:assert/strict';
import {readPending,writePending,answerKey} from '../../resources/js/studyDelivery.js';

function memory(){const data=new Map();return {getItem:k=>data.get(k)||null,setItem:(k,v)=>data.set(k,v),removeItem:k=>data.delete(k)}}
test('unsent answers survive reload and are scoped to learner and round',()=>{
    const storage=memory(),key='sushi:pending:1:attempt:7';
    const row={action:'answer',payload:{answer:'B',item_id:8,revision:2,request_token:'unique'},at:1000};
    assert.equal(writePending(storage,key,row),true);
    assert.deepEqual(readPending(storage,key,1100),row);
    assert.equal(readPending(storage,'sushi:pending:2:attempt:7',1100),null);
    writePending(storage,key,null);assert.equal(readPending(storage,key,1100),null);
});
test('malformed, expired, and unsupported stored actions cannot be retried',()=>{
    const storage=memory();
    storage.setItem('x','broken JSON');assert.equal(readPending(storage,'x',1000),null);
    storage.setItem('x',JSON.stringify({action:'delete',payload:{},at:100}));assert.equal(readPending(storage,'x',1000),null);
    storage.setItem('x',JSON.stringify({action:'rate',payload:{confidence:2},at:100}));assert.equal(readPending(storage,'x',86400200),null);
    assert.equal(writePending({setItem:()=>{throw new Error('Unavailable')}},'x',{}),false);
});
test('answer shortcuts skip text entry, interactive controls, modifiers, and repeat keys',()=>{
    assert.equal(answerKey({key:'b'}),2);assert.equal(answerKey({key:'5'}),5);
    for(const event of [{key:'A',ctrlKey:true},{key:'A',repeat:true},{key:'A',target:{closest:()=>true}},{key:'z'}])assert.equal(answerKey(event),null);
});
