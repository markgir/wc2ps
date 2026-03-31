/* ── WC → PS Migration Wizard — app.js ──────────────────────────────────── */

'use strict';

// ── State ─────────────────────────────────────────────────────────────────────

// Session: stable within a browser tab, derived from a fixed key
// Persisted in sessionStorage so it survives soft reloads
const S = {
  session:      sessionStorage.getItem('wc2ps_session') || (() => {
    const s = 'sess_' + Math.random().toString(36).slice(2, 10);
    sessionStorage.setItem('wc2ps_session', s);
    return s;
  })(),
  wcConn:       null,
  psConn:       null,
  analysis:     null,
  logOffset:    0,
  logFilter:    'all',    // all|info|success|warning|error|sql
  autoScroll:   true,
  pollTimer:    null,
  logTimer:     null,
  migrating:    false,
};

// Collect all DB params from the form
function dbParams() {
  return {
    wc_host:   v('wc-host'),   wc_port:   v('wc-port'),
    wc_db:     v('wc-db'),     wc_user:   v('wc-user'),
    wc_pass:   v('wc-pass'),   wc_prefix: v('wc-prefix'),
    ps_host:   v('ps-host'),   ps_port:   v('ps-port'),
    ps_db:     v('ps-db'),     ps_user:   v('ps-user'),
    ps_pass:   v('ps-pass'),   ps_prefix: v('ps-prefix'),
    ps_id_lang: v('ps-id-lang'), ps_id_shop: v('ps-id-shop'),
    session:   S.session,
  };
}

// ── DOM helpers ───────────────────────────────────────────────────────────────

const $  = id => document.getElementById(id);
const $$ = sel => document.querySelectorAll(sel);
const v  = id => $(`${id}`) ? $(`${id}`).value.trim() : '';
const show = id => $(id)?.classList.remove('hidden');
const hide = id => $(id)?.classList.add('hidden');
const html = (id, h) => { if ($(id)) $(id).innerHTML = h; };

function setStep(n) {
  // setStep(n) maps to goToSlide(n-1)
  goToSlide(n - 1);
}

// ── Slide navigation ─────────────────────────────────────────────────────────

const SLIDE_IDS = ['connections', 'analyse', 'mapping', 'migrate', 'images', 'done'];
let currentSlide = 0;

function goToSlide(idx) {
  idx = Math.max(0, Math.min(idx, SLIDE_IDS.length - 1));
  currentSlide = idx;
  const track = $('slide-track');
  // 6 slides: each occupies 100/6 % of track → shift by idx * 100/6
  if (track) track.style.transform = `translateX(-${idx * (100 / SLIDE_IDS.length)}%)`;

  // Auto-detect paths when entering the images slide (only if not already filled)
  if (SLIDE_IDS[idx] === 'images') {
    const wcInput = $('wc-uploads-path');
    if (!wcInput || !wcInput.value) detectImagePaths();
  }

  // Update step nav
  $$('.snav-item').forEach((el, i) => {
    el.classList.remove('active','done');
    if (i < idx)  el.classList.add('done');
    if (i === idx) el.classList.add('active');
  });

  // Scroll slide to top
  const slide = $('slide-' + idx);
  if (slide) slide.scrollTop = 0;
}

// Legacy compat: showCard('migrate') → goToSlide(3) etc.
const CARD_TO_SLIDE = { connections:0, analyse:1, mapping:2, migrate:3, images:4, done:5 };
function showCard(id) {
  const idx = CARD_TO_SLIDE[id] ?? 0;
  goToSlide(idx);
}

// ── API ───────────────────────────────────────────────────────────────────────

async function api(action, extra = {}) {
  const body = { action, ...extra };
  const r = await fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const data = await r.json();
  if (!data.ok) throw new Error(data.error || 'API error');
  return data;
}

// ── Step 1 — Connections ──────────────────────────────────────────────────────

async function testConnection(side) {
  const prefix = side === 'wc' ? v('wc-prefix') || 'wp_' : v('ps-prefix') || 'ps_';
  const badge  = $(`${side}-status`);
  badge.className = 'conn-badge testing';
  badge.textContent = '⟳ testing…';

  try {
    const data = await api('test_connection', {
      side,
      host:   v(`${side}-host`),
      port:   v(`${side}-port`),
      db:     v(`${side}-db`),
      user:   v(`${side}-user`),
      pass:   v(`${side}-pass`),
      prefix,
    });

    badge.className = 'conn-badge ok';
    badge.textContent = '✓ connected';

    if (side === 'wc') {
      S.wcConn = data;
      $('wc-info').textContent =
        `WooCommerce ${data.wc_version} · ${data.tables} tables · ${data.site_url}`;
      $('wc-info').className = '';
      // Derive a stable session from the DB name so it survives page reloads
      const dbName = v('wc-db').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 20);
      if (dbName) {
        const stableSession = 'db_' + dbName;
        S.session = stableSession;
        sessionStorage.setItem('wc2ps_session', stableSession);
      }
    } else {
      S.psConn = data;
      $('ps-info').textContent =
        `PrestaShop ${data.ps_version} · lang ${data.default_lang} · shop ${data.default_shop}`;
      $('ps-info').className = '';
      // Auto-fill lang + shop from PS
      if ($('ps-id-lang')) $('ps-id-lang').value = data.default_lang;
      if ($('ps-id-shop')) $('ps-id-shop').value = data.default_shop;
    }

    if (S.wcConn && S.psConn) {
      show('btn-next-1');
    }
    // Auto-save credentials after each successful connection test
    saveCredentials();
  } catch (e) {
    badge.className = 'conn-badge err';
    badge.textContent = '✗ error';
    alert(e.message);
  }
}

// ── Step 2 — Analysis ─────────────────────────────────────────────────────────

async function runAnalysis() {
  setStep(2);
  showCard('analyse');
  $('btn-analyse').disabled = true;
  $('btn-analyse').innerHTML = '<span class="spin"></span> Analysing…';

  try {
    const data = await api('analyse', dbParams());
    S.analysis = data.analysis;
    renderAnalysis(data.analysis);
    renderMapping(data.analysis);
    goToSlide(1);  // go to analysis slide
  } catch (e) {
    alert('Analysis failed: ' + e.message);
  } finally {
    $('btn-analyse').disabled = false;
    $('btn-analyse').innerHTML = '🔍 Re-analyse';
  }
}

function renderAnalysis(a) {
  const wc = a.wc; const ps = a.ps;

  html('info-products',    wc.counts.products    ?? '?');
  html('info-variations',  wc.counts.variations  ?? '?');
  html('info-categories',  wc.counts.categories  ?? '?');
  html('info-attributes',  wc.counts.attributes  ?? '?');
  html('info-images',      wc.counts.images      ?? '?');
  html('info-wc-version',  `WC ${wc.versions.woocommerce} / WP ${wc.versions.wordpress}`);
  html('info-ps-version',  `PS ${ps.version}`);
  html('info-ps-lang',     ps.default_lang);
  html('info-ps-shop',     ps.default_shop);

  // Issues
  const allIssues = [...(wc.issues || []), ...(ps.issues || [])];
  if (allIssues.length) {
    html('analysis-issues',
      allIssues.map(i =>
        `<div class="issue ${i.level}">
           <span>${i.level === 'error' ? '✗' : i.level === 'warning' ? '⚠' : 'ℹ'}</span>
           <span>${esc(i.msg)}</span>
         </div>`
      ).join('')
    );
    show('analysis-issues');
  } else {
    hide('analysis-issues');
  }

  // Table status panel
  const tableMap = wc.tables_found || {};
  const wcPrefix = S.analysis?.wc?.versions ? 'wp_' : 'wp_';
  html('table-status',
    Object.keys(tableMap).length
      ? Object.entries(tableMap).map(([t, found]) =>
          `<div style="display:flex;align-items:center;gap:8px;padding:3px 0;font-family:var(--mono);font-size:.78rem">
             <span style="color:${found ? 'var(--green)' : 'var(--red)'}">${found ? '✓' : '✗'}</span>
             <span style="color:${found ? 'var(--text1)' : 'var(--red)'}">${esc(t)}</span>
             ${!found ? '<span style="color:var(--amber);font-size:.72rem">[missing — optional]</span>' : ''}
           </div>`
        ).join('')
      : '<span style="color:var(--text2);font-size:.8rem">Run analysis to see table status</span>'
  );

  // Sample products
  if (wc.sample?.length) {
    html('sample-products',
      wc.sample.map(p =>
        `<div class="sample-card">
           <div class="s-title">${esc(p.title)}</div>
           <div class="s-row"><span>Type</span><span class="s-val">${esc(p.type)}</span></div>
           <div class="s-row"><span>Price</span><span class="s-val">${esc(p.price || '—')}</span></div>
           <div class="s-row"><span>SKU</span><span class="s-val">${esc(p.sku || '—')}</span></div>
           <div class="s-row"><span>Stock</span><span class="s-val">${esc(String(p.stock ?? '—'))}</span></div>
           <div class="s-row"><span>Image</span><span class="s-val">${p.has_image ? '✓' : '—'}</span></div>
         </div>`
      ).join('')
    );
  }
}

// ── Step 3 — Mapping ──────────────────────────────────────────────────────────

function renderMapping(a) {
  const wc = a.wc;

  // Field coverage table
  const keys = wc.meta_keys || {};
  const rows = Object.entries(keys).map(([k, info]) => {
    const cov = info.coverage;
    const pct = Math.min(100, cov);
    const destKey = {
      '_regular_price': 'price',
      '_sku': 'reference',
      '_weight': 'weight',
      '_length': 'depth',
      '_width': 'width',
      '_height': 'height',
      '_stock': 'stock_available.quantity',
      '_stock_quantity': 'stock_available.quantity',
      '_thumbnail_id': 'image (cover)',
      '_product_image_gallery': 'image (gallery)',
    }[k] || '—';
    const std = info.standard
      ? '<span class="chip blue">standard</span>'
      : '<span class="chip purple">custom</span>';
    return `<tr>
      <td><span class="mono">${esc(k)}</span></td>
      <td>${std}</td>
      <td>
        <span class="cov-bar" style="width:${pct}px"></span>
        <span style="margin-left:6px;color:var(--text1)">${cov}%</span>
      </td>
      <td><span class="mono" style="color:var(--green)">${esc(destKey)}</span></td>
    </tr>`;
  }).join('');

  html('mapping-table-body', rows || '<tr><td colspan="4" style="color:var(--text2);padding:12px">No meta keys found</td></tr>');

  // Category preview
  const cats = Object.values(wc.categories || {}).slice(0, 8);
  html('category-preview',
    cats.length
      ? cats.map(c => `<div style="display:flex;align-items:center;gap:6px;padding:3px 0;font-size:.8rem">
          <span style="color:var(--text2);font-family:var(--mono)">→</span>
          <span>${esc(c.name)}</span>
          <span style="color:var(--text2);font-size:.72rem">(${c.count} products)</span>
        </div>`).join('') +
        (Object.keys(wc.categories || {}).length > 8
          ? `<div style="color:var(--text2);font-size:.75rem;margin-top:4px">… and ${Object.keys(wc.categories).length - 8} more</div>`
          : '')
      : '<span style="color:var(--text2)">No categories found</span>'
  );

  // Attribute preview
  const attrs = Object.values(wc.attributes || {});
  html('attribute-preview',
    attrs.length
      ? attrs.map(a =>
          `<div style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:.8rem">
             <span class="chip blue">${esc(a.label)}</span>
             <span style="color:var(--text2);font-size:.72rem">${a.term_count} values</span>
           </div>`
        ).join('')
      : '<span style="color:var(--text2)">No global attributes found</span>'
  );
}

// ── Step 4 — Migrate ──────────────────────────────────────────────────────────

function revealMigrationCard() {
  goToSlide(3);
}

function startMigration() {
  const opts = {
    ...dbParams(),
    migrate_categories: $('opt-cats')?.checked ?? true,
    migrate_attributes: $('opt-attrs')?.checked ?? true,
    migrate_products:   $('opt-prods')?.checked ?? true,
    migrate_images:     $('opt-images')?.checked ?? false,
    ps_root_path:       v('ps-root-path'),
    batch_size:         parseInt(v('batch-size') || '20'),
  };

  // Card already shown by revealMigrationCard() — just update state
  setStep(4);
  S.migrating    = true;
  S.logOffset    = 0;
  S.totalQueries = 0;
  S.startTime    = Date.now();
  // Prove terminal is working
  logLine({ level:'info', message:'[CLIENT] Migração iniciada. Session: ' + S.session, ts: new Date().toTimeString().slice(0,8), step:'init' });

  $('btn-start').disabled = true;
  $('btn-start').innerHTML = '<span class="spin"></span> Running…';
  hide('btn-reset');

  clearInterval(S.pollTimer);
  clearInterval(S.logTimer);

  S.lastOpts = opts;  // save for resume after pause/stop
  // Start polling FIRST — before the migration API call
  // so we catch log entries from categories/attributes immediately
  pollLog();
  S.logTimer = setInterval(() => pollLog(), 600);
  // Small delay so first poll completes before migration starts
  setTimeout(() => doMigrationStep(opts), 200);
}

async function doMigrationStep(opts) {
  try {
    const data = await api('start', opts);
    // Confirm session from server (in case of discrepancy)
    if (data.progress?.session) {
      S.session = data.progress.session;
      sessionStorage.setItem('wc2ps_session', S.session);
    }
    handleProgress(data.progress, opts);
  } catch (e) {
    logLine({ level: 'error', message: 'API error: ' + e.message, ts: '--:--:--', step: 'api' });
    $('btn-start').disabled = false;
    $('btn-start').textContent = 'Retry';
    S.migrating = false;
  }
}

async function continueStep(opts) {
  try {
    const data = await api('step', opts);
    // Sync session from server response
    if (data.progress?.session && data.progress.session !== S.session) {
      console.warn('[wc2ps] Session mismatch: client=' + S.session + ' server=' + data.progress.session + ' → syncing');
      S.session = data.progress.session;
      sessionStorage.setItem('wc2ps_session', S.session);
    }
    handleProgress(data.progress, opts);
  } catch (e) {
    logLine({ level: 'error', message: 'API error: ' + e.message, ts: '--:--:--', step: 'api' });
    S.migrating = false;
  }
}

function handleProgress(p, opts) {
  renderProgress(p);

  if (p.status === 'completed') {
    S.migrating = false;
    clearInterval(S.logTimer);
    pollLog();
    $('btn-start').disabled = true;
    $('btn-start').innerHTML = '✓ Concluído';
    hide('btn-pause');
    hide('btn-stop');
    show('btn-reset');
    // Populate done slide stats
    html('done-products', p.done_products ?? 0);
    html('done-cats',     p.done_categories ?? 0);
    html('done-attrs',    p.done_attrs ?? 0);
    html('done-errors',   p.errors?.length ?? 0);
    setTimeout(() => goToSlide(4), 800);  // go to images slide  // → images slide

  } else if (p.status === 'paused') {
    S.migrating = false;
    clearInterval(S.logTimer);
    pollLog();
    $('btn-start').disabled = false;
    $('btn-start').innerHTML = '▶ Retomar';
    $('btn-start').onclick = () => resumeMigration(opts);
    // Restore pause button state
    const bp = $('btn-pause');
    if (bp) { bp.innerHTML = '⏸ Em pausa'; bp.disabled = true; }
    // Restore stop button state (may have been spinning)
    const bs = $('btn-stop');
    if (bs) { bs.innerHTML = '⏹ Parar'; bs.disabled = false; }
    show('btn-stop');
    show('btn-reset');

  } else if (p.status === 'stopped') {
    S.migrating = false;
    clearInterval(S.logTimer);
    pollLog();
    $('btn-start').disabled = false;
    $('btn-start').innerHTML = '▶ Retomar';
    $('btn-start').onclick = () => resumeMigration(opts);
    // Restore button states before hiding
    const bp2 = $('btn-pause');
    const bs2 = $('btn-stop');
    if (bp2) { bp2.innerHTML = '⏸ Pausar'; bp2.disabled = false; }
    if (bs2) { bs2.innerHTML = '⏹ Parar';  bs2.disabled = false; }
    hide('btn-pause');
    hide('btn-stop');
    show('btn-reset');

  } else if (p.status === 'error') {
    S.migrating = false;
    clearInterval(S.logTimer);
    pollLog();
    $('btn-start').disabled = false;
    $('btn-start').innerHTML = '↺ Tentar novamente';
    $('btn-start').onclick = () => continueStep(opts);
    hide('btn-pause');
    hide('btn-stop');
    show('btn-reset');

  } else if (p.status === 'running') {
    // Show pause/stop buttons while running
    show('btn-pause');
    show('btn-stop');
    $('btn-pause').disabled = false;
    $('btn-pause').innerHTML = '⏸ Pausar';
    // Poll immediately before next step request to catch any pending logs
    pollLog();
    setTimeout(() => continueStep(opts), 150);
  }
}

function renderProgress(p) {
  const cats  = pct(p.done_categories,  p.total_categories);
  const attrs = pct(p.done_attrs,       p.total_attrs);
  const prods = pct(p.done_products + (p.skipped_products||0), p.total_products);

  setBar('prog-categories', cats);
  setBar('prog-attributes', attrs);
  setBar('prog-products',   prods);

  html('stat-done',    p.done_products    ?? 0);
  html('stat-skipped', p.skipped_products ?? 0);
  html('stat-errors',  p.errors?.length   ?? 0);
  html('stat-cats',    p.done_categories  ?? 0);
  html('stat-attrs',   p.done_attrs       ?? 0);
  html('stat-step',    p.step ?? '—');

  if (p.log_stats) {
    // Accumulate queries across calls (each call resets the logger counter)
    S.totalQueries = (S.totalQueries || 0) + (p.log_stats.queries ?? 0);
    S.startTime    = S.startTime || Date.now();
    html('stat-queries', S.totalQueries);
    html('stat-elapsed', Date.now() - (S.startTime || Date.now()));
  }

  // Errors
  if (p.errors?.length) {
    html('error-list',
      p.errors.slice(-20).map(e =>
        `<div style="padding:4px 0;border-bottom:1px solid var(--border);font-size:.76rem">
           <span style="color:var(--red);font-family:var(--mono)">WC#${e.wc_id ?? '?'}</span>
           <span style="color:var(--text1);margin-left:6px">${esc(e.title || '').slice(0,50)}</span>
           <div style="color:var(--text2);font-size:.72rem;margin-top:1px">${esc(e.message.slice(0,120))}</div>
         </div>`
      ).join('')
    );
    show('error-section');
  }
}

function setBar(id, value) {
  const el = $(id);
  if (!el) return;
  const fill = el.querySelector('.prog-fill');
  const lbl  = el.querySelector('.prog-val');
  if (fill) fill.style.width = value + '%';
  if (lbl)  lbl.textContent  = Math.round(value) + '%';
}

function pct(done, total) {
  if (!total || total <= 0) return 0;
  return Math.min(100, Math.round((done / total) * 100));
}

// ── Log polling ───────────────────────────────────────────────────────────────

const LEVEL_ORDER = ['debug','info','success','warning','error','sql'];

async function pollLog() {
  try {
    const url = `api.php?action=log&session=${encodeURIComponent(S.session)}&offset=${S.logOffset}`;
    const resp = await fetch(url);
    
    // Check for non-JSON response (server error)
    const ct = resp.headers.get('content-type') || '';
    if (!ct.includes('json')) {
      const text = await resp.text();
      logLine({ level:'error', message:'Log poll: servidor devolveu HTML em vez de JSON. Verifica api.php.', ts:'--:--:--', step:'poll' });
      logLine({ level:'debug', message: text.slice(0, 200), ts:'--:--:--', step:'poll' });
      return;
    }

    const data = await resp.json();
    if (!data.ok) {
      logLine({ level:'warning', message: 'Log poll error: ' + (data.error||'?'), ts:'--:--:--', step:'poll' });
      return;
    }

    // Show server-side diagnostics on first poll
    // Show server diagnostic on first poll (offset=0)
    if (S.logOffset === 0 && data.debug_session) {
      const fileStatus = data.debug_file_exists
        ? `✓ (${(data.debug_file_size/1024).toFixed(0)}KB)`
        : data.debug_dir_write ? '— ainda não criado' : '✗ dir sem escrita!';
      logLine({ level: data.debug_dir_write ? 'info' : 'error', message:
        `[SERVER v${data.version||'?'}] session=${data.debug_session} ` +
        `log=${fileStatus}`,
        ts:'--:--:--', step:'diag'
      });
    }

    if (!data.entries?.length) return;

    S.logOffset = data.next_offset;
    const term  = $('terminal');
    if (!term) return;

    for (const e of data.entries) {
      if (S.logFilter !== 'all' && e.level !== S.logFilter) continue;
      logLine(e, term);
    }
  } catch (err) {
    // Show fetch errors in terminal instead of swallowing them
    const term = $('terminal');
    if (term) logLine({ level:'error', message:'Log poll exception: ' + err.message, ts:'--:--:--', step:'poll' }, term);
  }
}

function logLine(e, term) {
  term = term || $('terminal');
  if (!term) return;

  const line = document.createElement('div');
  line.className = `log-line lvl-${e.level}`;
  line.innerHTML =
    `<span class="log-ts">${esc(e.ts||'')}</span>` +
    `<span class="log-step">${esc(e.step||'')}</span>` +
    `<span class="log-lvl ${e.level}">[${(e.level||'').toUpperCase().padEnd(7)}]</span>` +
    `<span class="log-msg">${esc(e.message||'')}</span>`;
  term.appendChild(line);

  if (e.ctx && Object.keys(e.ctx).length) {
    const ctx = document.createElement('div');
    ctx.className = 'log-ctx';
    ctx.textContent = JSON.stringify(e.ctx);
    term.appendChild(ctx);
  }

  if (S.autoScroll) term.scrollTop = term.scrollHeight;
}

function filterLog(level) {
  S.logFilter = level;
  $$('.log-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.lvl === level));
  const term = $('terminal');
  if (!term) return;
  // Walk lines: hide log-ctx if its preceding log-line is hidden
  let lastLineVisible = true;
  term.querySelectorAll('.log-line, .log-ctx').forEach(el => {
    if (el.classList.contains('log-line')) {
      lastLineVisible = (level === 'all') || el.classList.contains('lvl-' + level);
      el.style.display = lastLineVisible ? '' : 'none';
    } else {
      // log-ctx: visible only if preceding log-line is visible
      el.style.display = lastLineVisible ? '' : 'none';
    }
  });
}

function clearTerminal() {
  const term = $('terminal');
  if (term) term.innerHTML = '';
  S.logOffset = 0;
}

// ── Image import ─────────────────────────────────────────────────────────────

function updateImgMode() {
  // Show/hide WC uploads path field based on mode (only needed for copy)
  const mode = document.querySelector('input[name="img-mode"]:checked')?.value
            || document.querySelector('input[name="img-strategy"]:checked')?.value
            || 'http';
  const wcGroup = $('wc-uploads-group');
  if (wcGroup) wcGroup.style.display = (mode === 'local' || mode === 'copy') ? '' : 'none';
}

async function detectImagePaths() {
  const btn = $('btn-detect-paths');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spin"></span> A detectar…'; }

  try {
    const resp = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'detect_paths', ...dbParams() })
    });
    const data = await resp.json();

    if (data.ok) {
      // WC uploads path
      const wcInput = $('wc-uploads-path');
      const wcStatus = $('wc-path-status');
      if (wcInput) {
        wcInput.value = data.wc_uploads || '';
        wcInput.readOnly = !!data.wc_found;
        wcInput.style.color = data.wc_found ? 'var(--green)' : 'var(--amber)';
      }
      if (wcStatus) {
        wcStatus.textContent = data.wc_found ? '✓ detectado' : '✗ não encontrado — preenche manualmente';
        wcStatus.style.color = data.wc_found ? 'var(--green)' : 'var(--amber)';
      }

      // PS root path
      const psInput = $('img-ps-root');
      const psStatus = $('ps-path-status');
      if (psInput) {
        psInput.value = data.ps_root || '';
        psInput.readOnly = !!data.ps_found;
        psInput.style.color = data.ps_found ? 'var(--green)' : 'var(--amber)';
      }
      if (psStatus) {
        psStatus.textContent = data.ps_found ? '✓ detectado' : '✗ não encontrado — preenche manualmente';
        psStatus.style.color = data.ps_found ? 'var(--green)' : 'var(--amber)';
      }

      // Allow editing if auto-detect failed
      if (!data.wc_found && wcInput) wcInput.readOnly = false;
      if (!data.ps_found && psInput) psInput.readOnly = false;
    }
  } catch(e) {
    const wcInput = $('wc-uploads-path');
    const psInput = $('img-ps-root');
    if (wcInput) { wcInput.readOnly = false; wcInput.placeholder = 'Detecção falhou — preenche manualmente'; }
    if (psInput) { psInput.readOnly = false; psInput.placeholder = 'Detecção falhou — preenche manualmente'; }
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '🔍 Detectar'; }
  }
}

let imgImporting = false;
let imgLogOffset = 0;
let imgTimer = null;

async function startImageImport() {
  const mode = document.querySelector('input[name="img-mode"]:checked')?.value || 'http';
  const psRootPath = mode === 'copy'
    ? v('ps-root-path-img')
    : v('ps-root-path-img-http');
  const wcUploadsPath = v('wc-uploads-path');

  imgImporting = true;
  imgLogOffset = 0;
  $('btn-img-start').disabled = true;
  $('btn-img-start').innerHTML = '<span class="spin"></span> A importar…';
  show('btn-img-stop');

  logImgLine({ level:'info', message:`[IMAGES] Modo: ${mode}. PS root: ${psRootPath||'—'}`, ts: new Date().toTimeString().slice(0,8), step:'images' });

  imgTimer = setInterval(() => pollImgLog(), 800);
  await runImageBatch({ image_mode: mode, ps_root_path: psRootPath, wc_uploads_path: wcUploadsPath });
}

async function runImageBatch(opts) {
  if (!imgImporting) return;
  try {
    const data = await api('import_images', { ...dbParams(), ...opts });
    const p = data.progress;

    // Update stats
    html('stat-img-done',    p.done_images    ?? 0);
    html('stat-img-skipped', p.skipped_images ?? 0);
    html('stat-img-total',   p.total_images   ?? 0);
    setBar('prog-images', pct(p.done_images ?? 0, p.total_images ?? 1));

    if (p.status === 'images_done') {
      imgImporting = false;
      clearInterval(imgTimer);
      pollImgLog();
      $('btn-img-start').disabled = false;
      $('btn-img-start').innerHTML = '✓ Concluído';
      hide('btn-img-stop');
      logImgLine({ level:'success', message:`[IMAGES] Importação concluída! ${p.done_images} imagens, ${p.skipped_images} saltadas.`, ts: new Date().toTimeString().slice(0,8), step:'images' });
    } else if (p.status === 'images_running') {
      setTimeout(() => runImageBatch(opts), 200);
    }
  } catch(e) {
    imgImporting = false;
    clearInterval(imgTimer);
    logImgLine({ level:'error', message:'[IMAGES] Erro: ' + e.message, ts:'--:--:--', step:'images' });
    $('btn-img-start').disabled = false;
    $('btn-img-start').innerHTML = '↺ Tentar novamente';
    hide('btn-img-stop');
  }
}

async function stopImageImport() {
  imgImporting = false;
  clearInterval(imgTimer);
  hide('btn-img-stop');
  $('btn-img-start').disabled = false;
  $('btn-img-start').innerHTML = '▶ Retomar';
  logImgLine({ level:'warning', message:'[IMAGES] Importação interrompida. Podes retomar.', ts: new Date().toTimeString().slice(0,8), step:'images' });
}

async function pollImgLog() {
  try {
    const url = `api.php?action=log&session=${encodeURIComponent(S.session)}&offset=${imgLogOffset}`;
    const resp = await fetch(url);
    if (!resp.headers.get('content-type')?.includes('json')) return;
    const data = await resp.json();
    if (!data.ok || !data.entries?.length) return;
    imgLogOffset = data.next_offset;
    for (const e of data.entries) {
      if (e.step === 'images' || e.message?.includes('[IMAGE')) logImgLine(e);
    }
  } catch(_) {}
}

function logImgLine(e) {
  const term = $('terminal-img');
  if (!term) return;
  const line = document.createElement('div');
  line.className = `log-line lvl-${e.level}`;
  line.innerHTML =
    `<span class="log-ts">${esc(e.ts||'')}</span>` +
    `<span class="log-step">${esc(e.step||'')}</span>` +
    `<span class="log-lvl ${e.level}">[${(e.level||'').toUpperCase().padEnd(7)}]</span>` +
    `<span class="log-msg">${esc(e.message||'')}</span>`;
  term.appendChild(line);
  const cb = $('auto-scroll-img');
  if (!cb || cb.checked) term.scrollTop = term.scrollHeight;
}

// ── Pause / Stop / Resume ────────────────────────────────────────────────────

async function pauseMigration() {
  const btnPause = $('btn-pause');
  const btnStop  = $('btn-stop');
  btnPause.disabled = true;
  btnPause.innerHTML = '<span class="spin"></span>';
  // Immediately restore stop button in case it was spinning
  if (btnStop) { btnStop.disabled = false; btnStop.innerHTML = '⏹ Parar'; }
  clearInterval(S.logTimer);
  try {
    const data = await api('pause', dbParams());
    logLine({ level:'info', message:'[PAUSE] Migração pausada.', ts: new Date().toTimeString().slice(0,8), step:'control' });
    handleProgress(data.progress, S.lastOpts || {});
  } catch(e) {
    logLine({ level:'error', message:'[PAUSE] Erro: ' + e.message, ts:'--:--:--', step:'control' });
    btnPause.disabled = false;
    btnPause.innerHTML = '⏸ Pausar';
  }
}

async function stopMigration() {
  if (!confirm('Parar a migração? Os produtos já importados ficam no PrestaShop. Podes continuar mais tarde ou fazer Reset para apagar tudo.')) return;
  const btnStop  = $('btn-stop');
  const btnPause = $('btn-pause');
  if (btnStop)  { btnStop.disabled = true;  btnStop.innerHTML = '<span class="spin"></span>'; }
  if (btnPause) { btnPause.disabled = true; btnPause.innerHTML = '⏸ Pausar'; }
  clearInterval(S.logTimer);
  S.migrating = false;
  try {
    const data = await api('stop', dbParams());
    logLine({ level:'warning', message:'[STOP] Migração parada. Podes retomar ou fazer Reset.', ts: new Date().toTimeString().slice(0,8), step:'control' });
    handleProgress(data.progress, S.lastOpts || {});
  } catch(e) {
    logLine({ level:'error', message:'[STOP] Erro: ' + e.message, ts:'--:--:--', step:'control' });
  } finally {
    // Always restore buttons regardless of outcome
    if (btnStop)  { btnStop.disabled = false;  btnStop.innerHTML = '⏹ Parar'; }
    if (btnPause) { btnPause.disabled = false; btnPause.innerHTML = '⏸ Pausar'; }
  }
}

async function resumeMigration(opts) {
  opts = opts || S.lastOpts || dbParams();
  S.migrating = true;
  $('btn-start').disabled = true;
  $('btn-start').innerHTML = '<span class="spin"></span> Running…';
  hide('btn-reset');
  logLine({ level:'info', message:'[RESUME] A retomar migração…', ts: new Date().toTimeString().slice(0,8), step:'control' });
  S.logTimer = setInterval(() => pollLog(), 800);
  try {
    const data = await api('resume', opts);
    if (data.progress?.session) {
      S.session = data.progress.session;
      sessionStorage.setItem('wc2ps_session', S.session);
    }
    handleProgress(data.progress, opts);
  } catch(e) {
    logLine({ level:'error', message:'[RESUME] Erro: ' + e.message, ts:'--:--:--', step:'control' });
    S.migrating = false;
  }
}

// ── Reset ─────────────────────────────────────────────────────────────────────

async function doReset() {
  if (!confirm('Isto vai APAGAR todos os produtos, categorias e atributos migrados do PrestaShop. Continuar?')) return;

  const btn = $('btn-reset');
  btn.disabled = true;

  // Log reset start in terminal
  logLine({ level:'info', message:'[RESET] A iniciar remoção de dados migrados…', ts: new Date().toTimeString().slice(0,8), step:'reset' });

  let phase = 'products';
  let remaining = 99999;
  let calls = 0;

  try {
    // Loop until server says done=true (batched deletes)
    while (true) {
      calls++;
      btn.innerHTML = `<span class="spin"></span> Resetting… ${phase} ${remaining > 0 && remaining < 99999 ? '(' + remaining + ' restantes)' : ''}`;

      const data = await api('reset', dbParams());

      phase     = data.phase     ?? 'unknown';
      remaining = data.remaining ?? 0;

      logLine({
        level:   'info',
        message: `[RESET] fase=${phase} restantes=${remaining}`,
        ts:      new Date().toTimeString().slice(0,8),
        step:    'reset'
      });

      if (data.done) break;

      // Safety valve: max 2000 batch calls (~40k products at batch=20)
      if (calls > 2000) {
        logLine({ level:'warning', message:'[RESET] Limite de iterações atingido', ts:'--:--:--', step:'reset' });
        break;
      }

      // Small pause between batches to avoid hammering the server
      await new Promise(r => setTimeout(r, 100));
    }

    logLine({ level:'success', message:'[RESET] Concluído após ' + calls + ' chamadas.', ts: new Date().toTimeString().slice(0,8), step:'reset' });

    renderProgress({ done_categories:0, total_categories:0, done_attrs:0, total_attrs:0,
                     done_products:0, total_products:0, skipped_products:0, errors:[] });
    $('btn-start').disabled   = false;
    $('btn-start').textContent = '▶ Iniciar Migração';
    hide('btn-reset');
    hide('error-section');
    goToSlide(2);  // back to mapping

  } catch (e) {
    logLine({ level:'error', message:'[RESET] Erro: ' + e.message, ts:'--:--:--', step:'reset' });
    alert('Reset falhou: ' + e.message);
  } finally {
    btn.disabled   = false;
    btn.innerHTML  = '↺ Reset &amp; Reimportar';
  }
}

// ── Tab switching ─────────────────────────────────────────────────────────────

function switchTab(group, id) {
  $$(`[data-tabgroup="${group}"]`).forEach(el => {
    el.classList.toggle('active', el.dataset.tab === id);
  });
  $$(`[data-tabpanel="${group}"]`).forEach(el => {
    el.classList.toggle('active', el.dataset.panel === id);
  });
}

// ── Resume existing migration ────────────────────────────────────────────────

async function checkExistingProgress() {
  // If there's an active session with a progress file, restore the UI state
  try {
    const resp = await fetch(`api.php?action=log&session=${encodeURIComponent(S.session)}&offset=0`);
    const data = await resp.json();
    // If the log file exists, a migration was run with this session
    if (data.debug_file_exists && data.debug_file_size > 0) {
      // Show terminal with existing log entries
      goToSlide(3);
      pollLog();
      // Also load progress state
      const pResp = await fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action:'progress', session: S.session})
      });
      const pData = await pResp.json();
      if (pData.ok && pData.progress) {
        renderProgress(pData.progress);
        const st = pData.progress.status;
        if (st === 'running') {
          logLine({level:'warning', message:'[RESUME] Migração em curso detectada — a retomar polling…', ts:'--:--:--', step:'resume'});
          // Don't auto-resume, just show state
        } else if (st === 'completed') {
          $('btn-start').disabled = true;
          $('btn-start').innerHTML = '✓ Done';
          show('btn-reset');
          setStep(5);
        }
      }
    }
  } catch (_) {}
}

// ── Image migration ─────────────────────────────────────────────────────────

let imgMigrating = false;
let imgPaused    = false;

function onStrategyChange() {
  const strategy = document.querySelector('input[name="img-strategy"]:checked')?.value;
  const wcGroup  = $('wc-uploads-group');
  if (wcGroup) wcGroup.style.display = strategy === 'local' ? '' : 'none';
}

function clearImageTerminal() {
  const t = $('terminal-images');
  if (t) t.innerHTML = '';
}

function imgLog(level, msg) {
  const t = $('terminal-images');
  if (!t) return;
  const line = document.createElement('div');
  line.className = `log-line lvl-${level}`;
  line.innerHTML =
    `<span class="log-ts">${new Date().toTimeString().slice(0,8)}</span>` +
    `<span class="log-step">images</span>` +
    `<span class="log-lvl ${level}">[${level.toUpperCase().padEnd(7)}]</span>` +
    `<span class="log-msg">${esc(msg)}</span>`;
  t.appendChild(line);
  t.scrollTop = t.scrollHeight;
}

function renderImageProgress(p) {
  const total   = p.total_images   ?? 0;
  const done    = p.done_images    ?? 0;
  const skipped = p.skipped_images ?? 0;
  html('stat-img-done',    done);
  html('stat-img-skipped', skipped);
  html('stat-img-total',   total);
  const pct = total > 0 ? Math.min(100, Math.round(((done + skipped) / total) * 100)) : 0;
  const bar = $('prog-images');
  if (bar) {
    const fill = bar.querySelector('.prog-fill');
    const lbl  = bar.querySelector('.prog-val');
    if (fill) fill.style.width = pct + '%';
    if (lbl)  lbl.textContent  = pct + '%';
  }
  return p.images_status;
}

async function startImageMigration() {
  const strategy = document.querySelector('input[name="img-strategy"]:checked')?.value || 'http';
  const psRoot   = v('img-ps-root');
  const wcUploads= v('wc-uploads-path');
  const batchSz  = parseInt(v('img-batch-size') || '20');

  if (!psRoot) { alert('Indica o caminho raiz do PrestaShop.'); return; }
  if (strategy === 'local' && !wcUploads) { alert('Indica o caminho dos uploads do WooCommerce.'); return; }

  imgMigrating = true;
  imgPaused    = false;
  $('btn-img-start').disabled = true;
  $('btn-img-start').innerHTML = '<span class="spin"></span> A importar…';
  show('btn-img-pause');
  hide('btn-img-skip');

  imgLog('info', `[START] Estratégia: ${strategy} | PS root: ${psRoot}`);
  if (strategy === 'local') imgLog('info', `[START] WC uploads: ${wcUploads}`);

  await runImageBatch({ ...dbParams(), image_strategy: strategy,
    ps_root_path: psRoot, wc_uploads_path: wcUploads, batch_size: batchSz });
}

async function runImageBatch(opts) {
  if (!imgMigrating || imgPaused) return;
  try {
    const data = await api('images', opts);
    const status = renderImageProgress(data.progress);
    const done    = data.progress?.done_images    ?? 0;
    const skipped = data.progress?.skipped_images ?? 0;
    const total   = data.progress?.total_images   ?? 0;

    imgLog('info', `Batch: done=${done} skipped=${skipped} total=${total}`);

    if (status === 'completed') {
      imgLog('success', `Importação de imagens concluída! ${done} copiadas, ${skipped} saltadas.`);
      $('btn-img-start').disabled  = false;
      $('btn-img-start').textContent = '✓ Concluído';
      hide('btn-img-pause');
      show('btn-img-skip');
      imgMigrating = false;
    } else {
      // Continue with next batch after short delay
      setTimeout(() => runImageBatch(opts), 150);
    }
  } catch(e) {
    imgLog('error', 'Erro: ' + e.message);
    $('btn-img-start').disabled   = false;
    $('btn-img-start').textContent = '↺ Tentar novamente';
    imgMigrating = false;
  }
}

async function pauseImageMigration() {
  imgPaused    = !imgPaused;
  imgMigrating = !imgPaused;
  const btn = $('btn-img-pause');
  if (imgPaused) {
    btn.innerHTML = '▶ Retomar';
    btn.style.background     = 'var(--green-lo)';
    btn.style.borderColor    = 'var(--green)';
    btn.style.color          = 'var(--green)';
    imgLog('warning', 'Importação pausada.');
    $('btn-img-start').disabled = false;
    $('btn-img-start').textContent = '▶ Retomar';
    $('btn-img-start').onclick = () => {
      imgPaused = false; imgMigrating = true;
      $('btn-img-start').disabled = true;
      $('btn-img-start').innerHTML = '<span class="spin"></span> A importar…';
      btn.innerHTML = '⏸ Pausar';
      btn.style.background = 'var(--amber-lo)';
      btn.style.borderColor = 'var(--amber)';
      btn.style.color = 'var(--amber)';
      imgLog('info', 'A retomar importação…');
      // Re-run with same opts stored in closure
      runImageBatch(window._imgOpts || {});
    };
  }
}

// ── Utility ───────────────────────────────────────────────────────────────────

function esc(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Credential persistence (localStorage) ────────────────────────────────────

const CRED_KEY    = 'wc2ps_credentials_v2';
const CRED_FIELDS = [
  'wc-host','wc-port','wc-db','wc-user','wc-pass','wc-prefix',
  'ps-host','ps-port','ps-db','ps-user','ps-pass','ps-prefix',
  'ps-id-lang','ps-id-shop','batch-size','ps-root-path',
];

function saveCredentials() {
  const creds = {};
  for (const id of CRED_FIELDS) {
    const el = $(id);
    if (el) creds[id] = el.value;
  }
  try {
    localStorage.setItem(CRED_KEY, JSON.stringify(creds));
    showCredFeedback('✓ Guardado', 'var(--green)');
  } catch(e) {
    showCredFeedback('✗ Erro: ' + e.message, 'var(--red)');
  }
}

function loadCredentials() {
  try {
    const raw = localStorage.getItem(CRED_KEY);
    if (!raw) return false;
    const creds = JSON.parse(raw);
    let loaded = 0;
    for (const id of CRED_FIELDS) {
      const el = $(id);
      if (el && creds[id] !== undefined) {
        el.value = creds[id];
        loaded++;
      }
    }
    return loaded > 0;
  } catch(e) {
    return false;
  }
}

function clearCredentials() {
  if (!confirm('Limpar todas as credenciais guardadas?\nOs campos de ligação serão limpos.')) return;
  try {
    localStorage.removeItem(CRED_KEY);
    // Clear all credential fields
    for (const id of CRED_FIELDS) {
      const el = $(id);
      if (el) el.value = '';
    }
    // Reset defaults for non-sensitive fields
    const defaults = {
      'wc-host':'127.0.0.1','wc-port':'3306','wc-prefix':'wp_',
      'ps-host':'127.0.0.1','ps-port':'3306','ps-prefix':'ps_',
      'ps-id-lang':'1','ps-id-shop':'1','batch-size':'20',
    };
    for (const [id, val] of Object.entries(defaults)) {
      const el = $(id);
      if (el) el.value = val;
    }
    showCredFeedback('✓ Credenciais apagadas', 'var(--amber)');
    // Reset connection badges
    ['wc','ps'].forEach(side => {
      const badge = $(`${side}-status`);
      if (badge) { badge.className = 'conn-badge idle'; badge.textContent = 'not tested'; }
      const info = $(`${side}-info`);
      if (info) info.textContent = '';
    });
    // Reset session
    sessionStorage.removeItem('wc2ps_session');
    S.session = 'sess_' + Math.random().toString(36).slice(2,10);
    sessionStorage.setItem('wc2ps_session', S.session);
    S.wcConn = null; S.psConn = null;
    hide('btn-next-1');
  } catch(e) {
    showCredFeedback('✗ Erro: ' + e.message, 'var(--red)');
  }
}

function showCredFeedback(msg, color) {
  const el = $('cred-feedback');
  if (!el) return;
  el.textContent = msg;
  el.style.color = color;
  el.style.opacity = '1';
  setTimeout(() => { el.style.opacity = '0'; }, 2500);
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  // Autoscroll toggle
  const ascEl = $('auto-scroll');
  if (ascEl) ascEl.addEventListener('change', e => { S.autoScroll = e.target.checked; });

  // Log filter buttons
  $$('.log-filter-btn').forEach(b => {
    b.addEventListener('click', () => filterLog(b.dataset.lvl));
  });

  // Check if there's an ongoing/previous migration for this session
  checkExistingProgress();

  // Load saved credentials on startup
  if (loadCredentials()) {
    showCredFeedback('✓ Credenciais carregadas', 'var(--green)');
  }

  // Start on slide 0
  goToSlide(0);
});
