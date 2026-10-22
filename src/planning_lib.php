<?php
// Fungsi untuk halaman Planning Hari Ini (Tahap 2).
// Membaca WO CB dari ERP dan supply list di tblcb*. Aksi tulis hanya ke tblcbsupply, tblcbsupplyitem, tblcbsupplylog.
require_once __DIR__ . '/sync_lib.php';

// Kesalahan yang pesannya aman ditampilkan apa adanya ke pengguna.
class UserError extends Exception
{
}

function erp_cb_in_list()
{
    global $CFG;
    $codes = array_keys($CFG['cb_lines']);
    return [$codes, implode(',', array_fill(0, count($codes), '?'))];
}

// Semua WO CB untuk satu tanggal, urut line lalu work_prtio_seq.
function erp_cb_wos($date)
{
    global $CFG;
    list($codes, $ph) = erp_cb_in_list();
    $sql = 'SELECT work_ord_no, part_no, work_ord_dt, work_ord_qty, work_prtio_seq, work_close_yn, prdpc_no '
         . 'FROM ' . ident($CFG['db_mes']) . '.v_ppr_work_ord '
         . 'WHERE CAST(work_ord_dt AS DATE) = CAST(? AS DATE) AND TRIM(prdpc_no) IN (' . $ph . ') '
         . 'ORDER BY prdpc_no, work_prtio_seq, work_ord_no';
    $st = db()->prepare($sql);
    $st->execute(array_merge([$date], $codes));
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[trim($r['work_ord_no'])] = $r;
    }
    return $map;
}

// Satu WO CB berdasarkan nomornya (tanpa filter tanggal).
function erp_cb_one($wo)
{
    global $CFG;
    list($codes, $ph) = erp_cb_in_list();
    $sql = 'SELECT work_ord_no, part_no, work_ord_dt, work_ord_qty, work_prtio_seq, work_close_yn, prdpc_no '
         . 'FROM ' . ident($CFG['db_mes']) . '.v_ppr_work_ord '
         . 'WHERE TRIM(work_ord_no) = ? AND TRIM(prdpc_no) IN (' . $ph . ') LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute(array_merge([$wo], $codes));
    $r = $st->fetch();
    return $r ? $r : null;
}

function planning_list($date)
{
    global $CFG;
    $main = cb_main();
    $db = db();
    $erp = erp_cb_wos($date);

    $sup = [];
    $st = $db->prepare('SELECT * FROM ' . $main . '.tblcbsupply WHERE PlanDate = ?');
    $st->execute([$date]);
    foreach ($st->fetchAll() as $r) {
        $sup[$r['WONO']] = $r;
    }
    if (count($erp) > 0) {
        $keys = array_keys($erp);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare('SELECT * FROM ' . $main . '.tblcbsupply WHERE WONO IN (' . $ph . ')');
        $st->execute($keys);
        foreach ($st->fetchAll() as $r) {
            $sup[$r['WONO']] = $r;
        }
    }

    // WO yang sedang running di POP (cStatus = 1), untuk penanda "running tanpa supply list".
    $run = [];
    try {
        $st = $db->prepare('SELECT TRIM(WOrder) AS wo, MAX(cStatus) AS st FROM ' . ident($CFG['db_led'])
            . '.tblledsupply WHERE Tanggal = ? AND COALESCE(cHapus, 0) = 0 GROUP BY TRIM(WOrder)');
        $st->execute([$date]);
        foreach ($st->fetchAll() as $r) {
            $run[$r['wo']] = ((int)$r['st'] === 1);
        }
    } catch (Exception $e) {
        // abaikan, penanda running tidak ditampilkan
    }

    $parts = [];
    foreach ($erp as $e) {
        $parts[] = trim((string)$e['part_no']);
    }
    $names = part_names($parts);

    $rows = [];
    foreach ($erp as $wo => $e) {
        $code = trim((string)$e['prdpc_no']);
        $lineNm = isset($CFG['cb_lines'][$code]) ? $CFG['cb_lines'][$code] : $code;
        $part = trim((string)$e['part_no']);
        $plan = (float)$e['work_ord_qty'];
        $planDate = norm_date($e['work_ord_dt']);
        $s = isset($sup[$wo]) ? $sup[$wo] : null;
        $status = $s !== null ? $s['Status'] : 'NEW';

        $changed = '';
        if ($s !== null && $status !== 'CANCEL' && $plan > 0) {
            $diff = [];
            $planChanged = abs((float)$s['PlanQty'] - $plan) > 0.0001;
            if ($planChanged) {
                $diff[] = 'plan ' . (float)$s['PlanQty'] . ' -> ' . $plan;
            }
            if ($s['Line_nm'] !== $lineNm) {
                $diff[] = 'line ' . $s['Line_nm'] . ' -> ' . $lineNm;
            }
            if (!$planChanged) {
                $sigNew = items_sig(make_items(bom_items($part, $planDate), $plan));
                if (stored_sig($db, $wo, (int)$s['Rev']) !== $sigNew) {
                    $diff[] = 'BOM berubah';
                }
            }
            $changed = implode('; ', $diff);
        }

        $rows[] = [
            'wo'        => $wo,
            'line'      => $lineNm,
            'seq'       => (int)$e['work_prtio_seq'],
            'partNo'    => $part,
            'partNm'    => isset($names[$part]) ? $names[$part] : '',
            'planErp'   => $plan,
            'status'    => $status,
            'snapPlan'  => $s !== null ? (float)$s['PlanQty'] : null,
            'source'    => $s !== null ? (string)$s['Source'] : '',
            'rev'       => $s !== null ? (int)$s['Rev'] : 0,
            'createdDt' => $s !== null ? (string)$s['CreatedDt'] : '',
            'supplyDt'  => $s !== null ? (string)$s['SupplyDt'] : '',
            'receivedDt' => $s !== null ? (string)$s['ReceivedDt'] : '',
            'note'      => $s !== null ? (string)$s['Note'] : '',
            'changed'   => $changed,
            'running'   => !empty($run[$wo]),
            'noList'    => !empty($run[$wo]) && ($s === null || $status === 'CANCEL'),
            'canCancel' => $status === 'PREPARED',
            'canCreate' => $status === 'NEW' || $status === 'CANCEL',
        ];
    }

    // Supply list untuk tanggal ini yang WO-nya sudah tidak ada di ERP.
    foreach ($sup as $wo => $s) {
        if (isset($erp[$wo]) || $s['Status'] === 'CANCEL' || norm_date($s['PlanDate']) !== $date) {
            continue;
        }
        $rows[] = [
            'wo'        => $wo,
            'line'      => (string)$s['Line_nm'],
            'seq'       => 0,
            'partNo'    => (string)$s['PartNo'],
            'partNm'    => (string)$s['PartNm'],
            'planErp'   => null,
            'status'    => 'MISSING',
            'snapPlan'  => (float)$s['PlanQty'],
            'source'    => (string)$s['Source'],
            'rev'       => (int)$s['Rev'],
            'createdDt' => (string)$s['CreatedDt'],
            'supplyDt'  => (string)$s['SupplyDt'],
            'receivedDt' => (string)$s['ReceivedDt'],
            'note'      => 'WO tidak ada lagi di ERP (status supply list: ' . $s['Status'] . ')',
            'changed'   => '',
            'running'   => !empty($run[$wo]),
            'noList'    => false,
            'canCancel' => $s['Status'] === 'PREPARED',
            'canCreate' => false,
        ];
    }

    usort($rows, function ($a, $b) {
        $c = strcmp($a['line'], $b['line']);
        return $c !== 0 ? $c : ($a['seq'] - $b['seq']);
    });

    return [
        'date'      => $date,
        'generated' => date('Y-m-d H:i:s'),
        'needPin'   => !empty($CFG['admin_pin']),
        'rows'      => $rows,
    ];
}

// Isi supply list satu WO (revisi aktif) beserta 10 kejadian terakhir.
function planning_items($wo)
{
    $db = db();
    $main = cb_main();
    $st = $db->prepare('SELECT Rev, Status, PlanQty FROM ' . $main . '.tblcbsupply WHERE WONO = ?');
    $st->execute([$wo]);
    $h = $st->fetch();
    if (!$h) {
        return ['wo' => $wo, 'found' => false, 'items' => [], 'log' => []];
    }
    $st = $db->prepare('SELECT ChPartNo, ChPartNm, SpecNm, UnitTp, StrgCd, UsageQty, NeedQty, PrepareQty '
        . 'FROM ' . $main . '.tblcbsupplyitem WHERE WONO = ? AND Rev = ? ORDER BY ChPartNo');
    $st->execute([$wo, (int)$h['Rev']]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'partNo'  => trim((string)$r['ChPartNo']),
            'partNm'  => trim((string)$r['ChPartNm']),
            'spec'    => trim((string)$r['SpecNm']),
            'unit'    => trim((string)$r['UnitTp']),
            'strg'    => trim((string)$r['StrgCd']),
            'usage'   => (float)$r['UsageQty'],
            'need'    => (float)$r['NeedQty'],
            'prepare' => (float)$r['PrepareQty'],
        ];
    }
    $st = $db->prepare('SELECT EventDt, EventType, Detail, UserID FROM ' . $main . '.tblcbsupplylog '
        . 'WHERE WONO = ? ORDER BY ID DESC LIMIT 10');
    $st->execute([$wo]);
    $log = [];
    foreach ($st->fetchAll() as $r) {
        $log[] = ['dt' => (string)$r['EventDt'], 'type' => (string)$r['EventType'],
                  'detail' => (string)$r['Detail'], 'user' => (string)$r['UserID']];
    }
    return ['wo' => $wo, 'found' => true, 'rev' => (int)$h['Rev'], 'status' => $h['Status'],
            'plan' => (float)$h['PlanQty'], 'items' => $items, 'log' => $log];
}

// Batalkan supply list (hanya yang masih PREPARED).
function cancel_list($wo)
{
    $w = db_write();
    $main = cb_main();
    $w->beginTransaction();
    try {
        $st = $w->prepare('SELECT Status FROM ' . $main . '.tblcbsupply WHERE WONO = ? FOR UPDATE');
        $st->execute([$wo]);
        $r = $st->fetch();
        if (!$r) {
            throw new UserError('Supply list untuk WO ini tidak ditemukan.');
        }
        if ($r['Status'] !== 'PREPARED') {
            throw new UserError('Hanya supply list berstatus PREPARED yang bisa dibatalkan (status sekarang: ' . $r['Status'] . ').');
        }
        $up = $w->prepare('UPDATE ' . $main . ".tblcbsupply SET Status = 'CANCEL', CancelDt = NOW(), "
            . "LastSyncDt = NOW(), Note = 'Dibatalkan leader' WHERE WONO = ?");
        $up->execute([$wo]);
        wlog($w, $wo, 'CANCEL', 'Dibatalkan leader (manual)', 'leader');
        $w->commit();
    } catch (Exception $e) {
        if ($w->inTransaction()) {
            $w->rollBack();
        }
        throw $e;
    }
}

// Buat supply list manual untuk WO yang belum ada, atau aktifkan lagi yang dibatalkan.
function create_list($wo)
{
    global $CFG;
    $e = erp_cb_one($wo);
    if ($e === null) {
        throw new UserError('WO tidak ditemukan di ERP untuk line Cover Bottom.');
    }
    $code = trim((string)$e['prdpc_no']);
    $lineNm = isset($CFG['cb_lines'][$code]) ? $CFG['cb_lines'][$code] : $code;
    $part = trim((string)$e['part_no']);
    $plan = (float)$e['work_ord_qty'];
    $planDate = norm_date($e['work_ord_dt']);
    if ($plan <= 0) {
        throw new UserError('Plan WO ini 0, tidak ada yang disiapkan.');
    }
    $names = part_names([$part]);
    $partNm = isset($names[$part]) ? $names[$part] : null;
    $items = make_items(bom_items($part, $planDate), $plan);
    $note = count($items) > 0 ? null : 'BOM tidak ditemukan';

    $w = db_write();
    $main = cb_main();
    $w->beginTransaction();
    try {
        $st = $w->prepare('SELECT Status, Rev FROM ' . $main . '.tblcbsupply WHERE WONO = ? FOR UPDATE');
        $st->execute([$wo]);
        $ex = $st->fetch();
        if (!$ex) {
            $ins = $w->prepare('INSERT INTO ' . $main . '.tblcbsupply '
                . '(WONO, Line_nm, PartNo, PartNm, PlanDate, PlanQty, BomDate, Status, Source, Rev, '
                . 'CreatedDt, CreatedBy, LastSyncDt, Note) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, 'PREPARED', 'MANUAL', 1, NOW(), 'leader', NOW(), ?)");
            $ins->execute([$wo, $lineNm, $part, $partNm, $planDate, $plan, $planDate, $note]);
            insert_items($w, $wo, 1, $items);
            wlog($w, $wo, 'CREATE', 'manual, plan ' . $plan . ', ' . count($items) . ' part, ' . $lineNm, 'leader');
        } elseif ($ex['Status'] === 'CANCEL') {
            $newRev = (int)$ex['Rev'] + 1;
            $del = $w->prepare('DELETE FROM ' . $main . '.tblcbsupplyitem WHERE WONO = ?');
            $del->execute([$wo]);
            insert_items($w, $wo, $newRev, $items);
            $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET Line_nm = ?, PartNo = ?, PartNm = ?, '
                . "PlanDate = ?, PlanQty = ?, BomDate = ?, Rev = ?, Status = 'PREPARED', Source = 'MANUAL', "
                . 'CancelDt = NULL, LastSyncDt = NOW(), Note = ? WHERE WONO = ?');
            $up->execute([$lineNm, $part, $partNm, $planDate, $plan, $planDate, $newRev, $note, $wo]);
            wlog($w, $wo, 'REACTIVATE', 'diaktifkan lagi manual, plan ' . $plan, 'leader');
        } else {
            throw new UserError('Supply list untuk WO ini sudah ada (status ' . $ex['Status'] . ').');
        }
        $w->commit();
    } catch (Exception $e2) {
        if ($w->inTransaction()) {
            $w->rollBack();
        }
        throw $e2;
    }
}
