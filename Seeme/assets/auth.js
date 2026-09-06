(function(){
  'use strict';
  const source=document.currentScript&&document.currentScript.src?document.currentScript.src:location.href;
  const siteRoot=new URL('../',new URL(source));
  const apiRoot=new URL('api/',siteRoot); /* self-contained Prefix backend */
  const css=new URL('assets/auth.css?v=27.0.1',siteRoot).href;
  if(!document.querySelector('link[data-sts-auth-css]')){const l=document.createElement('link');l.rel='stylesheet';l.href=css;l.dataset.stsAuthCss='1';document.head.appendChild(l)}
  let csrf='',member=null,readyResolve,lastFocus=null;
  const ready=new Promise(resolve=>{readyResolve=resolve});
  const endpoint=path=>new URL(path.replace(/^\//,''),apiRoot).href;
  function escapeHtml(value){return String(value||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
  async function request(path,options={}){
    const method=(options.method||'GET').toUpperCase();
    const headers=Object.assign({'Accept':'application/json'},options.headers||{});
    if(method!=='GET'&&method!=='HEAD'){
      if(!csrf)await refresh();
      headers['X-CSRF-Token']=csrf;
      if(options.body&&typeof options.body!=='string'){headers['Content-Type']='application/json';options.body=JSON.stringify(options.body)}
    }
    const response=await fetch(endpoint(path),Object.assign({credentials:'same-origin'},options,{method,headers}));
    const payload=await response.json().catch(()=>({ok:false,error:{message:'The server returned an invalid response.'}}));
    if(!response.ok||payload.ok===false){const error=new Error(payload.error&&payload.error.message?payload.error.message:'Request failed.');error.status=response.status;error.code=payload.error&&payload.error.code;error.details=payload.error&&payload.error.details;throw error}
    return payload;
  }
  async function refresh(){
    try{const result=await request('auth/me.php',{method:'GET'});csrf=result.data.csrf_token||csrf;member=result.data.member||null;updateUi();return member}
    catch(error){member=null;updateUi();throw error}
  }
  function updateUi(){
    document.querySelectorAll('[data-auth-state="signed-in"]').forEach(el=>{el.hidden=!member});
    document.querySelectorAll('[data-auth-state="signed-out"]').forEach(el=>{el.hidden=!!member});
    document.querySelectorAll('[data-member-name]').forEach(el=>{el.textContent=member?member.name:''});
    document.dispatchEvent(new CustomEvent('seetosee:auth-changed',{detail:{member}}));
  }
  function ensureModal(){
    let modal=document.getElementById('sts-auth-modal');if(modal)return modal;
    modal=document.createElement('div');modal.id='sts-auth-modal';modal.className='sts-auth-modal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');modal.setAttribute('aria-hidden','true');modal.setAttribute('aria-labelledby','sts-auth-title');
    modal.innerHTML='<div class="sts-auth-card"><div class="sts-auth-head"><div class="sts-auth-brand">SEE<span>TO</span>SEE</div><button type="button" class="sts-auth-close" aria-label="Close">✕</button></div><div class="sts-auth-tabs"><button type="button" data-mode="login">Sign in</button><button type="button" data-mode="register">Create account</button></div><h2 id="sts-auth-title"></h2><p class="sts-auth-copy" id="sts-auth-copy"></p><form id="sts-auth-form"></form><div class="sts-auth-message" id="sts-auth-message" role="status" aria-live="polite"></div><div class="sts-auth-links" id="sts-auth-links"></div></div>';
    document.body.appendChild(modal);
    modal.querySelector('.sts-auth-close').addEventListener('click',close);
    modal.addEventListener('click',event=>{if(event.target===modal)close()});
    modal.querySelectorAll('[data-mode]').forEach(button=>button.addEventListener('click',()=>render(button.dataset.mode)));
    document.addEventListener('keydown',event=>{if(!modal.classList.contains('open'))return;if(event.key==='Escape'){event.preventDefault();close();return}if(event.key!=='Tab')return;const focusable=[...modal.querySelectorAll('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(el=>el.getClientRects().length);if(!focusable.length)return;const first=focusable[0],last=focusable[focusable.length-1];if(event.shiftKey&&(document.activeElement===first||!modal.contains(document.activeElement))){event.preventDefault();last.focus()}else if(!event.shiftKey&&(document.activeElement===last||!modal.contains(document.activeElement))){event.preventDefault();first.focus()}});
    return modal;
  }
  function message(text,type=''){const el=ensureModal().querySelector('#sts-auth-message');el.textContent=text;el.className='sts-auth-message show '+type}
  function render(mode){
    const modal=ensureModal(),form=modal.querySelector('#sts-auth-form'),links=modal.querySelector('#sts-auth-links');
    modal.dataset.mode=mode;modal.querySelectorAll('[data-mode]').forEach(b=>b.classList.toggle('active',b.dataset.mode===mode));
    const title=modal.querySelector('#sts-auth-title'),copy=modal.querySelector('#sts-auth-copy'),msg=modal.querySelector('#sts-auth-message');msg.className='sts-auth-message';msg.textContent='';links.innerHTML='';
    if(mode==='register'){
      title.textContent='Create your account';copy.textContent='';
      form.innerHTML='<label class="sts-auth-field"><span>Name</span><input name="name" autocomplete="name" required maxlength="120"></label><label class="sts-auth-field"><span>Email</span><input name="email" type="email" autocomplete="email" inputmode="email" required maxlength="254"></label><label class="sts-auth-field"><span>Password</span><div class="sts-auth-password"><input name="password" type="password" autocomplete="new-password" required minlength="12"><button type="button" class="sts-auth-password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false" title="Show password"><span aria-hidden="true">👁</span></button></div><small style="color:#8c9399">At least 12 characters, including a letter and number.</small></label><label class="sts-auth-check"><input name="terms" type="checkbox" required><span>I agree to the account, privacy, and electronic-record terms.</span></label><button class="sts-auth-submit" type="submit">Create account</button>';
    }else if(mode==='forgot'){
      title.textContent='Reset your password';copy.textContent='Enter your email. The response is intentionally the same for every address.';
      form.innerHTML='<label class="sts-auth-field"><span>Email</span><input name="email" type="email" autocomplete="email" inputmode="email" required maxlength="254"></label><button class="sts-auth-submit" type="submit">Send reset link</button>';
    }else if(mode==='resend'){
      title.textContent='Resend verification';copy.textContent='We will send a fresh link if the account still needs verification.';
      form.innerHTML='<label class="sts-auth-field"><span>Email</span><input name="email" type="email" autocomplete="email" inputmode="email" required maxlength="254"></label><button class="sts-auth-submit" type="submit">Resend verification</button>';
    }else{
      mode='login';modal.dataset.mode=mode;title.textContent='Welcome back';copy.textContent='Sign in to your private SeeToSee dashboard.';
      form.innerHTML='<label class="sts-auth-field"><span>Email</span><input name="email" type="email" autocomplete="email" inputmode="email" required maxlength="254"></label><label class="sts-auth-field"><span>Password</span><div class="sts-auth-password"><input name="password" type="password" autocomplete="current-password" required><button type="button" class="sts-auth-password-toggle" data-password-toggle aria-label="Show password" aria-pressed="false" title="Show password"><span aria-hidden="true">👁</span></button></div></label><button class="sts-auth-submit" type="submit">Sign in</button>';
      links.innerHTML='<button type="button" data-link="forgot">Forgot password?</button><button type="button" data-link="resend">Resend verification</button>';
      links.querySelectorAll('[data-link]').forEach(b=>b.addEventListener('click',()=>render(b.dataset.link)));
    }
    form.querySelectorAll('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{const wrap=button.closest('.sts-auth-password'),input=wrap&&wrap.querySelector('input');if(!input)return;const show=input.type==='password';input.type=show?'text':'password';button.setAttribute('aria-label',show?'Hide password':'Show password');button.setAttribute('title',show?'Hide password':'Show password');button.setAttribute('aria-pressed',show?'true':'false');button.classList.toggle('is-visible',show);try{input.focus({preventScroll:true})}catch(_){input.focus()}}));
    form.onsubmit=submit;setTimeout(()=>form.querySelector('input')&&form.querySelector('input').focus(),40);
  }
  async function submit(event){
    event.preventDefault();const form=event.currentTarget,button=form.querySelector('button[type="submit"]'),mode=ensureModal().dataset.mode;button.disabled=true;button.dataset.label=button.textContent;button.textContent='Working…';
    try{
      const data=Object.fromEntries(new FormData(form).entries());
      if(mode==='register'){
        await request('auth/register.php',{method:'POST',body:{name:data.name,email:data.email,password:data.password,terms_accepted:data.terms==='on'}});message('If this email is new, check your inbox for a verification link. If you already have an account, sign in or use Forgot password.','success');form.reset();
      }else if(mode==='forgot'){
        await request('auth/forgot-password.php',{method:'POST',body:{email:data.email}});message('If the account exists, a reset email is on its way.','success');
      }else if(mode==='resend'){
        await request('auth/resend-verification.php',{method:'POST',body:{email:data.email}});message('If verification is still needed, a new email is on its way.','success');
      }else{
        const result=await request('auth/login.php',{method:'POST',body:{email:data.email,password:data.password}});member=result.data.member;csrf=result.data.csrf_token;updateUi();
        let handled=false;
        if(typeof window.stsAfterAuthLogin==='function'){
          try{handled=(await window.stsAfterAuthLogin(member))===true}catch(_){handled=false}
        }
        if(handled){message('Signed in. Your NDA Gate is saved to this account.','success');return}
        message('Signed in. Opening your dashboard…','success');setTimeout(()=>{close();location.href=new URL('dashboard/',siteRoot).href},450);
      }
    }catch(error){message(error.message,'error')}finally{button.disabled=false;button.textContent=button.dataset.label||'Continue'}
  }
  function open(mode='login'){const modal=ensureModal();lastFocus=document.activeElement;render(mode);modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden'}
  function close(){const modal=document.getElementById('sts-auth-modal');if(modal){modal.classList.remove('open');modal.setAttribute('aria-hidden','true')}document.body.style.overflow='';if(lastFocus&&typeof lastFocus.focus==='function')lastFocus.focus();lastFocus=null}
  async function signOut(){await request('auth/logout.php',{method:'POST',body:{}});member=null;csrf='';await refresh();location.href=siteRoot.href}
  document.addEventListener('click',event=>{const trigger=event.target.closest&&event.target.closest('[data-auth-open]');if(trigger){event.preventDefault();open(trigger.dataset.authOpen||'login')}});
  const api={open,close,refresh,ready,request,signOut,member:()=>member,siteRoot:siteRoot.href,apiRoot:apiRoot.href};window.SeeToSeeAuth=api;
  refresh().catch(()=>{}).finally(()=>{readyResolve(api);if(new URLSearchParams(location.search).get('signin')==='1')open('login')});
})();
