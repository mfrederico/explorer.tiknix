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
    @media (max-width:820px){ .body{grid-template-columns:1fr} }
</style>
</head>
<body>
<header>
    <div class="brand">Architecture <span class="b">Explorer</span></div>
    <span class="chip" id="freshness">—</span>
    <div class="spacer"></div>
    <span class="who"><?= htmlspecialchars($email) ?></span>
    <a href="/sso/logout">Sign out</a>
</header>

<div class="scopebar">
    <label class="muted" style="font-size:0.85rem">Instance</label>
    <select id="instancePick">
        <?php foreach ($instances as $i): ?>
        <option value="<?= htmlspecialchars($i['slug']) ?>"><?= htmlspecialchars($i['name']) ?> (<?= htmlspecialchars($i['slug']) ?>)<?= $i['owned'] ? '' : ' · team' ?></option>
        <?php endforeach; ?>
    </select>
    <input id="urlInput" placeholder="…or paste an instance URL (/ = default)" value="<?= htmlspecialchars($initial) ?>">
    <button id="loadBtn">Explore</button>
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
        <h2>Data <span class="muted">(select *)</span></h2>
        <div class="tablepick" id="tablepick"></div>
        <div class="rowscroll"><div id="rows" class="muted">Pick a table.</div></div>
    </div>
</div>

<script>
"use strict";
const $ = s => document.querySelector(s);
let MODEL = null, SEL = 0;

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
        $('#freshness').textContent = 'hash ' + (MODEL.meta.codeHash||'').slice(0,8) + ' · ' + MODEL.meta.controlCount + ' controls';
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
    let html = '<table><thead><tr><th>method</th><th>level</th></tr></thead><tbody>';
    c.methods.forEach(m => { html += '<tr class="methrow"><td>'+esc(m.method)+'</td><td><span class="badge">'+levelName(m.level)+'</span></td></tr>'; });
    html += '</tbody></table>';
    $('#methods').innerHTML = html;
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
