<?php
// Pengaturan bawaan Monitor Cover Bottom.
// Jangan edit password di sini. Pakai halaman setup.php (GUI), hasilnya disimpan di settings.local.php
// dan otomatis menimpa nilai bawaan di bawah.
$defaults = [
    'timezone' => 'Asia/Jakarta',

    // Server MySQL .235 (sama dengan yang dipakai POP). Bukan MySQL milik XAMPP.
    'db' => [
        'host' => '192.50.0.235',
        'port' => 3306,
        'user' => 'pop',
        'pass' => '',
    ],

    'db_main' => 'ardatabase',      // tempat tabel tblcbsupply, tblcbsupplyitem, tblcbsupplylog

    // Kode proses ERP (prdpc_no) untuk line Cover Bottom dan nama line di supply list.
    'cb_lines' => [
        'E04004' => 'C/B Assy 2',
        'E04006' => 'C/B Assy 3',
    ],

    'db_led' => 'dbled',            // berisi tblledsupply
    'db_mes' => 'is_mes_v3_wi',     // berisi v_ppr_work_ord dan v_pba_fac_part_no (view ke ERP)

    // Parameter ?line= di alamat halaman dan nama line di tblledsupply.cLine
    'lines' => [
        '2' => 'LINE 2',
        '3' => 'LINE 3',
    ],

    // Sumber angka Prod:
    //   'erp'     = prod_gd_qty dari ERP per WO (sama untuk semua ProdNo dari WO yang sama)
    //   'scanout' = tblledsupply.ScanOut per ProdNo
    'prod_source' => 'erp',

    // true = api.php menampilkan detail error (berguna saat pemasangan). Ubah ke false setelah stabil.
    'show_errors' => true,

    // false = supply list dibuat MANUAL lewat tombol di halaman Planning. true = sinkronisasi membuatnya otomatis.
    'auto_create' => false,

    // PIN untuk aksi di halaman Planning (Batalkan, Buat, Sinkron). Kosong = tanpa PIN. Atur lewat setup.php.
    'admin_pin' => '',

    // setup.php hanya bisa dibuka dari PC server itu sendiri. Ubah ke true hanya jika benar-benar perlu
    // membuka setup dari PC lain (tidak disarankan).
    'setup_allow_remote' => false,
];

$local = __DIR__ . '/settings.local.php';
if (is_file($local)) {
    $saved = require $local;
    if (is_array($saved)) {
        $defaults = array_replace_recursive($defaults, $saved);
    }
}

return $defaults;