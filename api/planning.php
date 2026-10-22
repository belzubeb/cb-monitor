<?php
// API halaman Planning Hari Ini.
//   GET  planning_api.php?date=2026-10-15        daftar WO CB beserta status supply list
//   GET  planning_api.php?items=MO26101500002    isi supply list satu WO
//   POST action=cancel|create wo=MO...           batalkan atau buat manual
//   POST action=sync date=2026-10-15             sinkron sekarang
// Aksi POST meminta PIN jika admin_pin diisi di Pengaturan.
require __DIR__ . '/../src/planning_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($code, $arr)
{
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail_json($e)
{
    global $CFG;
    if ($e instanceof UserError) {
        out(400, ['error' => $e->getMessage()]);
    }
    $o = ['error' => 'Gagal memproses. Pastikan tabel tblcb* sudah dibuat dan koneksi database benar.'];
    if (!empty($CFG['show_errors'])) {
        $o['detail'] = $e->getMessage();
    }
    out(500, $o);
}

function valid_date($d)
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
}

function valid_wo($w)
{
    return preg_match('/^[A-Za-z0-9]{1,13}$/', $w) === 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = isset($CFG['admin_pin']) ? (string)$CFG['admin_pin'] : '';
    if ($pin !== '' && (!isset($_POST['pin']) || (string)$_POST['pin'] !== $pin)) {
        out(403, ['error' => 'PIN salah atau belum diisi.']);
    }
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    try {
        if ($action === 'cancel' || $action === 'create') {
            $wo = isset($_POST['wo']) ? trim((string)$_POST['wo']) : '';
            if (!valid_wo($wo)) {
                out(400, ['error' => 'Nomor WO tidak valid.']);
            }
            if ($action === 'cancel') {
                cancel_list($wo);
                out(200, ['ok' => true, 'message' => 'Supply list ' . $wo . ' dibatalkan.']);
            }
            create_list($wo);
            out(200, ['ok' => true, 'message' => 'Supply list ' . $wo . ' dibuat.']);
        } elseif ($action === 'sync') {
            $date = isset($_POST['date']) ? (string)$_POST['date'] : date('Y-m-d');
            if (!valid_date($date)) {
                out(400, ['error' => 'Format tanggal harus YYYY-MM-DD.']);
            }
            $r = sync_run($date);
            out(200, ['ok' => true, 'message' => 'Sinkronisasi selesai: dibuat ' . $r['created'] . ', diperbarui ' . $r['revised']
                . ', ditandai ' . $r['flagged'] . ', dibatalkan ' . $r['cancelled'] . ', menunggu dibuat ' . $r['pending'] . ', gagal ' . $r['errors'] . '.',
                'lines' => $r['lines']]);
        } else {
            out(400, ['error' => 'Aksi tidak dikenal.']);
        }
    } catch (Exception $e) {
        fail_json($e);
    }
}

try {
    if (isset($_GET['items'])) {
        $wo = trim((string)$_GET['items']);
        if (!valid_wo($wo)) {
            out(400, ['error' => 'Nomor WO tidak valid.']);
        }
        out(200, planning_items($wo));
    }
    $date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
    if (!valid_date($date)) {
        out(400, ['error' => 'Format tanggal harus YYYY-MM-DD.']);
    }
    out(200, planning_list($date));
} catch (Exception $e) {
    fail_json($e);
}
