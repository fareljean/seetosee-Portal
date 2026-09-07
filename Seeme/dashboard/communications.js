(function(){
  'use strict';

  const root=document;
  const esc=value=>String(value==null?'':value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const date=value=>value?new Date(String(value).replace(' ','T')+'Z').toLocaleString():'—';
  const statusLabel=value=>({confirmed:'Confirmed',not_confirmed:'Not confirmed',sent:'Sent',failed:'Failed',queued:'Queued',awaiting_confirmation:'Awaiting confirmations',completed:'Completed',completed_with_errors:'Completed with errors',sent_with_errors:'Sent with errors'}[String(value||'')]||String(value||'—'));
  let ui=null;

  function injectCss(){
    if(root.querySelector('link[data-sts-communications-css]'))return;
    const link=root.createElement('link');link.rel='stylesheet';link.href='communications.css?v=1.0.0';link.dataset.stsCommunicationsCss='1';root.head.appendChild(link);
  }

  function build(){
    injectCss();
    const nav=root.querySelector('.side nav'),grid=root.querySelector('.grid');
    if(!nav||!grid)return null;
    let link=root.getElementById('control-center-link');
    if(!link){
      link=root.createElement('a');link.id='control-center-link';link.href='#control-center';link.textContent='Control Center';link.hidden=true;nav.appendChild(link);
    }
    let section=root.getElementById('control-center');
    if(!section){
      section=root.createElement('section');section.id='control-center';section.className='card wide communications-card';section.hidden=true;
      section.innerHTML=`
        <div class="cc-head"><div><div class="eyebrow">Private operator tools</div><h2>Communications Control Center</h2><p class="cc-note">Send a controlled test first. Member campaigns remain locked until all three operator receipts are confirmed.</p></div><div class="cc-pill locked" id="cc-access-pill">Server check pending</div></div>
        <div class="cc-block"><h3>Test Message</h3><p class="cc-note">This sends separately to the three private operator recipients configured on the server.</p><form id="cc-test-form"><label class="field"><span>Message subject</span><input id="cc-test-subject" name="subject" maxlength="200" required value="SeeToSee communications test"></label><label class="field"><span>Message body</span><textarea id="cc-test-body" name="body" maxlength="20000" required>Testing the SeeToSee Communications Control Center. Please confirm receipt from this email.</textarea></label><button class="btn" type="submit" id="cc-test-send">Send Test Message</button><div class="message" id="cc-test-message" role="status" aria-live="polite"></div></form><div class="table-wrap cc-table"><table><thead><tr><th>Recipient</th><th>Status</th><th>Sent</th><th>Confirmed</th><th>Error</th></tr></thead><tbody id="cc-test-results"><tr><td colspan="5" class="empty">No operator test has been sent.</td></tr></tbody></table></div></div>
        <div class="cc-block"><h3>Member Campaigns</h3><p class="cc-note">Verified, active account emails only. One personalized message and one receipt token are created per member.</p><div id="cc-campaign-lock" class="cc-lock">Complete the three-recipient test and confirm all three receipt links before sending to members.</div><form id="cc-campaign-form" hidden><label class="field"><span>Campaign title</span><input name="title" maxlength="200" required></label><label class="field"><span>Message subject</span><input name="subject" maxlength="200" required></label><label class="field"><span>Message body</span><textarea name="body" maxlength="20000" required></textarea></label><button class="btn" type="submit" id="cc-campaign-send">Send to verified active members</button><div class="message" id="cc-campaign-message" role="status" aria-live="polite"></div></form></div>
        <div class="cc-block"><h3>Weekly Checkup</h3><p class="cc-note">The schedule is server-side and starts paused. Resume is available only after the operator test is complete.</p><div class="cc-summary"><div class="cc-stat"><span>Active verified members</span><strong id="cc-member-count">—</strong></div><div class="cc-stat"><span>Schedule</span><strong id="cc-weekly-state">Paused</strong></div><div class="cc-stat"><span>Last run</span><strong id="cc-weekly-last">—</strong></div><div class="cc-stat"><span>Next run</span><strong id="cc-weekly-next">—</strong></div></div><button class="btn ghost" type="button" id="cc-weekly-toggle" hidden></button><div class="message" id="cc-weekly-message" role="status" aria-live="polite"></div></div>
        <div class="cc-block"><h3>Campaign History</h3><p class="cc-note">Every test, member campaign, and weekly run remains recorded with sent, confirmed, failed, and pending counts.</p><div class="table-wrap cc-table"><table><thead><tr><th>Title</th><th>Audience</th><th>Date sent</th><th>Sent</th><th>Confirmed</th><th>Failed</th><th>Pending</th><th></th></tr></thead><tbody id="cc-history"><tr><td colspan="8" class="empty">No campaign history.</td></tr></tbody></table></div></div>`;
      grid.appendChild(section);
    }
    ui={link,section,access:root.getElementById('cc-access-pill'),testForm:root.getElementById('cc-test-form'),testMessage:root.getElementById('cc-test-message'),testButton:root.getElementById('cc-test-send'),testResults:root.getElementById('cc-test-results'),campaignLock:root.getElementById('cc-campaign-lock'),campaignForm:root.getElementById('cc-campaign-form'),campaignMessage:root.getElementById('cc-campaign-message'),memberCount:root.getElementById('cc-member-count'),weeklyState:root.getElementById('cc-weekly-state'),weeklyLast:root.getElementById('cc-weekly-last'),weeklyNext:root.getElementById('cc-weekly-next'),weeklyToggle:root.getElementById('cc-weekly-toggle'),weeklyMessage:root.getElementById('cc-weekly-message'),history:root.getElementById('cc-history')};
    return ui;
  }

  function message(element,text,error){if(!element)return;element.textContent=text||'';element.className='message'+(text?' show ':' ')+(error?'error':'success')}
  function renderDeliveries(campaign){
    if(!ui||!ui.testResults)return;
    const rows=campaign&&Array.isArray(campaign.deliveries)?campaign.deliveries:[];
    ui.testResults.innerHTML=rows.length?rows.map(row=>'<tr><td><strong>'+esc(row.recipient)+'</strong><br><span class="subtle">'+esc(row.name)+'</span></td><td>'+esc(statusLabel(row.status))+'</td><td>'+esc(row.sent_at?date(row.sent_at):'—')+'</td><td>'+esc(row.confirmed_at?date(row.confirmed_at):(row.status==='not_confirmed'?'Not confirmed':'—'))+'</td><td>'+esc(row.error||'—')+'</td></tr>').join(''):'<tr><td colspan="5" class="empty">No delivery records.</td></tr>';
  }
  function renderHistory(rows){
    if(!ui||!ui.history)return;
    ui.history.innerHTML=Array.isArray(rows)&&rows.length?rows.map(row=>'<tr><td><strong>'+esc(row.title)+'</strong><br><span class="subtle">'+esc(statusLabel(row.status))+'</span></td><td>'+esc(row.audience)+'</td><td>'+esc(date(row.sent_at))+'</td><td>'+esc(row.sent)+'</td><td>'+esc(row.confirmed)+'</td><td>'+esc(row.failed)+'</td><td>'+esc(row.pending)+'</td><td><button class="cc-view" type="button" data-cc-view="'+esc(row.id)+'">View</button></td></tr>').join(''):'<tr><td colspan="8" class="empty">No campaign history.</td></tr>';
  }
  function render(data){
    if(!ui)return;
    ui.link.hidden=false;ui.section.hidden=false;ui.access.textContent=data.operator_test.complete?'Operator test complete':'Operator access active';ui.access.className='cc-pill';
    ui.memberCount.textContent=String(data.verified_active_member_count==null?'—':data.verified_active_member_count);
    const test=data.operator_test||{};
    ui.campaignLock.hidden=!!test.complete;ui.campaignForm.hidden=!test.complete;
    if(test.complete){ui.campaignLock.textContent='Operator test complete. Member campaigns are available to approved operators.'}
    const weekly=data.weekly;
    if(weekly){
      ui.weeklyState.textContent=weekly.enabled?'Running':'Paused';ui.weeklyLast.textContent=date(weekly.last_run_at);ui.weeklyNext.textContent=date(weekly.next_run_at);ui.weeklyToggle.hidden=false;ui.weeklyToggle.textContent=weekly.enabled?'Pause weekly checkup':'Resume weekly checkup';ui.weeklyToggle.dataset.action=weekly.enabled?'pause':'resume';
    }else{ui.weeklyState.textContent='Not installed';ui.weeklyLast.textContent='—';ui.weeklyNext.textContent='—';ui.weeklyToggle.hidden=true}
    renderDeliveries(data.selected_campaign);renderHistory(data.history);
  }
  async function load(campaignId){
    const suffix=campaignId?'?campaign_id='+encodeURIComponent(campaignId):'';
    try{const response=await SeeToSeeAuth.request('communications/status.php'+suffix);render(response.data);return response.data}catch(error){if(error.status!==403){if(ui){ui.access.textContent=error.message||'Control Center unavailable';ui.access.className='cc-pill locked'}}return null}
  }
  function bind(){
    if(!ui)return;
    ui.testForm.addEventListener('submit',async event=>{event.preventDefault();ui.testButton.disabled=true;message(ui.testMessage,'Sending the operator test…',false);try{const form=event.currentTarget;const response=await SeeToSeeAuth.request('communications/test.php',{method:'POST',body:{subject:form.subject.value,body:form.body.value}});renderDeliveries(response.data.campaign);message(ui.testMessage,'Test sent. Confirm each receipt link, then reload this dashboard to see the completion state.',false);await load()}catch(error){message(ui.testMessage,error.message||'The test could not be sent.',true)}finally{ui.testButton.disabled=false}});
    ui.campaignForm.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=ui.campaignForm.querySelector('button[type="submit"]');button.disabled=true;message(ui.campaignMessage,'Sending the member campaign…',false);try{await SeeToSeeAuth.request('communications/campaigns.php',{method:'POST',body:{title:form.title.value,subject:form.subject.value,body:form.body.value}});message(ui.campaignMessage,'Campaign recorded and sent to the verified active audience.',false);form.reset();await load()}catch(error){message(ui.campaignMessage,error.message||'The member campaign could not be sent.',true)}finally{button.disabled=false}});
    ui.weeklyToggle.addEventListener('click',async()=>{const action=ui.weeklyToggle.dataset.action;ui.weeklyToggle.disabled=true;message(ui.weeklyMessage,action==='resume'?'Resuming the weekly checkup…':'Pausing the weekly checkup…',false);try{await SeeToSeeAuth.request('communications/weekly.php',{method:'POST',body:{action}});message(ui.weeklyMessage,action==='resume'?'Weekly checkup resumed.':'Weekly checkup paused.',false);await load()}catch(error){message(ui.weeklyMessage,error.message||'The schedule could not be changed.',true)}finally{ui.weeklyToggle.disabled=false}});
    ui.history.addEventListener('click',event=>{const button=event.target.closest('[data-cc-view]');if(button)load(button.dataset.ccView)});
  }
  async function init(){
    build();if(!ui)return;bind();await SeeToSeeAuth.ready;if(!SeeToSeeAuth.member())return;await load();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
