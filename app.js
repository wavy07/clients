const $=id=>document.getElementById(id);
const esc=t=>String(t==null?'':t).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=n=>Number(n)?Number(n).toLocaleString('en-US')+'/=':'BURE';
let BSIG='',ME=null,SITE={networks:[],offers:[],banners:[]},CUR=0,UNREAD=-1,LASTCODE='',CFGF='all',GRP=[],pending=null,pollTimer=null,currentConfig='',currentDescr='',MINE=[],NOTIF=[];
async function api(url,body){
  const r=await fetch(url,body?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}:{});
  let d={};try{d=await r.json()}catch(e){}
  if(r.status===401){location.reload();throw 0}
  d._ok=r.ok;return d;
}
let toastT;function showToast(m){$('toast-message').textContent=m;$('toast').classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>$('toast').classList.remove('show'),4200)}
function copyText(t){const a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy')}catch(e){}if(navigator.clipboard)navigator.clipboard.writeText(t).catch(()=>{});document.body.removeChild(a)}
function dlConfig(t,name){const a=document.createElement('a');if(isFile(t)){const f=fInfo(t);if(!f||!/^\/api\/config-file\//.test(f.url||''))return;a.href=f.url;a.download=f.name}else if(isImg(t)){a.download=(name||'visibleTech-eSIM')+'.png';a.href=t}else{a.download=(name||'visibleTech-Config')+'.txt';a.href=URL.createObjectURL(new Blob([t],{type:'text/plain'}))}a.style.display='none';document.body.appendChild(a);a.click();document.body.removeChild(a);showToast('Download started!')}
const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.12});
function reveal(){document.querySelectorAll('.rv:not(.in)').forEach((el,i)=>{el.style.transitionDelay=(i%6)*70+'ms';io.observe(el)})}
const chip=c=>c?'<span class="code" data-code="'+esc(c)+'" title="Tap to copy">'+esc(c)+' <i class="fa-regular fa-copy"></i></span>':'';
const bcls=b=>'cc-'+(b.bg?'custom':esc(b.color))+(b.anim?'':' noanim'),bsty=b=>b.bg?' style="background:'+esc(b.bg)+';color:'+esc(b.fg||'#fff')+'"':'';
const bimg=(b,c)=>b.img?'<img class="'+(c||'bimg')+'" src="'+esc(b.img)+'" alt="">':'';
const bbtn=(b,c)=>b.btn&&b.link?'<a class="'+(c||'bbtn')+'" href="'+esc(b.link)+'"'+(/^https?:/.test(b.link)?' target="_blank" rel="noopener"':'')+'>'+esc(b.btn)+'</a>':'';
const bx=b=>b.dismiss?'<button class="bx" data-hx="'+b.id+'" aria-label="Funga">✕</button>':'';
function closePop(){$('pop-modal').classList.add('hidden')}
function renderBanners(){
  const p=curPage(),seen=JSON.parse(sessionStorage.getItem('hx')||'[]');
  const B=(SITE.banners||[]).filter(b=>(b.pages==='all'||(b.pages||'all').split(',').includes(p))&&!seen.includes(b.id)),by=k=>B.filter(b=>b.place===k);
  const sig=p+'|'+JSON.stringify(B);if(sig===BSIG)return;BSIG=sig;
  $('strips').innerHTML=by('top').map(b=>'<div class="strip '+bcls(b)+'"'+bsty(b)+'>'+bimg(b)+'<span><b>'+esc(b.title)+'</b> '+esc(b.text)+'</span>'+chip(b.coupon)+bbtn(b)+bx(b)+'</div>').join('');
  $('page-banners').innerHTML=by('hero').map(b=>'<div class="hb '+bcls(b)+'"'+bsty(b)+'><div style="display:flex;gap:14px;align-items:center">'+bimg(b)+'<div><h3>'+esc(b.title)+'</h3><p>'+esc(b.text)+'</p></div></div><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">'+chip(b.coupon)+bbtn(b)+'</div>'+bx(b)+'</div>').join('');
  $('hangs').innerHTML=by('hang').slice(0,3).map((b,i)=>'<div class="hang'+(b.anim?'':' noanim')+'" style="right:'+(14+i*142)+'px;animation-delay:-'+i*1.1+'s"><div class="tag '+bcls(b)+'"'+bsty(b)+' '+(b.coupon?'data-code="'+esc(b.coupon)+'" ':'')+(b.link?'data-link="'+esc(b.link)+'"':'')+'>'+bimg(b)+'<span>'+esc(b.title)+'</span><small>'+esc(b.text)+'</small>'+(b.coupon?'<small><b>'+esc(b.coupon)+'</b></small>':'')+'</div>'+bx(b)+'</div>').join('');
  const pb=by('popup').find(b=>!sessionStorage.getItem('pb'+b.id));
  if(pb){sessionStorage.setItem('pb'+pb.id,'1');setTimeout(()=>{$('pop-sheet').innerHTML='<div class="'+bcls(pb)+'"'+bsty(pb)+' style="padding:30px 24px;border-radius:24px 24px 0 0'+(pb.bg?';background:'+esc(pb.bg)+';color:'+esc(pb.fg||'#fff'):'')+'">'+(pb.img?bimg(pb,'pop-img'):'<i class="fa-solid fa-gift" style="font-size:44px;margin-bottom:10px"></i>')+'<h3 style="font-size:30px">'+esc(pb.title)+'</h3><p style="font-weight:600;margin-top:6px">'+esc(pb.text)+'</p>'+(pb.coupon?'<p style="margin-top:14px">'+chip(pb.coupon)+'</p>':'')+'</div><div class="mb">'+bbtn(pb,'btn btn-gold')+'<button class="btn btn-pri" style="width:100%;margin-top:8px" onclick="closePop()">Sawa</button></div>';$('pop-modal').classList.remove('hidden')},900)}
}
document.addEventListener('click',e=>{
  const bl=e.target.closest('.bbtn,.btn[href^="#/"],.btn[href^="http"]');if(bl&&bl.closest('#pop-sheet'))closePop();
  const c=e.target.closest('[data-code]');
  if(c&&c.dataset.code){LASTCODE=c.dataset.code;copyText(LASTCODE);showToast('Kodi '+LASTCODE+' imenakiliwa. Itumie unaponunua!');if(c.dataset.link)location.hash=c.dataset.link;else if(c.closest('.hang'))location.hash='#/offers';return}
  const lk=e.target.closest('[data-link]');if(lk&&lk.dataset.link){const l=lk.dataset.link;if(/^https?:/.test(l))window.open(l,'_blank','noopener');else location.hash=l;return}
  const hx=e.target.closest('[data-hx]');if(hx){const s=JSON.parse(sessionStorage.getItem('hx')||'[]');s.push(+hx.dataset.hx);sessionStorage.setItem('hx',JSON.stringify(s));BSIG='';renderBanners();return}
  const n=e.target.closest('.nets button[data-n]');if(n){CUR=+n.dataset.n;drawPlans();return}
  const f=e.target.closest('.nets button[data-cf]');if(f){CFGF=f.dataset.cf;drawMine();return}
  const o=e.target.closest('button[data-offer]');if(o){const x=SITE.offers.find(z=>z.id==o.dataset.offer);if(x)openPay({label:x.title,desc:x.descr,price:Number(x.price)||0,offerId:x.id,esim:x.kind==='esim'});return}
  const mb=e.target.closest('button[data-member]');if(mb){const pm=SITE.premium||{},x=(pm.plans||[]).find(z=>z.id===mb.dataset.member);if(x)openPay({label:'👑 '+pm.title+' — '+x.label,desc:'Premium inafunguka mara moja baada ya malipo.',price:Number(x.price)||0,memberId:x.id});return}
  const b=e.target.closest('button[data-t]');if(b){const net=(SITE.networks[CUR]||{}).name||'';openPay({label:b.dataset.l,price:Number(b.dataset.p),speedType:b.dataset.t,duration:b.dataset.d,network:net})}
});
const PAGES=['home','offers','esim','packages','premium','configs','notifs','help'];
const curPage=()=>{const p=location.hash.replace(/^#\/?/,'');return PAGES.includes(p)?p:'home'};
function openMenu(){$('drawer').classList.add('open');$('drawer').setAttribute('aria-hidden','false');$('menu-btn').setAttribute('aria-expanded','true');document.body.style.overflow='hidden'}
function closeMenu(){$('drawer').classList.remove('open');$('drawer').setAttribute('aria-hidden','true');$('menu-btn').setAttribute('aria-expanded','false');document.body.style.overflow=''}
function route(){
  const p=curPage();
  document.querySelectorAll('.page').forEach(e=>e.classList.toggle('hidden',e.dataset.page!==p));
  document.querySelectorAll('.dp a.mi').forEach(a=>a.classList.toggle('on',a.dataset.p===p));closeMenu();
  document.querySelectorAll('.page:not(.hidden) .rv').forEach(e=>e.classList.add('in'));
  window.scrollTo(0,0);renderBanners();
  if(p==='notifs')openNotifsPage();
  if(p==='esim'||p==='offers'||p==='packages'||p==='premium')loadSite();
  if(p==='esim'||p==='configs')loadMine();
}
window.addEventListener('hashchange',route);
function applyTheme(){const p=SITE.premium||{},on=!!p.active;document.body.classList.toggle('prem',on);const m=document.querySelector('meta[name=theme-color]');if(m)m.content=on?'#0A0805':'#070D0A';
  document.querySelectorAll('.pmi').forEach(a=>a.classList.toggle('hidden',!p.on&&!on));
  const nm=((ME&&(ME.name||ME.email))||'').split(' ')[0].split('@')[0].slice(0,14);$('acct-btn').innerHTML=(on?'👑 ':'<i class="fa-regular fa-user"></i> ')+esc(nm||'Akaunti')}
function renderPremium(){const p=SITE.premium||{};applyTheme();$('pm-title').textContent=p.title||'Premium';
  $('pm-status').textContent=p.active?'Wewe ni mwanachama wa Premium hadi '+p.until+'.':'Fungua vifurushi, ofa na eSIM za kipekee kwa wanachama wa Premium.';
  $('pm-perks').innerHTML=(p.perks||[]).map(t=>'<li><i class="fa-solid fa-crown"></i> '+esc(t)+'</li>').join('');
  $('pm-h').textContent=p.active?'Ongeza muda wa Premium':'Chagua muda wa Premium';
  $('pm-plans').innerHTML=p.on?((p.plans||[]).map(x=>'<div class="pm-plan"><div><b>'+esc(x.label)+'</b><small>Siku '+Number(x.days)+'</small></div><div class="pr">'+money(x.price)+'</div><button class="btn btn-pri sm" data-member="'+esc(x.id)+'"><i class="fa-solid fa-crown"></i> '+(p.active?'Ongeza':'Pata')+'</button></div>').join('')||'<p class="mute">Mipango ya Premium inakuja hivi karibuni.</p>'):'<p class="mute">Premium haipatikani kwa sasa.</p>'}
function lockCard(){const p=SITE.premium||{};return p.on&&!p.active&&p.locked>0?'<a class="pm-lock glass" href="#/premium"><span>👑</span><div><b>Kuna maudhui '+p.locked+' ya Premium</b><small>Pata Premium kufungua vifurushi na ofa za kipekee.</small></div><i class="fa-solid fa-chevron-right"></i></a>':''}
function offerCard(x,ic){return '<article class="offer glass rv"><div class="ico"><i class="fa-solid '+ic+'"></i></div><span class="stk '+(x.free?'ok':'no')+'">'+(x.free?(x.shared?(x.left<0?'Kwa wote':x.left+' nafasi zimebaki'):x.free+' zimebaki'):'Zimeisha')+'</span><h3>'+(x.prem?'👑 ':'')+esc(x.title)+'</h3><p class="desc">'+esc(x.descr)+'</p><div class="foot"><div class="pr">'+(x.price>0?money(x.price):'BURE')+'</div><button class="btn btn-pri sm" data-offer="'+x.id+'" '+(x.free?'':'disabled')+'>'+(x.price>0?'<i class="fa-solid fa-wallet"></i> Nunua':'<i class="fa-solid fa-download"></i> Pakua bure')+'</button></div></article>'}
function renderOffers(){renderOffers0();const lc=lockCard();if(lc)['offer-list','esim-list'].forEach(i=>$(i).insertAdjacentHTML('beforeend',lc))}
function renderOffers0(){
  const all=SITE.offers||[],o=all.filter(x=>x.kind!=='esim'),e=all.filter(x=>x.kind==='esim');
  $('offer-list').innerHTML=o.length?o.map(x=>offerCard(x,'fa-key')).join(''):'<p class="mute">Ofa mpya zinakuja hivi karibuni.</p>';
  $('esim-list').innerHTML=e.length?e.map(x=>offerCard(x,'fa-sim-card')).join(''):'<p class="mute">eSIM mpya zinakuja hivi karibuni.</p>';
  reveal();
}
function drawPlans(){drawPlans0();const lc=lockCard();if(lc)$('plans').insertAdjacentHTML('beforeend',lc)}
function drawPlans0(){
  const nets=SITE.networks||[];
  if(!nets.some(n=>n.plans.length)){$('plans').innerHTML='<p class="mute">Vifurushi vipya vinakuja hivi karibuni.</p>';$('nets').innerHTML='';return}
  if(CUR>=nets.length)CUR=0;const n=nets[CUR];
  $('pk-title').textContent=(n.prem?'👑 Premium — ':'Vifurushi vya ')+n.name;
  $('nets').innerHTML=nets.length>1?nets.map((x,i)=>'<button class="'+(i==CUR?'on':'')+'" data-n="'+i+'">'+(x.prem?'👑 ':'')+esc(x.name)+'</button>').join(''):'';
  $('plans').innerHTML=n.plans.map(p=>'<article class="plan glass '+esc(p.cls)+'"><header><h3>'+esc(p.title)+'</h3><span>'+esc(p.range)+'</span></header>'+
    p.rows.map(r=>'<div class="row'+(r.best?' best':'')+'"><div class="d">'+esc(r.label)+(r.best?'<span class="tag">Inapendekezwa</span>':'')+'<small>'+esc(r.small)+'</small></div><div class="p">'+money(r.price)+'</div><button class="btn btn-pri sm" data-t="'+esc(p.id)+'" data-d="'+esc(r.d)+'" data-p="'+Number(r.price)+'" data-l="'+esc(n.name+' — '+p.title+' — '+r.label)+'">Nunua</button></div>').join('')+
    '<footer><i class="fa-solid fa-circle-check"></i>'+esc(p.footer)+'</footer></article>').join('');
}
async function loadSite(first){
  const d=await api('/api/site');if(!d._ok)return;SITE=d;
  document.title='visibleTech – Online Cloud Config';
  renderBanners();renderOffers();drawPlans();renderPremium();
  const wa='https://wa.me/'+SITE.whatsapp+'?text=Habari%2C%20nahitaji%20msaada%20kuhusu%20'+encodeURIComponent(SITE.name);
  document.querySelectorAll('a[href^="https://wa.me/"]').forEach(a=>a.href=wa);
  document.querySelectorAll('a[data-placeholder="youtube-link"]').forEach(a=>{if(SITE.youtube){a.href=SITE.youtube;a.target='_blank';a.rel='noopener'}});
}
let mkT=null;async function loadMine(){const d=await api('/api/my-configs');MINE=d.items||[];drawMine();clearTimeout(mkT);const w=MINE.filter(m=>m.making).map(m=>m.wait||1);if(w.length)mkT=setTimeout(loadMine,(Math.min(...w)+1)*1000)}   // a config still being made: look again when its time is up
function rankNet(n){if(n==='Offers')return 9000;const i=(SITE.networks||[]).findIndex(x=>x.name===n);return i<0?5000:i*100}
function rankGrp(g){if(g.net==='Offers')return 9000;const ni=(SITE.networks||[]).findIndex(x=>x.name===g.net);if(ni<0)return 5000;const pi=SITE.networks[ni].plans.findIndex(p=>p.title===g.plan);return ni*100+(pi<0?50:pi)}
function drawMine(){
  const el=$('mine-list'),box=$('cfg-chips');drawEsimMine();
  const CFG=MINE.map((m,i)=>i).filter(i=>!isEs(MINE[i]));
  if(!CFG.length){box.innerHTML='';el.innerHTML='<div class="glass" style="padding:26px;text-align:center"><i class="fa-solid fa-box-open" style="font-size:34px;color:var(--mute)"></i><p class="mute" style="margin-top:8px">Bado huna config yoyote. Ukinunua, itaonekana hapa.</p></div>';return}
  const nets=[...new Set(CFG.map(i=>MINE[i].net))].sort((a,b)=>rankNet(a)-rankNet(b));
  if(CFGF!=='all'&&!nets.includes(CFGF))CFGF='all';
  const chip=(k,l,c)=>'<button class="'+(CFGF===k?'on':'')+'" data-cf="'+esc(k)+'">'+esc(l)+' ('+c+')</button>';
  box.innerHTML=nets.length>1?chip('all','Zote',CFG.length)+nets.map(n=>chip(n,n==='Offers'?'🔥 Ofa':n,CFG.filter(i=>MINE[i].net===n).length)).join(''):'';
  const groups={};
  CFG.forEach(i=>{const m=MINE[i];if(CFGF!=='all'&&m.net!==CFGF)return;const k=m.net+'|'+m.plan;(groups[k]=groups[k]||{net:m.net,plan:m.plan,items:[]}).items.push(i)});
  GRP=Object.values(groups).sort((a,b)=>rankGrp(a)-rankGrp(b)||a.plan.localeCompare(b.plan));
  el.innerHTML=GRP.map((g,gi)=>'<div class="gh"><h3>'+(g.net==='Offers'?'🔥 '+esc(g.plan):esc(g.net)+' · '+esc(g.plan))+'</h3><span>'+g.items.length+' config</span><button class="btn btn-ghost sm btn-del" onclick="delGroup('+gi+')"><i class="fa-regular fa-trash-can"></i> Futa zote</button></div><div class="mine">'+
    g.items.map(i=>{const m=MINE[i];if(m.making)return '<div class="cfgc glass"><div class="center" style="padding:18px 6px"><div class="spin"></div><h4>Inatengeneza config yako...</h4><p class="hint">'+esc(m.label||m.package)+' - subiri sekunde chache.</p></div></div>';if(m.need_id)return '<div class="cfgc glass"><div class="t"><div><b>'+esc(m.label||m.package)+'</b><small>'+esc(m.t)+' · '+money(m.price)+'</small></div><i class="fa-solid fa-hourglass-half" style="color:var(--gold)"></i></div><p class="hint">'+(m.price>0?'Malipo yamepokelewa ✅ ':'Kodi imekubaliwa ✅ ')+'Weka '+esc(m.idn)+' ya '+esc(m.app)+' ili upate config yako.</p><input class="in" id="mid'+i+'" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="'+esc(m.idn)+'"><div class="cpm" id="mmsg'+i+'"></div><button class="btn btn-pri sm" onclick="mineHwid('+i+')"><i class="fa-solid fa-paper-plane"></i> Tuma ID</button></div>';return '<div class="cfgc glass'+(isImg(m.config)?' isimg':'')+'"><div class="t"><div><b>'+esc(m.label||m.package)+'</b><small>'+esc(m.t)+' · '+money(m.price)+'</small></div><i class="fa-solid fa-circle-check ok"></i></div>'+cfgBox(m.config)+(m.descr?'<p class="hint" style="margin:10px 0 0;white-space:pre-wrap">📝 '+esc(m.descr)+'</p>':'')+'<div class="two"><button class="btn btn-pri sm" onclick="copyCfg(MINE['+i+'].config)"><i class="fa-regular fa-copy"></i> Nakili</button><button class="btn btn-ghost sm" onclick="dlConfig(MINE['+i+'].config,\'config-\'+MINE['+i+'].t.slice(0,10))"><i class="fa-solid fa-file-arrow-down"></i> Pakua</button><button class="btn btn-ghost sm btn-del" onclick="delCfgs([MINE['+i+'].token])" aria-label="Futa"><i class="fa-regular fa-trash-can"></i></button></div></div>'}).join('')+'</div>').join('');
}
function delGroup(gi){const g=GRP[gi];if(g)delCfgs(g.items.map(i=>MINE[i].token))}
async function delCfgs(tokens){
  if(!tokens.length||!confirm((tokens.length>1?'Futa config '+tokens.length+'?':'Futa config hii?')+' Hatua hii haiwezi kurudishwa, hakikisha umeihifadhi kwanza.'))return;
  const d=await api('/api/my-configs/delete',{tokens});
  if(d._ok){MINE=MINE.filter(m=>!tokens.includes(m.token));drawMine();showToast('Imefutwa.')}else showToast(d.error||'Imeshindikana. Jaribu tena.');
}
function setTotal(){
  const p=pending,price=p.price2!=null?p.price2:p.price,free=price===0;p.free=free;
  $('modal-price-display').textContent=money(price);$('modal-old').textContent=money(p.price);$('modal-old').classList.toggle('hidden',p.price2==null);
  $('paid-fields').style.display=free?'none':'';$('pay-btn-label').textContent=free?(p.price===0?'Pakua Config':'Pata Config Bure'):(p.esim?'Lipa & Pata eSIM':'Lipa & Pata Config');
  const pure=p.price===0;  // free offer / eSIM: no coupon, no total, no payment wording at all
  ['tot-row','cp-lbl','cp-msg'].forEach(x=>$(x).classList.toggle('hidden',pure));document.querySelector('#payment-step-1 .cp').classList.toggle('hidden',pure);
  $('modal-sub').textContent=pure?'Pakua config bure':'Kamilisha oda yako';$('pay-ico').className=pure?'fa-solid fa-download':'fa-solid fa-wallet';
}
function openPay(o){
  pending=Object.assign({price2:null,coupon:''},o);
  $('modal-product-title').textContent=o.label;$('modal-desc').textContent=o.desc||'';$('modal-desc').classList.toggle('hidden',!o.desc);
  $('cp-code').value=LASTCODE;$('cp-msg').textContent='';$('cp-msg').className='cpm';
  const pf=normPhone(ME&&ME.phone);$('checkout-phone').value=pf.length===10?pf:'';
  setTotal();showPayStep('1');$('payment-modal').classList.remove('hidden');
  if(LASTCODE&&o.price)applyCoupon();
}
async function applyCoupon(){
  const code=$('cp-code').value.trim(),m=$('cp-msg');if(!code){pending.price2=null;pending.coupon='';m.textContent='';setTotal();return}
  const d=await api('/api/coupon',{code:code,offer_id:pending.offerId||'',member_id:pending.memberId||'',speed_type:pending.speedType||'',duration:pending.duration||''});
  if(d._ok){pending.coupon=code;pending.price2=d.price;m.className='cpm ok';m.textContent='✅ Kodi imekubaliwa ('+d.label+')'}
  else{pending.coupon='';pending.price2=null;m.className='cpm no';m.textContent=d.error||'Kodi si sahihi.'}
  setTotal();
}
const isFile=c=>/^\{"vtfile":1/.test(c||''),fInfo=c=>{try{return JSON.parse(c)}catch(e){return null}};
const fileBox=c=>{const f=fInfo(c);return f?'<div style="display:flex;gap:12px;align-items:center;padding:6px 2px"><i class="fa-solid fa-file-lines" style="font-size:26px"></i><div style="min-width:0;word-break:break-all"><b>'+esc(f.name)+'</b><br><small>'+fsz(f.size)+'</small></div></div>':''};
async function copyCfg(c){if(isFile(c)){const f=fInfo(c);if(!f||!f.txt){showToast('Hili ni faili - bonyeza Pakua.');return}try{const r=await fetch(f.url);copyText(await r.text());showToast('Config imenakiliwa!')}catch(e){showToast('Imeshindikana kunakili.')}return}copyText(c);showToast('Config imenakiliwa!')}
const isImg=c=>/^data:image\//.test(c||''),isLnk=c=>/^https?:\/\/\S+$/.test((c||'').trim());
const lnkBtn=c=>isLnk(c)?'<a class="btn btn-ghost sm" style="margin-top:8px" href="'+esc(c.trim())+'" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Fungua link</a>':'';
const qrImg=c=>'<img class="qr" alt="eSIM QR code" src="'+esc(c)+'">';
const isEs=m=>m.kind==='esim'||m.net==='eSIM';
function esimParts(c){c=String(c||'').trim();let d={};
  if(c[0]==='{'){try{d=JSON.parse(c)||{}}catch(e){}}else if(isImg(c))d={qr:c};else if(/^https?:\/\/\S+$/i.test(c))d={link:c};else if(c)d={code:c};
  const qr=isImg(d.qr)?d.qr:'',link=/^https?:\/\/\S+$/i.test(d.link||'')?d.link:'',code=String(d.code||''),re=/LPA:1\$([^$\s&"'<>]+)\$([^$\s&"'<>]*)(\$[^\s&"'<>]*)?/i;
  const dec=t=>{try{return decodeURIComponent(t)}catch(e){return t}},mc=dec(code).match(re),ml=dec(link).match(re),m=mc||ml;
  return {qr,link,lpa:m?m[0]:'',smdp:String(d.smdp||'')||(m?m[1]:''),act:mc?mc[2]:(code||(ml?ml[2]:''))}}
const esRow=(l,v)=>v?'<div class="esr"><small>'+esc(l)+'</small><code>'+esc(v)+'</code><button class="btn btn-ghost sm" data-v="'+esc(v)+'" onclick="copyText(this.dataset.v);showToast(\'Imenakiliwa!\')"><i class="fa-regular fa-copy"></i> Nakili</button></div>':'';
function esimBox(c){const p=esimParts(c);
  return '<div class="esb">'+(p.qr?qrImg(p.qr)+'<div class="esa"><a class="btn btn-ghost sm" download="visibleTech-eSIM-QR.png" href="'+esc(p.qr)+'"><i class="fa-solid fa-file-arrow-down"></i> Pakua QR</a></div>':'')
  +(p.link?'<div class="esa"><a class="btn btn-pri sm" href="'+esc(p.link)+'" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Fungua link ya eSIM</a></div>'+esRow('Link ya eSIM',p.link):'')
  +esRow('Activation code (LPA)',p.lpa)+esRow('SM-DP+ Address',p.smdp)+esRow('Activation Code',p.act)+'</div>'}
function drawEsimMine(){const w=$('esim-mine-wrap'),el=$('esim-mine');if(!w)return;const L=MINE.filter(isEs);w.classList.toggle('hidden',!L.length);
  el.innerHTML=L.map(m=>'<div class="cfgc glass"><div class="t"><div><b>'+esc(m.package)+'</b><small>'+esc(m.t)+' · '+money(m.price)+'</small></div><i class="fa-solid fa-circle-check ok"></i></div>'+esimBox(m.config)+'<div class="two"><button class="btn btn-ghost sm btn-del" onclick="delCfgs([\''+esc(m.token)+'\'])"><i class="fa-regular fa-trash-can"></i> Futa</button></div></div>').join('')}
const cfgBox=c=>isImg(c)?qrImg(c):isFile(c)?fileBox(c):'<pre class="code2">'+esc(c)+'</pre>'+lnkBtn(c);
function showCfg(){const fd=$('final-desc');if(fd){fd.textContent=currentDescr?'📝 '+currentDescr:'';fd.classList.toggle('hidden',!currentDescr)}const es=!!(pending&&pending.esim);$('final-config-uri').innerHTML=es?esimBox(currentConfig):isImg(currentConfig)?qrImg(currentConfig):isFile(currentConfig)?fileBox(currentConfig):esc(currentConfig)+lnkBtn(currentConfig);$('payment-step-success').classList.toggle('isimg',es||isImg(currentConfig))}
function showPayStep(s){['1','loading','success','failed','id'].forEach(x=>$('payment-step-'+x).classList.toggle('hidden',x!==s))}
function closePay(){$('payment-modal').classList.add('hidden');if(pollTimer){clearInterval(pollTimer);pollTimer=null}}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeMenu();document.querySelectorAll('.modal').forEach(m=>m.classList.add('hidden'))}});
$('checkout-phone').addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').substring(0,12)});
function normPhone(raw){let p=String(raw||'').replace(/\D/g,'');if(p.startsWith('255')&&p.length===12)p='0'+p.substring(3);if(p.length===9)p='0'+p;return p}
async function startPayment(){
  if(!pending)return;let phone='';
  if(!pending.free){phone=normPhone($('checkout-phone').value);
    if(phone.length!==10||!phone.startsWith('0')){showToast('Weka namba sahihi yenye tarakimu 10.');return}}
  const fr=pending.price===0;$('ld-t').textContent=fr?'Inaandaa config yako...':'Inatuma ombi la malipo...';$('ld-p').textContent=fr?'Subiri kidogo.':'Angalia simu yako sasa na weka PIN ili kukamilisha malipo.';
  showPayStep('loading');
  try{
    const d=await api('/api/buy-direct',{offer_id:pending.offerId||'',member_id:pending.memberId||'',speed_type:pending.speedType||'',duration:pending.duration||'',coupon:pending.coupon||'',phone:phone});
    if(d.status==='success'){
      if(d.free&&d.need_id){showIdStep(d.token,d);showToast('Weka ID yako ili upate config.');afterBuy()}
      else if(d.free){currentConfig=d.config||'';currentDescr=d.descr||'';okTexts(pending.price===0);showCfg();showPayStep('success');showToast('Config yako iko tayari!');afterBuy()}
      else startPolling(d.access_token);
    }else showFailed(d.message||d.error||'Kuna hitilafu. Jaribu tena.');
  }catch(e){if(e!==0)showFailed('Tatizo la network. Jaribu tena.')}
}
let idTok='';
function okTexts(free){if(pending&&pending.memberId){$('ok-title').textContent='Karibu Premium! 👑';$('ok-text').textContent='Premium yako imewashwa. Vifurushi na ofa za Premium sasa zimefunguka.';return}if(pending&&pending.esim){$('ok-title').textContent='Malipo yamefanikiwa!';$('ok-text').textContent='eSIM yako iko tayari. Pia imehifadhiwa ndani ya ukurasa wa eSIM, sehemu ya “eSIM Zangu”.';return}$('ok-title').textContent=free?'Config yako iko tayari!':'Malipo yamefanikiwa!';$('ok-text').textContent=free?'Imehifadhiwa ndani ya “Config Zangu”. Unaweza kuinakili au kuipakua.':'Asante kwa kuiamini visibleTech. Config yako pia imehifadhiwa ndani ya “Config Zangu”.'}
function showIdStep(token,i){idTok=token;$('id-label').textContent='Weka '+i.idn+' ya '+i.app;$('id-help').textContent='';$('id-input').value='';$('id-msg').textContent='';showPayStep('id')}
const MK=[['Inakagua ID yako...','Subiri kidogo.'],['Inatengeneza config yako...','Inaunganisha na server.'],['Inaweka mipangilio...','Karibu tunamaliza.'],['Inakamilisha...','Config yako iko njiani.']];
function makeWait(cb,secs){const t0=Date.now(),tot=Math.max(1,secs||15)*1000;return new Promise(ok=>{let t=null;const tick=()=>{const el=Date.now()-t0;if(el>=tot){clearInterval(t);ok();return}cb([MK[Math.min(MK.length-1,Math.floor(el/tot*MK.length))][0],MK[Math.min(MK.length-1,Math.floor(el/tot*MK.length))][1]+' ('+Math.ceil((tot-el)/1000)+'s)'])};t=setInterval(tick,500);tick()})}   // ~15 seconds of "making your config": the SERVER also holds the config back until the time is up
async function hwFinish(token){for(let k=0;k<6;k++){const d=await api('/api/order/hwid',{token:token,finish:1});if(d.status==='success')return d;if(d.status==='making'){await new Promise(r=>setTimeout(r,Math.max(1,d.wait||1)*1000+300));continue}return d}return{status:'error',message:'Config bado inatengenezwa. Angalia \"Config Zangu\" baada ya muda mfupi.'}}
let hwBusy=false;
async function sendHwid(){const v=$('id-input').value.trim(),m=$('id-msg');m.className='cpm no';if(!v){m.textContent='Weka ID kwanza.';return}
  if(hwBusy)return;hwBusy=true;m.textContent='';
  try{const d=await api('/api/order/hwid',{token:idTok,hwid:v});
    if(d.status!=='making'&&d.status!=='success'){m.textContent=d.message||d.error||'ID si sahihi.';return}
    showPayStep('loading');if(d.status==='making')await makeWait(x=>{$('ld-t').textContent=x[0];$('ld-p').textContent=x[1]},d.wait||15);
    const r=d.status==='success'?d:await hwFinish(idTok);
    if(r.status!=='success'){showFailed(r.message||r.error||'Imeshindikana. Jaribu tena.');loadMine();return}
    currentConfig=r.config||'';currentDescr=r.descr||'';okTexts(!!(pending&&pending.free));showCfg();showPayStep('success');showToast('Config yako iko tayari!');afterBuy()
  }finally{hwBusy=false}}
async function mineHwid(i){const m=MINE[i],v=$('mid'+i).value.trim(),el=$('mmsg'+i);el.className='cpm no';if(!v){el.textContent='Weka ID kwanza.';return}
  if(hwBusy)return;hwBusy=true;
  try{const d=await api('/api/order/hwid',{token:m.token,hwid:v});
    if(d.status!=='making'&&d.status!=='success'){el.textContent=d.message||d.error||'ID si sahihi.';return}
    const card=el.closest('.cfgc');if(card)card.innerHTML='<div class="center" style="padding:18px 6px"><div class="spin"></div><h4 id="mk-t'+i+'"></h4><p id="mk-p'+i+'" class="hint"></p></div>';
    if(d.status==='making')await makeWait(x=>{const t=$('mk-t'+i),p=$('mk-p'+i);if(t)t.textContent=x[0];if(p)p.textContent=x[1]},d.wait||15);
    const r=d.status==='success'?d:await hwFinish(m.token);
    if(r.status!=='success'){showToast(r.message||r.error||'Imeshindikana. Jaribu tena.');loadMine();return}
    showToast('Config yako iko tayari!');loadMine();loadNotifs()
  }finally{hwBusy=false}}
function afterBuy(){loadMine();loadNotifs();loadSite()}
function startPolling(token){
  let n=0;pollTimer=setInterval(async()=>{
    if(++n>75){clearInterval(pollTimer);pollTimer=null;showFailed('Muda umeisha. Kama ulilipa, angalia "Config Zangu" na Notification zako.');loadNotifs();return}
    try{const d=await api('/api/order/status/'+encodeURIComponent(token));
      if(d.payment_status==='success'){clearInterval(pollTimer);pollTimer=null;currentConfig=d.config||'';currentDescr=d.descr||'';okTexts(false);showCfg();showPayStep('success');showToast('Malipo yamefanikiwa!');afterBuy()}
      else if(d.payment_status==='need_id'){clearInterval(pollTimer);pollTimer=null;showIdStep(token,d);showToast('Malipo yamefanikiwa! Weka ID yako.');afterBuy()}
      else if(d.payment_status==='failed'){clearInterval(pollTimer);pollTimer=null;showFailed('Malipo yameshindikana. Hakuna pesa iliyokatwa. Jaribu tena.');loadNotifs()}
      else if(d.payment_status==='no_stock'){clearInterval(pollTimer);pollTimer=null;showFailed('Malipo yamepokelewa lakini config zimeisha. Wasiliana nasi kurejeshewa pesa.');loadNotifs()}
    }catch(e){}
  },4000);
}
function showFailed(m){$('fail-message').textContent=m;showPayStep('failed')}
const NI={paid:'fa-circle-check',issue:'fa-triangle-exclamation',pending:'fa-hourglass-half',info:'fa-bullhorn'};
function setBadge(n){UNREAD=n;const b=$('bell-n');b.textContent=n>99?'99+':n;b.classList.toggle('hidden',!n);const m=$('menu-nt-n');m.textContent=n>99?'99+':n;m.classList.toggle('hidden',!n)}
async function loadNotifs(){
  const d=await api('/api/notifications');if(!d._ok)return;NOTIF=d.items||[];setBadge(d.unread||0);
  if(curPage()==='notifs')drawNotifs();
}
function fsz(b){return b>=1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB'}
function ntFiles(fl){if(!fl||!fl.length)return '';return '<div class="ntf">'+fl.map(f=>{const u='/api/notif-file/'+f.id;return f.img?'<a href="'+u+'" target="_blank" rel="noopener"><img src="'+u+'" alt="'+esc(f.name)+'" loading="lazy"></a><a class="fl" href="'+u+'" download="'+esc(f.name)+'"><i class="fa-solid fa-file-arrow-down"></i> '+esc(f.name)+' · '+fsz(f.size)+'</a>':'<a class="fl" href="'+u+'" download="'+esc(f.name)+'"><i class="fa-solid fa-file-arrow-down"></i> '+esc(f.name)+' · '+fsz(f.size)+'</a>'}).join('')+'</div>'}
function drawNotifs(){
  $('nt-clear').classList.toggle('hidden',!NOTIF.length);$('nt-count').textContent=NOTIF.length?NOTIF.length+' notification':'';
  $('nt-list').innerHTML=NOTIF.length?NOTIF.map(n=>'<div class="nt '+esc(n.kind)+(n.read?'':' un')+'"><div class="ni"><i class="fa-solid '+(NI[n.kind]||NI.info)+'"></i></div><div style="flex:1;min-width:0"><b>'+esc(n.title)+'</b>'+(n.body?'<p>'+esc(n.body)+'</p>':'')+ntFiles(n.files)+'<small>'+esc(n.t)+'</small></div><button class="x del" onclick="delNotif('+n.id+')" aria-label="Futa arifa"><i class="fa-regular fa-trash-can"></i></button></div>').join(''):'<p class="mute" style="text-align:center;padding:20px">Hakuna arifa bado.</p>';
}
async function openNotifsPage(){
  await loadNotifs();
  if(UNREAD>0){await api('/api/notifications/read',{all:1});setBadge(0)}
}
async function delNotif(id){const d=await api('/api/notifications/delete',{id});if(d._ok){NOTIF=NOTIF.filter(n=>n.id!==id);drawNotifs();setBadge(NOTIF.filter(n=>!n.read).length)}}
async function delAllNotifs(){if(!NOTIF.length||!confirm('Futa notification zote?'))return;const d=await api('/api/notifications/delete',{all:1});if(d._ok){NOTIF=[];drawNotifs();setBadge(0)}}
const ST={success:'Imekamilika',pending:'Inasubiri malipo',failed:'Imeshindikana',no_stock:'Zimeisha'};
async function openAcct(){
  $('acct-id').textContent=ME.name||ME.email||'+'+ME.phone;$('acct-orders').textContent='...';{const p=SITE.premium||{};$('acct-prem').innerHTML=p.on?(p.active?'<div class="pm-acct">👑 Premium hadi '+esc(p.until)+' <a class="btn btn-gold sm" href="#/premium" onclick="$(\'acct-modal\').classList.add(\'hidden\')">Ongeza muda</a></div>':'<a class="btn btn-gold" style="width:100%" href="#/premium" onclick="$(\'acct-modal\').classList.add(\'hidden\')">👑 Pata Premium</a>'):''}$('acct-modal').classList.remove('hidden');
  const d=await api('/api/my-orders');
  $('acct-orders').innerHTML=(d.orders||[]).map(o=>'<div class="ord"><div>'+esc(o.package)+'<small>'+esc(o.t)+' · '+money(o.price)+' · '+(ST[o.status]||esc(o.status))+'</small></div>'+(o.status==='success'&&!o.hidden?'<a href="#/configs" onclick="$(\'acct-modal\').classList.add(\'hidden\')">Config Zangu</a>':'')+'</div>').join('')||'<p class="mute">Bado huna oda yoyote.</p>';
}
async function chPw(){const m=$('pw-msg');const d=await api('/api/auth/password',{old:$('pw-old').value,new:$('pw-new').value});m.className='cpm '+(d._ok?'ok':'no');m.textContent=d._ok?'Nenosiri limebadilishwa.':(d.error||'Kuna hitilafu.');if(d._ok){$('pw-old').value='';$('pw-new').value=''}}
async function doLogout(){try{await api('/api/auth/logout',{})}catch(e){}location.reload()}
(async()=>{
  const m=await api('/api/me');if(!m.user){location.reload();return}
  ME=m.user;const nm=(ME.name||ME.email||'').split(' ')[0].split('@')[0].slice(0,14);
  $('acct-btn').innerHTML='<i class="fa-regular fa-user"></i> '+esc(nm||'Akaunti');$('hello').textContent=nm?'· Karibu, '+nm:'';
  reveal();await loadSite(true);await loadMine();loadNotifs();route();
  setInterval(loadNotifs,30000);setInterval(()=>loadSite(false),20000);document.addEventListener('visibilitychange',()=>{if(!document.hidden){loadSite(false);loadMine()}});
})();
(function(){
 if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});
 const bar=$('pwa-bar'),mi=$('pwa-menu'),K='vs_pwa_x';let ev=null;
 const ls=f=>{try{return f(localStorage)}catch(e){return null}};
 const standalone=()=>['standalone','fullscreen','minimal-ui'].some(m=>matchMedia('(display-mode: '+m+')').matches)||navigator.standalone===true;
 const ios=/iphone|ipad|ipod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
 ls(l=>l.removeItem('vs_pwa_ok'));
 // Inside the installed app: nothing about installing is ever shown.
 if(standalone()){document.documentElement.classList.add('is-app');bar.remove();mi.remove();return}
 // In the browser (after login): menu item always, banner unless closed in the last 3 days.
 const snoozed=()=>{const t=+ls(l=>l.getItem(K))||0;return t&&Date.now()-t<3*864e5};
 const hideAll=()=>{bar.classList.add('hidden');mi.classList.add('hidden')};
 const showBar=()=>{if(!snoozed())setTimeout(()=>{if(!standalone())bar.classList.remove('hidden')},1200)};
 mi.classList.remove('hidden');
 const steps=()=>ios
  ?'1. Gusa kitufe cha <b>Share</b> (mraba wenye mshale wa juu) chini ya Safari.<br>2. Chagua <b>Add to Home Screen</b>.<br>3. Gusa <b>Add</b>. Jina litakuwa <b>Visible Tech</b>.'
  :'1. Fungua menyu ya browser yako (<b>⋮</b> au <b>⋯</b>).<br>2. Chagua <b>Install app</b> au <b>Add to Home screen</b>.<br>3. Thibitisha. Jina litakuwa <b>Visible Tech</b>.';
 const install=async()=>{
  if(ev){const e=ev;ev=null;e.prompt();try{const r=await e.userChoice;if(r.outcome!=='accepted')ls(l=>l.setItem(K,String(Date.now())))}catch(x){}return}
  $('pwa-steps').innerHTML=steps();$('pwa-modal').classList.remove('hidden')};
 window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();ev=e;showBar()});
 window.addEventListener('appinstalled',()=>{ev=null;hideAll();showToast('Visible Tech imewekwa!')});
 const mq=matchMedia('(display-mode: standalone)');if(mq.addEventListener)mq.addEventListener('change',e=>{if(e.matches)hideAll()});
 if(ios)showBar();
 $('pwa-x').onclick=()=>{bar.classList.add('hidden');ls(l=>l.setItem(K,String(Date.now())))};
 $('pwa-go').onclick=()=>{bar.classList.add('hidden');install()};
 mi.onclick=()=>{closeMenu();install()};
})();
async function sendDM(){const t=$('dm-text').value.trim(),m=$('dm-msg'),b=$('dm-btn');m.className='cpm no';if(t.length<2){m.textContent='Andika ujumbe wako kwanza.';return}
  b.disabled=true;try{const d=await api('/api/msg/send',{body:t});if(d._ok){$('dm-text').value='';m.className='cpm ok';m.textContent='✅ Ujumbe umetumwa. Utajibiwa kupitia notification.';showToast('Ujumbe umetumwa!')}else m.textContent=d.error||'Imeshindikana. Jaribu tena.'}catch(e){}b.disabled=false}
