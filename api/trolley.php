<?php
// API halaman scan troli.
//   GET  trolley_api.php?wos=1[&date=YYYY-MM-DD]   WO yang boleh dimasukkan ke troli
//   GET  trolley_api.php?trolley=T01               data troli terakhir dengan kode itu
//   GET  trolley_api.php?open=1                    troli yang sedang di jalan
//   GET  trolley_api.php?slip=12                   data slip untuk dicetak
//   POST action=out  user=BUDI wos=MO1,MO2 complete=MO1                  scan OUT (gudang), nomor troli dibuat otomatis
//                    (trolley=... boleh diisi untuk memakai kode sendiri)
//   POST action=in   trolley=T01 user=BUDI                              scan IN (produksi)
//   POST action=void id=12 user=BUDI pin=...                            batalkan kirim (PIN jika diatur)
require __DIR__ . '/../src/trolley_lib.php';

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
    $o = ['error' => 'Gagal memproses. Pastikan tabel troli sudah dibuat (install_tables_t3.sql) dan koneksi database benar.'];
    if (!empty($CFG['show_errors'])) {
        $o['detail'] = $e->getMessage();
    }
    out(500, $o);
}

function split_wos($s)
{
    $list = [];
    foreach (explode(',', (string)$s) as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (!preg_match('/^[A-Za-z0-9]{1,13}$/', $p)) {
            throw new UserError('Nomor WO tidak valid: ' . $p);
        }
        $list[$p] = $p;
    }
    return array_values($list);
}

$pinSet = isset($CFG['admin_pin']) ? (string)$CFG['admin_pin'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $user = isset($_POST['user']) ? (string)$_POST['user'] : '';
    try {
        if ($action === 'out') {
            $wos = split_wos(isset($_POST['wos']) ? $_POST['wos'] : '');
            $complete = split_wos(isset($_POST['complete']) ? $_POST['complete'] : '');
            $r = trolley_out(isset($_POST['trolley']) ? $_POST['trolley'] : '', $user, $wos, $complete);
            out(200, ['ok' => true, 'id' => $r['id'], 'slip' => slip_no($r['id']), 'trolley' => $r['code'],
                      'message' => 'Troli ' . $r['code'] . ' dikirim (OUT), ' . count($wos) . ' WO. Slip ' . slip_no($r['id']) . ' siap dicetak.']);
        } elseif ($action === 'in') {
            trolley_in(isset($_POST['trolley']) ? $_POST['trolley'] : '', $user);
            out(200, ['ok' => true, 'message' => trim($_POST['trolley']) . ' diterima (IN).']);
        } elseif ($action === 'void') {
            if ($pinSet !== '' && (!isset($_POST['pin']) || (string)$_POST['pin'] !== $pinSet)) {
                out(403, ['error' => 'PIN salah atau belum diisi.']);
            }
            trolley_void(isset($_POST['id']) ? $_POST['id'] : 0, $user);
            out(200, ['ok' => true, 'message' => 'Kirim troli dibatalkan.']);
        } else {
            out(400, ['error' => 'Aksi tidak dikenal.']);
        }
    } catch (Exception $e) {
        fail_json($e);
    }
}

try {
    if (isset($_GET['trolley'])) {
        out(200, trolley_lookup((string)$_GET['trolley']));
    }
    if (isset($_GET['slip'])) {
        out(200, slip_data((int)$_GET['slip']));
    }
    if (isset($_GET['open'])) {
        out(200, ['needPin' => $pinSet !== '', 'rows' => trolley_open_list()]);
    }
    if (isset($_GET['wos'])) {
        $date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            out(400, ['error' => 'Format tanggal harus YYYY-MM-DD.']);
        }
        out(200, ['date' => $date, 'rows' => trolley_wo_options($date)]);
    }
    out(400, ['error' => 'Parameter tidak dikenal.']);
} catch (Exception $e) {
    fail_json($e);
}
