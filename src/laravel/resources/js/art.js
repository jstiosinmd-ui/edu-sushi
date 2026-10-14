export const art = {
 nori:'/art/nori-librarian.png',rice:'/art/rice-rookie.png',
 logo:'/art/20261013_EDU_SushiBrandLogo_v1.0.png',banner:'/art/20261013_EDU_SushiBrandBanner_v1.0.png',
 loginBanner:'/art/20261014_EDU_SushiLoginBanner_v2.4.webp',
 maki:'/art/20261013_EDU_SushiCharacterMaki_v1.1.png',temaki:'/art/20261013_EDU_SushiCharacterTemaki_v1.1.png',
 wasabi:'/art/20261013_EDU_SushiCharacterWasabi_v1.1.png',chef:'/art/20261013_EDU_SushiCharacterChef_v1.1.png',
 memo:'/art/20261013_EDU_SushiMemoMaki_v2.0.png',
};
export const crew = {
 'Internal Medicine':{name:'Miso Medic',image:'/art/20261013_EDU_SushiSubjectIM_v1.2.png'},
 'Pediatrics':{name:'Pip the Pedia Roll',image:'/art/20261013_EDU_SushiSubjectPediatrics_v1.2.png'},
 'Surgery':{name:'Suture Salmon',image:'/art/20261013_EDU_SushiSubjectSurgery_v1.2.png'},
 'OB-GYN':{name:'Nori Nurture',image:'/art/20261013_EDU_SushiSubjectOBGYN_v1.2.png'},
 'Preventive Medicine':{name:'Kappa Care',image:'/art/subjects/preventive-v2.5.webp'},
 'Anatomy':{name:'Tama Atlas',image:'/art/subjects/anatomy-v2.5.webp'},
 'Legal Medicine-Ethics':{name:'Inari Integrity',image:'/art/subjects/legal-v2.5.webp'},
 'Pathology':{name:'Ikura Insight',image:'/art/subjects/pathology-v2.5.webp'},
 'Pharmacology':{name:'Ebi Remedy',image:'/art/subjects/pharmacology-v2.5.webp'},
 'Biochemistry':{name:'Temaki Spark',image:'/art/subjects/biochemistry-v2.5.webp'},
 'Physiology':{name:'Unagi Pulse',image:'/art/subjects/physiology-v2.7.webp'},
 'Microbiology-Parasitology':{name:'Shimeji Scout',image:'/art/subjects/microbiology-v2.7.webp'},
};
export const modes={fresh:'Fresh Catch 10',tutor:'Maki Mentor',exam:'Wasabi Rush',assigned:'Assigned exam'};
export const date = value => value ? new Intl.DateTimeFormat('en-PH',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Manila'}).format(new Date(value)) : 'None yet';
export const message = error => Object.values(error.response?.data?.errors || {}).flat().join(' ') || error.response?.data?.message || 'Could not save. Check your connection and try again.';
