/* fsnav.js — the ONE shared nav + footer for frimpomaasync.com.
   Edit links here and every page updates. Loaded with <script src="fsnav.js" defer>. */
(function(){
"use strict";
var BOOK="https://calendar.app.google/DkRJFRA3G6W6d8E48";
var LINKS=[
  ["client-catcher.html","the client catcher"],
  ["services.html","services"],
  ["products.html","tools"],
  ["portfolio.html","portfolio"],
  ["blog.html","notes"]
];

var css=""
+"#fsnav{position:sticky;top:0;z-index:80;background:rgba(255,255,255,.88);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-bottom:1px solid #E8E5E0;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;line-height:1.4}"
+"#fsnav *{margin:0;padding:0;box-sizing:border-box}"
+"#fsnav .fs-in{max-width:1060px;margin:0 auto;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px}"
+"#fsnav .fs-logo{font-family:Georgia,'Palatino Linotype','Iowan Old Style','Times New Roman',serif;font-weight:700;font-size:17px;letter-spacing:-.01em;text-decoration:none;color:#1A1A1A;white-space:nowrap}"
+"#fsnav .fs-logo .fs-dot{color:#C9975B}"
+"#fsnav .fs-links{display:flex;align-items:center;gap:20px;font-size:13.5px}"
+"#fsnav .fs-links a{text-decoration:none;color:#6E6A62}"
+"#fsnav .fs-links a:hover{color:#1A1A1A}"
+"#fsnav .fs-links a.fs-here{color:#1A1A1A;font-weight:600;border-bottom:2px solid #C9975B;padding-bottom:2px}"
+"#fsnav .fs-dd{position:relative;display:inline-flex;align-items:center;gap:5px}"
+"#fsnav .fs-dd .fs-car{font-size:9px;color:#9A948A;transition:transform .18s;pointer-events:none}"
+"#fsnav .fs-dd:hover .fs-car,#fsnav .fs-dd:focus-within .fs-car{transform:rotate(180deg)}"
+"#fsnav .fs-sub{display:none;position:absolute;top:100%;left:50%;transform:translateX(-50%);padding-top:12px;min-width:250px;z-index:90}"
+"#fsnav .fs-dd:hover .fs-sub,#fsnav .fs-dd:focus-within .fs-sub{display:block}"
+"#fsnav .fs-sub-in{display:block;background:#fff;border:1px solid #E8E5E0;border-radius:14px;box-shadow:0 18px 40px -18px rgba(26,26,26,.25);padding:8px}"
+"#fsnav .fs-sub a{display:block;padding:10px 14px;border-radius:9px;color:#33302B;font-size:13px;line-height:1.35}"
+"#fsnav .fs-sub a:hover{background:#F6F5F3;color:#1A1A1A}"
+"#fsnav .fs-sub .fs-sub-note{display:block;font-size:10.5px;color:#9A948A;margin-top:2px;font-weight:400}"
+"#fsnav .fs-cta,#fsnav .fs-links a.fs-cta{padding:9px 18px;border-radius:999px;background:#B4552D;color:#fff;font-weight:700;font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;text-decoration:none;white-space:nowrap}"
+"#fsnav .fs-cta:hover,#fsnav .fs-links a.fs-cta:hover{background:#9A4522;color:#fff}"
+"#fsnav .fs-burger{display:none;border:1px solid #D9D5CE;background:#fff;border-radius:10px;width:40px;height:36px;cursor:pointer;padding:0;flex-direction:column;align-items:center;justify-content:center;gap:4px}"
+"#fsnav .fs-burger span{display:block;width:16px;height:2px;background:#1A1A1A;border-radius:2px;transition:transform .2s,opacity .2s}"
+"#fsnav .fs-burger[aria-expanded=true] span:nth-child(1){transform:translateY(6px) rotate(45deg)}"
+"#fsnav .fs-burger[aria-expanded=true] span:nth-child(2){opacity:0}"
+"#fsnav .fs-burger[aria-expanded=true] span:nth-child(3){transform:translateY(-6px) rotate(-45deg)}"
+"#fsnav .fs-menu{display:none;border-top:1px solid #E8E5E0;background:#fff}"
+"#fsnav .fs-menu.fs-open{display:block}"
+"#fsnav .fs-menu a{display:block;padding:14px 24px;font-size:15px;color:#33302B;text-decoration:none;border-bottom:1px solid #F6F5F3}"
+"#fsnav .fs-menu a.fs-here{color:#1A1A1A;font-weight:600;border-left:3px solid #C9975B;padding-left:21px}"
+"#fsnav .fs-menu a.fs-menu-sub{padding-left:44px;font-size:14px;color:#6E6A62;background:#FAFAF8}"
+"#fsnav .fs-menu .fs-menu-cta{margin:14px 24px 18px;display:inline-block;padding:13px 26px;border-radius:999px;background:#B4552D;color:#fff;font-weight:700;font-size:12.5px;text-transform:uppercase;letter-spacing:.08em;border-bottom:none}"
+"@media(max-width:760px){#fsnav .fs-links{display:none}#fsnav .fs-burger{display:flex}}"
+"#fsfoot{border-top:1px solid #E8E5E0;padding:56px 0 64px;margin-top:70px;background:#FFFFFF;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;text-align:left;line-height:1.6;color:#33302B;font-size:14px}"
+"#fsfoot *{margin:0;padding:0;box-sizing:border-box}"
+"#fsfoot .fs-in{max-width:1060px;margin:0 auto;padding:0 24px;display:flex;justify-content:space-between;gap:30px;flex-wrap:wrap}"
+"#fsfoot .fs-brand{font-family:Georgia,'Palatino Linotype','Iowan Old Style','Times New Roman',serif;font-weight:700;font-size:17px;color:#1A1A1A}"
+"#fsfoot .fs-brand .fs-dot{color:#C9975B}"
+"#fsfoot .fs-brand p{font-weight:400;font-size:13px;color:#6E6A62;margin-top:8px;max-width:260px}"
+"#fsfoot .fs-col{font-size:13.5px}"
+"#fsfoot .fs-col .fs-h{font-family:ui-monospace,'SF Mono',Menlo,Monaco,Consolas,monospace;font-size:10.5px;letter-spacing:.16em;text-transform:uppercase;color:#9A948A;margin-bottom:12px}"
+"#fsfoot .fs-col a{display:block;color:#6E6A62;text-decoration:none;padding:4px 0}"
+"#fsfoot .fs-col a:hover{color:#A8763B}"
+"#fsfoot .fs-base{max-width:1060px;margin:40px auto 0;padding:18px 24px 0;border-top:1px solid #E8E5E0;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;font-size:12px;color:#9A948A}";

var here=(location.pathname.split("/").pop()||"index.html").replace(/\.html$/,"")||"index";
function mark(href){return href.replace(/\.html$/,"")===here?' class="fs-here"':"";}

var SUB={"client-catcher.html":[
  ["/client-catcher-demo/","try the live demo","press play — watch a lead get booked"],
  ["/client-catcher#demo-video","the 40-second run","screen recording of a full save"]
]};

var navLinks=LINKS.map(function(l){
  var a='<a href="'+l[0]+'"'+mark(l[0])+'>'+l[1]+'</a>';
  var sub=SUB[l[0]];
  if(!sub) return a;
  var items=sub.map(function(s){
    return '<a href="'+s[0]+'">'+s[1]+'<span class="fs-sub-note">'+s[2]+'</span></a>';
  }).join("");
  return '<span class="fs-dd">'+a+'<span class="fs-car">▾</span><span class="fs-sub"><span class="fs-sub-in">'+items+'</span></span></span>';
}).join("");
var menuLinks=[["index.html","home"]].concat(LINKS,[["planner.html","the free blueprint"]]).map(function(l){
  var out='<a href="'+l[0]+'"'+mark(l[0])+'>'+l[1]+'</a>';
  var sub=SUB[l[0]];
  if(sub){out+=sub.map(function(s){
    return '<a class="fs-menu-sub" href="'+s[0]+'">↳ '+s[1]+'</a>';
  }).join("");}
  return out;
}).join("");

var navHTML=''
+'<div class="fs-in">'
+'<a class="fs-logo" href="/">frimpomaasync<span class="fs-dot">.com</span></a>'
+'<div class="fs-links">'+navLinks
+'<a class="fs-cta" href="'+BOOK+'" target="_blank" rel="noopener">book a free call</a></div>'
+'<button class="fs-burger" type="button" aria-label="Menu" aria-expanded="false" aria-controls="fsMenu"><span></span><span></span><span></span></button>'
+'</div>'
+'<div class="fs-menu" id="fsMenu">'+menuLinks
+'<a class="fs-menu-cta" href="'+BOOK+'" target="_blank" rel="noopener">book a free call →</a></div>';

var footHTML=''
+'<div class="fs-in">'
+'<div class="fs-brand">NaNa Frimpomaa<span class="fs-dot">.</span><p>I make one-person businesses feel fully staffed — done for you, owned by you.</p></div>'
+'<div class="fs-col"><div class="fs-h">Work with me</div>'
+'<a href="client-catcher.html">the Client Catcher — the flagship</a>'
+'<a href="services.html">services &amp; pricing</a>'
+'<a href="portfolio.html">portfolio</a>'
+'<a href="ugc.html">UGC for brands</a>'
+'<a href="intake.html">build brief</a></div>'
+'<div class="fs-col"><div class="fs-h">Free</div>'
+'<a href="planner.html">the Small Business Blueprint</a>'
+'<a href="som.html">Som — capture tool</a>'
+'<a href="soma.html">Soma — live automation demo</a>'
+'<a href="products.html">all tools</a></div>'
+'<div class="fs-col"><div class="fs-h">Elsewhere</div>'
+'<a href="https://www.instagram.com/frimpomaasync" target="_blank" rel="noopener">Instagram</a>'
+'<a href="https://www.facebook.com/share/18qcDjQme8/?mibextid=wwXIfr" target="_blank" rel="noopener">Facebook</a>'
+'<a href="https://youtube.com/@frimpomaasync?si=KKimG0QwsgOsB1Xl" target="_blank" rel="noopener">YouTube</a>'
+'<a href="https://www.tiktok.com/@frimpomaasync?_r=1&amp;_t=ZT-97e6dr2nWms" target="_blank" rel="noopener">TikTok</a>'
+'<a href="blog.html">notes</a></div>'
+'</div>'
+'<div class="fs-base"><span>© 2026 NaNa Frimpomaa · frimpomaasync.com</span><span>Built in public.</span></div>';

var bandCSS=""
+"#fscta{background:linear-gradient(160deg,#1E2A45,#16203A);color:#F4F1EC;padding:72px 24px;text-align:center;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,Arial,sans-serif}"
+"#fscta *{margin:0;padding:0;box-sizing:border-box}"
+"#fscta h2{font-family:Georgia,'Palatino Linotype','Iowan Old Style','Times New Roman',serif;font-size:clamp(1.9rem,4.2vw,2.9rem);font-weight:700;letter-spacing:-.02em;color:#fff;line-height:1.1}"
+"#fscta h2 .fs-period{color:#C9975B}"
+"#fscta p{margin:14px auto 0;max-width:520px;font-size:15.5px;color:#A9B1C8;line-height:1.6}"
+"#fscta .fs-book{display:inline-block;margin-top:26px;background:#B4552D;color:#fff;font-weight:700;font-size:13px;text-transform:uppercase;letter-spacing:.08em;text-decoration:none;border-radius:6px;padding:16px 32px;transition:background .2s ease,transform .18s ease}"
+"#fscta .fs-book:hover{background:#9A4522;transform:translateY(-2px)}"
+"#fscta .fs-guar{margin-top:18px;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:11.5px;color:#9dbb8a;letter-spacing:.04em}"
+"#fscta .fs-trust{margin-top:8px;font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:11px;color:#8D97B3;letter-spacing:.04em}";

var style=document.createElement("style");
style.textContent=css+bandCSS;
document.head.appendChild(style);

var nav=document.createElement("nav");
nav.id="fsnav";
nav.setAttribute("aria-label","Site");
nav.innerHTML=navHTML;
document.body.insertBefore(nav,document.body.firstChild);

if(!document.body.hasAttribute("data-no-fscta")){
  var band=document.createElement("section");
  band.id="fscta";
  band.setAttribute("aria-label","Book a call");
  band.innerHTML='<h2>Ready when you are<span class="fs-period">.</span></h2>'
    +'<p>One free 15-minute call. We find the leak costing you the most — and you leave with the map either way.</p>'
    +'<a class="fs-book" href="'+BOOK+'" target="_blank" rel="noopener">book a free 15-min call →</a>'
    +'<div class="fs-guar">✓ Live and working in 7 days, booking appointments within 30 — or you don\u2019t pay.</div>'
    +'<div class="fs-trust">Built, wired, and cared for by NaNa Frimpomaa · frimpomaasync.com</div>';
  document.body.appendChild(band);
}

var foot=document.createElement("footer");
foot.id="fsfoot";
foot.innerHTML=footHTML;
if(document.getElementById("fscta")){foot.style.marginTop="0";}
document.body.appendChild(foot);

var burger=nav.querySelector(".fs-burger"),menu=nav.querySelector(".fs-menu");
burger.addEventListener("click",function(){
  var open=menu.classList.toggle("fs-open");
  burger.setAttribute("aria-expanded",open?"true":"false");
});
menu.addEventListener("click",function(e){
  if(e.target.tagName==="A"){menu.classList.remove("fs-open");burger.setAttribute("aria-expanded","false");}
});
})();
