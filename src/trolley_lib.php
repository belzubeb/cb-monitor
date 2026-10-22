<?php
// Logika scan troli (Tahap 3). Menulis hanya ke tblcbtrolley, tblcbtrolleywo, tblcbsupply, tblcbsupplylog.
// Status supply list per WO selalu dihitung ulang dari data troli (recompute_wo), jadi konsisten.
require_once __DIR__ . '/planning_lib.php';

// Hitung ulang status satu WO dari troli aktif (OUT atau IN, bukan VOID):
//   tidak ada troli                     -> PREPARED
//   ada tanda lengkap dan tak ada OUT   -> RECEIVED (full kit diterima)
//   selain itu                          -> SUPPLY
function recompute_wo($w, $wo)
{
    $main = cb_main();
    $st = $w->prepare('SELECT Status FROM ' . $main . '.tblcbsupply WHERE WONO = ? FOR UPDATE');
    $st->execute([$wo]);
    $cur = $st->fetch();
    if (!$cur || $cur['Status'] === 'CANCEL') {
        return;
    }

    $st = $w->prepare('SELECT t.Status AS ts, tw.IsComplete AS ic FROM ' . $main . '.tblcbtrolleywo tw '
        . 'JOIN ' . $main . ".tblcbtrolley t ON t.ID = tw.TrolleyID WHERE tw.WONO = ? AND t.Status IN ('OUT','IN')");
    $st->execute([$wo]);
    $nOut = 0;
    $nIn = 0;
    $hasComplete = false;
    foreach ($st->fetchAll() as $r) {
        if ($r['ts'] === 'OUT') {
            $nOut++;
        } else {
            $nIn++;
        }
        if ($r['ic'] === 'Y') {
            $hasComplete = true;
        }
    }

    if ($nOut + $nIn === 0) {
        $new = 'PREPARED';
    } elseif ($hasComplete && $nOut === 0) {
        $new = 'RECEIVED';
    } else {
        $new = 'SUPPLY';
    }

    $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET Status = ?, '
        . "SupplyDt = CASE WHEN ? = 'PREPARED' THEN NULL ELSE COALESCE(SupplyDt, NOW()) END, "
        . "ReceivedDt = CASE WHEN ? = 'RECEIVED' THEN COALESCE(ReceivedDt, NOW()) ELSE NULL END WHERE WONO = ?");
    $up->execute([$new, $new, $new, $wo]);
    if ($new !== $cur['Status']) {
        wlog($w, $wo, 'STATUS', $cur['Status'] . ' -> ' . $new, 'scan');
    }
}

function clean_user($u)
{
    $u = trim((string)$u);
    if (!preg_match('/^[A-Za-z0-9 .\-_]{1,30}$/', $u)) {
        throw new UserError('Isi nama atau ID petugas (huruf dan angka, maksimal 30 karakter).');
    }
    return $u;
}

// Nomor slip = SLP + ID baris troli (6 digit). Dicetak sebagai barcode di slip.
function slip_no($id)
{
    return 'SLP' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT);
}

// Mengembalikan ID troli jika kode yang dipindai berupa nomor slip, selain itu null.
function parse_slip($code)
{
    if (preg_match('/^SLP0*([0-9]+)$/i', $code, $m)) {
        return (int)$m[1];
    }
    return null;
}

// Kode troli dari luar boleh kosong (artinya: buat nomor otomatis). Pengiriman lama yang tanpa kode tersimpan sebagai '-'.
function opt_trolley($c)
{
    $c = trim((string)$c);
    return $c === '' ? '-' : clean_trolley($c);
}

function clean_trolley($c)
{
    $c = trim((string)$c);
    if (!preg_match('/^[A-Za-z0-9\-_.]{1,30}$/', $c)) {
        throw new UserError('Kode troli tidak valid (huruf, angka, tanda minus, titik, atau underscore).');
    }
    return $c;
}

// Nomor troli otomatis: T + yymmdd + '-' + urutan 3 digit per hari, mis. T261020-001.
// Tanggal diambil dari jam server database (sama dengan OutDt), urutan = nomor terbesar hari itu + 1.
// Harus dipanggil dengan kunci GET_LOCK aktif supaya dua scan OUT bersamaan tidak mendapat nomor kembar.
function next_trolley_no($w)
{
    $main = cb_main();
    $prefix = 'T' . $w->query('SELECT DATE_FORMAT(NOW(), \'%y%m%d\')')->fetchColumn() . '-';
    $st = $w->prepare('SELECT MAX(CAST(SUBSTRING(TrolleyNo, ' . (strlen($prefix) + 1) . ') AS UNSIGNED)) FROM ' . $main . '.tblcbtrolley WHERE TrolleyNo LIKE ?');
    $st->execute([$prefix . '%']);
    $n = (int)$st->fetchColumn() + 1;
    return $prefix . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
}

// Scan OUT di warehouse: catat troli beserta WO di dalamnya.
// Kode troli dikosongkan = nomor dibuat otomatis oleh sistem. Mengembalikan ['id' => ID baris, 'code' => nomor troli].
function trolley_out($code, $user, $wos, $complete)
{
    $code = opt_trolley($code);
    $user = clean_user($user);
    if (count($wos) === 0) {
        throw new UserError('Pilih minimal satu WO untuk troli ini.');
    }
    $w = db_write();
    $main = cb_main();
    $locked = false;
    if ($code === '-') {
        $got = $w->query('SELECT GET_LOCK(\'cb_trolley_no\', 5)')->fetchColumn();
        if ((int)$got !== 1) {
            throw new UserError('Sistem sedang membuat nomor troli untuk pengiriman lain. Coba lagi sebentar.');
        }
        $locked = true;
    }
    $w->beginTransaction();
    try {
        if ($code === '-') {
            $code = next_trolley_no($w);
        }
        if ($code !== '-') {
            $st = $w->prepare('SELECT ID FROM ' . $main . ".tblcbtrolley WHERE TrolleyNo = ? AND Status = 'OUT' LIMIT 1 FOR UPDATE");
            $st->execute([$code]);
            if ($st->fetch()) {
                throw new UserError('Troli ' . $code . ' masih tercatat di jalan (belum di-scan IN). Scan IN dulu, atau batalkan kirim sebelumnya.');
            }
        }

        $lines = [];
        foreach ($wos as $wo) {
            $st = $w->prepare('SELECT Status, Line_nm FROM ' . $main . '.tblcbsupply WHERE WONO = ? FOR UPDATE');
            $st->execute([$wo]);
            $r = $st->fetch();
            if (!$r) {
                throw new UserError('WO ' . $wo . ' belum punya supply list.');
            }
            if ($r['Status'] === 'CANCEL') {
                throw new UserError('Supply list WO ' . $wo . ' dibatalkan, tidak bisa dikirim.');
            }
            $lines[$r['Line_nm']] = true;
        }
        $lineNm = count($lines) === 1 ? key($lines) : 'MIX';

        $ins = $w->prepare('INSERT INTO ' . $main . ".tblcbtrolley (TrolleyNo, Line_nm, Status, OutDt, OutID) VALUES (?, ?, 'OUT', NOW(), ?)");
        $ins->execute([$code, $lineNm, $user]);
        $tid = (int)$w->lastInsertId();

        $insWo = $w->prepare('INSERT INTO ' . $main . '.tblcbtrolleywo (TrolleyID, WONO, IsComplete, InsertDt) VALUES (?, ?, ?, NOW())');
        foreach ($wos as $wo) {
            $ic = in_array($wo, $complete, true) ? 'Y' : 'N';
            $insWo->execute([$tid, $wo, $ic]);
            wlog($w, $wo, 'OUT', slip_no($tid) . ' troli ' . $code . ' oleh ' . $user . ($ic === 'Y' ? ' (lengkap)' : ' (sebagian)'), 'scan');
            recompute_wo($w, $wo);
        }
        $w->commit();
        if ($locked) {
            $w->query('SELECT RELEASE_LOCK(\'cb_trolley_no\')');
        }
        return ['id' => $tid, 'code' => $code];
    } catch (Exception $e) {
        if ($w->inTransaction()) {
            $w->rollBack();
        }
        if ($locked) {
            $w->query('SELECT RELEASE_LOCK(\'cb_trolley_no\')');
        }
        throw $e;
    }
}

// Scan IN di produksi: troli diterima, semua WO di dalamnya dihitung ulang.
function trolley_in($code, $user)
{
    $code = clean_trolley($code);
    $user = clean_user($user);
    $w = db_write();
    $main = cb_main();
    $w->beginTransaction();
    try {
        $sid = parse_slip($code);
        if ($sid !== null) {
            $st = $w->prepare('SELECT ID FROM ' . $main . ".tblcbtrolley WHERE ID = ? AND Status = 'OUT' FOR UPDATE");
            $st->execute([$sid]);
        } else {
            $st = $w->prepare('SELECT ID FROM ' . $main . ".tblcbtrolley WHERE TrolleyNo = ? AND Status = 'OUT' ORDER BY ID DESC LIMIT 1 FOR UPDATE");
            $st->execute([$code]);
        }
        $t = $st->fetch();
        if (!$t) {
            throw new UserError($code . ' tidak ditemukan di daftar yang sedang di jalan (belum di-scan OUT, atau sudah diterima).');
        }
        $tid = (int)$t['ID'];
        $up = $w->prepare('UPDATE ' . $main . ".tblcbtrolley SET Status = 'IN', InDt = NOW(), InID = ? WHERE ID = ?");
        $up->execute([$user, $tid]);

        $st = $w->prepare('SELECT WONO FROM ' . $main . '.tblcbtrolleywo WHERE TrolleyID = ?');
        $st->execute([$tid]);
        foreach ($st->fetchAll() as $r) {
            wlog($w, $r['WONO'], 'IN', slip_no($tid) . ' diterima oleh ' . $user, 'scan');
            recompute_wo($w, $r['WONO']);
        }
        $w->commit();
    } catch (Exception $e) {
        if ($w->inTransaction()) {
            $w->rollBack();
        }
        throw $e;
    }
}

// Batalkan kirim (hanya troli yang masih OUT), status WO dihitung ulang.
function trolley_void($id, $user)
{
    $user = clean_user($user);
    $w = db_write();
    $main = cb_main();
    $w->beginTransaction();
    try {
        $st = $w->prepare('SELECT TrolleyNo, Status FROM ' . $main . '.tblcbtrolley WHERE ID = ? FOR UPDATE');
        $st->execute([(int)$id]);
        $t = $st->fetch();
        if (!$t) {
            throw new UserError('Data troli tidak ditemukan.');
        }
        if ($t['Status'] !== 'OUT') {
            throw new UserError('Hanya troli yang masih di jalan (OUT) yang bisa dibatalkan. Status sekarang: ' . $t['Status'] . '.');
        }
        $up = $w->prepare('UPDATE ' . $main . ".tblcbtrolley SET Status = 'VOID', Note = ? WHERE ID = ?");
        $up->execute(['Dibatalkan oleh ' . $user, (int)$id]);

        $st = $w->prepare('SELECT WONO FROM ' . $main . '.tblcbtrolleywo WHERE TrolleyID = ?');
        $st->execute([(int)$id]);
        foreach ($st->fetchAll() as $r) {
            wlog($w, $r['WONO'], 'VOID', 'kirim ' . slip_no((int)$id) . ' (troli ' . $t['TrolleyNo'] . ') dibatalkan oleh ' . $user, 'scan');
            recompute_wo($w, $r['WONO']);
        }
        $w->commit();
    } catch (Exception $e) {
        if ($w->inTransaction()) {
            $w->rollBack();
        }
        throw $e;
    }
}

// Cari data troli terakhir berdasarkan kodenya.
function trolley_lookup($code)
{
    $code = clean_trolley($code);
    $db = db();
    $main = cb_main();
    $sid = parse_slip($code);
    if ($sid !== null) {
        $st = $db->prepare('SELECT * FROM ' . $main . '.tblcbtrolley WHERE ID = ?');
        $st->execute([$sid]);
    } else {
        $st = $db->prepare('SELECT * FROM ' . $main . '.tblcbtrolley WHERE TrolleyNo = ? ORDER BY ID DESC LIMIT 1');
        $st->execute([$code]);
    }
    $t = $st->fetch();
    if (!$t) {
        return ['found' => false, 'code' => $code];
    }
    $st = $db->prepare('SELECT tw.WONO, tw.IsComplete, s.Line_nm, s.PartNo, s.PartNm, s.PlanQty FROM ' . $main . '.tblcbtrolleywo tw '
        . 'LEFT JOIN ' . $main . '.tblcbsupply s ON s.WONO = tw.WONO WHERE tw.TrolleyID = ? ORDER BY tw.ID');
    $st->execute([(int)$t['ID']]);
    $wos = [];
    foreach ($st->fetchAll() as $r) {
        $wos[] = ['wo' => trim($r['WONO']), 'complete' => $r['IsComplete'] === 'Y', 'line' => (string)$r['Line_nm'],
                  'partNo' => (string)$r['PartNo'], 'partNm' => (string)$r['PartNm'], 'plan' => (float)$r['PlanQty']];
    }
    return ['found' => true, 'id' => (int)$t['ID'], 'slip' => slip_no($t['ID']), 'code' => $t['TrolleyNo'], 'status' => $t['Status'], 'line' => (string)$t['Line_nm'],
            'outDt' => (string)$t['OutDt'], 'outBy' => (string)$t['OutID'], 'inDt' => (string)$t['InDt'], 'inBy' => (string)$t['InID'],
            'wos' => $wos];
}

// Troli yang sedang di jalan (OUT), beserta WO-nya.
function trolley_open_list()
{
    $main = cb_main();
    $st = db()->query('SELECT t.ID, t.TrolleyNo, t.Line_nm, t.OutDt, t.OutID, '
        . '(SELECT GROUP_CONCAT(tw.WONO ORDER BY tw.ID SEPARATOR \', \') FROM ' . $main . '.tblcbtrolleywo tw WHERE tw.TrolleyID = t.ID) AS wos '
        . 'FROM ' . $main . ".tblcbtrolley t WHERE t.Status = 'OUT' ORDER BY t.OutDt");
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['id' => (int)$r['ID'], 'slip' => slip_no($r['ID']), 'code' => $r['TrolleyNo'], 'line' => (string)$r['Line_nm'],
                   'outDt' => (string)$r['OutDt'], 'outBy' => (string)$r['OutID'], 'wos' => (string)$r['wos']];
    }
    return $rows;
}

// WO yang boleh dimasukkan ke troli (supply list ada dan tidak dibatalkan) untuk satu tanggal.
function trolley_wo_options($date)
{
    $main = cb_main();
    $st = db()->prepare('SELECT s.WONO, s.Line_nm, s.PartNo, s.PartNm, s.PlanQty, s.Status, '
        . '(SELECT COUNT(*) FROM ' . $main . '.tblcbtrolleywo tw JOIN ' . $main . ".tblcbtrolley t ON t.ID = tw.TrolleyID WHERE tw.WONO = s.WONO AND t.Status IN ('OUT','IN')) AS ntro, "
        . '(SELECT COUNT(*) FROM ' . $main . '.tblcbtrolleywo tw JOIN ' . $main . ".tblcbtrolley t ON t.ID = tw.TrolleyID WHERE tw.WONO = s.WONO AND t.Status IN ('OUT','IN') AND tw.IsComplete = 'Y') AS ncomp "
        . 'FROM ' . $main . ".tblcbsupply s WHERE s.PlanDate = ? AND s.Status IN ('PREPARED','SUPPLY','RECEIVED') ORDER BY s.Line_nm, s.ID");
    $st->execute([$date]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = ['wo' => trim($r['WONO']), 'line' => (string)$r['Line_nm'], 'partNo' => (string)$r['PartNo'], 'partNm' => (string)$r['PartNm'],
                   'plan' => (float)$r['PlanQty'], 'status' => $r['Status'], 'trolleys' => (int)$r['ntro'], 'complete' => ((int)$r['ncomp']) > 0];
    }
    return $rows;
}

// Data lengkap satu slip untuk dicetak: header pengiriman, WO di dalamnya, dan isi BOM tiap WO (revisi aktif).
function slip_data($id)
{
    $db = db();
    $main = cb_main();
    $st = $db->prepare('SELECT * FROM ' . $main . '.tblcbtrolley WHERE ID = ?');
    $st->execute([(int)$id]);
    $t = $st->fetch();
    if (!$t) {
        return ['found' => false];
    }
    $st = $db->prepare('SELECT tw.WONO, tw.IsComplete, s.Line_nm, s.PartNo, s.PartNm, s.PlanQty, s.Rev FROM ' . $main . '.tblcbtrolleywo tw '
        . 'LEFT JOIN ' . $main . '.tblcbsupply s ON s.WONO = tw.WONO WHERE tw.TrolleyID = ? ORDER BY tw.ID');
    $st->execute([(int)$t['ID']]);
    $wos = [];
    foreach ($st->fetchAll() as $r) {
        $wo = trim($r['WONO']);
        $st2 = $db->prepare('SELECT ChPartNo, ChPartNm, SpecNm, UnitTp, NeedQty FROM ' . $main . '.tblcbsupplyitem '
            . 'WHERE WONO = ? AND Rev = ? ORDER BY ChPartNo');
        $st2->execute([$wo, (int)$r['Rev']]);
        $items = [];
        foreach ($st2->fetchAll() as $i) {
            $items[] = ['partNo' => trim((string)$i['ChPartNo']), 'partNm' => trim((string)$i['ChPartNm']),
                        'spec' => trim((string)$i['SpecNm']), 'unit' => trim((string)$i['UnitTp']), 'need' => (float)$i['NeedQty']];
        }
        $wos[] = ['wo' => $wo, 'line' => (string)$r['Line_nm'], 'partNo' => (string)$r['PartNo'], 'partNm' => (string)$r['PartNm'],
                  'plan' => (float)$r['PlanQty'], 'rev' => (int)$r['Rev'], 'complete' => $r['IsComplete'] === 'Y', 'items' => $items];
    }
    return ['found' => true, 'id' => (int)$t['ID'], 'slip' => slip_no($t['ID']), 'trolley' => (string)$t['TrolleyNo'],
            'status' => (string)$t['Status'], 'line' => (string)$t['Line_nm'], 'outDt' => (string)$t['OutDt'], 'outBy' => (string)$t['OutID'],
            'wos' => $wos, 'printedAt' => date('Y-m-d H:i:s')];
}
