self.addEventListener('push',event=>{
 let data;try{data=event.data.json()}catch{return}
 event.waitUntil(self.registration.showNotification(data.title||'SUSHI',{body:data.body,icon:'/art/rice-rookie.png',badge:'/art/20261013_EDU_SushiBrandLogo_v1.0.png',tag:data.tag,data:{url:data.url||'/fresh-catch'}}));
});
self.addEventListener('notificationclick',event=>{
 event.notification.close();
 const target=new URL(event.notification.data?.url||'/fresh-catch',self.location.origin);
 if(target.origin!==self.location.origin)return;
 event.waitUntil(clients.matchAll({type:'window',includeUncontrolled:true}).then(async windows=>{
  const win=windows.find(w=>new URL(w.url).origin===self.location.origin);
  if(win){await win.navigate(target.href);return win.focus()}
  return clients.openWindow(target.href);
 }));
});
// Never cache authenticated pages, answers, profiles, or API responses.

