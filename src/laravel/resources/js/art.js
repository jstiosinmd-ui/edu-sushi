export const art = {
 nori:'/art/nori-librarian.png',rice:'/art/rice-rookie.png',
 logo:'/art/20261013_EDU_SushiBrandLogo_v1.0.png',banner:'/art/20261013_EDU_SushiBrandBanner_v1.0.png',
 maki:'/art/20261013_EDU_SushiCharacterMaki_v1.1.png',temaki:'/art/20261013_EDU_SushiCharacterTemaki_v1.1.png',
 wasabi:'/art/20261013_EDU_SushiCharacterWasabi_v1.1.png',chef:'/art/20261013_EDU_SushiCharacterChef_v1.1.png',
 memo:'/art/20261013_EDU_SushiMemoMaki_v2.0.png',
};
export const crew = {
 'Internal Medicine':{name:'Miso Medic',image:'/art/20261013_EDU_SushiSubjectIM_v1.2.png'},
 'Pediatrics':{name:'Pip the Pedia Roll',image:'/art/20261013_EDU_SushiSubjectPediatrics_v1.2.png'},
 'Surgery':{name:'Suture Salmon',image:'/art/20261013_EDU_SushiSubjectSurgery_v1.2.png'},
 'OB-GYN':{name:'Nori Nurture',image:'/art/20261013_EDU_SushiSubjectOBGYN_v1.2.png'},
};
export const modes={fresh:'Fresh Catch 10',tutor:'Maki Mentor',exam:'Wasabi Rush',assigned:'Assigned exam'};
export const date = value => value ? new Intl.DateTimeFormat('en-PH',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Manila'}).format(new Date(value)) : 'None yet';
export const message = error => Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Could not save. Check your connection and try again.';
