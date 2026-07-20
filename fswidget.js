/* fswidget.js — the frimpomaasync.com chat widget (SynKasa engine, branded for NaNa Frimpomaa).
   Self-contained: injects its own CSS + HTML. No backend, no API key — scripted intents.
   Include with: <script src="fswidget.js" defer></script> */
(function(){
var BOOK="https://calendar.app.google/DkRJFRA3G6W6d8E48";

var P={
  name:"NaNa's Assistant", av:"N", email:"hello@frimpomaasync.com", booking:BOOK,
  greeting:"Hey — welcome! I'm NaNa's AI assistant. I can tell you about the Client Catcher, point you to the free tools, or get you booked for a free 15-min call. What are you here for?",
  services:[
    {name:"The Client Catcher — never lose a lead",price:"$1,500 · live in 7 days · optional $99/mo care"},
    {name:"Founding Client build (first 3 only)",price:"$750"},
    {name:"Som (capture tool)",price:"free download"},
    {name:"Soma (watch automation run)",price:"free live demo"}
  ],
  faqs:[
    {keys:["build","make","offer","services","service","create","do you do"],a:"I make one-person businesses feel fully staffed. The main offer is the Client Catcher — calls answered, follow-ups sent, jobs booked while your hands stay on the work. $1,500, live in 7 days, or $750 for the first 3 founding clients. The free tools are Som (catches thoughts before they slip) and Soma (a live automation demo)."},
    {keys:["soma"],a:"Soma is my free live demo — type one customer in and watch it land in four tools at once. Zero commitment. It's at frimpomaasync.com/soma."},
    {keys:["som"],a:"Som is free — it catches the things you'd normally lose: ideas, links, follow-ups. One field, no login, yours forever. Grab it at frimpomaasync.com/som."},
    {keys:["catcher","client","lead","missed","follow"],a:"The Client Catcher is the flagship: every call answered, every lead chased, every job on your calendar — without you touching any of it. Live in 7 days, booking in 30 — or you don't pay. Want a free 15-min mapping call?"},
    {keys:["guarantee","refund","risk","pay"],a:"The guarantee, in writing: live and working in 7 days, booking appointments within 30 — or you don't pay."},
    {keys:["receptionist","chatbot","assistant","answer","widget"],a:"You're talking to it — this same assistant gets built for your business, answering your customers 24/7 in your voice. It's part of what a Client Catcher install can include. Want a free call to see it on your business?"},
    {keys:["who","nana","frimpomaa","yourself"],a:"NaNa Frimpomaa — a solo founder who makes one-person businesses feel fully staffed. Everything on this site is real and live. What can I help you with?"}
  ],
  quick:[
    {label:"What do you build?",msg:"What do you build?"},
    {label:"Pricing",msg:"How much does it cost?"},
    {label:"The free tools",msg:"Tell me about the free tools"},
    {label:"Book a call",msg:"I'd like to book a call"}
  ]
};

var css=''
+'#sk-bubble{position:fixed;right:20px;bottom:20px;width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#C9975B,#A8763B);color:#fff;border:none;cursor:pointer;font-size:24px;font-weight:700;font-family:Georgia,serif;box-shadow:0 10px 28px -8px rgba(168,118,59,.55);z-index:9000;transition:transform .18s ease}'
+'#sk-bubble:hover{transform:scale(1.07)}'
+'#sk-bubble .badge{position:absolute;top:-3px;right:-3px;width:20px;height:20px;border-radius:50%;background:#B4552D;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;font-family:-apple-system,sans-serif}'
+'#sk-panel{position:fixed;right:20px;bottom:96px;width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 130px));background:#fff;border-radius:18px;box-shadow:0 24px 60px -12px rgba(26,26,26,.35);display:none;flex-direction:column;overflow:hidden;z-index:9001;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Segoe UI",Roboto,Arial,sans-serif}'
+'#sk-panel.open{display:flex;animation:skpop .22s ease}'
+'@keyframes skpop{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}'
+'.sk-head{background:linear-gradient(135deg,#1E2A45,#16203A);padding:16px 18px;color:#F5F0E8;display:flex;align-items:center;gap:12px}'
+'.sk-head .av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#C9975B,#A8763B);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;flex-shrink:0}'
+'.sk-head .who{flex:1;min-width:0}'
+'.sk-head .who .n{font-weight:700;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
+'.sk-head .who .s{font-size:11px;color:#9FE6B5;display:flex;align-items:center;gap:5px}'
+'.sk-head .who .s::before{content:"";width:7px;height:7px;border-radius:50%;background:#4ADE80;animation:sklive 2s infinite}'
+'@keyframes sklive{0%{box-shadow:0 0 0 0 rgba(74,222,128,.5)}70%{box-shadow:0 0 0 7px rgba(74,222,128,0)}100%{box-shadow:0 0 0 0 rgba(74,222,128,0)}}'
+'.sk-head .x{background:none;border:none;color:rgba(255,255,255,.6);font-size:22px;cursor:pointer;line-height:1;padding:2px 6px}'
+'.sk-head .x:hover{color:#fff}'
+'.sk-body{flex:1;overflow-y:auto;padding:16px;background:#F6F5F3;display:flex;flex-direction:column;gap:10px}'
+'.sk-body .msg{max-width:82%;padding:10px 13px;border-radius:14px;font-size:14px;line-height:1.5;white-space:pre-wrap;word-wrap:break-word}'
+'.sk-body .msg.bot{background:#fff;border:1px solid #E8E5E0;border-bottom-left-radius:4px;align-self:flex-start;color:#33302B}'
+'.sk-body .msg.me{background:linear-gradient(135deg,#C9975B,#A8763B);color:#fff;border-bottom-right-radius:4px;align-self:flex-end}'
+'.sk-body .msg a{color:#A8763B;font-weight:600}'
+'.sk-body .msg.me a{color:#fff}'
+'.sk-typing{align-self:flex-start;background:#fff;border:1px solid #E8E5E0;border-radius:14px;border-bottom-left-radius:4px;padding:12px 14px;display:flex;gap:4px}'
+'.sk-typing span{width:7px;height:7px;border-radius:50%;background:#C9975B;animation:skbnc 1.2s infinite}'
+'.sk-typing span:nth-child(2){animation-delay:.15s}.sk-typing span:nth-child(3){animation-delay:.3s}'
+'@keyframes skbnc{0%,60%,100%{transform:translateY(0);opacity:.5}30%{transform:translateY(-5px);opacity:1}}'
+'.sk-quick{display:flex;gap:7px;flex-wrap:wrap;padding:0 16px 8px;background:#F6F5F3}'
+'.sk-quick button{background:#fff;border:1px solid #E4C79A;color:#A8763B;font-size:12.5px;font-weight:600;padding:7px 12px;border-radius:999px;cursor:pointer;font-family:inherit}'
+'.sk-quick button:hover{background:#C9975B;color:#fff;border-color:#C9975B}'
+'.sk-input{display:flex;gap:8px;padding:12px;border-top:1px solid #E8E5E0;background:#fff}'
+'.sk-input input{flex:1;border:1px solid #E8E5E0;border-radius:999px;padding:11px 15px;font-size:14px;font-family:inherit;outline:none}'
+'.sk-input input:focus{border-color:#C9975B}'
+'.sk-input button{background:#C9975B;border:none;color:#fff;width:42px;height:42px;border-radius:50%;cursor:pointer;font-size:17px;flex-shrink:0}'
+'.sk-input button:hover{background:#A8763B}'
+'.sk-foot{text-align:center;font-size:10.5px;color:#6E6A62;padding:7px;background:#fff;border-top:1px solid #EFEDE9}'
+'.sk-foot a{color:#A8763B;text-decoration:none;font-weight:600}';

var html=''
+'<button id="sk-bubble" aria-label="Chat with NaNa\'s assistant">'+P.av+'<span class="badge">1</span></button>'
+'<div id="sk-panel" role="dialog" aria-label="Chat with NaNa\'s assistant">'
+'<div class="sk-head"><div class="av">'+P.av+'</div>'
+'<div class="who"><div class="n">'+P.name+'</div><div class="s">Online now</div></div>'
+'<button class="x" aria-label="Close chat">&times;</button></div>'
+'<div class="sk-body" id="sk-body"></div>'
+'<div class="sk-quick" id="sk-quick"></div>'
+'<div class="sk-input"><input id="sk-text" type="text" placeholder="Type a message&hellip;" autocomplete="off">'
+'<button id="sk-send" aria-label="Send">&rarr;</button></div>'
+'<div class="sk-foot">Powered by <a href="synkasa.html">SynKasa</a> &mdash; built, wired, and cared for by NaNa Frimpomaa</div>'
+'</div>';

var st=document.createElement('style');st.textContent=css;document.head.appendChild(st);
var wrap=document.createElement('div');wrap.innerHTML=html;
while(wrap.firstChild){document.body.appendChild(wrap.firstChild);}

var convo={mode:"idle",lead:{}};
var started=false;
function el(id){return document.getElementById(id);}
function skToggle(){var p=el('sk-panel');if(p.classList.contains('open')){p.classList.remove('open');}else{p.classList.add('open');el('sk-bubble').querySelector('.badge').style.display='none';if(!started){started=true;greet();}el('sk-text').focus();}}
function bubble(text,who){var b=el('sk-body');var d=document.createElement('div');d.className='msg '+(who||'bot');d.innerHTML=text;b.appendChild(d);b.scrollTop=b.scrollHeight;}
function typing(){var b=el('sk-body');var t=document.createElement('div');t.className='sk-typing';t.id='sk-typing';t.innerHTML='<span></span><span></span><span></span>';b.appendChild(t);b.scrollTop=b.scrollHeight;}
function untyping(){var t=el('sk-typing');if(t)t.remove();}
function say(text,who){typing();setTimeout(function(){untyping();bubble(text,who||'bot');},520);}
function renderQuick(){var q=el('sk-quick');q.innerHTML='';P.quick.forEach(function(item){var btn=document.createElement('button');btn.textContent=item.label;btn.onclick=function(){handle(item.msg);};q.appendChild(btn);});}
function greet(){renderQuick();bubble(P.greeting,'bot');}

function norm(s){return (' '+s.toLowerCase()+' ').replace(/[^a-z0-9\s]/g,' ');}
function has(t){for(var i=1;i<arguments.length;i++){var a=arguments[i];var hit=a.indexOf(' ')>-1?(t.indexOf(a)>-1):(t.indexOf(' '+a+' ')>-1);if(hit)return true;}return false;}
function servicesReply(){var lines=P.services.map(function(s){return '• '+s.name+' — '+s.price;}).join('\n');return lines+'\n\nWant me to book you a free 15-min call?';}

function handle(text){
  bubble(text.replace(/</g,'&lt;'),'me');
  el('sk-text').value='';
  var t=norm(text);
  if(convo.mode==='lead-name'){convo.lead.name=text.trim();convo.mode='lead-contact';say("Lovely to meet you, "+convo.lead.name+"! What's the best email or phone to reach you?");return;}
  if(convo.mode==='lead-contact'){convo.lead.contact=text.trim();convo.mode='idle';
    try{var L=JSON.parse(localStorage.getItem('sk_leads')||'[]');L.push({biz:'frimpomaasync.com',name:convo.lead.name,contact:convo.lead.contact,at:new Date().toISOString()});localStorage.setItem('sk_leads',JSON.stringify(L));}catch(e){}
    say("Perfect — got it, "+convo.lead.name+". I've passed your details along and NaNa will reach out shortly. Want to grab a time now so you don't wait?\n\n→ <a href='"+P.booking+"' target='_blank' rel='noopener'>Pick a time</a>");
    return;}
  if(has(t,'book','appointment','schedule','get started','sign up','start','reserve','slot','call')){
    convo.mode='lead-name';
    say("Love it! I can set that up. First — what's your name?");
    return;}
  if(has(t,'price','pricing','cost','how much','rate','fee','charge','$')){say(servicesReply());return;}
  if(has(t,'free','tool','tools','download')){say("Two free ones: Som catches the thought before it's gone (frimpomaasync.com/som), and Soma lets you watch one entry land in four tools at once (frimpomaasync.com/soma). Both free, both yours.");return;}
  if(has(t,'email','phone','reach','contact','number')){say("You can reach NaNa at "+P.email+", or I can take your details right here — want to do that? Just say 'book me'.");return;}
  if(has(t,'hi','hello','hey','yo','good morning','good evening')&&text.trim().length<14){say(P.greeting);return;}
  if(has(t,'thank','thanks','appreciate')){say("Anytime! Want me to get you booked before you go?");return;}
  var best=null,bestScore=0;
  P.faqs.forEach(function(f){var sc=0;f.keys.forEach(function(k){if(t.indexOf(k)>-1)sc++;});if(sc>bestScore){bestScore=sc;best=f;}});
  if(best&&bestScore>0){say(best.a);return;}
  say("Great question — I can help with what NaNa builds, pricing, the free tools, or booking you a free 15-min call. Which would you like?\n\nOr reach her directly at "+P.email+".");
}

el('sk-bubble').addEventListener('click',skToggle);
el('sk-panel').querySelector('.x').addEventListener('click',skToggle);
el('sk-send').addEventListener('click',function(){var v=el('sk-text').value.trim();if(v)handle(v);});
el('sk-text').addEventListener('keydown',function(e){if(e.key==='Enter'){var v=el('sk-text').value.trim();if(v)handle(v);}});
})();
