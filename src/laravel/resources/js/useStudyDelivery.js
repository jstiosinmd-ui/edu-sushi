import {ref,onMounted,onUnmounted} from 'vue';
import axios from 'axios';
import {message} from './art';
import {readPending,writePending} from './studyDelivery';

export function useStudyDelivery(key,base,accept){
    let storage;try{storage=window.sessionStorage}catch{storage={getItem:()=>null,setItem:()=>{throw new Error('Storage unavailable')},removeItem:()=>{}}}
    const pending=ref(readPending(storage,key)),busy=ref(false),error=ref(''),online=ref(navigator.onLine),status=ref(pending.value?'An unsaved selection is waiting in this tab.':'All changes saved');
    let durable=true;
    function connection(){online.value=navigator.onLine;if(!online.value)status.value=pending.value?'Offline · selection kept in this tab':'Offline · reconnect to save answers';else if(pending.value)status.value='Connection restored · retry your saved selection'}
    onMounted(()=>{window.addEventListener('online',connection);window.addEventListener('offline',connection);connection()});
    onUnmounted(()=>{window.removeEventListener('online',connection);window.removeEventListener('offline',connection)});
    async function retry(){
        if(!pending.value||busy.value)return;
        busy.value=true;error.value='';status.value='Saving…';
        try{
            const response=await axios.post(base+'/'+pending.value.action,pending.value.payload,{timeout:20000});
            accept(response);pending.value=null;writePending(storage,key,null);status.value='All changes saved';
        }catch(e){error.value=message(e);status.value=(navigator.onLine?'Not saved':'Offline')+' · '+(durable?'selection kept in this tab':'keep this page open to retain your selection')}
        finally{busy.value=false}
    }
    async function send(action,payload){
        if(busy.value||pending.value)return;
        pending.value={action,payload,at:Date.now()};
        durable=writePending(storage,key,pending.value);
        await retry();
    }
    function discard(){pending.value=null;writePending(storage,key,null);error.value='';status.value='Unsent selection discarded. Reloading the saved round.'}
    return {pending,busy,error,online,status,send,retry,discard};
}
