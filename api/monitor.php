<?php
// Endpoint data untuk monitor.html.
//   api/monitor.php?line=2            data LINE 2 untuk hari ini
//   api/monitor.php?line=3&date=2026-10-14
//   api/monitor.php?check=1           uji koneksi dan akses tabel
require __DIR__ . '/../src/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail_json($code, $msg, $e)
{
    global $CFG;
    http_response_code($code);
    $o = ['error' => $msg];
    if ($e !== null && !empty($CFG['show_errors'])) {
        $o['detail'] = $e->getMessage();
    }
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['check'])) {
    echo json_encode(run_checks($CFG), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$lineKey = isset($_GET['line']) ? (string)$_GET['line'] : '2';
if (!isset($CFG['lines'][$lineKey])) {
    fail_json(400, 'Parameter line tidak valid', null);
}
$date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fail_json(400, 'Parameter date harus berformat YYYY-MM-DD', null);
}

try {
    echo json_encode(build_monitor($date, $lineKey), JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    fail_json(500, 'Gagal membaca database', $e);
}
