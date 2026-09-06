(function(){'use strict';
const $=s=>document.querySelector(s),esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));let member,commerce;
function date(v){return v?new Date(String(v).replace(' ','T')+'Z').toLocaleString():'—'}
function money(cents,currency='usd'){return new Intl.NumberFormat(undefined,{style:'currency',currency:String(currency||'usd').toUpperCase(),minimumFractionDigits:0,maximumFractionDigits:2}).format(Number(cents||0)/100)}
function msg(form,text,error=false){const el=form.querySelector('[data-form-message]');if(!el)return;el.textContent=text;el.className='message show '+(error?'error':'success')}
async function api(path,options){return SeeToSeeAuth.request(path,options)}

const WEBBOOK_KEY='seetosee_portal_quick_poc_v2';
let webbookEntrySaveTimer=null;
let pendingWebBookMedia='';

function webbookRecord(){
  const p=member&&member.profile_settings&&typeof member.profile_settings==='object'?member.profile_settings:{};
  return p&&p.webbook&&typeof p.webbook==='object'?p.webbook:null;
}
function webbookSavedState(){
  const w=webbookRecord();
  return w&&w.state&&typeof w.state==='object'?w:null;
}
function looksLikeEmail(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v||'').trim())}
function webbookEntry(){
  const w=webbookRecord();
  const e=w&&w.entry&&typeof w.entry==='object'?w.entry:{};
  const savedAbout=String(e.about||'').trim();
  return {
    name:String(e.name||''),
    // V5.4: legacy versions briefly stored the account email here.
    // Never reuse an email as the site's "What do you do?" value.
    about:looksLikeEmail(savedAbout)?'':savedAbout
  };
}
function seedWebBookLocal(w){
  if(!w||!w.state)return;
  const s=w.state;
  try{
    if(s.card)localStorage.setItem(WEBBOOK_KEY,JSON.stringify(s.card));
    if(typeof s.light_theme!=='undefined')localStorage.setItem(WEBBOOK_KEY+'_light_theme',s.light_theme?'1':'0');
    if(typeof s.palette_index!=='undefined')localStorage.setItem(WEBBOOK_KEY+'_palette',String(s.palette_index));
    if(typeof s.site_pages!=='undefined')localStorage.setItem(WEBBOOK_KEY+'_pages',String(s.site_pages));
    if(s.z_mode)localStorage.setItem(WEBBOOK_KEY+'_zmode',String(s.z_mode));
    if(s.site_galleries)localStorage.setItem(WEBBOOK_KEY+'_site_galleries',JSON.stringify(s.site_galleries));
  }catch(_){}
}
function currentWebBookEntryFromForm(){
  const fallback=webbookEntry();
  const n=$('#webbook-name-input'),a=$('#webbook-about-input');
  return {
    name:(n?n.value:fallback.name).trim(),
    about:(a?a.value:fallback.about).trim()
  };
}
function webbookInitials(name){
  const parts=String(name||'').trim().split(/\s+/).filter(Boolean);
  return (parts.length>1?(parts[0][0]+parts[parts.length-1][0]):(parts[0]||'ST').slice(0,2)).toUpperCase();
}
function stsThirdPersonVerb(v){
  const irregular={have:'has',do:'does',go:'goes',be:'is'};
  if(irregular[v])return irregular[v];
  if(/[^aeiou]y$/i.test(v))return v.slice(0,-1)+'ies';
  if(/(s|sh|ch|x|z|o)$/i.test(v))return v+'es';
  return v+'s';
}
function stsRoleSentence(name,role){
  let r=String(role||'').trim().replace(/[.!?]+$/,'');
  if(!r)return name+'.';

  const iam=r.match(/^I\s+am\s+(.+)$/i)||r.match(/^I['’]m\s+(.+)$/i);
  if(iam)return name+' is '+iam[1]+'.';

  const iDo=r.match(/^I\s+(.+)$/i);
  if(iDo)r=iDo[1].trim();

  const gerunds={
    making:'make',creating:'create',building:'build',designing:'design',
    producing:'produce',writing:'write',singing:'sing',rapping:'rap',
    engineering:'engineer',developing:'develop',coding:'code',teaching:'teach',
    consulting:'consult',photographing:'photograph',directing:'direct',
    editing:'edit',selling:'sell',streaming:'stream',performing:'perform',
    managing:'manage',leading:'lead',coaching:'coach',hosting:'host',
    composing:'compose',recording:'record',mixing:'mix',mastering:'master',
    dancing:'dance',painting:'paint',drawing:'draw',styling:'style',
    marketing:'market',running:'run',owning:'own',helping:'help'
  };
  const actionVerbs=new Set([
    'make','create','build','design','produce','write','sing','rap','engineer',
    'develop','code','teach','consult','photograph','direct','edit','sell',
    'stream','perform','manage','lead','coach','host','compose','record','mix',
    'master','dance','paint','draw','style','market','run','own','help'
  ]);

  let parts=r.split(/\s+/),first=parts[0].toLowerCase();
  if(gerunds[first]){
    parts[0]=stsThirdPersonVerb(gerunds[first]);
    return name+' '+parts.join(' ')+'.';
  }
  if(actionVerbs.has(first)){
    parts[0]=stsThirdPersonVerb(first);
    return name+' '+parts.join(' ')+'.';
  }

  if(/^(a|an|the)\s+/i.test(r))return name+' is '+r+'.';
  const article=/^[aeiou]/i.test(r)?'an':'a';
  return name+' is '+article+' '+r+'.';
}
function syncWebBookIdentityIntoState(state,entry){
  if(!state||typeof state!=='object'||!state.card||typeof state.card!=='object')return state;
  const s=JSON.parse(JSON.stringify(state));
  const c=s.card,name=String(entry.name||'').trim(),role=String(entry.about||'').trim();
  if(!name||!role)return s;
  const roleLine=stsRoleSentence(name,role);

  c.brand=c.brand||{};
  c.brand.name=name;
  c.brand.mark=webbookInitials(name);

  c.seo=c.seo||{};
  c.seo.title='Meet '+name+' — '+role;
  c.seo.description='WATCH · ABOUT · LEARN — '+roleLine+' Step inside the work, the story, and what comes next.';
  c.seo.keywords=['WATCH','ABOUT','LEARN',name,role,'SeeToSee hosted website'];

  c.hero=c.hero||{};
  c.hero.kicker=role.toUpperCase().slice(0,90);
  c.hero.headline=name;
  c.hero.subtext=roleLine+' Watch the work, learn the thinking, and step inside the world behind it.';

  if(Array.isArray(c.cards)&&c.cards.length>=3){
    c.cards[0]=Object.assign({},c.cards[0],{
      title:'Meet '+name,
      body:roleLine+' The work is shaped by practice, curiosity, and the point of view behind it.'
    });
    c.cards[1]=Object.assign({},c.cards[1],{
      title:'Watch '+name+' in motion',
      body:'See the projects, ideas, sessions, and proof behind '+name+'\'s work.'
    });
    c.cards[2]=Object.assign({},c.cards[2],{
      title:'Connect with '+name,
      body:'A direct place for the right people to reach '+name+' and continue the conversation.'
    });
  }

  c.messaging=c.messaging||{};
  c.messaging.statement='Meet '+name+'. Watch the work. Learn the point of view. Follow what comes next.';
  c.messaging.ticker=['WATCH','ABOUT','LEARN',role];
  c.messaging.runwayTop=name+' · '+role+' · WATCH · ABOUT · LEARN';
  c.messaging.runwayBottom='MEET '+name+' · THE WORK MOVES · '+role.toUpperCase();

  return s;
}
function webbookMediaFromStorage(){
  try{return String(localStorage.getItem('sts_webbook_pending_media')||'')}catch(_){return ''}
}
function setPendingWebBookMedia(src){
  pendingWebBookMedia=String(src||'');
  try{
    if(pendingWebBookMedia)localStorage.setItem('sts_webbook_pending_media',pendingWebBookMedia);
    else localStorage.removeItem('sts_webbook_pending_media');
  }catch(_){}
  renderWebBookMediaPreview();
}
function renderWebBookMediaPreview(){
  const box=$('#webbook-media-preview');
  if(!box)return;
  const src=pendingWebBookMedia||webbookMediaFromStorage();
  if(!src){box.hidden=true;box.innerHTML='';return}
  pendingWebBookMedia=src;
  box.hidden=false;
  const video=/\.(mp4|webm|mov)(?:$|[?#])/i.test(src);
  const safe=src.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
  box.innerHTML=video
    ?'<video src="'+safe+'" muted loop autoplay playsinline></video>'
    :'<img src="'+safe+'" alt="Selected WebBook media">';
}
function webbookFrontDoorUrl(){
  const w=webbookSavedState();
  if(w)seedWebBookLocal(w);
  const e=currentWebBookEntryFromForm();
  const q=new URLSearchParams();
  q.set('account','1');
  q.set('prefix','Greetings');
  if(w)q.set('resume','1');
  if(e.name)q.set('name',e.name);
  if(e.about)q.set('about',e.about);
  const media=pendingWebBookMedia||webbookMediaFromStorage();
  if(media)q.set('media',media);
  return '/WebBook/?'+q.toString();
}
function webbookBuilderUrl(){
  const w=webbookSavedState();
  if(w)seedWebBookLocal(w);
  const e=currentWebBookEntryFromForm();
  const q=new URLSearchParams();
  q.set('account','1');
  q.set('prefix','Greetings');
  if(w){
    q.set('resume','1');
  }else{
    q.set('fresh','1');
    if(e.name)q.set('name',e.name);
    if(e.about)q.set('about',e.about);
  }
  const media=pendingWebBookMedia||webbookMediaFromStorage();
  if(media)q.set('media',media);
  return '/WebBook/Seeme101/WebBook/quick-builder.html?'+q.toString();
}
function renderWebBook(){
  const w=webbookRecord();
  const e=webbookEntry();
  const n=$('#webbook-name-input'),a=$('#webbook-about-input'),status=$('#webbook-status'),updated=$('#webbook-updated'),front=$('#webbook-frontdoor');
  if(n&&document.activeElement!==n)n.value=e.name;
  if(a&&document.activeElement!==a)a.value=e.about;
  if(status)status.textContent=w&&w.state?'Saved to this SeeToSee account':'Ready to build';
  if(updated)updated.textContent=w&&w.updated_at?date(w.updated_at):'—';
  if(front)front.href=webbookFrontDoorUrl();
  renderWebBookMediaPreview();
}
function updateMemberWebBookLocal(saved){
  if(!member.profile_settings||typeof member.profile_settings!=='object')member.profile_settings={};
  member.profile_settings.webbook=saved;
  renderWebBook();
}
async function saveWebBookEntry(){
  const msg=$('#webbook-message');
  const entry=currentWebBookEntryFromForm();

  if(!entry.name){
    if(msg){msg.textContent='Enter Name / Company.';msg.className='message show error'}
    const n=$('#webbook-name-input');if(n)n.focus();
    return false;
  }
  if(!entry.about||looksLikeEmail(entry.about)){
    if(msg){msg.textContent='Enter what you do — not an email address.';msg.className='message show error'}
    const a=$('#webbook-about-input');if(a){a.value='';a.focus()}
    return false;
  }

  if(msg){msg.textContent='Saving WebBook information…';msg.className='message show'}
  try{
    const w=webbookSavedState();
    const body={entry};
    if(w&&w.state)body.state=syncWebBookIdentityIntoState(w.state,entry);
    const r=await api('account/webbook.php',{method:'POST',body});
    if(r&&r.data&&r.data.webbook)updateMemberWebBookLocal(r.data.webbook);
    if(msg){msg.textContent='✓ Name / Company and What do you do? saved to this WebBook.';msg.className='message show success'}
    return true;
  }catch(e){
    if(msg){msg.textContent=e.message||'Could not save WebBook information.';msg.className='message show error'}
    return false;
  }
}
function scheduleWebBookEntrySave(){
  clearTimeout(webbookEntrySaveTimer);
  const msg=$('#webbook-message');
  if(msg){msg.textContent='WebBook information changed — saving…';msg.className='message show'}
  webbookEntrySaveTimer=setTimeout(saveWebBookEntry,550);
}
function renderMember(){
 document.querySelectorAll('[data-member-name]').forEach(e=>e.textContent=member.name);$('#account-status').textContent='Secure session active';$('#member-id').textContent=member.public_id;$('#member-email').textContent=member.email;$('#member-verified').textContent=member.email_verified?'Verified':'Verification required';$('#member-joined').textContent=date(member.joined_at);$('#member-login').textContent=date(member.last_login_at);
 const pf=$('#profile-form');pf.name.value=member.name;pf.email.value=member.email;pf.notification_email.value=member.notification_email||'';pf.destination_url.value=member.destination_url||'';
 const xf=$('#prefix-form');if(member.prefix){xf.handle.value=member.prefix.handle;xf.suffix.value=member.prefix.suffix;xf.destination_url.value=member.prefix.destination_url}else if(member.destination_url)xf.destination_url.value=member.destination_url; renderWebBook();
 renderEmailHub();
 syncVerifiedUi();
}
function renderPrefix(prefix){const pill=$('#prefix-pill'),copy=$('#prefix-status-copy'),a=$('#prefix-availability');if(!prefix){pill.textContent='NOT LOCKED';pill.className='prefix-pill';copy.textContent='Choose an available PreFix. Its free reservation lasts one month.';return}pill.textContent=String(prefix.status||'reserved').toUpperCase();pill.className='prefix-pill '+(prefix.active?'active':'expired');copy.textContent=prefix.paid?'Paid PreFix is active.':prefix.active?'Reserved until '+date(prefix.reserved_until)+'.':'Free reservation expired. Activate the $12/month PreFix product to continue.';a.textContent=prefix.display_identity+' → '+prefix.destination_url+(prefix.reserved_until?' · reserved until '+date(prefix.reserved_until):'');a.className='message show '+(prefix.active?'success':'error')}
function renderEntitlements(items){const active=new Set(items.map(e=>e.entitlement_key));$('#entitlement-chips').innerHTML=items.length?items.map(e=>'<span class="chip">'+esc(e.entitlement_key)+(e.ends_at?' · until '+esc(date(e.ends_at)):'')+'</span>').join(''):'<span class="chip">No paid entitlements active.</span>';const isActive=active.has('prefix.active');$('#prefix-activate').hidden=isActive;$('#prefix-active-chip').hidden=!isActive}
function renderOrders(rows){$('#order-rows').innerHTML=rows.length?rows.map(o=>'<tr><td><strong>'+esc(o.public_id)+'</strong></td><td>'+esc(o.product_id)+'</td><td>'+esc(money(o.amount_cents,o.currency))+'</td><td>'+esc(o.status)+'</td><td>'+esc(date(o.paid_at||o.created_at))+'</td></tr>').join(''):'<tr><td colspan="5" class="empty">No purchases yet.</td></tr>'}
function renderCommerce(data){commerce=data;renderPrefix(data.prefix);renderEntitlements(data.entitlements);renderOrders(data.orders)}
async function loadCommerce(){const r=await api('commerce/status.php');renderCommerce(r.data)}
$('#prefix-form').addEventListener('input',async()=>{const f=$('#prefix-form'),h=f.handle.value.trim(),s=f.suffix.value,a=$('#prefix-availability');if(h.length<2)return;try{const r=await api('prefix/availability.php?handle='+encodeURIComponent(h)+'&suffix='+encodeURIComponent(s));a.textContent=r.data.results[0].available?'Available: '+r.data.results[0].identity:'Already reserved or paid';a.className='message show '+(r.data.results[0].available?'success':'error')}catch(e){a.textContent=e.message;a.className='message show error'}});
$('#prefix-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button[type=submit]');if(!isVerified()){$('#prefix-availability').textContent='Verify your email before locking a PreFix.';$('#prefix-availability').className='message show error';return}b.disabled=true;try{const r=await api('prefix/claim.php',{method:'POST',body:{handle:f.handle.value,suffix:f.suffix.value,destination_url:f.destination_url.value}});member.prefix=r.data.prefix;renderPrefix(r.data.prefix);syncVerifiedUi()}catch(x){$('#prefix-availability').textContent=x.message;$('#prefix-availability').className='message show error'}finally{b.disabled=false}});
$('#prefix-activate').addEventListener('click',async e=>{if(!isVerified()){alert('Verify your email before activating PreFix.');return}e.currentTarget.disabled=true;try{const r=await api('commerce/checkout.php',{method:'POST',body:{product_id:'subscription.prefix.monthly'}});location.href=r.data.checkout_url}catch(x){alert(x.message);e.currentTarget.disabled=false}});
$('#profile-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button');b.disabled=true;try{const r=await api('account/profile.php',{method:'POST',body:{name:f.name.value,email:f.email.value,notification_email:f.notification_email.value,destination_url:f.destination_url.value,current_password:f.current_password.value,profile_settings:member.profile_settings||{}}});if(r.data.reauthentication_required){msg(f,'Email change requested. Verify the new address, then sign in again.');setTimeout(()=>location.href='../',1400)}else{member=r.data.member;renderMember();msg(f,'Profile updated.')}}catch(x){msg(f,x.message,true)}finally{b.disabled=false}});
$('#password-form').addEventListener('submit',async e=>{e.preventDefault();const f=e.currentTarget,b=f.querySelector('button');b.disabled=true;try{await api('auth/change-password.php',{method:'POST',body:{current_password:f.current_password.value,new_password:f.new_password.value}});f.reset();msg(f,'Password changed. Other sessions were signed out.')}catch(x){msg(f,x.message,true)}finally{b.disabled=false}});
$('#billing-portal').addEventListener('click',async e=>{e.currentTarget.disabled=true;try{const r=await api('stripe/portal.php',{method:'POST',body:{}});location.href=r.data.portal_url}catch(x){alert(x.message);e.currentTarget.disabled=false}});
async function enterBooth(booth){const btn=$('#booth-enter-'+booth),msgEl=$('#booth-message');if(!isVerified()){msgEl.textContent='Verify your email before opening a booth.';msgEl.className='message show error';return}btn.disabled=true;msgEl.className='message';msgEl.textContent='';try{const r=await api('booth/issue-pass.php',{method:'POST',body:{booth}});const boothPath=booth==='ipv2'?'FJNBoothIPv2':'FJNBoothIPv1';location.href='https://fareljean.com/'+boothPath+'/?pass='+encodeURIComponent(r.data.pass)}catch(x){msgEl.textContent=x.message;msgEl.className='message show error';btn.disabled=false}}
$('#booth-enter-ipv1').addEventListener('click',()=>enterBooth('ipv1'));
$('#booth-enter-ipv2').addEventListener('click',()=>enterBooth('ipv2'));
document.addEventListener('click',async e=>{const so=e.target.closest('[data-signout]');if(so)await SeeToSeeAuth.signOut()});
$('#delete-account').addEventListener('click',async()=>{const confirmation=prompt('Type DELETE to permanently delete your account and member records.');if(confirmation!=='DELETE')return;const password=prompt('Enter your current password to confirm.');if(!password)return;try{await api('account/delete.php',{method:'POST',body:{confirmation,password}});location.href='../'}catch(e){alert(e.message)}});
function ensureProtocol(v){v=(v||'').trim();return v&&!/^[a-z][a-z0-9+.-]*:\/\//i.test(v)?'https://'+v:v}
document.querySelectorAll('input[name="destination_url"]').forEach(inp=>{const fix=()=>{if(inp.value)inp.value=ensureProtocol(inp.value)};inp.addEventListener('blur',fix);inp.addEventListener('keydown',e=>{if(e.key==='Enter')fix()})});

$('#email-resend').addEventListener('click',async e=>{
 const btn=e.currentTarget,msgEl=$('#email-hub-message');
 btn.disabled=true;msgEl.className='message';msgEl.textContent='';
 try{
  await api('auth/resend-verification.php',{method:'POST',body:{email:member.email}});
  msgEl.textContent='If verification is still needed, a new email is on its way.';
  msgEl.className='message show success';
 }catch(x){
  msgEl.textContent=x.message||'Could not resend verification.';
  msgEl.className='message show error';
 }finally{btn.disabled=false}
});

const wbNameInput=$('#webbook-name-input');
const wbAboutInput=$('#webbook-about-input');
[wbNameInput,wbAboutInput].filter(Boolean).forEach(el=>{
  el.addEventListener('input',scheduleWebBookEntrySave);
  el.addEventListener('change',saveWebBookEntry);
});

const wbMediaButton=$('#webbook-media-upload');
const wbMediaInput=$('#webbook-media-input');
if(wbMediaButton&&wbMediaInput){
  wbMediaButton.addEventListener('click',()=>{
    const csrf=document.cookie.match(/(?:^|;\s*)sts_csrf=([^;]+)/);
    if(!csrf){
      const m=$('#webbook-message');
      if(m){m.textContent='Sign in to upload a photo or video.';m.className='message show error'}
      return;
    }
    wbMediaInput.value='';
    wbMediaInput.click();
  });
  wbMediaInput.addEventListener('change',async()=>{
    const file=wbMediaInput.files&&wbMediaInput.files[0];
    if(!file)return;
    const isImage=file.type.startsWith('image/');
    const isVideo=file.type.startsWith('video/');
    const m=$('#webbook-message');
    if(!isImage&&!isVideo){
      if(m){m.textContent='Choose an image or video.';m.className='message show error'}
      return;
    }
    const max=isVideo?100*1024*1024:15*1024*1024;
    if(file.size>max){
      if(m){m.textContent=isVideo?'Video maximum is 100 MB.':'Image maximum is 15 MB.';m.className='message show error'}
      return;
    }
    const csrfMatch=document.cookie.match(/(?:^|;\s*)sts_csrf=([^;]+)/);
    if(!csrfMatch){
      if(m){m.textContent='Sign in to upload a photo or video.';m.className='message show error'}
      return;
    }
    const fd=new FormData();
    fd.append('media',file,file.name);
    wbMediaButton.disabled=true;
    wbMediaButton.textContent='UPLOADING…';
    if(m){m.textContent='Uploading WebBook media…';m.className='message show'}
    try{
      const r=await fetch('/Seeme/api/account/webbook-media.php',{
        method:'POST',
        credentials:'same-origin',
        headers:{'Accept':'application/json','X-CSRF-Token':decodeURIComponent(csrfMatch[1])},
        body:fd
      });
      const j=await r.json().catch(()=>null);
      if(!r.ok||!j||!j.ok)throw new Error(j&&j.error&&j.error.message?j.error.message:'Upload failed');
      const src=j.data&&j.data.media&&j.data.media.url;
      if(!src)throw new Error('Upload returned no media URL');
      setPendingWebBookMedia(src);
      if(m){m.textContent='✓ Photo / video ready for this WebBook.';m.className='message show success'}
    }catch(e){
      if(m){m.textContent=e.message||'Could not upload media.';m.className='message show error'}
    }finally{
      wbMediaButton.disabled=false;
      wbMediaButton.textContent='＋ UPLOAD PHOTO / VIDEO';
    }
  });
}

const wbOpen=$('#webbook-open');
if(wbOpen)wbOpen.addEventListener('click',async()=>{
  clearTimeout(webbookEntrySaveTimer);
  const ok=await saveWebBookEntry();
  if(!ok)return;
  try{location.href=webbookBuilderUrl()}catch(e){
    const m=$('#webbook-message');if(m){m.textContent=e.message||'Could not open WebBook builder.';m.className='message show error'}
  }
});

const wbFront=$('#webbook-frontdoor');
if(wbFront)wbFront.addEventListener('click',async e=>{
  e.preventDefault();
  clearTimeout(webbookEntrySaveTimer);
  const ok=await saveWebBookEntry();
  if(!ok)return;
  location.href=webbookFrontDoorUrl();
});

const wbRoute=$('#nda-webbook-route');
if(wbRoute)wbRoute.addEventListener('click',()=>{setTimeout(()=>{const n=$('#webbook-name-input');if(n)n.focus({preventScroll:true})},80)});

function showPurchaseBanner(){const q=new URLSearchParams(location.search);const purchase=q.get('purchase');if(purchase!=='success'&&purchase!=='portal')return;const el=$('#purchase-banner');if(!el)return;el.hidden=false;el.className='message show success';if(purchase==='portal'){el.innerHTML='Portal purchase received. When access is active, use <a href="#booth" style="color:inherit;font-weight:800;text-decoration:underline">Enter booth</a> below.'}try{q.delete('purchase');q.delete('order');const clean=location.pathname+(q.toString()?'?'+q.toString():'')+(location.hash||'#booth');history.replaceState(null,'',clean)}catch(_){}}
async function init(){await SeeToSeeAuth.ready;member=SeeToSeeAuth.member();if(!member){location.href='../?signin=1';return}renderMember();showPurchaseBanner();try{await loadCommerce()}catch(e){$('#account-status').textContent=e.message;$('#account-status').style.color='var(--red)'}}init();
})();
