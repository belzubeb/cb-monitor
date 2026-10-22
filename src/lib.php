<?php
// Lapisan data Monitor Cover Bottom, Tahap 1. Hanya SELECT, tidak ada INSERT/UPDATE/DELETE.

$CFG = require __DIR__ . '/../config/config.php';
date_default_timezone_set($CFG['timezone']);

// Nama database disisipkan ke SQL, jadi dibatasi huruf, angka, dan underscore.
function ident($name)
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('Nama database tidak valid');
    }
    return '`' . $name . '`';
}

// Buka koneksi dari pengaturan $c (host, port, user, pass).
function make_pdo($c, $readOnly = true)
{
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . (int)$c['port'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
    // Pengaman tambahan: sesi baca tidak boleh menulis, walaupun akunnya punya hak tulis.
    // Koneksi tulis (db_write) hanya dipakai sync_lib.php untuk tabel tblcb*.
    if ($readOnly) {
        try {
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        } catch (Exception $e) {
            // Server lama tanpa fitur ini: abaikan.
        }
    }
    return $pdo;
}

function db_write()
{
    global $CFG;
    static $pdoW = null;
    if ($pdoW === null) {
        $pdoW = make_pdo($CFG['db'], false);
    }
    return $pdoW;
}

function db()
{
    global $CFG;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = make_pdo($CFG['db']);
    }
    return $pdo;
}

// Uji koneksi dan akses tabel dengan pengaturan $cfg. Dipakai api/monitor.php?check=1 dan admin/setup.php.
function run_checks($cfg, $withGrants = false)
{
    $out = [];
    $today = date('Y-m-d');
    $sampleWo = null;
    $samplePart = null;

    try {
        $pdo = make_pdo($cfg['db']);
        $v = $pdo->query('SELECT VERSION() AS v')->fetch();
        $out['1. Koneksi ke MySQL'] = ['ok' => true, 'info' => 'MySQL ' . $v['v']];
    } catch (Exception $e) {
        $out['1. Koneksi ke MySQL'] = ['ok' => false, 'error' => $e->getMessage()];
        return $out;
    }

    try {
        $t = ident($cfg['db_led']) . '.tblledsupply';
        $st = $pdo->prepare('SELECT COUNT(*) AS c FROM ' . $t . ' WHERE Tanggal = :d');
        $st->execute([':d' => $today]);
        $c = $st->fetch();
        $st2 = $pdo->prepare('SELECT WOrder, PartNoFG FROM ' . $t
            . ' WHERE Tanggal = :d AND WOrder IS NOT NULL ORDER BY ID DESC LIMIT 1');
        $st2->execute([':d' => $today]);
        $s = $st2->fetch();
        if ($s) {
            $sampleWo = trim($s['WOrder']);
            $samplePart = trim($s['PartNoFG']);
        }
        $out['2. ' . $cfg['db_led'] . '.tblledsupply'] = ['ok' => true, 'info' => $c['c'] . ' baris untuk tanggal ' . $today];
    } catch (Exception $e) {
        $out['2. ' . $cfg['db_led'] . '.tblledsupply'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    try {
        if ($sampleWo === null) {
            $out['3. v_ppr_work_ord (ERP)'] = ['ok' => true, 'info' => 'dilewati: tidak ada WO hari ini di tblledsupply'];
        } else {
            $st = $pdo->prepare('SELECT work_ord_qty, prod_gd_qty FROM ' . ident($cfg['db_mes'])
                . '.v_ppr_work_ord WHERE work_ord_no = :w');
            $st->execute([':w' => $sampleWo]);
            $r = $st->fetch();
            if (!$r) {
                throw new RuntimeException('WO ' . $sampleWo . ' tidak ditemukan di v_ppr_work_ord');
            }
            $out['3. v_ppr_work_ord (ERP)'] = ['ok' => true,
                'info' => 'WO ' . $sampleWo . ': plan ' . $r['work_ord_qty'] . ', hasil ' . $r['prod_gd_qty']];
        }
    } catch (Exception $e) {
        $out['3. v_ppr_work_ord (ERP)'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    try {
        if ($samplePart === null) {
            $out['4. v_pba_fac_part_no (ERP)'] = ['ok' => true, 'info' => 'dilewati: tidak ada part hari ini'];
        } else {
            $st = $pdo->prepare('SELECT part_nm FROM ' . ident($cfg['db_mes'])
                . '.v_pba_fac_part_no WHERE part_no = :p LIMIT 1');
            $st->execute([':p' => $samplePart]);
            $r = $st->fetch();
            if (!$r) {
                throw new RuntimeException('Part ' . $samplePart . ' tidak ditemukan di v_pba_fac_part_no');
            }
            $out['4. v_pba_fac_part_no (ERP)'] = ['ok' => true, 'info' => $samplePart . ' = ' . $r['part_nm']];
        }
    } catch (Exception $e) {
        $out['4. v_pba_fac_part_no (ERP)'] = ['ok' => false, 'error' => $e->getMessage()];
    }

    // Hak akses akun (untuk memastikan akun boleh membuat tabel dan menulis di Tahap 2).
    // Hash password dibuang dari teks sebelum ditampilkan.
    if ($withGrants) {
        try {
            $g = [];
            foreach ($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM) as $row) {
                $g[] = preg_replace("/IDENTIFIED BY( PASSWORD)? '[^']*'/i", 'IDENTIFIED BY ***', (string)$row[0]);
            }
            $out['5. Hak akses akun'] = ['ok' => true, 'info' => implode('  ||  ', $g)];
        } catch (Exception $e) {
            $out['5. Hak akses akun'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    return $out;
}

// Baris planning CB di POP (satu baris = satu ProdNo) untuk satu tanggal dan satu line.
function led_rows($date, $lineName)
{
    global $CFG;
    $sql = 'SELECT ID, cLine, ProdNo, WOrder, LotNo, PartNoFG, PartNmFG, LotID, LotQty, cQty, '
         . 'cStatus, IsNextModel, ScanIN, ScanLED, ScanOut, Prodtm '
         . 'FROM ' . ident($CFG['db_led']) . '.tblledsupply '
         . 'WHERE Tanggal = :d AND TRIM(cLine) = :l AND COALESCE(cHapus, 0) = 0 '
         . 'ORDER BY CAST(ProdNo AS UNSIGNED), ID';
    $st = db()->prepare($sql);
    $st->execute([':d' => $date, ':l' => $lineName]);
    return $st->fetchAll();
}

// Plan dan hasil produksi per WO dari view ERP di server .235.
function erp_map($wos)
{
    global $CFG;
    $map = [];
    $wos = array_values(array_unique(array_filter($wos)));
    if (count($wos) === 0) {
        return $map;
    }
    $ph = implode(',', array_fill(0, count($wos), '?'));
    $sql = 'SELECT work_ord_no, part_no, lot_no, work_ord_dt, work_ord_qty, prod_gd_qty, work_close_yn '
         . 'FROM ' . ident($CFG['db_mes']) . '.v_ppr_work_ord '
         . 'WHERE work_ord_no IN (' . $ph . ')';
    $st = db()->prepare($sql);
    $st->execute($wos);
    foreach ($st->fetchAll() as $r) {
        $map[trim($r['work_ord_no'])] = $r;
    }
    return $map;
}

// Nama part dari master part ERP. Jika gagal, tampilan memakai PartNmFG dari POP.
function part_names($parts)
{
    global $CFG;
    $map = [];
    $parts = array_values(array_unique(array_filter($parts)));
    if (count($parts) === 0) {
        return $map;
    }
    try {
        $ph = implode(',', array_fill(0, count($parts), '?'));
        $sql = 'SELECT part_no, part_nm FROM ' . ident($CFG['db_mes']) . '.v_pba_fac_part_no '
             . 'WHERE part_no IN (' . $ph . ')';
        $st = db()->prepare($sql);
        $st->execute($parts);
        foreach ($st->fetchAll() as $r) {
            $k = trim($r['part_no']);
            if (!isset($map[$k])) {
                $map[$k] = trim($r['part_nm']);
            }
        }
    } catch (Exception $e) {
        // abaikan, pakai nama dari POP
    }
    return $map;
}

// Status supply list per WO untuk kolom RAW di monitor. Mengembalikan false jika tabel tblcb* belum ada.
function supply_map($wos)
{
    global $CFG;
    $wos = array_values(array_unique(array_filter($wos)));
    if (count($wos) === 0) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($wos), '?'));
        $st = db()->prepare('SELECT WONO, Status, PlanQty, Rev, Source FROM ' . ident($CFG['db_main'])
            . '.tblcbsupply WHERE WONO IN (' . $ph . ')');
        $st->execute($wos);
        $map = [];
        foreach ($st->fetchAll() as $r) {
            $map[trim($r['WONO'])] = [
                'status'   => (string)$r['Status'],
                'planSnap' => (float)$r['PlanQty'],
                'rev'      => (int)$r['Rev'],
                'source'   => (string)$r['Source'],
                'complete' => false,
                'trolleys' => '',
            ];
        }
        // Info troli (Tahap 3). Jika tabel troli belum dibuat, abaikan saja.
        try {
            $main = ident($CFG['db_main']);
            $st2 = db()->prepare('SELECT tw.WONO, MAX(tw.IsComplete = \'Y\') AS cmp, '
                . 'GROUP_CONCAT(DISTINCT t.TrolleyNo ORDER BY t.ID SEPARATOR \', \') AS tn '
                . 'FROM ' . $main . '.tblcbtrolleywo tw JOIN ' . $main . '.tblcbtrolley t ON t.ID = tw.TrolleyID '
                . 'WHERE tw.WONO IN (' . $ph . ') AND t.Status IN (\'OUT\', \'IN\') GROUP BY tw.WONO');
            $st2->execute($wos);
            foreach ($st2->fetchAll() as $r2) {
                $k = trim($r2['WONO']);
                if (isset($map[$k])) {
                    $map[$k]['complete'] = ((int)$r2['cmp']) === 1;
                    $map[$k]['trolleys'] = (string)$r2['tn'];
                }
            }
        } catch (Exception $e2) {
            // tabel troli belum ada
        }
        return $map;
    } catch (Exception $e) {
        return false;
    }
}

// Susun data mentah untuk tampilan. Aturan status (Running, Next, Finish) dihitung di monitor.html.
function build_monitor($date, $lineKey)
{
    global $CFG;
    $lineName = $CFG['lines'][$lineKey];
    $led = led_rows($date, $lineName);

    $wos = [];
    $parts = [];
    foreach ($led as $r) {
        $wos[] = trim((string)$r['WOrder']);
        $parts[] = trim((string)$r['PartNoFG']);
    }
    $erp = erp_map($wos);
    $names = part_names($parts);
    $sup = supply_map($wos);

    $rows = [];
    foreach ($led as $r) {
        $wo = trim((string)$r['WOrder']);
        $pn = trim((string)$r['PartNoFG']);
        $e = isset($erp[$wo]) ? $erp[$wo] : null;
        $rows[] = [
            'id'       => (int)$r['ID'],
            'prodNo'   => trim((string)$r['ProdNo']),
            'wo'       => $wo,
            'lotNo'    => trim((string)$r['LotNo']),
            'partNo'   => $pn,
            'partNm'   => isset($names[$pn]) ? $names[$pn] : trim((string)$r['PartNmFG']),
            'ledLot'   => trim((string)$r['LotID']),
            'lotQty'   => (float)$r['LotQty'],
            'cQty'     => (float)$r['cQty'],
            'cStatus'  => (int)$r['cStatus'],
            'isNext'   => (int)$r['IsNextModel'],
            'scanIn'   => (float)$r['ScanIN'],
            'scanLed'  => (float)$r['ScanLED'],
            'scanOut'  => (float)$r['ScanOut'],
            'prodtm'   => (float)$r['Prodtm'],
            'woFound'  => $e !== null,
            'plan'     => $e !== null ? (float)$e['work_ord_qty'] : 0,
            'prodErp'  => $e !== null ? (float)$e['prod_gd_qty'] : 0,
            'closeYn'  => $e !== null ? (string)$e['work_close_yn'] : '',
            'supply'   => ($sup !== false && isset($sup[$wo])) ? $sup[$wo] : null,
        ];
    }

    return [
        'supplyReady' => $sup !== false,
        'generated'  => date('Y-m-d H:i:s'),
        'date'       => $date,
        'line'       => $lineName,
        'prodSource' => $CFG['prod_source'],
        'rows'       => $rows,
    ];
}

// ---------------------------------------------------------------------------------------------
// Pratinjau supply list CB (hanya baca). BOM level 1 dari v_pba_mbom yang berlaku pada tanggal WO,
// dilengkapi nama, spec, satuan, dan gudang dari master part. Belum ada yang disimpan.
// need = usage x plan qty WO. Stok belum dihitung, jadi prepare = need (semua part ikut disiapkan).
// ---------------------------------------------------------------------------------------------
function bom_items($partNo, $bomDate)
{
    global $CFG;
    static $cache = [];
    $key = $partNo . '|' . $bomDate;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $mes = ident($CFG['db_mes']);
    $sql = 'SELECT m.ch_part_no, m.usage_qty, f.part_nm, f.spec_nm, f.unit_tp, f.strg_cd '
         . 'FROM ' . $mes . '.v_pba_mbom m '
         . 'LEFT JOIN ' . $mes . '.v_pba_fac_part_no f ON f.fac_tp = m.fac_tp AND f.part_no = m.ch_part_no '
         . 'WHERE m.pr_part_no = :p AND CAST(:d AS DATE) BETWEEN m.apply_start_dt AND m.apply_end_dt '
         . 'ORDER BY m.ch_part_no';
    $st = db()->prepare($sql);
    $st->execute([':p' => $partNo, ':d' => $bomDate]);
    $cache[$key] = $st->fetchAll();
    return $cache[$key];
}

function supply_preview($date, $lineKey)
{
    global $CFG;
    $lineName = $CFG['lines'][$lineKey];
    $led = led_rows($date, $lineName);

    $order = [];
    $first = [];
    foreach ($led as $r) {
        $wo = trim((string)$r['WOrder']);
        if ($wo === '' || isset($first[$wo])) {
            continue;
        }
        $first[$wo] = $r;
        $order[] = $wo;
    }
    $erp = erp_map($order);

    $result = [];
    foreach ($order as $wo) {
        $r = $first[$wo];
        $part = trim((string)$r['PartNoFG']);
        $e = isset($erp[$wo]) ? $erp[$wo] : null;
        $plan = $e !== null ? (float)$e['work_ord_qty'] : 0;
        $bomDate = ($e !== null && !empty($e['work_ord_dt'])) ? substr((string)$e['work_ord_dt'], 0, 10) : $date;

        $items = [];
        foreach (bom_items($part, $bomDate) as $b) {
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
        $result[] = [
            'wo'      => $wo,
            'prodNo'  => trim((string)$r['ProdNo']),
            'partNo'  => $part,
            'partNm'  => trim((string)$r['PartNmFG']),
            'plan'    => $plan,
            'woFound' => $e !== null,
            'bomDate' => $bomDate,
            'items'   => $items,
        ];
    }
    return $result;
}
