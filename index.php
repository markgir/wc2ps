<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WooCommerce → PrestaShop Migration</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="app-header">
  <div class="app-logo">
    <svg viewBox="0 0 24 24" fill="none"><path d="M4 7h16M4 12h10M4 17h7" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="19" cy="17" r="3" stroke="#fff" stroke-width="2"/><path d="M21.5 19.5L23 21" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
  </div>
  <div class="app-header-text">
    <div class="app-title">WooCommerce → PrestaShop</div>
    <div class="app-sub">direct database migration · v2.5.4</div>
  </div>
  <span class="version-badge">v2.5.4</span>
</header>

<nav class="step-nav">
  <div class="step-nav-inner">
    <button class="snav-item active" id="ws1" onclick="goToSlide(0)"><span class="snav-num">1</span><span class="snav-label">Conexão</span></button>
    <div class="snav-line"></div>
    <button class="snav-item" id="ws2" onclick="goToSlide(1)"><span class="snav-num">2</span><span class="snav-label">Análise</span></button>
    <div class="snav-line"></div>
    <button class="snav-item" id="ws3" onclick="goToSlide(2)"><span class="snav-num">3</span><span class="snav-label">Mapeamento</span></button>
    <div class="snav-line"></div>
    <button class="snav-item" id="ws4" onclick="goToSlide(3)"><span class="snav-num">4</span><span class="snav-label">Migração</span></button>
    <div class="snav-line"></div>
    <button class="snav-item" id="ws5" onclick="goToSlide(4)"><span class="snav-num">5</span><span class="snav-label">Imagens</span></button>
    <div class="snav-line"></div>
    <button class="snav-item" id="ws6" onclick="goToSlide(5)"><span class="snav-num">6</span><span class="snav-label">Concluído</span></button>
  </div>
</nav>

<div class="slide-viewport">
<div class="slide-track" id="slide-track">

  <!-- SLIDE 1: Conexão -->
  <div class="slide" id="slide-0">
  <div class="slide-inner">
    <div class="slide-title">
      <svg class="slide-icon" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h16" stroke="var(--accent)" stroke-width="2" stroke-linecap="round"/></svg>
      Configuração das Bases de Dados
    </div>

    <div class="db-section">
      <div class="section-label">
        <span style="color:var(--purple)">●</span> WooCommerce / WordPress
        <span id="wc-status" class="conn-badge idle" style="margin-left:10px">not tested</span>
      </div>
      <div class="form-grid">
        <div class="form-group"><label for="wc-host">Host</label><input type="text" id="wc-host" value="127.0.0.1" placeholder="127.0.0.1"></div>
        <div class="form-group"><label for="wc-port">Porta</label><input type="text" id="wc-port" value="3306" placeholder="3306"></div>
        <div class="form-group"><label for="wc-db">Base de dados</label><input type="text" id="wc-db" placeholder="wordpress"></div>
        <div class="form-group"><label for="wc-user">Utilizador</label><input type="text" id="wc-user" placeholder="root" autocomplete="username"></div>
        <div class="form-group"><label for="wc-pass">Password</label><input type="password" id="wc-pass" placeholder="••••••••" autocomplete="current-password"></div>
        <div class="form-group"><label for="wc-prefix">Prefixo tabelas</label><input type="text" id="wc-prefix" value="wp_" placeholder="wp_"></div>
        <div class="form-group full"><small id="wc-info" style="color:var(--text2);font-family:var(--mono);font-size:.75rem"></small></div>
      </div>
      <div class="btn-row"><button class="btn btn-secondary btn-sm" onclick="testConnection('wc')">Testar ligação</button></div>
    </div>

    <div class="divider"></div>

    <div class="db-section">
      <div class="section-label">
        <span style="color:var(--red)">●</span> PrestaShop
        <span id="ps-status" class="conn-badge idle" style="margin-left:10px">not tested</span>
      </div>
      <div class="form-grid">
        <div class="form-group"><label for="ps-host">Host</label><input type="text" id="ps-host" value="127.0.0.1" placeholder="127.0.0.1"></div>
        <div class="form-group"><label for="ps-port">Porta</label><input type="text" id="ps-port" value="3306" placeholder="3306"></div>
        <div class="form-group"><label for="ps-db">Base de dados</label><input type="text" id="ps-db" placeholder="prestashop"></div>
        <div class="form-group"><label for="ps-user">Utilizador</label><input type="text" id="ps-user" placeholder="root" autocomplete="username"></div>
        <div class="form-group"><label for="ps-pass">Password</label><input type="password" id="ps-pass" placeholder="••••••••" autocomplete="current-password"></div>
        <div class="form-group"><label for="ps-prefix">Prefixo tabelas</label><input type="text" id="ps-prefix" value="ps_" placeholder="ps_"></div>
        <div class="form-group"><label for="ps-id-lang">ID Idioma</label><input type="number" id="ps-id-lang" value="1" min="1"></div>
        <div class="form-group"><label for="ps-id-shop">ID Loja</label><input type="number" id="ps-id-shop" value="1" min="1"></div>
        <div class="form-group full"><small id="ps-info" style="color:var(--text2);font-family:var(--mono);font-size:.75rem"></small></div>
      </div>
      <div class="btn-row"><button class="btn btn-secondary btn-sm" onclick="testConnection('ps')">Testar ligação</button></div>
    </div>

    <div class="divider"></div>

    <div class="cred-bar">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" style="flex-shrink:0"><rect x="3" y="11" width="18" height="11" rx="2" stroke="var(--text2)" stroke-width="2"/><path d="M7 11V7a5 5 0 0110 0v4" stroke="var(--text2)" stroke-width="2" stroke-linecap="round"/></svg>
      <span class="cred-label">Dados de acesso guardados localmente no browser</span>
      <span id="cred-feedback" class="cred-feedback"></span>
      <button class="btn btn-secondary btn-sm" onclick="saveCredentials()" style="font-family:var(--mono)">💾 Guardar</button>
      <button class="btn btn-sm" onclick="clearCredentials()" style="font-family:var(--mono);color:var(--red);border-color:var(--red);background:var(--red-lo)">🗑 Limpar</button>
    </div>

    <div class="slide-footer">
      <div></div>
      <button class="btn btn-primary hidden" id="btn-next-1" onclick="runAnalysis()">Analisar bases de dados →</button>
    </div>
  </div>
  </div>

  <!-- SLIDE 2: Análise -->
  <div class="slide" id="slide-1">
  <div class="slide-inner">
    <div class="slide-title">
      <svg class="slide-icon" viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="8" stroke="var(--accent)" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke="var(--accent)" stroke-width="2" stroke-linecap="round"/></svg>
      Análise da Base de Dados WooCommerce
      <button class="btn btn-outline btn-sm" id="btn-analyse" onclick="runAnalysis()" style="margin-left:auto">🔍 Re-analisar</button>
    </div>

    <div class="info-grid">
      <div class="info-box"><div class="val" id="info-products">—</div><div class="lbl">Produtos</div></div>
      <div class="info-box"><div class="val" id="info-variations">—</div><div class="lbl">Variações</div></div>
      <div class="info-box"><div class="val" id="info-categories">—</div><div class="lbl">Categorias</div></div>
      <div class="info-box"><div class="val" id="info-attributes">—</div><div class="lbl">Atributos</div></div>
      <div class="info-box"><div class="val" id="info-images">—</div><div class="lbl">Imagens</div></div>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:.78rem;color:var(--text2);font-family:var(--mono);margin-bottom:14px">
      <span id="info-wc-version"></span><span>·</span>
      <span>PS <span id="info-ps-version">—</span> · lang <span id="info-ps-lang">—</span> · shop <span id="info-ps-shop">—</span></span>
    </div>

    <div id="analysis-issues" class="issue-list hidden" style="margin-bottom:16px"></div>

    <div class="tab-bar">
      <button class="tab-btn active" data-tabgroup="anal" data-tab="samples" onclick="switchTab('anal','samples')">Produtos exemplo</button>
      <button class="tab-btn" data-tabgroup="anal" data-tab="tables" onclick="switchTab('anal','tables')">Tabelas WC</button>
    </div>
    <div class="tab-pane active" data-tabpanel="anal" data-panel="samples"><div class="sample-grid" id="sample-products"></div></div>
    <div class="tab-pane" data-tabpanel="anal" data-panel="tables"><div id="table-status" style="font-family:var(--mono);font-size:.8rem;color:var(--text1)">Análise pendente…</div></div>

    <div class="slide-footer">
      <button class="btn btn-secondary" onclick="goToSlide(0)">← Voltar</button>
      <button class="btn btn-primary" onclick="goToSlide(2)">Ver mapeamento →</button>
    </div>
  </div>
  </div>

  <!-- SLIDE 3: Mapeamento -->
  <div class="slide" id="slide-2">
  <div class="slide-inner">
    <div class="slide-title">
      <svg class="slide-icon" viewBox="0 0 24 24" fill="none"><path d="M8 6l4-4 4 4M12 2v10.5M8 18l4 4 4-4M12 22V11.5" stroke="var(--green)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Mapeamento de Campos
    </div>

    <div class="tab-bar">
      <button class="tab-btn active" data-tabgroup="map" data-tab="fields" onclick="switchTab('map','fields')">Campos</button>
      <button class="tab-btn" data-tabgroup="map" data-tab="categories" onclick="switchTab('map','categories')">Categorias</button>
      <button class="tab-btn" data-tabgroup="map" data-tab="attributes" onclick="switchTab('map','attributes')">Atributos</button>
    </div>
    <div class="tab-pane active" data-tabpanel="map" data-panel="fields">
      <table class="mapping-table">
        <thead><tr><th>Campo WooCommerce</th><th>Tipo</th><th>Cobertura</th><th>Campo PrestaShop</th></tr></thead>
        <tbody id="mapping-table-body"><tr><td colspan="4" style="color:var(--text2);padding:12px">Execute a análise primeiro</td></tr></tbody>
      </table>
    </div>
    <div class="tab-pane" data-tabpanel="map" data-panel="categories"><div id="category-preview" style="padding:8px 0"></div></div>
    <div class="tab-pane" data-tabpanel="map" data-panel="attributes"><div id="attribute-preview" style="padding:8px 0"></div></div>

    <div class="divider"></div>

    <div style="margin-bottom:12px;font-size:.82rem;color:var(--text1)">Opções de migração:</div>
    <div class="options-grid">
      <div class="option-card"><input type="checkbox" id="opt-cats" checked><label for="opt-cats">Categorias<small>Estrutura hierárquica completa</small></label></div>
      <div class="option-card"><input type="checkbox" id="opt-attrs" checked><label for="opt-attrs">Atributos<small>Grupos + valores para combinações</small></label></div>
      <div class="option-card"><input type="checkbox" id="opt-prods" checked><label for="opt-prods">Produtos<small>Simples, variáveis e agrupados</small></label></div>
      <div class="option-card"><input type="checkbox" id="opt-images"><label for="opt-images">Imagens<small>Descarregar para PS (lento)</small></label></div>
    </div>
    <div class="form-grid" style="margin-bottom:0">
      <div class="form-group"><label for="batch-size">Batch size</label><input type="number" id="batch-size" value="20" min="1" max="100"></div>
      <div class="form-group full"><label for="ps-root-path">Caminho raiz PS (para imagens, opcional)</label><input type="text" id="ps-root-path" placeholder="/var/www/prestashop"></div>
    </div>

    <div class="slide-footer">
      <button class="btn btn-secondary" onclick="goToSlide(1)">← Voltar</button>
      <button class="btn btn-primary" onclick="revealMigrationCard()">Confirmar e continuar →</button>
    </div>
  </div>
  </div>

  <!-- SLIDE 4: Migração -->
  <div class="slide" id="slide-3">
  <div class="slide-inner">
    <div class="slide-title">
      <svg class="slide-icon" viewBox="0 0 24 24" fill="none"><path d="M5 12h14M12 5l7 7-7 7" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Progresso da Migração
    </div>

    <div class="progress-bars">
      <div class="prog-item" id="prog-categories">
        <div class="prog-label"><span style="color:var(--purple)">Categorias</span><span class="prog-val">0%</span></div>
        <div class="prog-track"><div class="prog-fill" style="width:0%"></div></div>
      </div>
      <div class="prog-item" id="prog-attributes">
        <div class="prog-label"><span style="color:var(--cyan)">Atributos</span><span class="prog-val">0%</span></div>
        <div class="prog-track"><div class="prog-fill" style="width:0%"></div></div>
      </div>
      <div class="prog-item" id="prog-products">
        <div class="prog-label"><span style="color:var(--green)">Produtos</span><span class="prog-val">0%</span></div>
        <div class="prog-track"><div class="prog-fill" style="width:0%"></div></div>
      </div>
    </div>

    <div class="stat-row">
      <div class="stat ok"><span>Importados</span><span class="n" id="stat-done">0</span></div>
      <div class="stat warn"><span>Saltados</span><span class="n" id="stat-skipped">0</span></div>
      <div class="stat err"><span>Erros</span><span class="n" id="stat-errors">0</span></div>
      <div class="stat"><span>Categorias</span><span class="n" id="stat-cats">0</span></div>
      <div class="stat"><span>Atributos</span><span class="n" id="stat-attrs">0</span></div>
      <div class="stat" style="margin-left:auto"><span>Queries</span><span class="n" id="stat-queries">0</span></div>
      <div class="stat"><span>Elapsed</span><span class="n" id="stat-elapsed">0</span><span>ms</span></div>
    </div>
    <div style="font-size:.75rem;color:var(--text2);margin-bottom:10px;font-family:var(--mono)">Step: <span id="stat-step" style="color:var(--accent)">—</span></div>

    <div id="error-section" class="hidden" style="margin-bottom:14px">
      <div class="section-label" style="color:var(--red)">Erros registados</div>
      <div id="error-list" style="max-height:120px;overflow-y:auto;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius);padding:8px 12px"></div>
    </div>

    <div class="log-controls">
      <span style="font-size:.75rem;color:var(--text2);font-family:var(--mono)">LOG</span>
      <button class="log-filter-btn active" data-lvl="all"     onclick="filterLog('all')">all</button>
      <button class="log-filter-btn"        data-lvl="success" onclick="filterLog('success')">success</button>
      <button class="log-filter-btn"        data-lvl="warning" onclick="filterLog('warning')">warning</button>
      <button class="log-filter-btn"        data-lvl="error"   onclick="filterLog('error')">error</button>
      <button class="log-filter-btn"        data-lvl="sql"     onclick="filterLog('sql')">sql</button>
      <button class="btn btn-secondary btn-sm" onclick="clearTerminal()">Clear</button>
      <label class="log-autoscroll"><input type="checkbox" id="auto-scroll" checked> autoscroll</label>
    </div>
    <div class="terminal" id="terminal">
      <div class="log-line">
        <span class="log-ts">--:--:--</span><span class="log-step">init</span>
        <span class="log-lvl info">[INFO   ]</span>
        <span class="log-msg">Terminal pronto. Inicie a migração para ver o output em tempo real.</span>
      </div>
    </div>

    <div class="btn-row" style="margin-top:14px">
      <button class="btn btn-primary" id="btn-start" onclick="startMigration()">▶ Iniciar Migração</button>
      <button class="btn btn-secondary hidden" id="btn-pause" onclick="pauseMigration()" style="background:var(--amber-lo);border-color:var(--amber);color:var(--amber)">⏸ Pausar</button>
      <button class="btn btn-secondary hidden" id="btn-stop"  onclick="stopMigration()"  style="background:var(--red-lo);border-color:var(--red);color:var(--red)">⏹ Parar</button>
      <button class="btn btn-danger hidden" id="btn-reset" onclick="doReset()">↺ Reset &amp; Reimportar</button>
    </div>
  </div>
  </div>

  <!-- SLIDE 5: Imagens -->
  <div class="slide" id="slide-4">
  <div class="slide-inner">
    <div class="slide-title">
      <svg class="slide-icon" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="3" stroke="var(--accent)" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="var(--accent)"/><path d="M21 15l-5-5L5 21" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Importação de Imagens
    </div>

    <!-- Strategy picker -->
    <div class="img-strategy-grid">
      <label class="strategy-card" id="strat-local-card">
        <input type="radio" name="img-strategy" id="strat-local" value="local" onchange="onStrategyChange()">
        <div class="strategy-icon">⚡</div>
        <div class="strategy-title">Cópia directa de ficheiros</div>
        <div class="strategy-desc">WC e PS no mesmo servidor. Copia via <code>copy()</code> sem HTTP — muito rápido, sem timeouts.</div>
        <div class="strategy-badge best">Mais rápido</div>
      </label>
      <label class="strategy-card" id="strat-http-card">
        <input type="radio" name="img-strategy" id="strat-http" value="http" checked onchange="onStrategyChange()">
        <div class="strategy-icon">🌐</div>
        <div class="strategy-title">Download via HTTP</div>
        <div class="strategy-desc">Descarrega as imagens do WooCommerce via HTTP. Funciona com servidores separados.</div>
        <div class="strategy-badge">Compatível</div>
      </label>
    </div>

    <!-- Paths: auto-detected, manual override available -->
    <div style="margin-top:16px;padding:12px 14px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius)">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
        <span style="font-size:.78rem;color:var(--text2);font-family:var(--mono);flex:1">
          Caminhos detectados automaticamente a partir das bases de dados
        </span>
        <button class="btn btn-secondary btn-sm" id="btn-detect-paths" onclick="detectImagePaths()">
          🔍 Detectar
        </button>
      </div>
      <div class="form-grid" style="margin-bottom:0">
        <div class="form-group full" id="wc-uploads-group">
          <label for="wc-uploads-path">
            WooCommerce uploads
            <span id="wc-path-status" style="font-family:var(--mono);font-size:.72rem;margin-left:6px"></span>
          </label>
          <input type="text" id="wc-uploads-path" placeholder="A detectar automaticamente…"
                 style="font-family:var(--mono);font-size:.78rem">
        </div>
        <div class="form-group full">
          <label for="img-ps-root">
            PrestaShop raiz
            <span id="ps-path-status" style="font-family:var(--mono);font-size:.72rem;margin-left:6px"></span>
          </label>
          <input type="text" id="img-ps-root" placeholder="A detectar automaticamente…"
                 style="font-family:var(--mono);font-size:.78rem">
        </div>
        <div class="form-group">
          <label for="img-batch-size">Imagens por batch</label>
          <input type="number" id="img-batch-size" value="20" min="1" max="100">
        </div>
      </div>
    </div>

    <div class="divider"></div>

    <!-- Progress -->
    <div class="progress-bars" style="margin-bottom:14px">
      <div class="prog-item" id="prog-images">
        <div class="prog-label"><span style="color:var(--accent)">Imagens</span><span class="prog-val">0%</span></div>
        <div class="prog-track"><div class="prog-fill" style="width:0%"></div></div>
      </div>
    </div>
    <div class="stat-row" style="margin-bottom:14px">
      <div class="stat ok"><span>Copiadas</span><span class="n" id="stat-img-done">0</span></div>
      <div class="stat warn"><span>Saltadas</span><span class="n" id="stat-img-skipped">0</span></div>
      <div class="stat" style="margin-left:auto"><span>Total</span><span class="n" id="stat-img-total">0</span></div>
    </div>

    <!-- Log mini-terminal -->
    <div class="log-controls" style="margin-bottom:6px">
      <span style="font-size:.75rem;color:var(--text2);font-family:var(--mono)">LOG</span>
      <button class="btn btn-secondary btn-sm" onclick="clearImageTerminal()">Clear</button>
    </div>
    <div class="terminal" id="terminal-images" style="height:180px">
      <div class="log-line">
        <span class="log-ts">--:--:--</span><span class="log-step">init</span>
        <span class="log-lvl info">[INFO   ]</span>
        <span class="log-msg">Selecciona a estratégia e clica "Iniciar importação de imagens".</span>
      </div>
    </div>

    <div class="btn-row" style="margin-top:14px">
      <button class="btn btn-primary" id="btn-img-start" onclick="startImageMigration()">▶ Iniciar importação de imagens</button>
      <button class="btn btn-secondary hidden" id="btn-img-pause" onclick="pauseImageMigration()"
              style="background:var(--amber-lo);border-color:var(--amber);color:var(--amber)">⏸ Pausar</button>
      <button class="btn btn-secondary hidden" id="btn-img-skip" onclick="goToSlide(5)"
              style="color:var(--text2);border-color:var(--border)">Saltar →</button>
    </div>

    <div class="slide-footer">
      <button class="btn btn-secondary" onclick="goToSlide(3)">← Migração</button>
      <button class="btn btn-primary" onclick="goToSlide(5)">Concluído →</button>
    </div>
  </div>
  </div>

  <!-- SLIDE 6: Concluído -->
  <div class="slide" id="slide-5">
  <div class="slide-inner slide-done">
    <div class="done-icon">✓</div>
    <h2 style="font-size:1.4rem;font-weight:600;margin-bottom:8px;color:var(--text0)">Migração concluída!</h2>
    <p style="color:var(--text2);margin-bottom:24px">Todos os produtos foram importados para o PrestaShop.</p>
    <div class="info-grid" style="max-width:500px;margin:0 auto 24px">
      <div class="info-box"><div class="val" id="done-products">—</div><div class="lbl">Produtos</div></div>
      <div class="info-box"><div class="val" id="done-cats">—</div><div class="lbl">Categorias</div></div>
      <div class="info-box"><div class="val" id="done-attrs">—</div><div class="lbl">Atributos</div></div>
      <div class="info-box"><div class="val" id="done-errors">—</div><div class="lbl">Erros</div></div>
    </div>
    <div class="btn-row" style="justify-content:center">
      <button class="btn btn-secondary" onclick="goToSlide(3)">← Ver log</button>
      <button class="btn btn-danger" onclick="doReset()">↺ Reset &amp; Reimportar</button>
    </div>
  </div>
  </div>

</div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
