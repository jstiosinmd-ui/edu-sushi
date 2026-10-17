import test from 'node:test';
import assert from 'node:assert/strict';
import {initialQuestionIndex,attemptAction} from '../../resources/js/attemptNavigation.mjs';
const fresh=answers=>({mode:'fresh',status:'active',items:answers.map((answer,i)=>({item_id:i+1,answer}))});
test('last Fresh Catch question offers finish once every answer is saved, even before checking',()=>{
 const a=fresh(['A','C']);assert.equal(attemptAction(a,1).label,'Finish catch');assert.equal(attemptAction(a,1).kind,'finish');
 assert.equal(attemptAction(a,0).kind,'next');
});
test('last question sends the learner to unanswered questions instead of dead-ending',()=>{
 assert.deepEqual(attemptAction(fresh([null,'A',null]),2),{kind:'missing',label:'Review 2 unanswered'});
});
test('all-answered reload lands on final action; missing answers and saved review position resume safely',()=>{
 assert.equal(initialQuestionIndex(fresh(['A','B']),1),1);
 assert.equal(initialQuestionIndex(fresh(['A',null,'C'])),1);
 assert.equal(initialQuestionIndex(fresh(['A',null,'C']),'3'),2);
 assert.equal(initialQuestionIndex(fresh([])),0);
});
test('timed rounds retain submission and finished review returns to results',()=>{
 const a={...fresh(['A',null]),mode:'exam'};
 assert.equal(attemptAction(a,1).label,'Submit round');
 a.status='finished';assert.equal(attemptAction(a,1).label,'Back to results');assert.equal(attemptAction(a,0).label,'Next');
});
