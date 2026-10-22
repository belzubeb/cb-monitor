<?php
// Sinkronisasi supply list Cover Bottom (Tahap 2).
// Menulis HANYA ke tblcbsupply, tblcbsupplyitem, dan tblcbsupplylog di database ardatabase.
// Tidak menyentuh tblsupplyraw atau tabel POP lain. ERP hanya dibaca.
require_once __DIR__ . '/lib.php';

function cb_main()
{
    global $CFG;
    return ident($CFG['db_main']);
}

// Ubah 20261015 atau 2026-10-15 00:00:00 menjadi 2026-10-15.
function norm_date($s)
{
    $s = trim((string)$s);
    if (preg_match('/^\d{8}$/', $s)) {
        return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
    }
    return substr($s, 0, 10);
}

function wlog($pdo, $wo, $type, $detail, $user = 'sync')
{
    $st = $pdo->prepare('INSERT INTO ' . cb_main() . '.tblcbsupplylog (WONO, EventDt, EventType, Detail, UserID) '
        . 'VALUES (?, NOW(), ?, ?, ?)');
    $st->execute([$wo, $type, substr($detail, 0, 500), $user]);
}

function last_log_detail($pdo, $wo, $type)
{
    $st = $pdo->prepare('SELECT Detail FROM ' . cb_main() . '.tblcbsupplylog WHERE WONO = ? AND EventType = ? '
        . 'ORDER BY ID DESC LIMIT 1');
    $st->execute([$wo, $type]);
    $r = $st->fetch();
    return $r ? (string)$r['Detail'] : null;
}

function make_items($bomRows, $plan)
{
    $items = [];
    foreach ($bomRows as $b) {
        $usage = (float)$b['usage_qty'];
        $need = round($usage * $plan, 5);
        $items[] = [
            'partNo'  => trim((string)$b['ch_part_no']),
            'partNm'  => trim((string)$b['part_nm']),
            'spec'    => trim((string)$b['spec_nm']),
            'unit'    => trim((string)$b['unit_tp']),
            'strg'    => trim((string)$b['strg_cd']),
            'usage'   => $usage,
            'need'    => $need,
            'prepare' => $need,
        ];
    }
    return $items;
}

// Tanda tangan isi supply list: peta part => need (dibulatkan), untuk mendeteksi perubahan.
function items_sig($items)
{
    $m = [];
    foreach ($items as $i) {
        $m[$i['partNo']] = round($i['need'], 4);
    }
    ksort($m);
    return json_encode($m);
}

function insert_items($pdo, $wo, $rev, $items)
{
    $st = $pdo->prepare('INSERT INTO ' . cb_main() . '.tblcbsupplyitem '
        . '(WONO, Rev, ChPartNo, ChPartNm, SpecNm, UnitTp, StrgCd, UsageQty, NeedQty, PrepareQty) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($items as $i) {
        $st->execute([$wo, $rev, $i['partNo'], $i['partNm'], $i['spec'], $i['unit'], $i['strg'],
                      $i['usage'], $i['need'], $i['prepare']]);
    }
}

function stored_sig($pdo, $wo, $rev)
{
    $st = $pdo->prepare('SELECT ChPartNo, NeedQty FROM ' . cb_main() . '.tblcbsupplyitem WHERE WONO = ? AND Rev = ?');
    $st->execute([$wo, $rev]);
    $m = [];
    foreach ($st->fetchAll() as $r) {
        $m[trim($r['ChPartNo'])] = round((float)$r['NeedQty'], 4);
    }
    ksort($m);
    return json_encode($m);
}

// Jalankan sinkronisasi untuk satu tanggal. Mengembalikan ringkasan.
function sync_run($date)
{
    global $CFG;
    $mes = ident($CFG['db_mes']);
    $main = cb_main();
    $res = ['date' => $date, 'created' => 0, 'revised' => 0, 'flagged' => 0, 'cancelled' => 0,
            'unchanged' => 0, 'pending' => 0, 'errors' => 0, 'lines' => []];

    $read = db();
    $w = db_write();

    // Cegah dua sinkronisasi berjalan bersamaan.
    $lk = $w->query("SELECT GET_LOCK('cb_supply_sync', 0) AS l")->fetch();
    if ((int)$lk['l'] !== 1) {
        $res['lines'][] = 'Sinkronisasi lain sedang berjalan, dilewati.';
        return $res;
    }

    try {
        // 1. Semua WO CB untuk tanggal ini dari ERP (view di server .235).
        $codes = array_keys($CFG['cb_lines']);
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $sql = 'SELECT work_ord_no, part_no, work_ord_dt, work_ord_qty, work_prtio_seq, work_close_yn, prdpc_no '
             . 'FROM ' . $mes . '.v_ppr_work_ord '
             . 'WHERE CAST(work_ord_dt AS DATE) = CAST(? AS DATE) AND TRIM(prdpc_no) IN (' . $ph . ') '
             . 'ORDER BY prdpc_no, work_prtio_seq, work_ord_no';
        $st = $read->prepare($sql);
        $st->execute(array_merge([$date], $codes));
        $erp = [];
        foreach ($st->fetchAll() as $r) {
            $erp[trim($r['work_ord_no'])] = $r;
        }

        // 2. Supply list yang sudah ada untuk WO-WO itu.
        $exist = [];
        if (count($erp) > 0) {
            $keys = array_keys($erp);
            $ph2 = implode(',', array_fill(0, count($keys), '?'));
            $st = $w->prepare('SELECT * FROM ' . $main . '.tblcbsupply WHERE WONO IN (' . $ph2 . ')');
            $st->execute($keys);
            foreach ($st->fetchAll() as $r) {
                $exist[$r['WONO']] = $r;
            }
        }

        $parts = [];
        foreach ($erp as $e) {
            $parts[] = trim((string)$e['part_no']);
        }
        $names = part_names($parts);

        // 3. Proses tiap WO.
        foreach ($erp as $wo => $e) {
            $code = trim((string)$e['prdpc_no']);
            $lineNm = isset($CFG['cb_lines'][$code]) ? $CFG['cb_lines'][$code] : $code;
            $part = trim((string)$e['part_no']);
            $partNm = isset($names[$part]) ? $names[$part] : null;
            $plan = (float)$e['work_ord_qty'];
            $planDate = norm_date($e['work_ord_dt']);

            if ($plan <= 0) {
                $res['lines'][] = $wo . ': plan 0, dilewati';
                continue;
            }

            // Mode manual: WO yang belum punya supply list hanya dihitung, dibuat lewat tombol di halaman Planning.
            if (!isset($exist[$wo]) && empty($CFG['auto_create'])) {
                $res['pending']++;
                continue;
            }

            try {
                $items = make_items(bom_items($part, $planDate), $plan);
                $sigNew = items_sig($items);
                $note = count($items) > 0 ? null : 'BOM tidak ditemukan';
                $ex = isset($exist[$wo]) ? $exist[$wo] : null;

                $w->beginTransaction();

                if ($ex === null) {
                    // Belum ada: buat supply list baru.
                    $ins = $w->prepare('INSERT INTO ' . $main . '.tblcbsupply '
                        . '(WONO, Line_nm, PartNo, PartNm, PlanDate, PlanQty, BomDate, Status, Source, Rev, '
                        . 'CreatedDt, CreatedBy, LastSyncDt, Note) '
                        . "VALUES (?, ?, ?, ?, ?, ?, ?, 'PREPARED', 'AUTO', 1, NOW(), 'sync', NOW(), ?)");
                    $ins->execute([$wo, $lineNm, $part, $partNm, $planDate, $plan, $planDate, $note]);
                    insert_items($w, $wo, 1, $items);
                    wlog($w, $wo, 'CREATE', 'plan ' . $plan . ', ' . count($items) . ' part, ' . $lineNm);
                    $res['created']++;
                    $res['lines'][] = $wo . ': dibuat (plan ' . $plan . ', ' . count($items) . ' part)';
                } else {
                    $status = $ex['Status'];
                    $rev = (int)$ex['Rev'];
                    $sigOld = stored_sig($w, $wo, $rev);

                    $diff = [];
                    if (abs((float)$ex['PlanQty'] - $plan) > 0.0001) {
                        $diff[] = 'plan ' . (float)$ex['PlanQty'] . ' -> ' . $plan;
                    }
                    if ($sigOld !== $sigNew) {
                        $diff[] = 'BOM/kebutuhan berubah';
                    }
                    if ($ex['Line_nm'] !== $lineNm) {
                        $diff[] = 'line ' . $ex['Line_nm'] . ' -> ' . $lineNm;
                    }
                    if (norm_date($ex['PlanDate']) !== $planDate) {
                        $diff[] = 'tanggal ' . norm_date($ex['PlanDate']) . ' -> ' . $planDate;
                    }
                    $detail = implode('; ', $diff);

                    $reactivate = false;
                    if ($status === 'CANCEL') {
                        // Hanya WO yang hilang dari ERP lalu muncul lagi yang diaktifkan kembali.
                        // Pembatalan oleh leader tidak diubah oleh sinkronisasi.
                        if (!empty($CFG['auto_create']) && strpos((string)$ex['Note'], 'WO hilang') === 0) {
                            $reactivate = true;
                        } else {
                            $res['unchanged']++;
                        }
                    }

                    if ($reactivate || ($status === 'PREPARED' && count($diff) > 0)) {
                        // Belum ada barang dikirim: perbarui otomatis.
                        $newRev = $rev + 1;
                        $del = $w->prepare('DELETE FROM ' . $main . '.tblcbsupplyitem WHERE WONO = ?');
                        $del->execute([$wo]);
                        insert_items($w, $wo, $newRev, $items);
                        $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET Line_nm = ?, PartNo = ?, PartNm = ?, '
                            . "PlanDate = ?, PlanQty = ?, BomDate = ?, Rev = ?, Status = 'PREPARED', CancelDt = NULL, "
                            . 'LastSyncDt = NOW(), Note = ? WHERE WONO = ?');
                        $up->execute([$lineNm, $part, $partNm, $planDate, $plan, $planDate, $newRev, $note, $wo]);
                        wlog($w, $wo, $reactivate ? 'REACTIVATE' : 'REVISE', $detail !== '' ? $detail : 'diaktifkan kembali');
                        $res['revised']++;
                        $res['lines'][] = $wo . ': diperbarui (' . $detail . ')';
                    } elseif ($status === 'SUPPLY' || $status === 'RECEIVED') {
                        // Barang sudah dikirim: hanya tandai, gudang yang memutuskan.
                        if (count($diff) > 0) {
                            if (last_log_detail($w, $wo, 'PLAN_CHANGE') !== $detail) {
                                wlog($w, $wo, 'PLAN_CHANGE', $detail);
                                $res['flagged']++;
                                $res['lines'][] = $wo . ': PERUBAHAN setelah dikirim (' . $detail . ')';
                            } else {
                                $res['unchanged']++;
                            }
                        } else {
                            $res['unchanged']++;
                        }
                        $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET LastSyncDt = NOW() WHERE WONO = ?');
                        $up->execute([$wo]);
                    } elseif ($status === 'PREPARED') {
                        $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET LastSyncDt = NOW() WHERE WONO = ?');
                        $up->execute([$wo]);
                        $res['unchanged']++;
                    }
                }

                $w->commit();
            } catch (Exception $e2) {
                if ($w->inTransaction()) {
                    $w->rollBack();
                }
                $res['errors']++;
                $res['lines'][] = $wo . ': GAGAL (' . $e2->getMessage() . ')';
            }
        }

        // 4. WO yang tadinya ada untuk tanggal ini tetapi sekarang hilang dari ERP.
        if (count($erp) === 0) {
            $res['lines'][] = 'ERP mengembalikan 0 WO CB: pembatalan otomatis dilewati (pengaman).';
        } else {
            $st = $w->prepare('SELECT WONO, Status FROM ' . $main . '.tblcbsupply WHERE PlanDate = ? AND Status <> \'CANCEL\'');
            $st->execute([$date]);
            foreach ($st->fetchAll() as $row) {
                $wo = $row['WONO'];
                if (isset($erp[$wo])) {
                    continue;
                }
                try {
                    $w->beginTransaction();
                    if ($row['Status'] === 'PREPARED') {
                        $up = $w->prepare('UPDATE ' . $main . '.tblcbsupply SET Status = \'CANCEL\', CancelDt = NOW(), '
                            . 'LastSyncDt = NOW(), Note = ? WHERE WONO = ?');
                        $up->execute(['WO hilang dari ERP', $wo]);
                        wlog($w, $wo, 'CANCEL', 'WO hilang dari ERP (belum dikirim)');
                        $res['cancelled']++;
                        $res['lines'][] = $wo . ': dibatalkan, hilang dari ERP';
                    } else {
                        if (last_log_detail($w, $wo, 'WO_MISSING') === null) {
                            wlog($w, $wo, 'WO_MISSING', 'WO hilang dari ERP padahal sudah dikirim');
                            $res['flagged']++;
                            $res['lines'][] = $wo . ': PERINGATAN, hilang dari ERP padahal sudah dikirim';
                        }
                    }
                    $w->commit();
                } catch (Exception $e3) {
                    if ($w->inTransaction()) {
                        $w->rollBack();
                    }
                    $res['errors']++;
                    $res['lines'][] = $wo . ': GAGAL (' . $e3->getMessage() . ')';
                }
            }
        }
    } catch (Exception $fatal) {
        $w->query("SELECT RELEASE_LOCK('cb_supply_sync')");
        throw $fatal;
    }

    $w->query("SELECT RELEASE_LOCK('cb_supply_sync')");
    return $res;
}