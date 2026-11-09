#!/usr/bin/env node
// Dev-only visual QA: renders every template page to SVG in one HTML contact sheet.
// Usage: node scripts/dev/template-contact-sheet.mjs [--filter text] [--out public/__sheet] [--zoom 1]
// Serve the output with `php artisan serve` and open /__sheet/index.html. Delete public/__sheet before committing.
//
// The renderer below is a small standalone approximation of the editor's SVG renderer. It loads the
// fonts from Google Fonts FOR THIS DEV SHEET ONLY (never product code) so text wrapping is measured
// with the real faces via canvas measureText; without network it falls back to average advance widths.
// Gradient angle convention assumed: CSS-like, 0 = to top, 90 = to right, 180 = to bottom.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf('--' + n); return i >= 0 ? args[i + 1] : d; };
const out = resolve(root, opt('out', 'public/__sheet'));
const filter = opt('filter', '');

const json = execFileSync('php', [join(root, 'scripts/dev/dump-templates.php'), ...(filter ? [filter] : [])], { cwd: root, maxBuffer: 1 << 28 }).toString();
const iconsPath = join(root, 'resources/design/icons.json');
const elemsPath = join(root, 'resources/design/elements.json');
const elems = existsSync(elemsPath) ? JSON.parse(readFileSync(elemsPath, 'utf8')) : {};
const icons = existsSync(iconsPath) ? JSON.parse(readFileSync(iconsPath, 'utf8')) : {};
delete icons.$comment;

const fonts = { 'inter': 'Inter', 'dm-sans': 'DM Sans', 'poppins': 'Poppins', 'montserrat': 'Montserrat', 'oswald': 'Oswald', 'bebas-neue': 'Bebas Neue', 'anton': 'Anton', 'archivo-black': 'Archivo Black', 'playfair-display': 'Playfair Display', 'lora': 'Lora', 'merriweather': 'Merriweather', 'cormorant-garamond': 'Cormorant Garamond', 'fraunces': 'Fraunces', 'space-grotesk': 'Space Grotesk', 'caveat': 'Caveat', 'pacifico': 'Pacifico', 'permanent-marker': 'Permanent Marker', 'jetbrains-mono': 'JetBrains Mono' };

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Template contact sheet (dev)</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&family=DM+Sans:wght@400;700&family=Poppins:wght@400;700;900&family=Montserrat:wght@400;700;900&family=Oswald:wght@400;700&family=Bebas+Neue&family=Anton&family=Archivo+Black&family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400;1,700&family=Lora:ital,wght@0,400;0,700;1,400&family=Merriweather:wght@400;700;900&family=Cormorant+Garamond:ital,wght@0,400;0,700;1,400&family=Fraunces:wght@400;700;900&family=Space+Grotesk:wght@400;700&family=Caveat:wght@400;700&family=Pacifico&family=Permanent+Marker&family=JetBrains+Mono:wght@400;700&display=block">
<style>
 body{margin:0;background:#d9d6cf;font:14px/1.4 system-ui,sans-serif;color:#222}
 header{position:sticky;top:0;z-index:5;background:#222;color:#eee;padding:8px 16px;display:flex;gap:16px;align-items:center}
 header input{padding:4px 8px}
 .tpl{margin:12px 16px 20px;display:inline-block;vertical-align:top;max-width:1500px}.tpl h2{margin:0 0 2px;font-size:16px}.tpl p{margin:0 0 8px;color:#444;font-size:12px}
 .pages{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap}
 figure{margin:0}figcaption{font-size:11px;color:#555;margin-top:2px}
 svg{display:block;box-shadow:0 1px 6px rgba(0,0,0,.35);background:#fff}
 .guides .g{display:block}.g{display:none}
</style></head><body>
<header><b>Template contact sheet</b><label><input type="checkbox" id="g"> margin guides</label><label><input type="checkbox" id="o" checked> overflow flags</label><input id="q" placeholder="filter slug / kind"><span id="n"></span></header>
<section id="icons" style="padding:8px 16px"></section><main id="main"></main>
<script>
const TEMPLATES=${json};
const ICONS=${JSON.stringify(icons)};
const ELEMS=${JSON.stringify(elems)};
const FONTS=${JSON.stringify(fonts)};
const HMAX=${Number(opt('h','560'))};const WMAX=${Number(opt('w','1380'))};
const ZOOM=${Number(opt('zoom', '1'))};
const ctx=document.createElement('canvas').getContext('2d');
const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const num=n=>Math.round(n*100)/100;
let uid=0;
function caseText(t,c){return c==='upper'?t.toUpperCase():c==='lower'?t.toLowerCase():c==='title'?t.replace(/\\w\\S*/g,w=>w[0].toUpperCase()+w.slice(1).toLowerCase()):t}
function fontCss(e){return (e.italic?'italic ':'')+(e.fontWeight||400)+' '+e.fontSize+'px "'+(FONTS[e.font]||'Inter')+'"'}
function measure(e,s){ctx.font=fontCss(e);return ctx.measureText(s).width+(e.letterSpacing||0)*e.fontSize*s.length}
function wrap(e){const out=[];const maxW=e.width;for(const para of caseText(e.text,e.textCase).split('\\n')){let line='';for(const w of para.split(/\\s+/).filter(Boolean)){const t=line?line+' '+w:w;if(line&&measure(e,t)>maxW){out.push(line);line=w}else line=t}out.push(line)}return out}
function paint(f,defs){if(!f)return 'none';if(typeof f==='string')return f;const id='g'+(++uid);const stops=f.stops.map(s=>'<stop offset="'+s.at+'" stop-color="'+s.color+'"/>').join('');if(f.kind==='radial')defs.push('<radialGradient id="'+id+'">'+stops+'</radialGradient>');else{const a=(f.angle??90)*Math.PI/180;const dx=Math.sin(a),dy=-Math.cos(a);defs.push('<linearGradient id="'+id+'" x1="'+num(.5-dx/2)+'" y1="'+num(.5-dy/2)+'" x2="'+num(.5+dx/2)+'" y2="'+num(.5+dy/2)+'">'+stops+'</linearGradient>')}return 'url(#'+id+')'}
function strokeAttrs(s){if(!s)return '';const d=s.dash==='dashed'?' stroke-dasharray="'+s.width*3+' '+s.width*2+'"':s.dash==='dotted'?' stroke-dasharray="0 '+s.width*2+'" stroke-linecap="round"':'';return ' stroke="'+s.color+'" stroke-width="'+s.width+'"'+d+(s.cap==='round'?' stroke-linecap="round"':'')}
function star(w,h,pts,inner){const cx=w/2,cy=h/2,r=[]; for(let i=0;i<pts*2;i++){const a=Math.PI*i/pts-Math.PI/2;const k=i%2?inner:1;r.push(num(cx+Math.cos(a)*cx*k)+','+num(cy+Math.sin(a)*cy*k))}return r.join(' ')}
function poly(w,h,n){const r=[];for(let i=0;i<n;i++){const a=2*Math.PI*i/n-Math.PI/2;r.push(num(w/2+Math.cos(a)*w/2)+','+num(h/2+Math.sin(a)*h/2))}return r.join(' ')}
function el(e,defs,flags){
 const tr='translate('+e.x+' '+e.y+')'+(e.rotation?' rotate('+e.rotation+' '+e.width/2+' '+e.height/2+')':'');
 let inner='';const w=e.width,h=e.height;
 const flip=(e.flip_x||e.flip_y)?' translate('+(e.flip_x?w:0)+' '+(e.flip_y?h:0)+') scale('+(e.flip_x?-1:1)+' '+(e.flip_y?-1:1)+')':'';
 let filter='';if(e.shadow){const id='f'+(++uid);defs.push('<filter id="'+id+'" x="-30%" y="-30%" width="160%" height="160%"><feDropShadow dx="'+e.shadow.x+'" dy="'+e.shadow.y+'" stdDeviation="'+e.shadow.blur/2+'" flood-color="'+e.shadow.color+'" flood-opacity="'+e.shadow.opacity+'"/></filter>');filter=' filter="url(#'+id+')"'}
 if(e.type==='shape'){const f=paint(e.fill,defs),st=strokeAttrs(e.stroke);
  switch(e.kind){case 'rect':inner='<rect width="'+w+'" height="'+h+'" rx="'+(e.radius||0)+'" fill="'+f+'"'+st+'/>';break;
  case 'ellipse':inner='<ellipse cx="'+w/2+'" cy="'+h/2+'" rx="'+w/2+'" ry="'+h/2+'" fill="'+f+'"'+st+'/>';break;
  case 'triangle':inner='<polygon points="'+w/2+',0 '+w+','+h+' 0,'+h+'" fill="'+f+'"'+st+'/>';break;
  case 'star':inner='<polygon points="'+star(w,h,e.points||5,e.inner||.5)+'" fill="'+f+'"'+st+'/>';break;
  case 'polygon':inner='<polygon points="'+poly(w,h,e.sides||6)+'" fill="'+f+'"'+st+'/>';break;
  case 'line':inner='<line x1="0" y1="0" x2="'+w+'" y2="'+h+'"'+(st||' stroke="'+f+'" stroke-width="2"')+'/>';break;
  case 'path':inner='<path d="'+esc(e.d)+'" fill="'+f+'"'+st+' stroke-linejoin="round"/>';break;
  default:inner='<rect width="'+w+'" height="'+h+'" fill="'+f+'"/>'}}
 else if(e.type==='image'){const r=e.mask==='ellipse'?'':'';const clip=e.mask==='ellipse'?'<ellipse cx="'+w/2+'" cy="'+h/2+'" rx="'+w/2+'" ry="'+h/2+'" fill="#e8e6e1" stroke="#b9b5ab" stroke-dasharray="6 4"/>':'<rect width="'+w+'" height="'+h+'" rx="'+(e.radius||0)+'" fill="#e8e6e1" stroke="#b9b5ab" stroke-dasharray="6 4"/>';const s=Math.min(w,h);inner=clip+'<g transform="translate('+(w/2-s*.08)+' '+(h/2-s*.12)+') scale('+s*.16/24+')" fill="none" stroke="#9a968c" stroke-width="1.6"><rect x="1" y="3" width="22" height="18" rx="2"/><circle cx="8" cy="10" r="2.2"/><path d="M1 18l6-5 5 4 4-3 7 6"/></g><text x="'+w/2+'" y="'+(h/2+s*.14)+'" text-anchor="middle" font-family="system-ui" font-size="'+Math.max(8,s*.055)+'" fill="#8d897f">Add a picture</text>'}
 else if(e.type==='icon'){const d=(ICONS[e.icon]||{}).d||'M4 4H20V20H4Z';inner='<g transform="scale('+w/24+')" fill="none" stroke="'+e.color+'" stroke-width="'+(e.strokeWidth||2)+'" stroke-linecap="round" stroke-linejoin="round"><path d="'+d+'"/></g>'}
 else if(e.type==='group'){inner=(e.children||[]).map(c=>el(c,defs,flags)).join('')}
 else if(e.type==='text'){const lines=wrap(e);const lh=e.fontSize*(e.lineHeight||1.2);const total=lines.length*lh;let y0=0;if(e.valign==='middle')y0=(h-total)/2;else if(e.valign==='bottom')y0=h-total;
  const anchor=e.align==='center'?'middle':e.align==='right'?'end':'start';const x=e.align==='center'?w/2:e.align==='right'?w:0;
  const fam=(FONTS[e.font]||'Inter');
  inner='<text font-family="'+fam+'" font-size="'+e.fontSize+'" font-weight="'+(e.fontWeight||400)+'"'+(e.italic?' font-style="italic"':'')+' fill="'+e.color+'" text-anchor="'+anchor+'"'+(e.letterSpacing?' letter-spacing="'+e.letterSpacing*e.fontSize+'"':'')+(e.underline?' text-decoration="underline"':'')+'>'+lines.map((l,i)=>'<tspan x="'+x+'" y="'+num(y0+i*lh+lh*0.5+e.fontSize*0.35)+'">'+esc(l)+'</tspan>').join('')+'</text>';
  if(total>h+1&&flags.overflow)inner+='<rect class="ovf" width="'+w+'" height="'+h+'" fill="none" stroke="#f0f" stroke-width="3"/>'}
 const wrapG='<g transform="'+tr+'"'+(e.opacity!=null&&e.opacity<1?' opacity="'+e.opacity+'"':'')+filter+'>'+(flip?'<g transform="'+flip+'">'+inner+'</g>':inner)+'</g>';
 return wrapG}
function renderPage(p,scale){const defs=[];const flags={overflow:document.getElementById('o').checked};
 const bg=p.background;let b;if(bg.type==='gradient'&&bg.gradient)b='<rect width="'+p.width+'" height="'+p.height+'" fill="'+paint(bg.gradient,defs)+'"/>';else b='<rect width="'+p.width+'" height="'+p.height+'" fill="'+(bg.color||'#fff')+'"/>';
 const body=p.elements.map(e=>el(e,defs,flags)).join('');
 const m=Math.min(p.width,p.height)*0.04;
 const guide='<g class="g" stroke="#f0f" stroke-width="'+Math.max(1,p.width/600)+'" fill="none" stroke-dasharray="6 6"><rect x="'+m+'" y="'+m+'" width="'+(p.width-2*m)+'" height="'+(p.height-2*m)+'"/></g>';
 const w=p.width*scale,h=p.height*scale;
 return '<svg xmlns="http://www.w3.org/2000/svg" width="'+num(w)+'" height="'+num(h)+'" viewBox="0 0 '+p.width+' '+p.height+'"><defs>'+defs.join('')+'</defs><clipPath id="c'+(++uid)+'"><rect width="'+p.width+'" height="'+p.height+'"/></clipPath><g clip-path="url(#c'+uid+')">'+b+body+'</g>'+guide+'</svg>'}
function draw(){const q=document.getElementById('q').value.toLowerCase();const main=document.getElementById('main');let n=0;
 main.innerHTML=TEMPLATES.filter(t=>!q||(t.slug+' '+t.kind+' '+t.category+' '+t.styles.join(' ')).includes(q)).map(t=>{n++;const longest=Math.max(...t.pages.map(p=>Math.max(p.width,p.height)));const P=t.pages[0];const sc=ZOOM*Math.min(1,HMAX/P.height,(t.pages.length>1?WMAX/2:WMAX)/P.width);
  return '<section class="tpl"><h2>'+esc(t.name)+' <small>('+t.slug+')</small></h2><p>'+esc(t.kind)+' | '+t.format_key+' '+t.width+'x'+t.height+' | '+t.styles.join(', ')+' | '+t.pages.length+' page(s)</p><div class="pages">'+t.pages.map((p,i)=>'<figure>'+renderPage(p,sc)+'<figcaption>'+esc(p.name||('Page '+(i+1)))+'</figcaption></figure>').join('')+'</div></section>'}).join('');
 document.getElementById('n').textContent=n+' templates'; document.body.classList.toggle('guides',document.getElementById('g').checked)}
if(location.hash==='#icons'){const cats={};Object.entries(ICONS).forEach(([k,v])=>{(cats[v.category]=cats[v.category]||[]).push([k,v])});document.getElementById('icons').innerHTML=Object.entries(cats).map(([c,l])=>'<h3 style="margin:10px 0 4px">'+c+' ('+l.length+')</h3><div style="display:flex;flex-wrap:wrap;gap:6px">'+l.map(([k,v])=>'<div style="width:78px;text-align:center;background:#fff;padding:6px 2px;border-radius:4px;font-size:9px"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#111" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="'+v.d+'"/></svg><br>'+k+'</div>').join('')+'</div>').join('');document.getElementById('main').style.display='none'}
if(location.hash==='#elements'){const sc=0.45;const h=[];for(const [cat,list] of Object.entries(ELEMS)){if(!Array.isArray(list))continue;h.push('<h3 style="margin:12px 0 4px">'+cat+' ('+list.length+')</h3><div style="display:flex;flex-wrap:wrap;gap:10px">');for(const it of list){if(it.element){const e=it.element;const defs=[];const body=el(Object.assign({},e,{x:0,y:0}),defs,{overflow:false});const w=Math.min(e.width,700),s=Math.min(1,150/Math.max(e.width,e.height));h.push('<figure style="background:#fff;padding:6px"><svg width="'+e.width*s+'" height="'+e.height*s+'" viewBox="0 0 '+e.width+' '+e.height+'"><defs>'+defs.join('')+'</defs>'+body+'</svg><figcaption>'+it.key+'</figcaption></figure>')}else if(it.gradient){const defs=[];const f=paint(it.gradient,defs);h.push('<figure style="background:#fff;padding:6px"><svg width="120" height="70"><defs>'+defs.join('')+'</defs><rect width="120" height="70" fill="'+f+'"/></svg><figcaption>'+it.key+'</figcaption></figure>')}else if(it.colors){h.push('<figure style="background:#fff;padding:6px"><svg width="150" height="40">'+it.colors.map((c,i)=>'<rect x="'+i*30+'" width="30" height="40" fill="'+c.hex+'"/>').join('')+'</svg><figcaption>'+it.key+'</figcaption></figure>')}}h.push('</div>')}document.getElementById('icons').innerHTML=h.join('');document.getElementById('main').style.display='none'}
document.getElementById('q').value=new URLSearchParams(location.search).get('q')||'';document.getElementById('g').onchange=draw;document.getElementById('o').onchange=draw;document.getElementById('q').oninput=draw;
(document.fonts&&document.fonts.ready?document.fonts.ready:Promise.resolve()).then(async()=>{try{await Promise.all(Object.values(FONTS).flatMap(f=>[400,700,900].map(w=>document.fonts.load(w+' 40px "'+f+'"'))))}catch(e){}draw()});
draw();
</script></body></html>`;
mkdirSync(out, { recursive: true });
writeFileSync(join(out, 'index.html'), html);
console.log('Wrote ' + join(out, 'index.html'));
