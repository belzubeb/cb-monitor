<?php
// BOM dan kebutuhan (need) satu WO, untuk panel kanan bawah di monitor.
//   api/bom.php?wo=MO26101400114
// Jika WO sudah punya supply list, isinya yang ditampilkan (sumber SUPPLY LIST).
// Jika belum, BOM berlaku dari ERP dihitung langsung: need = usage x plan WO (sumber BOM ERP). Hanya baca.
require __DIR__ . '/../src/planning_lib.php';

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

$wo = isset($_GET['wo']) ? trim((string)$_GET['wo']) : '';
if (!preg_match('/^[A-Za-z0-9]{1,13}$/', $wo)) {
    fail_json(400, 'Parameter wo tidak valid', null);
}

try {
    $erp = erp_map([$wo]);
    if (!isset($erp[$wo])) {
        fail_json(404, 'WO ' . $wo . ' tidak ditemukan di ERP', null);
    }
    $e = $erp[$wo];
    $part = trim((string)$e['part_no']);
    $plan = (float)$e['work_ord_qty'];
    $bomDate = !empty($e['work_ord_dt']) ? substr((string)$e['work_ord_dt'], 0, 10) : date('Y-m-d');
    $names = part_names([$part]);

    $out = [
        'wo'      => $wo,
        'partNo'  => $part,
        'partNm'  => isset($names[$part]) ? $names[$part] : '',
        'plan'    => $plan,
        'bomDate' => $bomDate,
        'source'  => 'BOM ERP',
        'status'  => '',
        'items'   => [],
    ];

    // Supply list tersimpan (jika tabel tblcb* ada dan WO sudah dibuatkan list).
    $stored = null;
    try {
        $stored = planning_items($wo);
    } catch (Exception $e2) {
        $stored = null;
    }
    if ($stored && !empty($stored['found']) && count($stored['items']) > 0) {
        $out['source'] = 'SUPPLY LIST';
        $out['status'] = (string)$stored['status'];
        $out['items'] = $stored['items'];
    } else {
        $out['items'] = make_items(bom_items($part, $bomDate), $plan);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    fail_json(500, 'Gagal membaca database', $e);
}
