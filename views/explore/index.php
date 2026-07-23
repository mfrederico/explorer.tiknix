<?php /** @var array $instances @var string $email @var string $initial */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Architecture Explorer</title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    :root { --bg:#0b1530; --bg2:#060d20; --panel:rgba(255,255,255,0.04); --line:rgba(255,255,255,0.12);
            --text:#eaedf5; --soft:#9ba4bd; --accent:#3b76f0;
            --root:#e0559b; --admin:#e0a23b; --member:#3b76f0; --public:#3bbf7a; }
    html { background:var(--bg2); }
    body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color:var(--text);
           background:linear-gradient(160deg,var(--bg) 0%,var(--bg2) 100%); min-height:100vh; }
    header { display:flex; align-items:center; gap:1rem; padding:0.7rem 1.1rem; border-bottom:1px solid var(--line); }
    header .brand { font-weight:800; letter-spacing:0.01em; }
    header .brand .b { color:var(--accent); }
    header .spacer { flex:1; }
    header .who { color:var(--soft); font-size:0.85rem; }
    header a { color:var(--soft); text-decoration:none; font-size:0.85rem; border-bottom:1px solid var(--line); }
    .scopebar { display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap; padding:0.8rem 1.1rem; border-bottom:1px solid var(--line); }
    .scopebar select, .scopebar input {
        background:rgba(255,255,255,0.07); border:1px solid var(--line); color:var(--text);
        border-radius:8px; padding:0.5rem 0.7rem; font-size:0.9rem; }
    .scopebar input { min-width:280px; }
    .scopebar button { background:var(--accent); color:#fff; border:none; border-radius:8px; padding:0.5rem 0.9rem; font-weight:600; cursor:pointer; }
    .chip { font-size:0.72rem; color:var(--soft); padding:0.25rem 0.5rem; border:1px solid var(--line); border-radius:999px; }
    .ribbon { display:flex; gap:0.5rem; overflow-x:auto; padding:0.9rem 1.1rem; }
    .ctrl { flex:0 0 auto; min-width:118px; background:var(--panel); border:1px solid var(--line);
            border-radius:12px; padding:0.7rem 0.8rem; cursor:pointer; outline:none; transition:border-color .12s,transform .12s; }
    .ctrl:focus, .ctrl.sel { border-color:var(--accent); transform:translateY(-2px); }
    .ctrl .name { font-weight:700; font-size:0.95rem; }
    .ctrl .meta { color:var(--soft); font-size:0.75rem; margin-top:0.25rem; }
    .lvl { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:4px; vertical-align:middle; }
    .lvl.ROOT{background:var(--root)} .lvl.ADMIN{background:var(--admin)}
    .lvl.MEMBER{background:var(--member)} .lvl.PUBLIC{background:var(--public)}
    .body { display:grid; grid-template-columns:1fr 1fr; gap:1rem; padding:0 1.1rem 1.5rem; }
    .panel { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:1rem; min-height:180px; }
    .panel h2 { font-size:0.8rem; text-transform:uppercase; letter-spacing:0.1em; color:var(--soft); margin-bottom:0.7rem; }
    table { width:100%; border-collapse:collapse; font-size:0.85rem; }
    th,td { text-align:left; padding:0.4rem 0.5rem; border-bottom:1px solid var(--line); white-space:nowrap;
            overflow:hidden; text-overflow:ellipsis; max-width:220px; }
    th { color:var(--soft); font-weight:600; }
    .methrow td:first-child { font-family:ui-monospace,monospace; }
    .badge { font-size:0.72rem; padding:0.1rem 0.4rem; border-radius:6px; border:1px solid var(--line); }
    .tablepick { display:flex; gap:0.4rem; flex-wrap:wrap; margin-bottom:0.7rem; }
    .tablepick button { background:rgba(255,255,255,0.06); border:1px solid var(--line); color:var(--text);
                        border-radius:8px; padding:0.3rem 0.55rem; font-size:0.8rem; cursor:pointer; }
    .tablepick button.on { border-color:var(--accent); color:#fff; }
    .muted { color:var(--soft); }
    .rowscroll { overflow-x:auto; }
    .hint { color:var(--soft); font-size:0.8rem; padding:0.4rem 1.1rem 0; }
    .broken { color:var(--admin); }
    /* graph canvas */
    .graphwrap { grid-column:1 / -1; }
    .graphwrap svg { width:100%; height:420px; display:block; background:rgba(0,0,0,0.15); border-radius:10px; cursor:grab; }
    .graphwrap svg:active { cursor:grabbing; }
    .gnode rect { stroke-width:1.2px; }
    .gnode text { font-size:11px; fill:var(--text); }
    .gcol-label { fill:var(--soft); font-size:10px; text-transform:uppercase; letter-spacing:0.08em; }
    .legend { display:flex; gap:1rem; flex-wrap:wrap; margin-top:0.6rem; font-size:0.75rem; color:var(--soft); }
    .legend span b { display:inline-block; width:22px; height:0; border-top:2px solid; margin-right:4px; vertical-align:middle; }
    .ins { font-size:0.85rem; }
    .ins .row { padding:0.3rem 0; border-bottom:1px solid var(--line); }
    .ins .k { color:var(--soft); }
    .ins code { font-family:ui-monospace,monospace; color:var(--text); }
    .brokenpill { color:var(--admin); border:1px solid var(--admin); border-radius:6px; padding:0 .35rem; font-size:.72rem; }
    @media (max-width:820px){ .body{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="scopebar">
    <label class="muted" style="font-size:0.85rem">Instance</label>
    <select id="instancePick">
        <?php foreach ($instances as $i): ?>
        <option value="<?= htmlspecialchars($i['slug']) ?>"><?= htmlspecialchars($i['name']) ?> (<?= htmlspecialchars($i['slug']) ?>)<?= $i['owned'] ? '' : ' · team' ?></option>
        <?php endforeach; ?>
    </select>
    <input id="urlInput" placeholder="…or paste an instance URL (/ = default)" value="<?= htmlspecialchars($initial) ?>">
    <button id="loadBtn">Explore</button>
    <span class="chip" id="freshness" style="margin-left:auto">—</span>
</div>

<?php if (!$instances): ?>
    <p class="hint">You have no instances to explore yet. Create one in the AI Builder on tiknix.com.</p>
<?php endif; ?>

<div class="hint">Arrow keys ← → move across controls; ↓ / Enter drills into methods; Esc backs out.</div>
<div class="ribbon" id="ribbon" role="grid" tabindex="0" aria-label="Controls"></div>

<div class="body">
    <div class="panel">
        <h2>Methods <span id="methTitle" class="muted"></span></h2>
        <div id="methods" class="muted">Select a control above.</div>
    </div>
    <div class="panel">
        <h2>Inspector <span id="insTitle" class="muted"></span></h2>
        <div id="inspector" class="ins muted">Click a method, then a node in the graph.</div>
    </div>
    <div class="panel graphwrap">
        <h2>Cross-reference graph <span id="graphTitle" class="muted"></span></h2>
        <svg id="graph" viewBox="0 0 1000 420" preserveAspectRatio="xMidYMid meet"></svg>
        <div class="legend">
            <span><b style="border-color:#3bbf7a"></b>renders</span>
            <span><b style="border-color:#e0a23b"></b>redirects</span>
            <span><b style="border-color:#3b76f0"></b>calls</span>
            <span><b style="border-color:#e0559b"></b>reads/writes</span>
            <span><b style="border-color:#8a93ad;border-top-style:dashed"></b>view-call / dynamic</span>
        </div>
    </div>
    <div class="panel">
        <h2>Data <span class="muted">(select *)</span></h2>
        <div class="tablepick" id="tablepick"></div>
        <div class="rowscroll"><div id="rows" class="muted">Pick a table.</div></div>
    </div>
</div>

<script>
"use strict";
const $ = s => document.querySelector(s);
let MODEL = null, SEL = 0, NODES = {}, EDGES = [], OUT = {}, IN = {};

const SVGNS = 'http://www.w3.org/2000/svg';
const EDGE_COLOR = { renders:'#3bbf7a', redirects:'#e0a23b', 'dynamic-redirect':'#e0a23b',
    calls:'#3b76f0', instantiates:'#3b76f0', reads:'#e0559b', writes:'#e0559b',
    'rel-own':'#e0559b', 'rel-shared':'#e0559b', 'view-call':'#8a93ad', includes:'#8a93ad',
    'dynamic-call':'#8a93ad', external:'#e0a23b' };

function currentScope() {
    const url = $('#urlInput').value.trim();
    return url !== '' ? url : $('#instancePick').value;
}
async function j(u) { const r = await fetch(u, {headers:{'Accept':'application/json'}}); if(!r.ok) throw new Error((await r.json().catch(()=>({error:r.status}))).error||r.status); return r.json(); }

async function load() {
    const scope = encodeURIComponent(currentScope());
    $('#ribbon').innerHTML = '<span class="muted" style="padding:.5rem">Loading…</span>';
    try {
        const res = await j('/explore/graph?url=' + scope);
        MODEL = res.model;
        indexGraph();
        $('#freshness').textContent = 'hash ' + (MODEL.meta.codeHash||'').slice(0,8) + ' · ' +
            MODEL.meta.controlCount + ' controls · ' + (MODEL.meta.edgeCount||0) + ' edges · ' +
            (MODEL.meta.brokenCount||0) + ' broken';
        renderRibbon(); renderTables();
        SEL = 0; selectControl(0);
    } catch (e) {
        $('#ribbon').innerHTML = '<span class="muted" style="padding:.5rem">' + (e.message||e) + '</span>';
    }
}

function renderRibbon() {
    const r = $('#ribbon'); r.innerHTML = '';
    MODEL.controls.forEach((c, i) => {
        const el = document.createElement('div');
        el.className = 'ctrl'; el.tabIndex = -1; el.dataset.i = i;
        el.innerHTML = '<div class="name">' + esc(c.control) + '</div><div class="meta"><span class="lvl ' +
            esc(c.levelLabel) + '"></span>' + c.routeCount + ' route' + (c.routeCount===1?'':'s') +
            (c.hasController ? '' : ' · <span class="broken">no ctrl</span>') + '</div>';
        el.onclick = () => selectControl(i);
        r.appendChild(el);
    });
}
function selectControl(i) {
    if (!MODEL || !MODEL.controls[i]) return;
    SEL = i;
    document.querySelectorAll('.ctrl').forEach((e,k)=>e.classList.toggle('sel', k===i));
    const el = document.querySelector('.ctrl[data-i="'+i+'"]'); if (el){ el.focus(); el.scrollIntoView({inline:'nearest',block:'nearest'}); }
    const c = MODEL.controls[i];
    $('#methTitle').textContent = '· ' + c.control;
    let html = '<table><thead><tr><th>method</th><th>level</th><th>reach</th></tr></thead><tbody>';
    c.methods.forEach(m => {
        const id = 'route:' + c.control.toLowerCase() + '::' + m.method.toLowerCase();
        const n = NODES[id];
        const reach = n && n.reach ? n.reach : '';
        const shape = n && n.shape === 'json' ? ' · json' : '';
        html += '<tr class="methrow" data-route="'+esc(id)+'" style="cursor:pointer"><td>'+esc(m.method)+
            '</td><td><span class="badge">'+levelName(m.level)+'</span></td><td class="muted">'+esc(reach)+shape+'</td></tr>';
    });
    html += '</tbody></table>';
    $('#methods').innerHTML = html;
    document.querySelectorAll('#methods .methrow').forEach(tr => tr.onclick = () => selectRoute(tr.dataset.route));
    // draw the whole control's neighborhood by default
    drawGraphForControl(c);
    $('#inspector').innerHTML = '<span class="muted">Click a method to inspect its connections.</span>';
}

function indexGraph() {
    NODES = {}; EDGES = (MODEL.graph && MODEL.graph.edges) || []; OUT = {}; IN = {};
    ((MODEL.graph && MODEL.graph.nodes) || []).forEach(n => NODES[n.id] = n);
    EDGES.forEach(e => { (OUT[e.from] = OUT[e.from]||[]).push(e); (IN[e.to] = IN[e.to]||[]).push(e); });
}
function nodeCol(n){ // layered column: 0 views/callers, 1 route, 2 libs, 3 beans
    const k = n ? n.kind : '';
    if (k==='view') return 0;
    if (k==='route'||k==='custom-route'||k==='broken-link'||k==='missing-view'||k==='dynamic') return 1;
    if (k==='lib'||k==='libmethod'||k==='external') return 2;
    if (k==='bean') return 3;
    return 1;
}
function nodeColor(n){
    const k=n?n.kind:''; if(k==='broken-link'||k==='missing-view')return '#e0559b';
    if(k==='bean')return '#2a3b6a'; if(k==='view')return '#1c3a2c'; if(k==='libmethod'||k==='lib')return '#1c2c52';
    if(k==='route')return '#243056'; return '#1a2540';
}
function selectRoute(routeId){
    const focus = NODES[routeId]; if(!focus) return;
    // neighborhood: the route + its out-neighbors + its in-neighbors (views/callers)
    const set = {}; set[routeId]=focus;
    (OUT[routeId]||[]).forEach(e=>{ if(NODES[e.to]) set[e.to]=NODES[e.to]; });
    (IN[routeId]||[]).forEach(e=>{ if(NODES[e.from]) set[e.from]=NODES[e.from]; });
    const ids = Object.keys(set);
    const edges = EDGES.filter(e => set[e.from] && set[e.to] && (e.from===routeId||e.to===routeId));
    drawGraph(ids.map(id=>set[id]), edges, routeId);
    inspect(routeId);
    document.querySelectorAll('#methods .methrow').forEach(tr=>tr.style.background = tr.dataset.route===routeId?'rgba(59,118,240,0.15)':'');
}
function drawGraphForControl(c){
    const prefix = 'route:' + c.control.toLowerCase() + '::';
    const routeIds = c.methods.map(m=>prefix+m.method.toLowerCase()).filter(id=>NODES[id]);
    const set={}; routeIds.forEach(id=>{ set[id]=NODES[id];
        (OUT[id]||[]).forEach(e=>{ if(NODES[e.to]) set[e.to]=NODES[e.to]; });
        (IN[id]||[]).forEach(e=>{ if(NODES[e.from]&&NODES[e.from].kind==='view') set[e.from]=NODES[e.from]; }); });
    const edges = EDGES.filter(e=>set[e.from]&&set[e.to]);
    drawGraph(Object.values(set), edges, null);
    $('#graphTitle').textContent = '· ' + c.control + ' (' + edges.length + ' edges)';
}
function drawGraph(nodes, edges, focusId){
    const svg = $('#graph'); svg.innerHTML='';
    const W=1000, H=420, colX=[120,400,650,880], pad=34;
    const cols=[[],[],[],[]]; nodes.forEach(n=>cols[nodeCol(n)].push(n));
    const pos={};
    cols.forEach((list,ci)=>{ const gap=(H-2*pad)/Math.max(1,list.length);
        list.forEach((n,k)=>{ pos[n.id]={x:colX[ci], y:pad+gap*(k+0.5)}; }); });
    // column labels
    ['views / callers','routes','lib / services','data'].forEach((lbl,ci)=>{
        const t=document.createElementNS(SVGNS,'text'); t.setAttribute('x',colX[ci]); t.setAttribute('y',16);
        t.setAttribute('text-anchor','middle'); t.setAttribute('class','gcol-label'); t.textContent=lbl; svg.appendChild(t); });
    // edges
    edges.forEach(e=>{ const a=pos[e.from], b=pos[e.to]; if(!a||!b) return;
        const p=document.createElementNS(SVGNS,'path');
        const mx=(a.x+b.x)/2; p.setAttribute('d',`M${a.x} ${a.y} C ${mx} ${a.y}, ${mx} ${b.y}, ${b.x} ${b.y}`);
        p.setAttribute('fill','none'); p.setAttribute('stroke',EDGE_COLOR[e.kind]||'#8a93ad');
        p.setAttribute('stroke-width', e.confidence==='exact'?1.5:1);
        if(e.confidence!=='exact') p.setAttribute('stroke-dasharray','4 3');
        p.setAttribute('opacity','0.75');
        const tt=document.createElementNS(SVGNS,'title'); tt.textContent=`${e.kind} [${e.confidence}] ${e.evidence}`; p.appendChild(tt);
        svg.appendChild(p); });
    // nodes
    nodes.forEach(n=>{ const pp=pos[n.id]; if(!pp) return;
        const g=document.createElementNS(SVGNS,'g'); g.setAttribute('class','gnode'); g.style.cursor='pointer';
        const label=(n.label||n.id).replace(/^views\//,'').replace(/^bean:/,'');
        const w=Math.min(210, 30+label.length*6.2), r=document.createElementNS(SVGNS,'rect');
        r.setAttribute('x',pp.x-w/2); r.setAttribute('y',pp.y-11); r.setAttribute('width',w); r.setAttribute('height',22);
        r.setAttribute('rx',6); r.setAttribute('fill',nodeColor(n));
        r.setAttribute('stroke', n.id===focusId?'#fff':(n.broken?'#e0559b':'rgba(255,255,255,0.25)'));
        g.appendChild(r);
        const t=document.createElementNS(SVGNS,'text'); t.setAttribute('x',pp.x); t.setAttribute('y',pp.y+4);
        t.setAttribute('text-anchor','middle'); t.textContent=label.length>32?label.slice(0,31)+'…':label; g.appendChild(t);
        const tt=document.createElementNS(SVGNS,'title'); tt.textContent=n.id+(n.path?('  '+n.path):'')+(n.suggest?('  → '+n.suggest):''); g.appendChild(tt);
        g.onclick=()=>{ if(n.kind==='route') selectRoute(n.id); else inspect(n.id); };
        svg.appendChild(g); });
    setupPanZoom(svg);
}
function inspect(id){
    const n=NODES[id]; if(!n){ $('#inspector').innerHTML='<span class="muted">No detail.</span>'; return; }
    $('#insTitle').textContent='· '+(n.label||id);
    let h='';
    const row=(k,v)=>{ h+='<div class="row"><span class="k">'+esc(k)+':</span> '+v+'</div>'; };
    row('kind', esc(n.kind)+(n.shape?(' · '+esc(n.shape)):'')+(n.reach?(' · '+esc(n.reach)):''));
    if(n.path) row('file','<code>'+esc(n.path)+(n.line?(':'+n.line):'')+'</code>');
    if(n.broken) h+='<div class="row"><span class="brokenpill">broken</span>'+(n.suggest?(' did you mean <code>'+esc(n.suggest)+'</code>?'):'')+'</div>';
    const outs=(OUT[id]||[]), ins=(IN[id]||[]);
    if(ins.length){ const byKind={}; ins.forEach(e=>{ (byKind[e.kind]=byKind[e.kind]||[]).push(e); });
        h+='<div class="row"><span class="k">called by ('+ins.length+'):</span></div>';
        ins.slice(0,12).forEach(e=>{ h+='<div class="row" style="padding-left:.6rem">'+esc(labelOf(e.from))+' <span class="muted">['+e.kind+'] '+esc(e.evidence)+'</span></div>'; });
    }
    if(outs.length){ h+='<div class="row"><span class="k">uses ('+outs.length+'):</span></div>';
        outs.slice(0,16).forEach(e=>{ h+='<div class="row" style="padding-left:.6rem">'+esc(e.kind)+' → '+esc(labelOf(e.to))+' <span class="muted">'+esc(e.evidence||'')+'</span></div>'; });
    }
    if(!ins.length && !outs.length) h+='<div class="row muted">No static edges (orphan — possibly webhook/external/dynamic).</div>';
    $('#inspector').innerHTML=h;
}
function labelOf(id){ const n=NODES[id]; return n?(n.label||id):id.replace(/^file:/,''); }
function setupPanZoom(svg){
    let vb=[0,0,1000,420], drag=null;
    const apply=()=>svg.setAttribute('viewBox',vb.join(' '));
    svg.onmousedown=e=>{ drag={x:e.clientX,y:e.clientY,vb:vb.slice()}; };
    window.onmouseup=()=>drag=null;
    svg.onmousemove=e=>{ if(!drag)return; const s=vb[2]/svg.clientWidth;
        vb[0]=drag.vb[0]-(e.clientX-drag.x)*s; vb[1]=drag.vb[1]-(e.clientY-drag.y)*s; apply(); };
    svg.onwheel=e=>{ e.preventDefault(); const f=e.deltaY>0?1.1:0.9;
        const mx=vb[0]+vb[2]*(e.offsetX/svg.clientWidth), my=vb[1]+vb[3]*(e.offsetY/svg.clientHeight);
        vb[2]*=f; vb[3]*=f; vb[0]=mx-(mx-vb[0])*f; vb[1]=my-(my-vb[1])*f; apply(); };
}
function renderTables() {
    const p = $('#tablepick'); p.innerHTML = '';
    (MODEL.tables||[]).forEach(t => {
        const b = document.createElement('button'); b.textContent = t;
        b.onclick = () => { document.querySelectorAll('#tablepick button').forEach(x=>x.classList.remove('on')); b.classList.add('on'); loadRows(t,0); };
        p.appendChild(b);
    });
    $('#rows').textContent = 'Pick a table.';
}
async function loadRows(table, offset) {
    $('#rows').innerHTML = '<span class="muted">Loading…</span>';
    try {
        const d = await j('/explore/rows?url=' + encodeURIComponent(currentScope()) + '&table=' + encodeURIComponent(table) + '&offset=' + offset);
        if (!d.columns.length) { $('#rows').textContent = 'No columns.'; return; }
        let html = '<div class="muted" style="margin-bottom:.5rem">'+d.total+' row'+(d.total===1?'':'s')+' · showing '+d.rows.length+'</div><table><thead><tr>';
        d.columns.forEach(c => html += '<th>'+esc(c)+'</th>'); html += '</tr></thead><tbody>';
        d.rows.forEach(row => { html+='<tr>'; d.columns.forEach(c=>{ let v=row[c]; v = v==null?'':String(v); if(v.length>60)v=v.slice(0,60)+'…'; html+='<td title="'+esc(String(row[c]??''))+'">'+esc(v)+'</td>'; }); html+='</tr>'; });
        html += '</tbody></table>';
        $('#rows').innerHTML = html;
    } catch(e){ $('#rows').textContent = e.message||e; }
}
function levelName(l){ return {1:'ROOT',50:'ADMIN',100:'MEMBER',101:'PUBLIC'}[l]||l; }
function esc(s){ return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

$('#ribbon').addEventListener('keydown', e => {
    if (!MODEL) return;
    if (e.key==='ArrowRight'){ e.preventDefault(); selectControl(Math.min(SEL+1, MODEL.controls.length-1)); }
    else if (e.key==='ArrowLeft'){ e.preventDefault(); selectControl(Math.max(SEL-1,0)); }
    else if (e.key==='ArrowDown'||e.key==='Enter'){ e.preventDefault(); $('#methods').scrollIntoView({behavior:'smooth',block:'nearest'}); }
    else if (e.key==='Escape'){ $('#ribbon').focus(); }
});
$('#loadBtn').onclick = load;
$('#urlInput').addEventListener('keydown', e => { if(e.key==='Enter') load(); });
$('#instancePick').onchange = () => { $('#urlInput').value=''; load(); };
if (<?= $instances ? 'true' : 'false' ?>) load();
</script>
</body>
</html>
