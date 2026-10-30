(function () {
  'use strict';

  var previous = document.getElementById('kyaaaa-debug-host');
  if (previous && previous.parentNode) { previous.parentNode.removeChild(previous); }

  var tpl = document.getElementById('kyaaaa-debug-tpl');
  if (!tpl || !tpl.content || !document.body || !document.body.attachShadow) { return; }

  var host = document.createElement('div');
  host.id = 'kyaaaa-debug-host';
  document.body.appendChild(host);

  var root = host.attachShadow({ mode: 'open' });
  root.appendChild(tpl.content.cloneNode(true));

  var KEY = 'kyaaaa-debug';
  var wrap = root.querySelector('.wrap');
  var mark = root.querySelector('.mark');
  var sheet = root.querySelector('.sheet');
  var title = root.querySelector('.sheet-title');
  var chips = Array.prototype.slice.call(root.querySelectorAll('.chip'));
  var panels = Array.prototype.slice.call(root.querySelectorAll('.panel'));

  function load() {
    try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; }
  }

  function save() {
    try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
  }

  var state = load();

  function closeSheet() {
    sheet.hidden = true;
    chips.forEach(function (chip) { chip.setAttribute('aria-expanded', 'false'); });
    state.panel = null;
    save();
  }

  function openPanel(id) {
    var chip = chips.filter(function (c) { return c.getAttribute('data-panel') === id; })[0];
    if (!chip) { return; }

    panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-panel') !== id; });
    chips.forEach(function (c) { c.setAttribute('aria-expanded', c === chip ? 'true' : 'false'); });
    title.textContent = chip.getAttribute('data-title') || id;
    sheet.hidden = false;
    state.panel = id;
    save();
  }

  function setCollapsed(on) {
    wrap.classList.toggle('is-collapsed', on);
    mark.setAttribute('aria-expanded', on ? 'false' : 'true');
    if (on) { closeSheet(); }
    state.collapsed = on;
    save();
  }

  // Colour the SQL keywords without touching innerHTML (the SQL is data).
  var KEYWORDS = /\b(SELECT|FROM|WHERE|AND|OR|INSERT|INTO|VALUES|UPDATE|SET|DELETE|LEFT|RIGHT|INNER|OUTER|FULL|CROSS|JOIN|ON|GROUP BY|ORDER BY|HAVING|LIMIT|OFFSET|UNION|ALL|AS|IN|LIKE|NOT|NULL|IS|DISTINCT|COUNT|SUM|AVG|MIN|MAX|TRUNCATE|TABLE|ASC|DESC)\b/gi;

  Array.prototype.forEach.call(root.querySelectorAll('.sql'), function (el) {
    var text = el.textContent;
    var last = 0;
    var m;
    el.textContent = '';
    KEYWORDS.lastIndex = 0;
    while ((m = KEYWORDS.exec(text)) !== null) {
      el.appendChild(document.createTextNode(text.slice(last, m.index)));
      var span = document.createElement('span');
      span.className = 'kw';
      span.textContent = m[0];
      el.appendChild(span);
      last = m.index + m[0].length;
    }
    el.appendChild(document.createTextNode(text.slice(last)));
  });

  function copy(text, button) {
    var done = function () {
      var old = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(function () { button.textContent = old; }, 1400);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done);
      return;
    }

    var area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try { if (document.execCommand('copy')) { done(); } } catch (e) { /* ignore */ }
    document.body.removeChild(area);
  }

  root.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) { return; }

    if (target.closest('.mark')) { setCollapsed(!wrap.classList.contains('is-collapsed')); return; }
    if (target.closest('.sheet-close')) { closeSheet(); return; }

    var chip = target.closest('.chip');
    if (chip) {
      var id = chip.getAttribute('data-panel');
      if (chip.getAttribute('aria-expanded') === 'true') { closeSheet(); } else { openPanel(id); }
      return;
    }

    var button = target.closest('[data-copy]');
    if (button) {
      var block = button.closest('.query');
      var sql = block ? block.querySelector('.sql') : null;
      if (sql) { copy(sql.textContent, button); }
    }
  });

  if (window.__kyaaaaDebugKey) { document.removeEventListener('keydown', window.__kyaaaaDebugKey); }
  window.__kyaaaaDebugKey = function (event) {
    if (event.altKey && (event.key === 'k' || event.key === 'K')) {
      event.preventDefault();
      setCollapsed(!wrap.classList.contains('is-collapsed'));
    } else if (event.key === 'Escape' && !sheet.hidden) {
      closeSheet();
    }
  };
  document.addEventListener('keydown', window.__kyaaaaDebugKey);

  if (state.collapsed) {
    setCollapsed(true);
  } else if (state.panel) {
    openPanel(state.panel);
  }
})();
