/* Sidebar navigasi untuk semua halaman KECUALI monitor.html (layar TV).
   Pasang di <head> halaman:  <script src="../assets/js/nav.js"></script>
   (dari index.html di root: assets/js/nav.js). Untuk menambah atau mengubah menu, edit daftar MENU di bawah. */
(function () {
  var script = document.currentScript;
  var ROOT = script && script.src ? script.src.replace(/assets\/js\/nav\.js(\?.*)?$/, '') : '';

  // c = warna penanda (sama dengan warna status di monitor), tab = buka di tab baru
  var MENU = [
    { href: 'index.html', label: 'Beranda', c: '#d9d9d9' },
    { title: 'Troli', items: [
      { href: 'pages/scan.html?mode=out', label: 'Kirim troli (OUT)', c: '#ffd24d', also: ['pages/slip.html'] },
      { href: 'pages/scan.html?mode=in', label: 'Terima troli (IN)', c: '#8fd0ff' }
    ] },
    { title: 'Supply list', items: [
      { href: 'pages/planning.html', label: 'Planning hari ini', c: '#ffff00' },
      { href: 'pages/supply.html?line=2', label: 'Cetak supply list Line 2', c: '#00ff00' },
      { href: 'pages/supply.html?line=3', label: 'Cetak supply list Line 3', c: '#00ff00' }
    ] },
    { title: 'Monitor TV', items: [
      { href: 'pages/monitor.html?line=2', label: 'Monitor Line 2', c: '#00ff00', tab: true },
      { href: 'pages/monitor.html?line=3', label: 'Monitor Line 3', c: '#00ff00', tab: true }
    ] },
    { title: 'Pengembang', fold: true, items: [
      { href: 'admin/setup.php', label: 'Pengaturan database', c: '#666' },
      { href: 'api/monitor.php?check=1', label: 'Uji sambungan sistem', c: '#666', tab: true },
      { href: 'pages/monitor.html?line=2&mock=1', label: 'Demo monitor', c: '#666', tab: true },
      { href: 'pages/supply.html?line=2&mock=1', label: 'Demo supply list', c: '#666' }
    ] }
  ];

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  }

  // Alamat relatif terhadap folder utama aplikasi (tidak bergantung pada nama folder di htdocs)
  function relPath(href) {
    var r = href.indexOf(ROOT) === 0 ? href.slice(ROOT.length) : href;
    r = r.split(/[?#]/)[0];
    return r === '' ? 'index.html' : r;
  }
  var here = new URL(location.href);
  var herePath = relPath(location.href);

  // Cocok jika file sama dan semua parameter (mode, line) di menu sama dengan halaman ini
  function isActive(item) {
    var u = new URL(ROOT + item.href, location.href);
    var p = relPath(u.href);
    if ((item.also || []).indexOf(herePath) !== -1) { return true; }  // halaman turunan, mis. slip.html milik "Kirim troli"
    if (p !== herePath) { return false; }
    if (here.searchParams.has('mock') !== u.searchParams.has('mock')) { return false; }
    var ok = true;
    ['mode', 'line'].forEach(function (k) {
      if (u.searchParams.has(k) && u.searchParams.get(k) !== here.searchParams.get(k)) { ok = false; }
    });
    return ok;
  }

  function build() {
    if (document.body.classList.contains('no-nav') || document.getElementById('cbnav')) { return; }

    var link = el('link'); link.rel = 'stylesheet'; link.href = ROOT + 'assets/css/nav.css';
    document.head.appendChild(link);

    var nav = el('nav', 'cbnav'); nav.id = 'cbnav'; nav.setAttribute('aria-label', 'Menu utama');
    var brand = el('a', 'brand'); brand.href = ROOT + 'index.html';
    brand.appendChild(el('b', '', 'Supply List CB'));
    brand.appendChild(el('span', '', 'Cover Bottom'));
    nav.appendChild(brand);

    function itemLi(it) {
      var li = el('li');
      var a = el('a', 'item'); a.href = ROOT + it.href;
      a.style.setProperty('--navc', it.c || '#666');
      a.appendChild(el('span', 'dot'));
      a.appendChild(el('span', '', it.label));
      if (it.tab) { a.target = '_blank'; a.rel = 'noopener'; a.appendChild(el('span', 'ext', '\u2197')); a.title = 'Dibuka di tab baru'; }
      if (isActive(it)) { a.setAttribute('aria-current', 'page'); }
      li.appendChild(a);
      return li;
    }

    MENU.forEach(function (g) {
      if (!g.items) { var ul0 = el('ul'); ul0.appendChild(itemLi(g)); nav.appendChild(ul0); return; }
      var ul = el('ul');
      var hasActive = false;
      g.items.forEach(function (it) { var li = itemLi(it); if (li.firstChild.getAttribute('aria-current')) { hasActive = true; } ul.appendChild(li); });
      if (g.fold) {
        var d = el('details'); if (hasActive) { d.open = true; }
        d.appendChild(el('summary', '', g.title)); d.appendChild(ul); nav.appendChild(d);
      } else {
        nav.appendChild(el('h2', '', g.title)); nav.appendChild(ul);
      }
    });

    var top = el('div', 'cbnav-top');
    var btn = el('button', '', '\u2630 Menu'); btn.type = 'button'; btn.setAttribute('aria-controls', 'cbnav'); btn.setAttribute('aria-expanded', 'false');
    top.appendChild(btn); top.appendChild(el('b', '', 'Supply List CB'));
    var ov = el('div', 'cbnav-ov');

    function setOpen(on) {
      nav.classList.toggle('open', on); ov.classList.toggle('open', on);
      btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    }
    btn.addEventListener('click', function () { setOpen(!nav.classList.contains('open')); });
    ov.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });

    document.body.classList.add('has-nav');
    document.body.insertBefore(ov, document.body.firstChild);
    document.body.insertBefore(nav, document.body.firstChild);
    document.body.insertBefore(top, document.body.firstChild);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', build); } else { build(); }
})();
