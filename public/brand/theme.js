(() => {
 const root=document.documentElement,media=window.matchMedia('(prefers-color-scheme: dark)');
 let selected;try{selected=localStorage.getItem('builderad.theme');}catch{}
 if(!['light','dark'].includes(selected))selected=null;
 function apply(){const dark=selected?selected==='dark':media.matches;root.classList.toggle('dark',dark);root.dataset.theme=dark?'dark':'light';document.querySelectorAll('[data-theme-toggle]').forEach(button=>{const label=dark?button.dataset.lightLabel:button.dataset.darkLabel;button.setAttribute('aria-pressed',String(dark));if(label){button.setAttribute('aria-label',label);const span=button.querySelector('[data-theme-label]');if(span)span.textContent=label;}});}
 document.addEventListener('click',event=>{const button=event.target.closest?.('[data-theme-toggle]');if(!button||button.disabled)return;selected=root.dataset.theme==='dark'?'light':'dark';try{localStorage.setItem('builderad.theme',selected);}catch{}apply();});
 document.addEventListener('DOMContentLoaded',apply);document.addEventListener('builderad:theme-controls',apply);
 media.addEventListener('change',()=>{if(!selected)apply();});
 window.addEventListener('storage',event=>{if(event.key==='builderad.theme'){selected=['light','dark'].includes(event.newValue)?event.newValue:null;apply();}});
 apply();
})();
