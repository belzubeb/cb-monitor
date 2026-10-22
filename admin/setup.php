<?php
// Halaman pengaturan database (GUI) untuk Monitor Cover Bottom.
// Hanya bisa dibuka dari PC server itu sendiri: http://localhost/cb-monitor/admin/setup.php
session_start();
require __DIR__ . '/../src/lib.php';

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function is_local_request()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    $srv = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '';
    return $ip === '127.0.0.1' || $ip === '::1' || ($srv !== '' && $ip === $srv);
}

if (!is_local_request() && empty($CFG['setup_allow_remote'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Akses ditolak</title></head>'
       . '<body style="font-family:Arial,sans-serif;padding:2rem">'
       . '<h2>Akses ditolak</h2>'
       . '<p>Halaman setup hanya bisa dibuka dari PC server itu sendiri, lewat alamat '
       . '<b>http://localhost/cb-monitor/admin/setup.php</b>.</p></body></html>';
    exit;
}

$localFile = __DIR__ . '/../config/settings.local.php';

if (empty($_SESSION['tok'])) {
    $_SESSION['tok'] = md5(uniqid((string)mt_rand(), true));
}

$cur = $CFG;
$hasPass = ((string)$cur['db']['pass'] !== '');
$vals = [
    'host'        => $cur['db']['host'],
    'port'        => $cur['db']['port'],
    'user'        => $cur['db']['user'],
    'db_led'      => $cur['db_led'],
    'db_mes'      => $cur['db_mes'],
    'prod_source' => $cur['prod_source'],
    'show_errors' => !empty($cur['show_errors']),
    'admin_pin'   => isset($cur['admin_pin']) ? (string)$cur['admin_pin'] : '',
    'auto_create' => !empty($cur['auto_create']),
];
$msg = '';
$msgOk = true;
$checks = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['tok']) || $_POST['tok'] !== $_SESSION['tok']) {
        $msg = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        $msgOk = false;
    } else {
        $vals['host']        = trim(isset($_POST['host']) ? (string)$_POST['host'] : '');
        $vals['port']        = trim(isset($_POST['port']) ? (string)$_POST['port'] : '');
        $vals['user']        = trim(isset($_POST['user']) ? (string)$_POST['user'] : '');
        $vals['db_led']      = trim(isset($_POST['db_led']) ? (string)$_POST['db_led'] : '');
        $vals['db_mes']      = trim(isset($_POST['db_mes']) ? (string)$_POST['db_mes'] : '');
        $vals['prod_source'] = isset($_POST['prod_source']) ? (string)$_POST['prod_source'] : 'erp';
        $vals['show_errors'] = isset($_POST['show_errors']);
        $vals['auto_create'] = isset($_POST['auto_create']);
        $vals['admin_pin']   = trim(isset($_POST['admin_pin']) ? (string)$_POST['admin_pin'] : '');

        // Password dikosongkan di form = pakai password yang sudah tersimpan.
        $passIn = isset($_POST['pass']) ? (string)$_POST['pass'] : '';
        $pass = ($passIn !== '') ? $passIn : (string)$cur['db']['pass'];

        $errs = [];
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $vals['host'])) {
            $errs[] = 'Host tidak valid.';
        }
        if (!ctype_digit($vals['port']) || (int)$vals['port'] < 1 || (int)$vals['port'] > 65535) {
            $errs[] = 'Port harus angka 1 sampai 65535.';
        }
        if ($vals['user'] === '') {
            $errs[] = 'Username wajib diisi.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $vals['db_led'])) {
            $errs[] = 'Nama database POP/LED tidak valid.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $vals['db_mes'])) {
            $errs[] = 'Nama database MES tidak valid.';
        }
        if (!preg_match('/^[A-Za-z0-9]{0,20}$/', $vals['admin_pin'])) {
            $errs[] = 'PIN hanya boleh huruf dan angka, maksimal 20 karakter.';
        }
        if ($vals['prod_source'] !== 'erp' && $vals['prod_source'] !== 'scanout') {
            $errs[] = 'Sumber Prod tidak valid.';
        }

        if (count($errs) > 0) {
            $msg = implode(' ', $errs);
            $msgOk = false;
        } else {
            $newCfg = $CFG;
            $newCfg['db'] = [
                'host' => $vals['host'],
                'port' => (int)$vals['port'],
                'user' => $vals['user'],
                'pass' => $pass,
            ];
            $newCfg['db_led'] = $vals['db_led'];
            $newCfg['db_mes'] = $vals['db_mes'];

            $action = isset($_POST['action']) ? (string)$_POST['action'] : 'test';
            if ($action === 'save') {
                $data = [
                    'db' => [
                        'host' => $vals['host'],
                        'port' => (int)$vals['port'],
                        'user' => $vals['user'],
                        'pass' => $pass,
                    ],
                    'db_led'      => $vals['db_led'],
                    'db_mes'      => $vals['db_mes'],
                    'prod_source' => $vals['prod_source'],
                    'show_errors' => (bool)$vals['show_errors'],
                    'admin_pin'   => $vals['admin_pin'],
                    'auto_create' => (bool)$vals['auto_create'],
                ];
                $php = "<?php\n// Dibuat oleh setup.php. Berisi password, jangan dibagikan.\nreturn "
                     . var_export($data, true) . ";\n";
                if (@file_put_contents($localFile, $php, LOCK_EX) === false) {
                    $msg = 'Gagal menyimpan settings.local.php. Periksa izin tulis folder config.';
                    $msgOk = false;
                } else {
                    $msg = 'Pengaturan tersimpan. Hasil uji koneksi dengan pengaturan ini ada di bawah.';
                    $hasPass = ($pass !== '');
                    $checks = run_checks($newCfg, true);
                }
            } else {
                $msg = 'Ini hasil uji koneksi. Pengaturan BELUM disimpan.';
                $checks = run_checks($newCfg, true);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pengaturan Monitor Cover Bottom</title>
<script src="../assets/js/nav.js"></script>
<style>
  body { font-family: Arial, Helvetica, sans-serif; background: #eef1f5; margin: 0; padding: 24px; color: #222; }
  .box { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 8px; padding: 24px 28px;
         box-shadow: 0 2px 8px rgba(0,0,0,.12); }
  h1 { margin: 0 0 4px; font-size: 22px; color: #1f3a5f; }
  .sub { margin: 0 0 18px; color: #666; font-size: 14px; }
  fieldset { border: 1px solid #ccd3dc; border-radius: 6px; margin: 0 0 16px; padding: 12px 16px 14px; }
  legend { font-weight: bold; color: #1f3a5f; padding: 0 6px; }
  label { display: block; margin: 8px 0 0; font-size: 14px; }
  input[type=text], input[type=password], select { width: 100%; padding: 8px; margin-top: 3px; font-size: 15px;
         border: 1px solid #b8c0cb; border-radius: 4px; }
  .row { display: flex; gap: 12px; } .row > label { flex: 1; }
  .hint { font-size: 12px; color: #777; margin-top: 3px; }
  button { padding: 10px 20px; font-size: 15px; border: 0; border-radius: 5px; cursor: pointer; margin-right: 8px; }
  button.test { background: #e3e8ef; color: #1f3a5f; }
  button.save { background: #1f3a5f; color: #fff; }
  .msg { padding: 10px 14px; border-radius: 5px; margin-bottom: 16px; font-size: 14px; }
  .msg.ok { background: #e3f6e8; color: #17602b; } .msg.bad { background: #fde7e7; color: #8a1c1c; }
  table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 14px; }
  td, th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #e3e6ea; vertical-align: top; }
  .s-ok { color: #17602b; font-weight: bold; } .s-bad { color: #b3261e; font-weight: bold; }
  .links a { margin-right: 14px; }
</style>
</head>
<body>
<div class="box">
  <h1>Pengaturan Database</h1>
  <p class="sub">Monitor Cover Bottom, Tahap 1 (baca-saja)</p>

  <?php if ($msg !== ''): ?>
    <div class="msg <?php echo $msgOk ? 'ok' : 'bad'; ?>"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="tok" value="<?php echo h($_SESSION['tok']); ?>">

    <fieldset>
      <legend>Server MySQL (server .235, sama dengan POP)</legend>
      <div class="row">
        <label>Host
          <input type="text" name="host" value="<?php echo h($vals['host']); ?>">
        </label>
        <label style="max-width:140px">Port
          <input type="text" name="port" value="<?php echo h($vals['port']); ?>">
        </label>
      </div>
      <div class="hint">Isi alamat server database POP (192.50.0.235), bukan localhost. MySQL bawaan XAMPP tidak dipakai.</div>
      <div class="row">
        <label>Username
          <input type="text" name="user" value="<?php echo h($vals['user']); ?>">
        </label>
        <label>Password
          <input type="password" name="pass" value="" placeholder="<?php echo $hasPass ? '(kosongkan untuk memakai yang tersimpan)' : 'isi password'; ?>">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Nama database</legend>
      <div class="row">
        <label>Database POP / LED
          <input type="text" name="db_led" value="<?php echo h($vals['db_led']); ?>">
        </label>
        <label>Database MES (view ERP)
          <input type="text" name="db_mes" value="<?php echo h($vals['db_mes']); ?>">
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Tampilan</legend>
      <label>Sumber angka Prod
        <select name="prod_source">
          <option value="erp" <?php echo $vals['prod_source'] === 'erp' ? 'selected' : ''; ?>>ERP: prod_gd_qty per WO</option>
          <option value="scanout" <?php echo $vals['prod_source'] === 'scanout' ? 'selected' : ''; ?>>POP: ScanOut per ProdNo</option>
        </select>
      </label>
      <label>
        <input type="checkbox" name="show_errors" value="1" <?php echo $vals['show_errors'] ? 'checked' : ''; ?>>
        Tampilkan detail error di monitor (matikan setelah pemasangan stabil)
      </label>
    </fieldset>

    <fieldset>
      <legend>Halaman Planning</legend>
      <label>PIN untuk aksi Batalkan, Buat, dan Sinkron (kosongkan jika tidak perlu PIN)
        <input type="text" name="admin_pin" value="<?php echo h($vals['admin_pin']); ?>" maxlength="20">
      </label>
      <label>
        <input type="checkbox" name="auto_create" value="1" <?php echo $vals['auto_create'] ? 'checked' : ''; ?>>
        Buat supply list otomatis untuk WO baru (jika tidak dicentang, dibuat manual lewat tombol di halaman Planning)
      </label>
      <div class="hint">Huruf dan angka saja. Halaman Planning bisa dibuka siapa saja di jaringan, PIN mencegah salah klik oleh yang bukan leader.</div>
    </fieldset>

    <button type="submit" name="action" value="test" class="test">Tes koneksi</button>
    <button type="submit" name="action" value="save" class="save">Simpan</button>
  </form>

  <?php if (is_array($checks)): ?>
    <h3 style="margin:22px 0 4px">Hasil uji</h3>
    <table>
      <?php foreach ($checks as $name => $r): ?>
        <tr>
          <td><?php echo h($name); ?></td>
          <td class="<?php echo $r['ok'] ? 's-ok' : 's-bad'; ?>"><?php echo $r['ok'] ? 'OK' : 'GAGAL'; ?></td>
          <td><?php echo h($r['ok'] ? $r['info'] : $r['error']); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <p class="hint" style="margin-top:20px">
    Pengaturan disimpan di file <b>settings.local.php</b> di folder config
    (<?php echo is_file($localFile) ? 'sudah ada' : 'belum ada'; ?>).
    Password hanya disimpan di file itu dan tidak pernah ditampilkan kembali.
  </p>
  <p class="links">
    <a href="../pages/monitor.html?line=2" target="_blank">Buka monitor LINE 2</a>
    <a href="../pages/monitor.html?line=3" target="_blank">Buka monitor LINE 3</a>
    <a href="../api/monitor.php?check=1" target="_blank">Uji sambungan sistem</a>
  </p>
</div>
</body>
</html>
