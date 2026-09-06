(function(){
'use strict';
const $=s=>document.querySelector(s);
const esc=v=>String(v==null?'':v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
let member=null,currentGate=null;
function draft(){try{return JSON.parse(localStorage.getItem('sts_nda_draft')||'null')}catch(_){return null}}
function draftBelongsToMember(d){return !d||!d.owner_member_public_id||!member||!member.public_id||d.owner_member_public_id===member.public_id}
function clearPending(){try{localStorage.removeItem('sts_nda_save_pending')}catch(_){}}
function setMessage(text,error=false){const el=$('#nda-save-msg');if(!el)return;el.textContent=text;el.className='message show '+(error?'error':'success')}
function abs(path){return new URL(path,SeeToSeeAuth.apiRoot).href}
function builderUrl(){return location.origin+'/?resume_nda=1'}

// V4.3 — owner-side inline wording editor + 8 templates + smart sizing for the ACTUAL saved Gate shown in the dashboard.
// The iframe is same-origin, so the authenticated owner can edit visible Gate copy directly.
// Saving reuses the existing owner-checked nda/get.php + nda/save.php flow; no new API or DB path.
const NDA_EDIT_SELECTOR='.ey,.hl,.sub,.nl,.nt,.f label,.ag-t,.btn';
let selectedTemplate='custom';
const TEMPLATE_LABELS={nda:'NDA Gate',agree:'Agree to Disagree',public:'Public Gate',holiday:'Holiday Gate',invitation:'Invitation Gate',early:'Early Access',message:'Message Gate',custom:'Custom'};
function gateBrand(){
  try{
    const u=new URL((currentGate&&currentGate.destination_url)||'');
    return u.hostname.replace(/^www\./,'')||'your site';
  }catch(_){return 'your site'}
}
function templateSet(){
  const brand=gateBrand();
  const owner=(currentGate&&currentGate.owner_name)||brand;
  return {
    nda:{
      eyebrow:'You have been invited',
      headline_html:'Welcome to <em>'+esc(brand)+'</em>',
      subtitle:'See to See — enter your details to access',
      agreement_label:'Confidentiality Agreement',
      agreement_text:'CONFIDENTIALITY AGREEMENT — The content accessible through this gate may include private creative material shared by '+owner+' (the Disclosing Party). By entering your details and agreeing, you agree to keep the information confidential, not copy, publish, distribute, or disclose it without written permission, and not use it for commercial gain or competitive advantage without a separate written agreement. Your name, email, timestamp, and record ID are captured as a record of your agreement.',
      name_label:'Name',email_label:'Email',
      agree_text:'I agree to the terms above and understand my entry creates a timestamped access record.',
      button_text:'I agree — show me the content'
    },
    agree:{
      eyebrow:'A simple agreement',
      headline_html:'We Agree to <em>Disagree</em>',
      subtitle:'Sign up here to continue',
      agreement_label:'The Agreement',
      agreement_text:'We agree to disagree. Sign up here to continue.',
      name_label:'Name',email_label:'Email',
      agree_text:'I agree to disagree and want to continue.',
      button_text:'Sign up — enter'
    },
    public:{
      eyebrow:'Welcome',
      headline_html:'Enter <em>'+esc(brand)+'</em>',
      subtitle:'A simple public entry Gate',
      agreement_label:'Public Access',
      agreement_text:'Enter your name and email to continue. Your entry creates a simple timestamped access record.',
      name_label:'Name',email_label:'Email',
      agree_text:'I want to continue into the site.',
      button_text:'Continue'
    },
    holiday:{
      eyebrow:'A holiday invitation',
      headline_html:'Holiday <em>Gate</em>',
      subtitle:'Join the list for this holiday experience',
      agreement_label:'Holiday Message',
      agreement_text:'Sign up to receive the holiday message, playlist, video, or experience shared through this Gate.',
      name_label:'Name',email_label:'Email',
      agree_text:'Yes, send me the holiday message.',
      button_text:'Join the holiday list'
    },
    invitation:{
      eyebrow:'You have been invited',
      headline_html:'Private <em>Invitation</em>',
      subtitle:'Identify yourself to enter',
      agreement_label:'Guest Entry',
      agreement_text:'You have been invited. Enter your name and email so the host knows who entered this private page.',
      name_label:'Guest Name',email_label:'Guest Email',
      agree_text:'I confirm I am the invited guest.',
      button_text:'Enter invitation'
    },
    early:{
      eyebrow:'Be first',
      headline_html:'Early <em>Access</em>',
      subtitle:'Join before the public release',
      agreement_label:'Early Access',
      agreement_text:'Join for early access to this private preview, launch, beta, release, or first-look experience.',
      name_label:'Name',email_label:'Email',
      agree_text:'I want early access and understand this may be a private preview.',
      button_text:'Join early access'
    },
    message:{
      eyebrow:'A message is waiting',
      headline_html:'Private <em>Message</em>',
      subtitle:'Sign up to receive what was shared for you',
      agreement_label:'Message Gate',
      agreement_text:'Sign up to receive this private message, link, file, video, audio, or other digital message.',
      name_label:'Name',email_label:'Email',
      agree_text:'I want to receive this message.',
      button_text:'Receive message'
    }
  };
}
function setTemplateStatus(key){
  selectedTemplate=key||'custom';
  document.querySelectorAll('[data-gate-template]').forEach(b=>b.classList.toggle('active',b.dataset.gateTemplate===selectedTemplate));
  const status=$('#nda-template-status');
  if(status)status.textContent=selectedTemplate==='custom'?'Custom · current wording kept.':'Active template: '+(TEMPLATE_LABELS[selectedTemplate]||selectedTemplate)+' · edit any words, then Save wording.';
}
function smartAgreementSize(doc){
  const el=doc&&doc.querySelector('.nt');if(!el)return;
  const n=(el.textContent||'').trim().length;
  let size=17,line=1.8,minH=220;
  if(n<=55){size=34;line=1.25;minH=220}
  else if(n<=110){size=28;line=1.35;minH=220}
  else if(n<=180){size=23;line=1.48;minH=240}
  else if(n<=300){size=19;line=1.62;minH=260}
  else if(n<=520){size=16;line=1.72;minH=300}
  else {size=14;line=1.78;minH=340}
  el.style.setProperty('font-size',size+'px','important');
  el.style.setProperty('line-height',String(line),'important');
  el.style.setProperty('min-height',minH+'px','important');
}
function smartHeadlineSize(doc){
  const el=doc&&doc.querySelector('.hl');if(!el)return;
  const n=(el.textContent||'').trim().length;
  let size=30;if(n<=14)size=40;else if(n<=28)size=34;else if(n<=48)size=30;else size=25;
  el.style.setProperty('font-size',size+'px','important');
}
function smartSubtitleSize(doc){
  const el=doc&&doc.querySelector('.sub');if(!el)return;
  const n=(el.textContent||'').trim().length;
  el.style.setProperty('font-size',(n<=28?15:n<=55?13:12)+'px','important');
}
function smartFit(doc){smartAgreementSize(doc);smartHeadlineSize(doc);smartSubtitleSize(doc)}
function applyGateTemplate(key){
  const doc=previewDoc();if(!doc||!currentGate)return;
  if(key==='custom'){setTemplateStatus('custom');smartFit(doc);return}
  const t=templateSet()[key];if(!t)return;
  const labels=doc.querySelectorAll('.f label');
  const ey=doc.querySelector('.ey'),hl=doc.querySelector('.hl'),sub=doc.querySelector('.sub'),nl=doc.querySelector('.nl'),nt=doc.querySelector('.nt'),ag=doc.querySelector('.ag-t'),btn=doc.querySelector('.btn');
  if(ey)ey.textContent=t.eyebrow;
  if(hl)hl.innerHTML=t.headline_html;
  if(sub)sub.textContent=t.subtitle;
  if(nl)nl.textContent=t.agreement_label;
  if(nt)nt.textContent=t.agreement_text;
  if(labels[0])labels[0].textContent=t.name_label;
  if(labels[1])labels[1].textContent=t.email_label;
  if(ag)ag.textContent=t.agree_text;
  if(btn)btn.textContent=t.button_text;
  setTemplateStatus(key);smartFit(doc);
}
function previewDoc(){
  const fr=$('#nda-preview-frame');
  try{return fr&&fr.contentDocument&&fr.contentDocument.body?fr.contentDocument:null}catch(_){return null}
}
function editorCopy(doc){
  const text=sel=>{const el=doc.querySelector(sel);return el?el.textContent.trim():''};
  const html=sel=>{const el=doc.querySelector(sel);return el?el.innerHTML.trim():''};
  const labels=doc.querySelectorAll('.f label');
  return {
    eyebrow:text('.ey'),
    headline_html:html('.hl'),
    subtitle:text('.sub'),
    agreement_label:text('.nl'),
    agreement_text:text('.nt'),
    name_label:labels[0]?labels[0].textContent.trim():'',
    email_label:labels[1]?labels[1].textContent.trim():'',
    agree_text:text('.ag-t'),
    button_text:text('.btn')
  };
}
function serializeEditedGate(doc){
  const root=doc.documentElement.cloneNode(true);
  root.querySelectorAll('#sts-owner-editor-style,#sts-nda-single-scroll').forEach(el=>el.remove());
  root.querySelectorAll('[data-sts-editable]').forEach(el=>{
    el.removeAttribute('contenteditable');
    el.removeAttribute('spellcheck');
    el.removeAttribute('data-sts-editable');
  });
  return '<!DOCTYPE html>\n'+root.outerHTML;
}
function installInlineEditor(){
  const doc=previewDoc(),saveBtn=$('#nda-save-wording');
  if(!doc||!currentGate||!currentGate.public_id){if(saveBtn)saveBtn.hidden=true;return}
  if(!doc.getElementById('sts-owner-editor-style')){
    const st=doc.createElement('style');
    st.id='sts-owner-editor-style';
    st.textContent='[data-sts-editable]{outline:1px dashed rgba(242,212,110,.32);outline-offset:3px;cursor:text}[data-sts-editable]:focus{outline:2px solid #F2D46E;outline-offset:4px;background:rgba(242,212,110,.06)}';
    (doc.head||doc.documentElement).appendChild(st);
  }
  doc.querySelectorAll(NDA_EDIT_SELECTOR).forEach(el=>{
    // Keep system-generated timestamp/record UI and form inputs functional/locked.
    if(el.closest('#ss')||el.classList.contains('ts')||el.closest('.badge'))return;
    el.setAttribute('contenteditable','true');
    el.setAttribute('spellcheck','true');
    el.setAttribute('data-sts-editable','1');
    el.addEventListener('input',function(){selectedTemplate='custom';setTemplateStatus('custom');smartFit(doc)});
  });
  const storedKey=(currentGate&&currentGate.builder_json&&currentGate.builder_json.template_key)||'custom';
  setTemplateStatus(storedKey);
  smartFit(doc);
  const templatePanel=$('#nda-template-editor');if(templatePanel)templatePanel.hidden=false;
  // In owner edit mode the call-to-action is text, not a signing action.
  const bn=doc.getElementById('bn');
  if(bn)bn.onclick=function(e){e.preventDefault();e.stopPropagation();this.focus();return false};
  const ag=doc.querySelector('.ag');
  if(ag)ag.addEventListener('click',function(e){if(e.target.closest('[data-sts-editable]'))e.preventDefault()},true);
  if(saveBtn)saveBtn.hidden=false;
}
async function saveInlineWording(){
  const saveBtn=$('#nda-save-wording'),doc=previewDoc();
  if(!currentGate||!currentGate.public_id||!doc){setMessage('Open a saved Gate preview before editing wording.',true);return}
  if(saveBtn){saveBtn.disabled=true;saveBtn.textContent='Saving…'}
  try{
    const r=await SeeToSeeAuth.request('nda/get.php?gate='+encodeURIComponent(currentGate.public_id));
    const g=r.data.gate;
    const copy=editorCopy(doc);
    const builder=Object.assign({},g.builder_json||{});
    builder.copy=copy;
    builder.template_key=selectedTemplate||'custom';
    const body={
      gate_public_id:g.public_id,
      title:g.title,
      destination_url:g.destination_url,
      owner_name:g.owner_name||'',
      alert_email:g.alert_email||'',
      agreement_text:copy.agreement_text||g.agreement_text||'',
      file_format:g.file_format||'html',
      content_html:serializeEditedGate(doc),
      builder_json:builder,
      updated_at:new Date().toISOString()
    };
    const saved=await SeeToSeeAuth.request('nda/save.php',{method:'POST',body});
    currentGate=Object.assign({},currentGate,saved.data.gate,{builder_json:builder});
    try{
      const d=draft();
      if(d&&draftBelongsToMember(d)&&d.gate_public_id===g.public_id){
        d.agreement_text=body.agreement_text;d.content_html=body.content_html;d.builder_json=builder;d.updated_at=body.updated_at;
        localStorage.setItem('sts_nda_draft',JSON.stringify(d));
      }
    }catch(_){}
    setMessage('✓ Wording saved. Check it out and fresh downloads now use these words.');
    const fr=$('#nda-preview-frame');
    if(fr){fr.src=abs(saved.data.gate.preview_url)+'&v='+Date.now()}
    await loadGates();
  }catch(e){setMessage('Could not save Gate wording: '+(e&&e.message?e.message:'Please try again.'),true)}
  finally{if(saveBtn){saveBtn.disabled=false;saveBtn.textContent='Save wording'}}
}
function gateVisualFor(g){
  const v=g&&g.builder_json&&g.builder_json.visual?g.builder_json.visual:null;
  const key=v&&v.key?String(v.key):'';
  const map={
    'silver-tunnel':{kind:'image',src:'../Zaxis/silvertunell-poster.jpg'},
    'business-escalator':{kind:'image',src:location.origin+'/Gates/business-escalator.png'},
    'business-lightbulb':{kind:'image',src:location.origin+'/Gates/business-lightbulb.png'},
    'business-lounge':{kind:'image',src:location.origin+'/Gates/business-lounge.png'},
    'film-sail-portal':{kind:'video',src:location.origin+'/Gates/film-sail-portal.mp4'},
    'film-orbits':{kind:'video',src:location.origin+'/Gates/film-orbits.mp4'},
    'film-tower-skys':{kind:'video',src:location.origin+'/Gates/film-tower-skys.mp4'},
    'design-tee':{kind:'video',src:location.origin+'/Gates/design-tee.mp4'},
    'code-negative-space':{kind:'iframe',src:location.origin+'/Gates/code-negative-space.html'},
    'other-curbside':{kind:'image',src:location.origin+'/Gates/other-curbside.jpeg'},
    'other-luxury-walkthrough':{kind:'video',src:location.origin+'/Gates/other-luxury-walkthrough.mp4'},
    'other-luxury-pools':{kind:'video',src:location.origin+'/Gates/other-luxury-pools.mp4'}
  };
  return map[key]||null;
}
function setCurrent(g){
  currentGate=g||null;let d=draft()||{};
  if(!draftBelongsToMember(d))d={};
  $('#nda-current-title').textContent=(g&&g.title)||d.title||'Your NDA Gate';
  $('#nda-current-url').textContent=(g&&g.destination_url)||d.destination_url||'—';
  $('#nda-current-email').textContent=(g&&g.alert_email)||d.alert_email||(member&&member.notification_email)||(member&&member.email)||'Account email';
  const preview=$('#nda-preview-current'),download=$('#nda-download-current'),saveWording=$('#nda-save-wording'),templatePanel=$('#nda-template-editor');
  const liveWrap=$('#nda-live-preview'),liveFrame=$('#nda-preview-frame'),liveVideo=$('#nda-preview-video'),liveImage=$('#nda-preview-image');
  if(saveWording)saveWording.hidden=true;
  if(templatePanel)templatePanel.hidden=true;
  [liveFrame,liveVideo,liveImage].forEach(el=>{if(!el)return;el.hidden=true;if(el.tagName==='VIDEO'){try{el.pause()}catch(_){}el.removeAttribute('src')}else el.removeAttribute('src')});
  if(g&&g.preview_url){preview.href=abs(g.preview_url);preview.hidden=false}else{preview.hidden=true;preview.removeAttribute('href')}
  if(g&&g.download_url){download.href=abs(g.download_url);download.hidden=false}else{download.hidden=true;download.removeAttribute('href')}
  if(liveWrap){
    // V3.2 SURGICAL FIX: the large dashboard box shows the saved Gate itself first.
    // preview_url is the owner-only inline version of the exact content_html saved by the builder.
    // That keeps the NDA words + signer fields + selected visual together as one product.
    if(g&&g.preview_url&&liveFrame){
      liveFrame.src=abs(g.preview_url);
      liveFrame.hidden=false;
      liveWrap.hidden=false;
    }else{
      // Only fall back to a decorative asset when there is no saved Gate preview URL.
      const visual=gateVisualFor(g);
      if(visual&&visual.kind==='video'&&liveVideo){liveVideo.src=visual.src;if(visual.poster)liveVideo.poster=visual.poster;liveVideo.hidden=false;liveWrap.hidden=false;liveVideo.play().catch(()=>{})}
      else if(visual&&visual.kind==='image'&&liveImage){liveImage.src=visual.src;liveImage.hidden=false;liveWrap.hidden=false}
      else if(visual&&visual.kind==='iframe'&&liveFrame){liveFrame.src=visual.src;liveFrame.hidden=false;liveWrap.hidden=false}
      else liveWrap.hidden=true;
    }
  }
}
async function syncDraft(){
  const d=draft();if(!d||!d.destination_url)return null;
  if(!draftBelongsToMember(d)){
    clearPending();
    setCurrent(null);
    setMessage('A Gate draft from a different SeeToSee account is stored in this browser. It was not copied into this account.',true);
    return null;
  }
  if(member&&member.public_id&&!d.owner_member_public_id)d.owner_member_public_id=member.public_id;
  const required=['title','owner_name','agreement_text','file_format','content_html'];
  if(required.some(k=>!d[k])){setCurrent(null);setMessage('Your NDA draft is still here. Continue the builder to finish it before saving.',false);return null}
  const r=await SeeToSeeAuth.request('nda/save.php',{method:'POST',body:d});
  const g=r.data.gate;d.gate_public_id=g.public_id;if(member&&member.public_id)d.owner_member_public_id=member.public_id;localStorage.setItem('sts_nda_draft',JSON.stringify(d));clearPending();
  setCurrent(g);setMessage('Gate saved to this SeeToSee account. NDA Gate is included — no separate NDA charge.');return g;
}
async function loadGates(){
  try{
    const r=await SeeToSeeAuth.request('nda/list.php');const rows=r.data.gates||[];
    $('#nda-gate-rows').innerHTML=rows.length?rows.map(g=>'<tr><td><strong>'+esc(g.title)+'</strong><br><span class="subtle">'+esc(g.public_id)+'</span></td><td>'+esc(g.destination_url)+'</td><td>'+esc(g.signer_count)+'</td><td><span class="chip">INCLUDED · '+esc(String(g.status||'active').toUpperCase())+'</span></td><td><div class="nda-table-actions"><button class="gold" type="button" data-nda-edit="'+esc(g.public_id)+'">Keep working</button><a target="_blank" rel="noopener" href="'+esc(abs(g.preview_url))+'">Check it out ↗</a><a href="'+esc(abs(g.download_url))+'">Download</a></div></td></tr>').join(''):'<tr><td colspan="5" class="empty">Your first NDA Gate will appear here after it is saved.</td></tr>';
    if(rows[0]&&(!currentGate||rows[0].public_id===currentGate.public_id))setCurrent(rows[0]);
    const pill=$('#nda-pill');pill.textContent=rows.length?String(rows.length)+' GATE'+(rows.length===1?'':'S'):'READY';pill.className='nda-pill active';
  }catch(e){
    $('#nda-gate-rows').innerHTML='<tr><td colspan="5" class="empty">'+esc(e.message)+'</td></tr>';const pill=$('#nda-pill');pill.textContent='NDA NEEDS ATTENTION';pill.className='nda-pill error';
  }
}
async function loadSigners(){
  try{
    const r=await SeeToSeeAuth.request('nda/signers.php');const rows=r.data.records||[];
    $('#nda-signer-rows').innerHTML=rows.length?rows.map(s=>'<tr><td><strong>'+esc(s.record_id)+'</strong></td><td>'+esc(s.gate_title)+'</td><td>'+esc(s.signer_name)+'<br><span class="subtle">'+esc(s.signer_email)+'</span></td><td>'+esc(s.signed_at)+'</td><td>'+esc(s.notification_status)+'</td></tr>').join(''):'<tr><td colspan="5" class="empty">No signer records yet.</td></tr>';
  }catch(e){$('#nda-signer-rows').innerHTML='<tr><td colspan="5" class="empty">'+esc(e.message)+'</td></tr>'}
}
async function editGate(publicId){
  if(!publicId){location.href=builderUrl();return}
  try{
    const r=await SeeToSeeAuth.request('nda/get.php?gate='+encodeURIComponent(publicId));const g=r.data.gate;
    localStorage.setItem('sts_nda_draft',JSON.stringify({gate_public_id:g.public_id,owner_member_public_id:(member&&member.public_id)||'',title:g.title,destination_url:g.destination_url,owner_name:g.owner_name||'',alert_email:g.alert_email||'',agreement_text:g.agreement_text||'',file_format:g.file_format||'html',builder_json:g.builder_json||{},updated_at:new Date().toISOString()}));
    location.href=builderUrl();
  }catch(e){alert(e.message)}
}
document.addEventListener('click',e=>{const b=e.target.closest('[data-nda-edit]');if(b){e.preventDefault();editGate(b.dataset.ndaEdit)}});
const continueButton=$('#nda-continue-current');if(continueButton)continueButton.addEventListener('click',()=>{const d=draft();editGate((currentGate&&currentGate.public_id)||(d&&draftBelongsToMember(d)&&d.gate_public_id)||'')});
const wordingButton=$('#nda-save-wording');if(wordingButton)wordingButton.addEventListener('click',saveInlineWording);
document.addEventListener('click',e=>{const b=e.target.closest('[data-gate-template]');if(b){e.preventDefault();applyGateTemplate(b.dataset.gateTemplate)}});
const previewFrame=$('#nda-preview-frame');if(previewFrame)previewFrame.addEventListener('load',installInlineEditor);
(async()=>{
  await SeeToSeeAuth.ready;member=SeeToSeeAuth.member();if(!member)return;
  setCurrent(null);
  try{await syncDraft()}catch(e){setMessage('Your account is open, but the NDA Gate could not be saved yet: '+e.message,true)}
  await Promise.all([loadGates(),loadSigners()]);
})();
})();
