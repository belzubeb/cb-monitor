/* Modal konfirmasi dan input PIN, pengganti window.confirm dan window.prompt.
   cbConfirm({title, message, okText, cancelText, danger}, onYes)
   cbPin({title, message, okText}, onSubmit)   -> onSubmit(pin)
   Jika browser tidak mendukung <dialog>, otomatis memakai popup bawaan browser. */
(function () {
  var hasDialog = typeof HTMLDialogElement === 'function';

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }

  function show(opts, withInput, done) {
    var dlg = el('dialog', 'cbm');
    dlg.setAttribute('aria-labelledby', 'cbm-title');
    var inner = el('div', 'in');
    var h = el('h2', '', opts.title || ''); h.id = 'cbm-title';
    inner.appendChild(h);
    if (opts.message) { inner.appendChild(el('p', '', opts.message)); }
    var input = null;
    if (withInput) {
      input = el('input'); input.type = 'password'; input.autocomplete = 'off';
      input.setAttribute('aria-label', 'PIN');
      inner.appendChild(input);
    }
    var btns = el('div', 'btns');
    var no = el('button', '', opts.cancelText || 'Batal'); no.type = 'button';
    var yes = el('button', opts.danger ? 'danger' : 'yes', opts.okText || 'Ya'); yes.type = 'button';
    btns.appendChild(no); btns.appendChild(yes);
    inner.appendChild(btns);
    dlg.appendChild(inner);
    document.body.appendChild(dlg);

    var result = null;
    function finish(ok) {
      if (!ok) { result = false; dlg.close(); return; }
      if (withInput) {
        var v = input.value.trim();
        if (!v) { input.focus(); return; }
        result = v;
      } else { result = true; }
      dlg.close();
    }
    no.addEventListener('click', function () { finish(false); });
    yes.addEventListener('click', function () { finish(true); });
    if (input) { input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); finish(true); } }); }
    // Klik di luar kotak menutup modal
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { finish(false); } });
    dlg.addEventListener('close', function () {
      var r = result;
      if (dlg.parentNode) { dlg.parentNode.removeChild(dlg); }
      if (r) { done(r); }
    });
    dlg.showModal();
    // Aksi berbahaya: fokus awal di Batal supaya tidak tertekan Enter tanpa sengaja
    (input || (opts.danger ? no : yes)).focus();
  }

  window.cbConfirm = function (opts, onYes) {
    if (!hasDialog) { if (window.confirm((opts.title || '') + '\n' + (opts.message || ''))) { onYes(); } return; }
    show(opts, false, function () { onYes(); });
  };
  window.cbPin = function (opts, onSubmit) {
    if (!hasDialog) { var v = window.prompt((opts.title || '') + '\n' + (opts.message || '')) || ''; if (v) { onSubmit(v); } return; }
    show(opts, true, onSubmit);
  };
})();
