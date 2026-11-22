// scripts/figma-export.js — exports pages of the running system as FigCraft-ready HTML (design/figma-export/).
// See design/figma-export/README.md. Needs: npm i -D playwright @fortawesome/fontawesome-free@6.4.0
//   PowerShell: $env:BASE="http://localhost/DRIVER-SCHEDULING-AND-MANAGEMENT-SYSTEM-FOR-UNIVERSITI-ISLAM-SELANGOR"; node scripts/figma-export.js
//
// Renders pages of the running system and rebuilds each as simple static HTML that FigCraft's
// import_html understands: flex rows/columns only, one padding value, solid hex colours,
// inline text styles, Font Awesome icons as inline SVG, images/charts as data: URIs.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const B = (process.env.BASE || 'http://127.0.0.1:8080').replace(/\/$/, '');
const OUT = process.argv[2] || path.join(__dirname, '..', 'design', 'figma-export');
const REPO = process.env.REPO || path.join(__dirname, '..');
const FA = path.join(path.dirname(require.resolve('@fortawesome/fontawesome-free/package.json')), 'svgs');
const PAGES = [
  // [file, user, pass, path, width]
  ['01-login', null, null, '/login.php?lang=en', 1440],
  ['02-admin-dashboard', 'admin', 'admin123', '/admin/dashboard.php', 1440],
  ['03-admin-vehicle-requests', 'admin', 'admin123', '/admin/vehicle_requests.php?tab=all&open=4', 1440],
  ['04-admin-assign-driver', 'admin', 'admin123', '/admin/assign_driver.php?request_id=' + (process.env.ASSIGN_ID || 2), 1440],
  ['05-admin-calendar', 'admin', 'admin123', '/admin/calendar.php', 1440],
  ['06-admin-drivers', 'admin', 'admin123', '/admin/drivers.php', 1440],
  ['07-admin-vehicles', 'admin', 'admin123', '/admin/vehicles.php', 1440],
  ['08-admin-reports', 'admin', 'admin123', '/admin/reports.php', 1440],
  ['09-staff-dashboard', 'stf001', 'staff001@uis', '/staff/dashboard.php', 1440],
  ['10-staff-request-vehicle', 'stf001', 'staff001@uis', '/staff/request_vehicle.php', 1440],
  ['11-hod-dashboard', 'hos002', 'hos002@uis', '/supervisor/dashboard.php', 1440],
  ['12-driver-dashboard', 'drv001', 'driver001@uis', '/driver/dashboard.php', 1440],
  ['13-staff-dashboard-mobile', 'stf001', 'staff001@uis', '/staff/dashboard.php', 390],
];

function iconSvg(name) {
  for (const dir of ['solid', 'regular', 'brands']) {
    const f = path.join(FA, dir, name + '.svg');
    if (fs.existsSync(f)) {
      const s = fs.readFileSync(f, 'utf8');
      const vb = (s.match(/viewBox="([^"]+)"/) || [])[1];
      const d = [...s.matchAll(/<path[^>]*d="([^"]+)"/g)].map(m => m[1]);
      if (vb && d.length) { return { vb, d }; }
    }
  }
  return null;
}

// Runs in the page: returns { html, height }
function convertPage(icons) {
  const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
  const cx = canvas.getContext('2d', { willReadFrequently: true });
  const cache = {};
  function rgba(c) {
    if (!c || c === 'transparent') { return [0, 0, 0, 0]; }
    if (cache[c]) { return cache[c]; }
    cx.clearRect(0, 0, 1, 1); cx.fillStyle = '#000'; cx.fillStyle = c; cx.fillRect(0, 0, 1, 1);
    const d = cx.getImageData(0, 0, 1, 1).data;
    const a = d[3] / 255;
    return (cache[c] = a ? [d[0], d[1], d[2], a] : [0, 0, 0, 0]);   // getImageData is not premultiplied
  }
  const hex = c => '#' + c.slice(0, 3).map(v => Math.round(v).toString(16).padStart(2, '0')).join('');
  function blend(c, bg) { const a = c[3]; return [0, 1, 2].map(i => c[i] * a + bg[i] * (1 - a)).concat(1); }
  function firstColor(s) { const m = s && s.match(/(rgba?\([^)]*\)|oklab\([^)]*\)|oklch\([^)]*\)|color\([^)]*\)|#[0-9a-f]{3,8})/i); return m ? m[1] : null; }
  const esc = t => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const px = v => Math.round(parseFloat(v) || 0);

  function visible(el, cs) {
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) { return false; }
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) { return false; }
    if (cs.position === 'absolute' && (cs.clip === 'rect(0px, 0px, 0px, 0px)' || r.width <= 1)) { return false; }
    if (el.closest('.sr-only, [aria-hidden="true"]:not(i)')) { return false; }
    return true;
  }
  function textStyle(cs, bg) {
    const col = rgba(cs.color);
    const st = { 'font-size': px(cs.fontSize) + 'px', 'font-weight': cs.fontWeight, color: hex(blend(col, bg)) };
    const lh = parseFloat(cs.lineHeight); if (lh) { st['line-height'] = Math.round(lh) + 'px'; }
    if (cs.textAlign === 'center') { st['text-align'] = 'center'; }
    return st;
  }
  const css = st => Object.entries(st).map(([k, v]) => k + ':' + v).join(';');
  function textOf(el, cs) {
    let t = el.innerText.replace(/\s+/g, ' ').trim();
    if (cs.textTransform === 'uppercase') { t = t.toUpperCase(); }
    return t;
  }
  function isTextLeaf(el) {
    // only inline/text descendants, no icons, images, inputs or block boxes
    for (const c of el.querySelectorAll('*')) {
      const ccs = getComputedStyle(c);
      if (!visible(c, ccs)) { continue; }
      if (/^(I|IMG|SVG|CANVAS|INPUT|SELECT|TEXTAREA|BUTTON)$/i.test(c.tagName)) { return false; }
      if (/block|flex|grid|table/.test(ccs.display) && c.innerText.trim()) { return false; }
      if (ccs.backgroundColor !== 'rgba(0, 0, 0, 0)' || parseFloat(ccs.borderTopWidth) > 0) { return false; }
    }
    return el.innerText.trim().length > 0;
  }
  function effBg(cs, bg) {
    let c = rgba(cs.backgroundColor);
    if (cs.backgroundImage && cs.backgroundImage !== 'none' && /gradient/.test(cs.backgroundImage)) {
      const f = firstColor(cs.backgroundImage); if (f) { c = rgba(f); }
    }
    return c[3] > 0 ? blend(c, bg) : null;
  }
  function border(cs, bg) {
    const w = parseFloat(cs.borderTopWidth);
    if (w > 0 && cs.borderTopStyle !== 'none') { return { w: Math.round(w), c: hex(blend(rgba(cs.borderTopColor), bg)) }; }
    const m = cs.boxShadow && cs.boxShadow.match(/(rgba?\([^)]*\)|oklab\([^)]*\)|oklch\([^)]*\)|color\([^)]*\)|#[0-9a-f]+)\s+0px\s+0px\s+0px\s+(\d+(?:\.\d+)?)px/i);
    if (m) { return { w: Math.max(1, Math.round(parseFloat(m[2]))), c: hex(blend(rgba(m[1]), bg)) }; }
    return null;
  }
  const slug = el => (el.tagName.toLowerCase() + '-' + ((el.className && typeof el.className === 'string' ? el.className.trim().split(/\s+/)[0] : '') || (el.id || 'box'))).replace(/[^a-z0-9-]/gi, '').slice(0, 40);

  // node object: {kind:'box'|'text'|'raw', style:{}, cls, kids:[], html, rect}
  function conv(el, bg) {
    const cs = getComputedStyle(el);
    if (!visible(el, cs)) { return null; }
    if (cs.position === 'absolute' || cs.position === 'fixed') {
      // decorative overlays have no text; badges and dots are dropped (the importer cannot place them)
      return null;
    }
    const rect = el.getBoundingClientRect();
    const tag = el.tagName;
    if (tag === 'SCRIPT' || tag === 'STYLE' || tag === 'TEMPLATE' || tag === 'DIALOG' && !el.open) { return null; }
    if (tag === 'I' && /\bfa-/.test(el.className)) {
      const name = [...el.classList].map(c => c.replace(/^fa-/, '')).find(n => icons[n]);
      if (!name) { return null; }
      const ic = icons[name], size = Math.max(10, Math.round(parseFloat(cs.fontSize)));
      const fill = hex(blend(rgba(cs.color), bg));
      const [, , vw, vh] = ic.vb.split(/\s+/).map(Number);
      const w = Math.round(size * vw / vh);
      return { kind: 'raw', rect, html: `<svg width="${w}" height="${size}" viewBox="${ic.vb}" xmlns="http://www.w3.org/2000/svg">${ic.d.map(d => `<path fill="${fill}" d="${d}"/>`).join('')}</svg>` };
    }
    if (tag === 'IMG' || tag === 'CANVAS') {
      let src = '';
      try {
        if (tag === 'CANVAS') { src = el.toDataURL('image/png'); }
        else { const c = document.createElement('canvas'); c.width = el.naturalWidth; c.height = el.naturalHeight; c.getContext('2d').drawImage(el, 0, 0); src = c.toDataURL('image/png'); }
      } catch (e) { src = el.currentSrc || el.src || ''; }   // cross-origin: the exporter inlines it from disk
      const st = { width: Math.round(rect.width) + 'px', height: Math.round(rect.height) + 'px' };
      if (px(cs.borderTopLeftRadius)) { st['border-radius'] = px(cs.borderTopLeftRadius) + 'px'; }
      return { kind: 'raw', rect, html: `<img src="${src}" alt="${esc(el.alt || tag.toLowerCase())}" style="${css(st)}">` };
    }
    if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
      if (el.type === 'hidden' || el.type === 'radio' || el.type === 'checkbox') { return null; }
      const ph = tag === 'SELECT' ? (el.options[el.selectedIndex] || {}).text || '' : (el.value || el.placeholder || '');
      const own = effBg(cs, bg) || bg, b = border(cs, own);
      const st = { 'background-color': hex(own), 'border-radius': px(cs.borderTopLeftRadius) + 'px', height: Math.round(rect.height) + 'px' };
      if (b) { st['border-color'] = b.c; }
      return { kind: 'raw', rect, html: `<input placeholder="${esc(ph)}" style="${css(st)}">` };
    }
    const own = effBg(cs, bg);
    const here = own || bg;
    const b = border(cs, here);
    const radius = px(cs.borderTopLeftRadius);
    let pad = px(cs.paddingTop) || px(cs.paddingLeft);
    if (isTextLeaf(el) && !own && !b) {
      return { kind: 'text', rect, style: textStyle(cs, here), text: textOf(el, cs) };
    }
    // children: elements and loose text
    const kids = [];
    for (const n of el.childNodes) {
      if (n.nodeType === 3) {
        const t = n.textContent.replace(/\s+/g, ' ').trim();
        if (t) {
          const range = document.createRange(); range.selectNodeContents(n);
          const r = range.getBoundingClientRect();
          if (r.width > 0) { kids.push({ kind: 'text', rect: r, style: textStyle(cs, here), text: cs.textTransform === 'uppercase' ? t.toUpperCase() : t }); }
        }
      } else if (n.nodeType === 1) {
        const k = conv(n, here); if (k) { kids.push(k); }
      }
    }
    if (!kids.length && !own && !b) { return null; }
    const node = { kind: 'box', rect, cls: slug(el), style: {}, kids };
    if (own) { node.style['background-color'] = hex(own); }
    if (b) { node.style['border'] = b.w + 'px solid ' + b.c; node.style['border-color'] = b.c; node.style['border-width'] = b.w + 'px'; }
    if (radius) { node.style['border-radius'] = radius + 'px'; }
    if (pad) { node.style['padding'] = pad + 'px'; }
    // collapse empty wrappers
    if (!own && !b && !pad && kids.length === 1 && kids[0].kind === 'box') { return kids[0]; }
    return node;
  }

  // layout: group kids into rows by geometry; set widths; gaps from geometry
  function layout(node) {
    if (node.kind !== 'box') { return node; }
    node.kids.forEach(layout);
    const ks = node.kids.slice().sort((a, b) => a.rect.top - b.rect.top || a.rect.left - b.rect.left);
    const rows = [];
    for (const k of ks) {
      const row = rows.find(r => k.rect.top < r.bottom - 2 && k.rect.bottom > r.top + 2);
      if (row) { row.items.push(k); row.top = Math.min(row.top, k.rect.top); row.bottom = Math.max(row.bottom, k.rect.bottom); }
      else { rows.push({ top: k.rect.top, bottom: k.rect.bottom, items: [k] }); }
    }
    rows.forEach(r => r.items.sort((a, b) => a.rect.left - b.rect.left));
    const gapOf = (arr, horiz) => {
      const gs = []; for (let i = 1; i < arr.length; i++) { gs.push(horiz ? arr[i].left - arr[i - 1].right : arr[i].top - arr[i - 1].bottom); }
      const g = gs.length ? Math.round(gs.reduce((a, b) => a + b, 0) / gs.length) : 0; return Math.max(0, g);
    };
    const mkRow = (items) => {
      const rect = { left: Math.min(...items.map(i => i.rect.left)), right: Math.max(...items.map(i => i.rect.right)), top: Math.min(...items.map(i => i.rect.top)), bottom: Math.max(...items.map(i => i.rect.bottom)) };
      items.forEach(i => { i.fixedWidth = Math.round(i.rect.width); });
      return { kind: 'box', cls: 'row', rect, style: { display: 'flex', 'flex-direction': 'row', gap: gapOf(items.map(i => i.rect), true) + 'px' }, kids: items };
    };
    if (rows.length === 1 && rows[0].items.length > 1) {
      const r = mkRow(rows[0].items); node.style.display = 'flex'; node.style['flex-direction'] = 'row'; node.style.gap = r.style.gap; node.kids = r.kids;
    } else {
      node.kids = rows.map(r => r.items.length > 1 ? mkRow(r.items) : r.items[0]);
      node.style.display = 'flex'; node.style['flex-direction'] = 'column';
      node.style.gap = gapOf(rows.map(r => ({ top: r.top, bottom: r.bottom })), false) + 'px';
      // children narrower than the content box keep their width
      const inner = node.rect.width - 2 * (parseInt(node.style.padding) || 0);
      node.kids.forEach(k => { if (k.rect.width < inner - 4) { k.fixedWidth = Math.round(k.rect.width); } });
    }
    return node;
  }

  function ser(n) {
    if (n.kind === 'raw') { return n.html; }
    if (n.kind === 'text') {
      const st = Object.assign({}, n.style); if (n.fixedWidth) { st.width = Math.ceil(n.fixedWidth * 1.12 + 4) + 'px'; }
      return `<span style="${css(st)}">${esc(n.text)}</span>`;
    }
    const st = Object.assign({}, n.style); if (n.fixedWidth) { st.width = n.fixedWidth + 'px'; }
    if (!n.kids.length) { st.width = Math.round(n.rect.width) + 'px'; st.height = Math.round(n.rect.height) + 'px'; }
    return `<div class="${n.cls}" style="${css(st)}">${n.kids.map(ser).join('')}</div>`;
  }

  const pageBg = rgba(getComputedStyle(document.body).backgroundColor);
  const root = conv(document.body, pageBg[3] ? pageBg : [255, 255, 255, 1]);
  root.style['background-color'] = hex(pageBg[3] ? pageBg : [255, 255, 255, 1]);
  layout(root);
  const count = (n) => n.kind === 'box' ? 1 + n.kids.reduce((a, k) => a + count(k), 0) : 1;
  const depth = (n) => n.kind === 'box' ? 1 + Math.max(0, ...n.kids.map(depth)) : 1;
  return { html: ser(root), height: Math.ceil(document.documentElement.scrollHeight), nodes: count(root), depth: depth(root) };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch();
  const index = [];
  for (const [file, user, pass, p, width] of PAGES) {
    const ctx = await b.newContext({ viewport: { width, height: 900 }, timezoneId: 'Asia/Kuala_Lumpur' });
    await ctx.addInitScript(() => { try { localStorage.setItem('uis-theme', 'light'); } catch (e) {} });
    const page = await ctx.newPage();
    if (user) {
      await page.goto(B + '/login.php?lang=en');
      await page.fill('#username', user); await page.fill('#password', pass);
      await Promise.all([page.waitForNavigation(), page.click('#loginBtn')]);
    } else {
      await page.goto(B + '/login.php?lang=en');
    }
    await page.goto(B + p); await page.waitForTimeout(1200);
    // sticky bars follow the viewport; lay them out in the page flow instead
    await page.evaluate(() => { document.querySelectorAll('*').forEach(e => { if (getComputedStyle(e).position === 'sticky') { e.style.position = 'static'; } }); });
    await page.waitForTimeout(200);
    const names = await page.evaluate(() => [...new Set([...document.querySelectorAll('i[class*="fa-"]')].flatMap(i => [...i.classList].map(c => c.replace(/^fa-/, ''))))]);
    const icons = {}; for (const n of names) { const s = iconSvg(n); if (s) { icons[n] = s; } }
    const title = (await page.title()).split('|')[0].trim();
    const r = await page.evaluate(convertPage, icons);
    // images that could not be read in the page: inline them from the repository
    r.html = r.html.replace(/src="(https?:\/\/[^"]+?\/(assets\/[^"?]+))[^"]*"/g, (m, url, rel) => {
      const f = path.join(REPO, rel);
      if (!fs.existsSync(f)) { return m; }
      const ext = path.extname(f).slice(1).replace('jpg', 'jpeg');
      return `src="data:image/${ext};base64,${fs.readFileSync(f).toString('base64')}"`;
    });
    const doc = `<!DOCTYPE html>\n<html lang="en"><head><meta charset="utf-8"><title>UIS · ${title}${width < 500 ? ' (mobile)' : ''}</title></head>\n<body style="margin:0">${r.html}</body></html>\n`;
    fs.writeFileSync(path.join(OUT, file + '.html'), doc);
    index.push({ file: file + '.html', title, width, height: r.height, nodes: r.nodes, depth: r.depth });
    console.log(file, 'nodes', r.nodes, 'depth', r.depth, 'height', r.height, Math.round(doc.length / 1024) + 'KB');
    await ctx.close();
  }
  fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify(index, null, 2));
  await b.close();
})();
