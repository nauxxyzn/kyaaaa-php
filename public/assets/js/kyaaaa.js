/* Kyaaaa-PHP default theme: code highlighting, copy buttons, theme toggle, docs scroll-spy. */
(function () {
  'use strict';

  if (window.__kyaaaaUi) { return; }
  window.__kyaaaaUi = true;

  var RULES = {
    php: {
      re: /(\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)|("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(\$[A-Za-z_]\w*)|\b(namespace|use|class|function|public|private|protected|static|return|new|if|else|elseif|foreach|as|for|while|throw|try|catch|fn|null|true|false|array|echo|extends|implements|final|const|declare|match|never|void|string|int|bool|object)\b|(\b\d+(?:\.\d+)?\b)|(<\?php|<\?=|\?>)/g,
      cls: [null, 'c', 's', 'v', 'k', 'n', 't']
    },
    js: {
      re: /(\/\/[^\n]*|\/\*[\s\S]*?\*\/)|("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|`(?:\\.|[^`\\])*`)|\b(const|let|var|function|return|await|async|new|if|else|true|false|null)\b|(\b\d+(?:\.\d+)?\b)/g,
      cls: [null, 'c', 's', 'k', 'n']
    },
    bash: {
      re: /(#[^\n]*)|("(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(\$[A-Za-z_]\w*)|(--[\w-]+)|\b(composer|php|cd|cp|mkdir|git)(?![\w.\/:-])|([\w.\/:@=-]+)/g,
      cls: [null, 'c', 's', 'v', 'n', 'k', null]
    },
    env: {
      re: /(#[^\n]*)|^([A-Z][A-Z0-9_]*)(?==)/gm,
      cls: [null, 'c', 'k']
    }
  };

  function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function highlight(code) {
    var rule = RULES[code.getAttribute('data-lang')];
    if (!rule || code.getAttribute('data-hl')) { return; }

    var text = code.textContent;
    var out = '';
    var last = 0;
    var m;

    rule.re.lastIndex = 0;
    while ((m = rule.re.exec(text)) !== null) {
      if (m[0] === '') { rule.re.lastIndex++; continue; }

      var cls = null;
      for (var i = 1; i < m.length; i++) {
        if (m[i] !== undefined) { cls = rule.cls[i]; break; }
      }

      out += escapeHtml(text.slice(last, m.index));
      out += cls ? '<span class="tk-' + cls + '">' + escapeHtml(m[0]) + '</span>' : escapeHtml(m[0]);
      last = m.index + m[0].length;
    }

    code.innerHTML = out + escapeHtml(text.slice(last));
    code.setAttribute('data-hl', '1');
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise(function (resolve, reject) {
      var area = document.createElement('textarea');
      area.value = text;
      area.setAttribute('readonly', '');
      area.style.position = 'fixed';
      area.style.opacity = '0';
      document.body.appendChild(area);
      area.select();

      var ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(area);

      return ok ? resolve() : reject(new Error('copy failed'));
    });
  }

  function flash(button, label) {
    button.textContent = label;
    window.setTimeout(function () { button.textContent = 'Copy'; }, 1600);
  }

  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) { return; }

    var copy = target.closest('[data-copy]');
    if (copy) {
      var block = copy.closest('.k-code');
      var code = block ? block.querySelector('code') : null;
      if (code) {
        copyText(code.textContent).then(
          function () { flash(copy, 'Copied'); },
          function () { flash(copy, 'Select and copy'); }
        );
      }
      return;
    }

    if (target.closest('[data-theme-toggle]')) {
      var root = document.documentElement;
      var current = root.getAttribute('data-theme')
        || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
      var next = current === 'dark' ? 'light' : 'dark';

      root.setAttribute('data-theme', next);
      try { localStorage.setItem('kyaaaa-theme', next); } catch (e) { /* storage unavailable */ }
    }
  });

  var spy = null;

  function initSpy() {
    if (spy) { spy.disconnect(); spy = null; }

    var links = document.querySelectorAll('.k-toc a[href^="#"]');
    if (!links.length || !('IntersectionObserver' in window)) { return; }

    var byId = {};
    Array.prototype.forEach.call(links, function (a) { byId[a.getAttribute('href').slice(1)] = a; });

    spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting || !byId[entry.target.id]) { return; }
        Array.prototype.forEach.call(links, function (a) { a.removeAttribute('aria-current'); });
        byId[entry.target.id].setAttribute('aria-current', 'true');
      });
    }, { rootMargin: '-80px 0px -70% 0px' });

    Array.prototype.forEach.call(document.querySelectorAll('.k-prose h2[id]'), function (h) { spy.observe(h); });
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('code[data-lang]'), highlight);
    initSpy();
  }

  boot();
  document.addEventListener('turbolinks:load', boot);
})();
