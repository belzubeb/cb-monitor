<?php
// Sinkronisasi supply list Cover Bottom. Dijalankan lewat Windows Task Scheduler tiap 5 menit:
//   C:\xampp\php\php.exe C:\xampp\htdocs\cb-monitor\cli\sync.php
// Tanggal tertentu (untuk uji):  php cli/sync.php 2026-10-15
// Hasilnya dicatat ke storage/sync.log.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'File ini hanya untuk dijalankan lewat Task Scheduler atau command prompt.';
    exit;
}

require __DIR__ . '/../src/sync_lib.php';

$date = isset($argv[1]) ? $argv[1] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Format tanggal harus YYYY-MM-DD\n");
    exit(2);
}

$logFile = __DIR__ . '/../storage/sync.log';
$stamp = date('Y-m-d H:i:s');

try {
    $r = sync_run($date);
    $summary = $stamp . ' tanggal ' . $r['date']
        . ': dibuat ' . $r['created'] . ', diperbarui ' . $r['revised']
        . ', ditandai ' . $r['flagged'] . ', dibatalkan ' . $r['cancelled']
        . ', tetap ' . $r['unchanged'] . ', menunggu dibuat manual ' . $r['pending'] . ', gagal ' . $r['errors'];
    $out = $summary . "\n";
    foreach ($r['lines'] as $l) {
        $out .= '    ' . $l . "\n";
    }
    echo $out;
    // Log hanya ditulis jika ada kejadian, supaya file tidak membengkak tiap 5 menit.
    if ($r['created'] + $r['revised'] + $r['flagged'] + $r['cancelled'] + $r['errors'] > 0 || count($r['lines']) > 0) {
        @file_put_contents($logFile, $out, FILE_APPEND | LOCK_EX);
    }
    exit($r['errors'] > 0 ? 1 : 0);
} catch (Exception $e) {
    $msg = $stamp . ' ERROR: ' . $e->getMessage() . "\n";
    fwrite(STDERR, $msg);
    @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
    exit(1);
}
