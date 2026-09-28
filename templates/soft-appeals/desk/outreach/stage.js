/* Video stage. DATA is prepended by sa-desk.php from prospects.json, behind the login. */
const MIA={
 rehab:{h:'Therapy denials in Maryland went <em>up 25.8%</em> in one year.',nums:[['4,565','PT, OT and speech denials in 2024, up from 3,630',''],['2 in 100','ever appealed. The lowest of any category','cu'],['49.9%','reversed when they are appealed. About half','']],src:'Maryland Insurance Administration, Health Care Appeals and Grievances Law report, 2024 data, published December 2025. Therapy category includes inpatient rehab. Overturn rate across all categories.',line:"This is Maryland's own report from last year. Therapy denials went up to 4,565. That's almost 26% more in one year. And only 2 in 100 got appealed. When one does get appealed, the carrier reverses about half."},
 bh:{h:'Mental health denials in Maryland went <em>up 149%</em> in one year.',nums:[['1,627','mental health denials in 2024, up from 652',''],['1 in 8','ever appealed, 11.9%','cu'],['49.9%','reversed when they are appealed. About half','']],src:'Maryland Insurance Administration, Health Care Appeals and Grievances Law report, 2024 data, published December 2025. Overturn rate across all categories.',line:"This is Maryland's own report from last year. Mental health denials went from 652 to 1,627. That's up 149% in one year. Only about 1 in 8 got appealed. When one does get appealed, the carrier reverses about half."}
};
const $=id=>document.getElementById(id);
let s=0,t0=null,tick=null;
const pick=$('pick');
DATA.forEach((d,i)=>{const o=document.createElement('option');o.value=i;o.textContent=d.name+(d.city?' · '+d.city:'');pick.appendChild(o)});
function cur(){return{name:$('f-name').value.trim()||'your practice',first:$('f-first').value.trim(),type:$('f-type').value,pay:$('f-pay').value.split(',').map(x=>x.trim()).filter(Boolean),site:$('f-site').value.trim()}}
function load(i){const d=DATA[i];$('f-name').value=d.name;$('f-type').value=d.type;$('f-pay').value=(d.payers||[]).join(', ');$('f-site').value=d.site||'';$('f-first').value='';render()}
function words(n){return ['no','one','two','three','four','five','six','seven','eight','nine','ten'][n]||String(n)}
function payText(p){if(!p.length)return 'your payers';if(p.length==1)return p[0];return p.slice(0,3).join(', ')}
function render(){
 const c=cur(),m=MIA[c.type];
 $('s0-eye').textContent=c.name;
 $('s0-h').innerHTML='I was on <em>your</em> insurance page.';
 $('s0-chips').innerHTML=c.pay.map(p=>'<span class="chip">'+p.replace(/&/g,'&amp;').replace(/</g,'&lt;')+'</span>').join('');
 $('s1-h').innerHTML=m.h;
 $('s1-nums').innerHTML=m.nums.map(n=>'<div class="num"><b class="'+n[2]+'">'+n[0]+'</b><span>'+n[1]+'</span></div>').join('');
 $('s1-src').textContent=m.src;
 $('s4-for').textContent='For '+c.name;
 $('s4-h').innerHTML=c.type=='bh'?'Your denials, next to <em>Maryland’s</em> numbers.':'Your denials, next to <em>Maryland’s</em> numbers.';
 const lines=[
  ["Hi, I'm Nana Frimpongmaa. This is your website. I was on your insurance page. "+payText(c.pay)+". That's a lot of rules for one billing desk.","Their website tab is on screen, scrolled to the insurance or payer page. About 10 seconds. Then switch to this tab."],
  [m.line,"Scene 2. About 25 seconds. Let the numbers sit."],
  ["So here's what I do. You fill five columns for your last twenty denials. Payer, code, reason, amount, how old. No patient names. I send back one page. What's still winnable, and what dies first.","Scene 3. About 20 seconds."],
  ["It's free, and the page is yours either way. The sheet is linked under this video. Just reply with it.","Scene 4. About 10 seconds. Stop recording after the last word."],
  ["Thumbnail. Nothing to say.","Screenshot this scene (Cmd Shift 4, then drag across the stage). Use it as the image in email 2."]];
 $('p-k').textContent='Scene '+(s+1)+(s==4?' · thumbnail':' of 4');
 $('p-line').textContent=lines[s][0];$('p-do').textContent=lines[s][1];
 const hi=c.first?'Hi '+c.first+',':'Hi,';
 const stat=c.type=='bh'?"Maryland mental health denials went from 652 to 1,627 last year, and about 1 in 8 got appealed. About half of the appealed ones got reversed.":"Maryland therapy offices appealed 2 in 100 denials last year. About half of the appealed ones got reversed.";
 $('mail').textContent=hi+"\n\nI made you a 75-second video. It starts on your own insurance page.\n\n[video link]\n\nThe short version: "+stat+"\n\nIf you want your own page, the five-column sheet is here: frimpomaasync.com/soft-appeals-sheet. No patient names on it.\n\nNana Frimpongmaa\nfrimpomaasync.com/soft-appeals\n[your mailing address]\nReply \"no\" and I'll stop.";
 document.querySelectorAll('.scene').forEach(e=>e.classList.toggle('on',+e.dataset.s===s));
 document.querySelectorAll('[data-go]').forEach(b=>b.classList.toggle('on',+b.dataset.go===s));
 try{localStorage.setItem('sa-stage',JSON.stringify({i:pick.value,s}))}catch(e){}
}
function go(n){s=Math.max(0,Math.min(4,n));render()}
document.querySelectorAll('[data-go]').forEach(b=>b.onclick=()=>go(+b.dataset.go));
$('next').onclick=()=>go(s+1);$('prev').onclick=()=>go(s-1);
pick.onchange=()=>load(pick.value);
['f-name','f-first','f-type','f-pay','f-site'].forEach(id=>$(id).addEventListener('input',render));
$('f-type').addEventListener('change',render);
$('opensite').onclick=()=>{const u=cur().site;if(u)window.open(/^https?:/.test(u)?u:'https://'+u,'_blank');else{$('opensite').textContent='No website on file, paste it above';setTimeout(()=>$('opensite').textContent='Open their website in a new tab',2200)}};
$('recbtn').onclick=()=>document.body.classList.add('rec');
$('copymail').onclick=async()=>{try{await navigator.clipboard.writeText($('mail').textContent);$('copymail').textContent='Copied'}catch(e){$('copymail').textContent='Select the text and copy'}setTimeout(()=>$('copymail').textContent='Copy email 2',1600)};
$('timer').onclick=()=>{if(tick){clearInterval(tick);tick=null;$('timer').textContent='Start the clock';return}t0=Date.now();$('timer').textContent='Stop the clock';tick=setInterval(()=>{const x=Math.floor((Date.now()-t0)/1000);$('p-t').textContent=Math.floor(x/60)+':'+String(x%60).padStart(2,'0')},250)};
document.addEventListener('keydown',e=>{if(e.target.tagName==='INPUT'||e.target.tagName==='SELECT')return;if(e.key==='ArrowRight'||e.key===' '){e.preventDefault();go(s+1)}if(e.key==='ArrowLeft')go(s-1);if(e.key==='Escape')document.body.classList.remove('rec')});
let saved={};try{saved=JSON.parse(localStorage.getItem('sa-stage')||'{}')}catch(e){}
let start=saved.i&&DATA[saved.i]?saved.i:0;
const hp=new URLSearchParams(location.hash.slice(1)).get('p');
if(hp){const k=DATA.findIndex(d=>d.name===hp);if(k>=0){start=String(k);saved.s=0}}
pick.value=start;load(pick.value);go(saved.s||0);
