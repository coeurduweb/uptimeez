/* Uptimeez : interactions. Aucune dépendance, aucun appel externe.
   Tout fonctionne sans JavaScript ; le script ne fait qu'éviter des rechargements. */
(function () {
  'use strict';

  var V = window.UPTIMEEZ || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var isTyping = function () {
    var el = document.activeElement;
    return el && (/input|textarea|select/i.test(el.tagName) || el.isContentEditable);
  };

  // ---------- Notifications d'interface ----------
  function toast(msg, tone, ms) {
    var box = $('#toasts');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 'toast ' + (tone || '');
    el.setAttribute('role', 'status');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .25s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 260);
    }, ms || 4200);
  }

  function post(action, data) {
    var body = new URLSearchParams(data || {});
    body.set('action', action);
    body.set('csrf', V.csrf || '');
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      if (r.status === 401) { location.href = 'index.php?p=login'; throw new Error('auth'); }
      return r.json();
    });
  }

  // ---------- Thème ----------
  var toggle = $('#theme-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('uptimeez-theme', next); } catch (e) {}
    });
  }

  // ---------- Accordéons : on retient ce que l'utilisateur a ouvert ----------
  $$('details[data-acc]').forEach(function (d) {
    var key = 'uptimeez-acc-' + V.view + '-' + d.dataset.acc;
    try {
      var saved = localStorage.getItem(key);
      if (saved === '1') d.open = true;
      else if (saved === '0' && !d.classList.contains('acc-attn')) d.open = false;
    } catch (e) {}
    d.addEventListener('toggle', function () {
      try { localStorage.setItem(key, d.open ? '1' : '0'); } catch (e) {}
    });
  });

  // ---------- Filtre instantané ----------
  var q = $('#q');
  if (q) {
    var counter = $('#filter-count');
    // Même repli des accents que côté serveur : « casse » trouve « cassé ».
    var fold = function (t) {
      return String(t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    };
    var apply = function () {
      var needle = fold(q.value.trim());
      var shown = 0, cards = $$('#cards .card');
      cards.forEach(function (c) {
        var hit = !needle || (c.dataset.hay || '').indexOf(needle) !== -1;
        c.classList.toggle('hidden', !hit);
        if (hit) shown++;
      });
      if (counter) counter.textContent = needle ? shown + ' / ' + cards.length : '';
    };
    q.addEventListener('input', apply);
    document.addEventListener('keydown', function (e) {
      if (e.key === '/' && !isTyping()) { e.preventDefault(); q.focus(); q.select(); }
      if (e.key === 'Escape' && document.activeElement === q) { q.value = ''; apply(); q.blur(); }
    });
  }

  // ---------- Mise à jour d'une carte sans rechargement ----------
  function paintCard(m) {
    var card = document.querySelector('.card[data-id="' + m.id + '"]');
    if (!card) return;
    ['up', 'down', 'degraded', 'paused', 'unknown'].forEach(function (s) { card.classList.remove('s-' + s); });
    card.classList.add('s-' + m.status);
    card.dataset.status = m.status;
    var dot = card.querySelector('.dot');
    if (dot) { dot.className = 'dot dot-' + m.status; dot.setAttribute('aria-label', m.label); }
    var msg = card.querySelector('.card-msg');
    if (msg) {
      msg.textContent = '';
      if (m.status === 'up' || m.status === 'unknown' || !m.message) {
        var span = document.createElement('span');
        span.className = 'muted';
        span.textContent = m.label + (m.checked ? ' · vérifié ' + m.checked : '');
        msg.appendChild(span);
      } else {
        msg.textContent = m.message;
      }
    }
    var up = card.querySelector('.card-head .badge');
    if (up && m.uptime_h) up.textContent = m.uptime_h;
    var num = card.querySelector('.card-num');
    if (num && m.ms_h) num.innerHTML = m.ms_h + '<small>réponse</small>';
  }

  function busy(btn, on) {
    btn.classList.toggle('is-busy', !!on);
    var svg = btn.querySelector('svg');
    if (svg) svg.classList.toggle('spin', !!on);
  }

  // ---------- Vérifier / mettre en pause ----------
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-check');
    if (btn) {
      e.preventDefault();
      busy(btn, true);
      post('check', { id: btn.dataset.id }).then(function (r) {
        busy(btn, false);
        if (r.error) { toast('Erreur : ' + (r.message || r.error), 'bad'); return; }
        paintCard(r.monitor);
        var tone = r.result.state === 'up' ? 'ok' : (r.result.state === 'degraded' ? 'warn' : 'bad');
        toast((r.monitor.name || 'Sonde') + ' : ' +
              (r.result.state === 'up' ? 'tout va bien' : r.result.message),
              tone, r.result.state === 'up' ? 3000 : 8000);
        if (V.view === 'monitor') setTimeout(function () { location.reload(); }, 900);
      }).catch(function () { busy(btn, false); });
      return;
    }

    var tg = e.target.closest('.js-toggle');
    if (tg) {
      e.preventDefault();
      busy(tg, true);
      post('toggle', { id: tg.dataset.id }).then(function (r) {
        busy(tg, false);
        if (r.error) { toast('Erreur : ' + (r.message || r.error), 'bad'); return; }
        toast(r.enabled ? 'Surveillance reprise' : 'Surveillance suspendue', r.enabled ? 'ok' : 'warn');
        if (r.monitor) paintCard(r.monitor);
        if (V.view === 'monitor') setTimeout(function () { location.reload(); }, 700);
      }).catch(function () { busy(tg, false); });
    }
  });

  // ---------- Tout revérifier ----------
  var checkAll = $('#check-all');
  if (checkAll) {
    var label = checkAll.innerHTML;
    checkAll.addEventListener('click', function () {
      // Sur « Aujourd'hui » les éléments sont des tâches, sur le mur ce sont des cartes.
      var cards = $$('[data-task]');
      if (!cards.length) {
        cards = $$('#cards .card').filter(function (c) {
          return ['down', 'degraded', 'unknown'].indexOf(c.dataset.status) !== -1;
        });
        if (!cards.length) cards = $$('#cards .card').slice(0, 12);
      }
      if (!cards.length) { toast('Aucune sonde à vérifier', 'warn'); return; }
      checkAll.classList.add('is-busy');
      var i = 0, done = 0;
      var next = function () {
        if (i >= cards.length) return Promise.resolve();
        var card = cards[i++];
        return post('check', { id: card.dataset.id }).then(function (r) {
          done++;
          checkAll.textContent = 'Vérification ' + done + '/' + cards.length;
          if (r.monitor) paintCard(r.monitor);
        }).catch(function () {}).then(next);
      };
      Promise.all([next(), next()]).then(function () {
        checkAll.classList.remove('is-busy');
        checkAll.innerHTML = label;
        toast(done + ' sonde(s) vérifiée(s)', 'ok');
        setTimeout(function () { location.reload(); }, 800);
      });
    });
  }

  // ---------- Rafraîchissement automatique ----------
  var auto = $('#autorefresh');
  var timer = null, autoOn = true;
  function refresh() {
    fetch('api.php?action=summary', { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (!r.ok) return;
        r.monitors.forEach(paintCard);
        var t = document.title.replace(/^\(\d+\)\s*/, '');
        document.title = (r.summary.down > 0 ? '(' + r.summary.down + ') ' : '') + t;
      }).catch(function () {});
  }
  function setAuto(on) {
    autoOn = on;
    if (auto) {
      auto.setAttribute('aria-pressed', on ? 'true' : 'false');
      auto.style.opacity = on ? '' : '.45';
      auto.title = on ? 'Rafraîchissement automatique activé (30 s)' : 'Rafraîchissement automatique désactivé';
    }
    if (timer) clearInterval(timer);
    if (on) timer = setInterval(function () { if (!document.hidden) refresh(); }, 30000);
    try { localStorage.setItem('uptimeez-auto', on ? '1' : '0'); } catch (e) {}
  }
  if (auto) {
    var pref = '1';
    try { pref = localStorage.getItem('uptimeez-auto') || '1'; } catch (e) {}
    setAuto(pref !== '0');
    auto.addEventListener('click', function () { setAuto(!autoOn); if (autoOn) refresh(); });
  }

  // ---------- Formulaires : barre d'enregistrement contextuelle ----------
  $$('form[data-dirty-watch]').forEach(function (form) {
    var bar = form.querySelector('[data-savebar]');
    var stat = form.querySelector('[data-static-save]');
    if (!bar) return;
    var snapshot = function () {
      var d = new FormData(form), s = [];
      d.forEach(function (v, k) { s.push(k + '=' + v); });
      return s.join('&');
    };
    var base = snapshot();
    var check = function () {
      var dirty = snapshot() !== base;
      bar.hidden = !dirty;
      if (stat) stat.style.display = dirty ? 'none' : '';
    };
    form.addEventListener('input', check);
    form.addEventListener('change', check);
    var reset = form.querySelector('[data-reset-form]');
    if (reset) {
      reset.addEventListener('click', function () {
        form.reset();
        // form.reset() ne restaure pas les champs modifiés par script : on recharge.
        setTimeout(function () { check(); }, 0);
      });
    }
    window.addEventListener('beforeunload', function (e) {
      if (!bar.hidden) { e.preventDefault(); e.returnValue = ''; }
    });
    form.addEventListener('submit', function () { bar.hidden = true; });
  });

  // ---------- Préparation des imports ----------
  var startBtn = $('#setup-start');
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function runSetupQueue(ids) {
    var panel = $('#setup-panel'), bar = $('#setup-bar'), log = $('#setup-log'), status = $('#setup-status');
    if (!ids.length) return Promise.resolve();
    if (panel) panel.hidden = false;
    var total = ids.length, done = 0;
    var step = function () {
      if (!ids.length) return Promise.resolve();
      var id = ids.shift();
      return post('setup', { id: id }).then(function (r) {
        done++;
        if (bar) bar.style.width = Math.round((done / total) * 100) + '%';
        if (status) status.textContent = done + ' / ' + total + ' préparée(s)';
        if (log) {
          var line = document.createElement('div');
          var name = (r.monitor && r.monitor.name) || ('sonde #' + id);
          line.innerHTML = '<span>' + (r.ok ? '✓' : '!') + '</span>' +
            '<a href="index.php?p=monitor&id=' + id + '">' + escapeHtml(name) + '</a>' +
            '<span class="muted tiny">' + escapeHtml(r.message || '') + '</span>';
          log.prepend(line);
        }
      }).catch(function () { done++; }).then(step);
    };
    return Promise.all([step(), step()]).then(function () {
      if (status) status.textContent = 'Préparation terminée (' + total + ')';
      toast('Préparation terminée : ' + total + ' sonde(s)', 'ok');
    });
  }
  if (startBtn) {
    startBtn.addEventListener('click', function () {
      var ids = (startBtn.dataset.ids || '').split(',').filter(Boolean);
      startBtn.classList.add('is-busy');
      runSetupQueue(ids).then(function () {
        startBtn.classList.remove('is-busy');
        startBtn.textContent = 'Relancer la préparation';
      });
    });
  }
  if (V.queue && V.queue.length) {
    var panel = $('#setup-panel');
    if (panel) { panel.hidden = false; runSetupQueue(V.queue.slice()); }
    else if (V.view === 'dashboard') {
      toast(V.queue.length + ' sonde(s) en préparation…', '', 3000);
      runSetupQueue(V.queue.slice()).then(function () { location.reload(); });
    }
  }

  // ---------- Sélection de masse ----------
  var all = $('#check-all-rows');
  function updateSel() {
    var n = $$('.row-check:checked').length;
    var c = $('#sel-count');
    if (c) c.textContent = n ? n + ' sélectionnée(s)' : '';
    var apply = $('#bulk-apply');
    if (apply) apply.disabled = n === 0;
  }
  if (all) {
    all.addEventListener('change', function () {
      $$('.row-check').forEach(function (c) { c.checked = all.checked; });
      updateSel();
    });
    document.addEventListener('change', function (e) {
      if (e.target.classList && e.target.classList.contains('row-check')) updateSel();
    });
    updateSel();
  }
  window.UptimeezConfirmBulk = function (form) {
    var n = $$('.row-check:checked').length;
    if (!n) { toast('Cochez au moins une sonde', 'warn'); return false; }
    if (form.bulk_action.value === 'delete') {
      return confirm('Supprimer ' + n + ' sonde(s) et tout leur historique ?');
    }
    return true;
  };

  // ---------- SMTP : afficher/masquer ----------
  var mt = $('#mail-transport');
  if (mt) {
    mt.addEventListener('change', function () {
      var b = $('#smtp-block');
      if (b) b.hidden = mt.value !== 'smtp';
    });
  }

  // ---------- Raccourcis clavier ----------
  document.addEventListener('keydown', function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey || isTyping()) return;
    var go = { a: 'import', d: 'dashboard', i: 'incidents', s: 'monitors' }[e.key];
    if (go) { location.href = 'index.php?p=' + go; return; }
    if (e.key === 'r') { e.preventDefault(); refresh(); toast('Actualisé', '', 1500); }
    if (e.key === '?') {
      toast('Raccourcis : / filtrer · r actualiser · d tableau de bord · s sondes · i incidents · a ajouter', '', 8000);
    }
  });
})();

/* ============================================================================
   Deuxième bloc : liste de tâches, palette de commandes, annulation.
   Chargé sur toutes les pages ; chaque morceau se désactive s'il n'a rien à faire.
   ========================================================================== */
(function () {
  'use strict';
  var V = window.UPTIMEEZ || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var isTyping = function () {
    var el = document.activeElement;
    return el && (/input|textarea|select/i.test(el.tagName) || el.isContentEditable);
  };

  function toast(msg, tone, ms, undoToken) {
    var box = $('#toasts');
    if (!box) return null;
    var el = document.createElement('div');
    el.className = 'toast ' + (tone || '');
    el.setAttribute('role', 'status');
    if (undoToken) {
      var span = document.createElement('span');
      span.className = 'undo';
      span.appendChild(document.createTextNode(msg + ' '));
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = 'Annuler';
      b.addEventListener('click', function () {
        post('undo', { token: undoToken }).then(function (r) {
          el.remove();
          toast(r.message || 'Annulé', r.ok ? 'ok' : 'warn');
          if (r.ok) setTimeout(function () { location.reload(); }, 600);
        });
      });
      span.appendChild(b);
      el.appendChild(span);
    } else {
      el.textContent = msg;
    }
    box.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .25s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 260);
    }, ms || (undoToken ? 9000 : 4200));
    return el;
  }

  function post(action, data) {
    var body = new URLSearchParams(data || {});
    body.set('action', action);
    body.set('csrf', V.csrf || '');
    return fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
      body: body.toString(), credentials: 'same-origin'
    }).then(function (r) {
      if (r.status === 401) { location.href = 'index.php?p=login'; throw new Error('auth'); }
      return r.json();
    });
  }

  // ---------- Correctifs appliqués depuis la liste de tâches ----------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.js-fix');
    if (b) {
      e.preventDefault();
      b.classList.add('is-busy');
      post('fix', { id: b.dataset.id, fix: b.dataset.fix }).then(function (r) {
        b.classList.remove('is-busy');
        if (r.error) { toast('Erreur : ' + (r.message || r.error), 'bad'); return; }
        toast(r.message || 'Fait', r.ok ? 'ok' : 'warn', null, r.undo);
        if (r.ok && !r.undo) setTimeout(function () { location.reload(); }, 900);
      }).catch(function () { b.classList.remove('is-busy'); });
      return;
    }

    // ---------- Rapport prêt à coller ----------
    var c = e.target.closest('.js-copy-report');
    if (c) {
      e.preventDefault();
      c.classList.add('is-busy');
      fetch('api.php?action=report&id=' + encodeURIComponent(c.dataset.id),
            { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (r) {
          c.classList.remove('is-busy');
          if (!r.ok) { toast('Rapport indisponible', 'bad'); return; }
          var done = function () { toast('Rapport copié — prêt à coller dans un ticket', 'ok'); };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(r.report).then(done, function () { fallback(r.report, done); });
          } else fallback(r.report, done);
        }).catch(function () { c.classList.remove('is-busy'); });
    }
  });

  function fallback(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (err) { toast('Copie impossible', 'warn'); }
    ta.remove();
  }

  // ============================ Palette de commandes ========================
  var COMMANDS = [
    { label: 'Aujourd’hui — ce qu’il y a à faire', ico: 'check', go: 'index.php?p=today' },
    { label: 'Tableau de bord (mur d’écran)', ico: 'grid', go: 'index.php?p=dashboard' },
    { label: 'Ajouter des sites', ico: 'plus', go: 'index.php?p=import' },
    { label: 'Toutes les sondes', ico: 'list', go: 'index.php?p=monitors' },
    { label: 'Incidents', ico: 'history', go: 'index.php?p=incidents' },
    { label: 'Rapport client', ico: 'file', go: 'index.php?p=report' },
    { label: 'Journal des évènements et alertes', ico: 'bell', go: 'index.php?p=events' },
    { label: 'Réglages', ico: 'sliders', go: 'index.php?p=settings' },
    { label: 'Tout revérifier maintenant', ico: 'refresh', act: 'checkall' },
    { label: 'Basculer clair / sombre', ico: 'moon', act: 'theme' }
  ];
  var ICONS = {
    check: 'M20 6 9 17l-5-5', grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
    plus: 'M12 5v14M5 12h14', list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
    history: 'M3 12a9 9 0 1 0 9-9 9 9 0 0 0-7 3.4M3 4v4h4M12 8v4l3 2',
    bell: 'M18 9a6 6 0 1 0-12 0c0 6-2 7-2 7h16s-2-1-2-7M10.3 21a2 2 0 0 0 3.4 0',
    sliders: 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12', refresh: 'M21 12a9 9 0 1 1-3-6.7M21 4v5h-5',
    moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z', file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5',
    dot: 'M12 12h.01'
  };
  function svg(name) {
    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' +
      (ICONS[name] || ICONS.dot) + '"/></svg>';
  }

  var pal = null, palItems = [], palIdx = 0, palTimer = null;

  // Même repli qu'au serveur : « regl » doit trouver « Réglages », « munchen »
  // trouver « München ». On décompose et on retire les signes diacritiques.
  function fold(t) {
    return String(t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function openPalette() {
    if (pal) return;
    pal = document.createElement('div');
    pal.className = 'pal-back';
    pal.innerHTML =
      '<div class="pal" role="dialog" aria-modal="true" aria-label="Palette de commandes">' +
        '<input type="text" id="pal-q" placeholder="Chercher un site, lancer une action…" ' +
          'autocomplete="off" spellcheck="false" aria-controls="pal-list">' +
        '<div class="pal-list" id="pal-list" role="listbox"></div>' +
        '<div class="pal-foot"><span><kbd>↑</kbd><kbd>↓</kbd> naviguer</span>' +
          '<span><kbd>Entrée</kbd> ouvrir</span><span><kbd>Échap</kbd> fermer</span></div>' +
      '</div>';
    document.body.appendChild(pal);
    pal.addEventListener('click', function (e) { if (e.target === pal) closePalette(); });
    var input = $('#pal-q', pal);
    input.addEventListener('input', function () { schedule(input.value); });
    input.addEventListener('keydown', onKey);
    input.focus();
    render(COMMANDS.map(toRow), '');
    schedule('');
  }
  function closePalette() { if (pal) { pal.remove(); pal = null; palItems = []; palIdx = 0; } }

  function toRow(c) { return { label: c.label, sub: '', ico: c.ico, go: c.go, act: c.act }; }

  function schedule(q) {
    if (palTimer) clearTimeout(palTimer);
    palTimer = setTimeout(function () { search(q); }, 110);
  }

  function search(q) {
    var needle = fold(q.trim());
    var cmds = COMMANDS.filter(function (c) {
      return !needle || fold(c.label).indexOf(needle) !== -1;
    }).map(toRow);
    fetch('api.php?action=search&q=' + encodeURIComponent(q),
          { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        var sites = (r.results || []).map(function (s) {
          return { label: s.name, sub: s.sub + ' · ' + s.label, ico: 'dot',
                   go: 'index.php?p=monitor&id=' + s.id, status: s.status };
        });
        render(sites.concat(cmds), needle);
      }).catch(function () { render(cmds, needle); });
  }

  function render(rows, needle) {
    var list = $('#pal-list', pal);
    if (!list) return;
    palItems = rows.slice(0, 14);
    palIdx = 0;
    if (!palItems.length) {
      list.innerHTML = '<div class="pal-empty">Aucun résultat pour « ' +
        needle.replace(/[<>&]/g, '') + ' »</div>';
      return;
    }
    list.innerHTML = palItems.map(function (it, i) {
      var dot = it.status ? '<span class="dot dot-' + it.status + '"></span>' : svg(it.ico);
      return '<div class="pal-item" role="option" data-i="' + i + '" aria-selected="' +
        (i === 0 ? 'true' : 'false') + '"><span class="pal-ico">' + dot + '</span>' +
        '<span>' + escapeHtml(it.label) + '</span>' +
        (it.sub ? '<span class="pal-sub">' + escapeHtml(it.sub) + '</span>' : '') + '</div>';
    }).join('');
    $$('.pal-item', list).forEach(function (el) {
      el.addEventListener('click', function () { run(palItems[+el.dataset.i]); });
      el.addEventListener('mousemove', function () { select(+el.dataset.i); });
    });
  }

  function select(i) {
    palIdx = Math.max(0, Math.min(palItems.length - 1, i));
    $$('.pal-item', pal).forEach(function (el, k) {
      el.setAttribute('aria-selected', k === palIdx ? 'true' : 'false');
      if (k === palIdx && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    });
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); closePalette(); return; }
    if (e.key === 'ArrowDown') { e.preventDefault(); select(palIdx + 1); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); select(palIdx - 1); return; }
    if (e.key === 'Enter') {
      e.preventDefault();
      if (palItems.length) run(palItems[palIdx]);
    }
  }

  function run(item) {
    if (!item) return;
    closePalette();
    if (item.go) { location.href = item.go; return; }
    if (item.act === 'theme') { var t = $('#theme-toggle'); if (t) t.click(); return; }
    if (item.act === 'checkall') {
      var c = $('#check-all');
      if (c) { c.click(); } else { location.href = 'index.php?p=today'; }
    }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      pal ? closePalette() : openPalette();
      return;
    }
    if (e.key === 'k' && !isTyping() && !e.ctrlKey && !e.metaKey && !e.altKey) {
      e.preventDefault();
      openPalette();
    }
  });
  var trigger = document.getElementById('palette-open');
  if (trigger) trigger.addEventListener('click', openPalette);
})();

/* ==========================================================================
   Aides contextuelles « ? » : utilisables au doigt comme au clavier.
   ========================================================================== */
(function () {
  'use strict';

  // La bulle est sortie du flux : en position fixe, aucun parent ne peut la
  // rogner (overflow) ni la faire passer dessous (z-index d'un accordéon, d'un
  // en-tête collant, d'une carte). On calcule ses coordonnées à l'ouverture.
  function place(hint) {
    var tip = hint.querySelector('.hint-t');
    var btn = hint.querySelector('.hint-b');
    if (!tip || !btn) return;
    tip.classList.add('hint-fixed');
    tip.style.left = tip.style.top = 'auto';
    // Mesure à taille réelle, avant de décider du côté.
    var prev = tip.style.visibility;
    tip.style.visibility = 'hidden';
    tip.style.display = 'block';
    var b = btn.getBoundingClientRect();
    var w = tip.offsetWidth, h = tip.offsetHeight;
    tip.style.display = '';
    tip.style.visibility = prev;

    var margin = 8;
    var left = Math.min(Math.max(margin, b.left - 8), window.innerWidth - w - margin);
    var above = b.top - h - margin >= margin;
    tip.style.left = left + 'px';
    tip.style.top = (above ? b.top - h - margin : b.bottom + margin) + 'px';
    hint.classList.toggle('hint-down', !above);
  }

  // Une bulle ouverte suit son bouton si la page bouge sous elle.
  function replaceOpen() {
    var open = document.querySelector('.hint.open');
    if (open) place(open);
  }
  window.addEventListener('scroll', replaceOpen, { passive: true });
  window.addEventListener('resize', replaceOpen);

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-hint]');
    document.querySelectorAll('.hint.open').forEach(function (h) {
      if (!b || h !== b.parentNode) h.classList.remove('open');
    });
    if (!b) return;
    e.preventDefault();
    var hint = b.parentNode;
    place(hint);
    hint.classList.toggle('open');
  });

  document.addEventListener('focusin', function (e) {
    var b = e.target.closest && e.target.closest('[data-hint]');
    if (b) place(b.parentNode);
  });

  // Au survol aussi : la bulle apparaît en CSS, il faut l'avoir placée avant.
  document.addEventListener('mouseover', function (e) {
    var b = e.target.closest && e.target.closest('[data-hint]');
    if (b) place(b.parentNode);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.hint.open').forEach(function (h) {
      h.classList.remove('open');
    });
  });
})();
