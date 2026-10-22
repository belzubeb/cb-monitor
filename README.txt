MONITOR COVER BOTTOM - TAHAP 1 (BACA-SAJA)
==========================================

PASANG DI XAMPP (Windows)
  1. Extract zip. Salin folder cb-monitor ke:   C:\xampp\htdocs\
     Hasil akhirnya harus begini (bukan folder di dalam folder):
        C:\xampp\htdocs\cb-monitor\index.html
        C:\xampp\htdocs\cb-monitor\admin\setup.php
  2. Buka XAMPP Control Panel, klik Start pada Apache.
     (MySQL milik XAMPP TIDAK perlu dinyalakan. Database yang dipakai adalah server POP di 192.50.0.235.)
  3. Di PC itu, buka browser: http://localhost/cb-monitor/admin/setup.php
     - Host: 192.50.0.235   Port: 3306   Username: pop   Password: (password akun pop)
     - Klik "Tes koneksi". Keempat langkah harus OK.
     - Klik "Simpan".
  4. Buka monitor:
        http://localhost/cb-monitor/pages/monitor.html?line=2
        http://localhost/cb-monitor/pages/monitor.html?line=3
     Tekan F11 untuk layar penuh.

TV ATAU PC LAIN MEMBUKA MONITOR
  Ganti localhost dengan alamat IP PC XAMPP, misalnya:
        http://192.50.0.xxx/cb-monitor/pages/monitor.html?line=2
  Syaratnya:
  - PC XAMPP menyala terus dan Apache berjalan (di XAMPP Control Panel, centang Service di sebelah Apache
    supaya otomatis hidup saat Windows start).
  - Windows Firewall di PC XAMPP mengizinkan Apache (port 80) dari jaringan pabrik.
  - PC XAMPP bisa menjangkau 192.50.0.235 port 3306, dan akun pop boleh login dari PC itu.
  Halaman admin/setup.php sengaja hanya bisa dibuka dari PC XAMPP itu sendiri.

JIKA ADA MASALAH
  - Apache tidak mau Start: biasanya port 80 dipakai program lain. Tutup program itu, atau ubah port Apache.
  - "could not find driver" saat Tes koneksi: aktifkan extension=pdo_mysql di C:\xampp\php\php.ini
    (hapus tanda titik koma di depannya), lalu Restart Apache.
  - "Access denied": username atau password salah, atau akun pop tidak diizinkan dari PC ini.
  - "Connection timed out": PC tidak bisa menjangkau 192.50.0.235:3306 (cek jaringan atau firewall server).
  - Gagal menyimpan: folder config tidak boleh ditulis. Klik kanan folder config, Properties, pastikan bukan Read-only.

ISI FOLDER
  index.html            beranda (pilih tugas)
  pages/                halaman yang dibuka di browser
    monitor.html          monitor TV per line (satu-satunya halaman TANPA menu samping)
    planning.html         planning hari ini, buat/batalkan supply list
    scan.html             kirim troli (OUT, gudang) dan terima troli (IN, line)
    slip.html             cetak slip troli (A4 atau label Zebra)
    supply.html           pratinjau dan cetak supply list per line
  api/                  endpoint data JSON yang dipanggil halaman di atas
    monitor.php           data monitor (uji koneksi: api/monitor.php?check=1)
    planning.php, supply.php, trolley.php
  src/                  logika dan query database (diblokir dari browser)
    lib.php, planning_lib.php, sync_lib.php, trolley_lib.php
  config/               pengaturan (diblokir dari browser)
    config.php            pengaturan bawaan (tidak perlu diedit)
    settings.local.php    dibuat otomatis oleh setup.php, berisi password. Jangan dibagikan.
  admin/setup.php       pengaturan database (GUI) dan tes koneksi, hanya dari PC server
  cli/sync.php          sinkronisasi supply list untuk Task Scheduler (diblokir dari browser)
  storage/              sync.log (diblokir dari browser)
  assets/               css/cb.css, css/nav.css, js/cb.js, js/nav.js
  demo/                 data contoh untuk mode demo
  monitor.html, sync.php (di root)   PENGALIH untuk alamat lama, lihat bagian di bawah

MENU SAMPING
  Semua halaman punya menu samping (di HP berubah jadi tombol Menu) KECUALI pages/monitor.html.
  Untuk menambah atau mengubah menu, edit daftar MENU di assets/js/nav.js (satu tempat untuk semua halaman).
  Halaman baru cukup menambahkan:  <script src="../assets/js/nav.js"></script>  di <head>.

NOMOR TROLI OTOMATIS
  Saat troli dikirim (Scan OUT), sistem memberi nomor sendiri: T + tanggal + urutan hari itu,
  contoh T261020-001, T261020-002, dst. Petugas tidak perlu mengetik kode troli.
  Nomor tercetak di slip, tampil di daftar "Troli sedang di jalan", dan jadi isi kolom RACK di monitor.
  Saat Terima troli (IN), scan barcode slip, atau ketik nomor troli jika slip hilang.
  Pengiriman lama yang tidak punya nomor tetap tampil kosong/"-".

JIKA PINDAH DARI VERSI SEBELUM FOLDERISASI (penting)
  1. Hapus isi folder cb-monitor lama, lalu salin folder baru. Simpan dulu config\settings.local.php
     (atau settings.local.php lama) kalau mau memakai pengaturan password yang sama: taruh di folder config\.
  2. Bookmark TV lama (.../cb-monitor/monitor.html?line=2) tetap jalan lewat file pengalih di root,
     tapi sebaiknya diganti ke .../cb-monitor/pages/monitor.html?line=2.
  3. Task Scheduler: ganti perintahnya menjadi
        C:\xampp\php\php.exe C:\xampp\htdocs\cb-monitor\cli\sync.php
     (sync.php lama di root masih meneruskan ke cli\sync.php, jadi tidak langsung rusak).
  4. Setelah 2 dan 3 selesai, file monitor.html dan sync.php di root boleh dihapus.
  Catatan: file "htaccess" (tanpa titik) dan ".htaccess" lama digantikan oleh .htaccess di tiap folder sensitif.

PRATINJAU SUPPLY LIST (BARU)
  Buka: http://localhost/cb-monitor/pages/supply.html?line=2  (atau line=3)
  Menampilkan tiap WO hari ini beserta BOM-nya (child part x plan WO). Hanya baca, belum menyimpan apa pun.
  Stok belum dihitung, jadi prepare = need. Tombol Cetak ada di halaman itu.
  Jika memperbarui dari versi sebelumnya: salin ulang SEMUA file dari zip ke folder cb-monitor (timpa).

PARAMETER ALAMAT MONITOR
  &debug=1          tampilkan nilai mentah (cStatus, IsNextModel, ScanIN, ScanOut, prod ERP) untuk verifikasi
  &date=2026-10-14  tampilkan tanggal tertentu
  &mock=1           mode demo dengan data contoh (tanpa database)

YANG DILAKUKAN TAHAP 1
  - Membaca tblledsupply (dbled) dan v_ppr_work_ord, v_pba_fac_part_no (is_mes_v3_wi).
  - Tidak ada INSERT, UPDATE, atau DELETE. Sesi database juga dipasang READ ONLY sebagai pengaman.
  - Refresh otomatis 60 detik.

ATURAN TAMPILAN (usulan, perlu dikonfirmasi tim)
  Plan  = work_ord_qty dari ERP per WO (apa adanya).
  Prod  = prod_gd_qty dari ERP per WO (bisa diganti ke ScanOut per ProdNo lewat setup.php).
  RUNNING = cStatus 1
  NEXT    = IsNextModel 1 dan belum ada scan
  FINISH  = sudah ada scan dan cStatus 0
  WAIT    = belum jalan dan belum dipilih next
  Baris FINISH di bagian atas = baris FINISH dengan ID terbesar (perkiraan "yang terakhir selesai").

BELUM ADA DI TAHAP 1
  Kolom RAW (Full Kit atau Shortage) dan RACK masih tanda "-". Menyusul di tahap supply list dan scan troli.
