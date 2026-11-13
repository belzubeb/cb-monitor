# Monitor Cover Bottom - Tahap 1 (Read-Only)

Sistem pemantauan (monitoring) data Cover Bottom untuk area produksi. Aplikasi ini dirancang untuk berjalan di lingkungan lokal menggunakan XAMPP dan mengambil data dari server pusat secara remote.

## Spesifikasi Teknis
- **Server:** Apache (XAMPP for Windows)
- **Database:** Remote MySQL Server (Host: 192.50.0.235, Port: 3306)
- **Frontend:** HTML5, CSS, JavaScript (Native)

## Instruksi Instalasi
1. Salin folder `cb-monitor` ke direktori root web server Anda (contoh: `C:\xampp\htdocs\cb-monitor`).
2. Pastikan file `index.html` dan direktori `admin/` berada langsung di bawah folder tersebut.
3. Jalankan service Apache melalui XAMPP Control Panel.
4. Pastikan PC server memiliki akses jaringan ke database pusat (192.50.0.235).

## Konfigurasi Database
Aplikasi ini menggunakan sistem konfigurasi berbasis web. Lakukan langkah-langkah berikut langsung dari PC server:
1. Akses halaman setup: `http://localhost/cb-monitor/admin/setup.php`
2. Masukkan kredensial database (Host, Port, Username, Password).
3. Klik tombol **Tes koneksi** untuk memastikan integrasi berhasil.
4. Klik **Simpan** untuk menerapkan konfigurasi.

## Penggunaan Monitor
Akses halaman monitor menggunakan parameter `line` sesuai dengan jalur produksi yang ingin dipantau:
- Line 2: `http://localhost/cb-monitor/pages/monitor.html?line=2`
- Line 3: `http://localhost/cb-monitor/pages/monitor.html?line=3`

Gunakan tombol **F11** pada browser untuk mode layar penuh (Full Screen).

## Akses dari Jaringan
Untuk membuka monitor dari PC lain atau Smart TV di jaringan yang sama:
1. Gunakan alamat IP PC server sebagai pengganti localhost.
   Contoh: `http://[IP_SERVER]/cb-monitor/pages/monitor.html?line=2`
2. Pastikan Windows Firewall pada PC server memberikan izin (Allow) untuk port 80 (Apache).
3. Halaman `admin/setup.php` dibatasi ke akses lokal (localhost) demi alasan keamanan.
