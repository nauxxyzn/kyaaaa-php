(function () {
  'use strict';

  function all(selector, context) {
    return Array.prototype.slice.call((context || document).querySelectorAll(selector));
  }

  // --- light PHP syntax colouring, one source line at a time (no innerHTML) ---
  var PHP = /(\/\/[^\n]*|#[^\n]*|\/\*.*?\*\/|\/\*.*|^\s*\*.*)|("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(\$[A-Za-z_]\w*)|\b(namespace|use|class|function|public|private|protected|static|return|new|if|else|elseif|foreach|as|for|while|throw|try|catch|fn|null|true|false|array|echo|extends|implements|final|const|declare|match|abstract|interface|trait|instanceof|self|parent)\b|(\b\d+(?:\.\d+)?\b)|(<\?php|<\?=|\?>)/g;
  var CLASSES = [null, 'tk-c', 'tk-s', 'tk-v', 'tk-k', 'tk-n', 'tk-t'];

  all('.src .ln code').forEach(function (code) {
    var text = code.textContent;
    var last = 0;
    var m;
    code.textContent = '';
    PHP.lastIndex = 0;
    while ((m = PHP.exec(text)) !== null) {
      if (m[0] === '') { PHP.lastIndex++; continue; }
      var cls = null;
      for (var i = 1; i < m.length; i++) {
        if (m[i] !== undefined) { cls = CLASSES[i]; break; }
      }
      code.appendChild(document.createTextNode(text.slice(last, m.index)));
      var span = document.createElement('span');
      span.className = cls;
      span.textContent = m[0];
      code.appendChild(span);
      last = m.index + m[0].length;
    }
    code.appendChild(document.createTextNode(text.slice(last)));
  });

  // --- frame selection ---
  function showFrame(n) {
    all('.frame').forEach(function (frame) { frame.hidden = frame.getAttribute('data-frame') !== String(n); });
    all('.ep-stack li').forEach(function (li) {
      var on = li.getAttribute('data-frame') === String(n);
      var button = li.querySelector('.frame-btn');
      li.classList.toggle('is-selected', on);
      if (on) { button.setAttribute('aria-current', 'true'); } else { button.removeAttribute('aria-current'); }
    });

    var pane = document.querySelector('.ep-code');
    var hit = document.querySelector('.frame[data-frame="' + n + '"] .ln.hit');
    if (pane && hit) { pane.scrollTop = Math.max(0, hit.offsetTop - pane.clientHeight / 2); }
  }

  all('.ep-stack .frame-btn').forEach(function (button) {
    button.addEventListener('click', function () {
      showFrame(button.parentNode.getAttribute('data-frame'));
    });
  });

  var selected = document.querySelector('.ep-stack li.is-selected');
  if (selected) { showFrame(selected.getAttribute('data-frame')); }

  var hide = document.getElementById('ep-hide');
  if (hide) {
    hide.addEventListener('change', function () { document.body.classList.toggle('hide-vendor', hide.checked); });
  }

  // --- context tabs ---
  all('.tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var id = tab.getAttribute('data-tab');
      all('.tab').forEach(function (t) { t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
      all('.tabpanel').forEach(function (panel) { panel.hidden = panel.getAttribute('data-tabpanel') !== id; });
    });
  });

  // --- SQL keywords in the queries tab ---
  var KEYWORDS = /\b(SELECT|FROM|WHERE|AND|OR|INSERT|INTO|VALUES|UPDATE|SET|DELETE|LEFT|RIGHT|INNER|OUTER|FULL|CROSS|JOIN|ON|GROUP BY|ORDER BY|HAVING|LIMIT|OFFSET|UNION|ALL|AS|IN|LIKE|NOT|NULL|IS|DISTINCT|COUNT|SUM|AVG|MIN|MAX|TRUNCATE|TABLE|ASC|DESC)\b/gi;

  all('.tabpanel .sql').forEach(function (el) {
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

  // --- copy buttons ---
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

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) { return; }

    var button = target.closest('[data-copy-text]');
    if (button) { copy(button.getAttribute('data-copy-text'), button); return; }

    var sqlButton = target.closest('.tabpanel [data-copy]');
    if (sqlButton) {
      var block = sqlButton.closest('.query');
      var sql = block ? block.querySelector('.sql') : null;
      if (sql) { copy(sql.textContent, sqlButton); }
    }
  });
})();
