<?php
// Pengaturan & fungsi bersama. Tidak perlu diubah.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'config.php') { http_response_code(404); exit; }

date_default_timezone_set('Asia/Jakarta');
define('UPLOAD_DIR',  __DIR__ . '/files_upload');   // file hasil upload (nama asli)
define('ADMIN_JSON',  __DIR__ . '/data/admin.json'); // login admin
define('TOKENS_JSON', __DIR__ . '/data/tokens.json');// link download bertoken

// File pelindung di folder upload — tidak boleh ditimpa / diunduh
const RESERVED = ['index.php', '.htaccess', 'web.config', '.user.ini'];

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Nama file: tetap asli, hanya buang yang berbahaya ---------- */
function clean_filename(string $name): string {
    $name = trim(basename(str_replace('\\', '/', $name)));
    $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/', '_', $name);   // juga karakter terlarang Windows
    $name = rtrim($name, '. ');
    if ($name === '' || $name === '.' || $name === '..') return '';
    if (in_array(strtolower($name), RESERVED, true) || preg_match('/^(con|prn|aux|nul|com\d|lpt\d)(\.|$)/i', $name)) $name = '_' . $name;
    if (strlen($name) > 200) {                                        // batas panjang nama, ekstensi dipertahankan
        $ext  = strrchr($name, '.');
        $ext  = ($ext !== false && strlen($ext) <= 20) ? $ext : '';
        $base = substr($name, 0, 200 - strlen($ext));
        while ($base !== '' && !preg_match('//u', $base)) $base = substr($base, 0, -1); // jangan potong di tengah huruf UTF-8
        $name = $base . $ext;
    }
    return $name;
}

/** Path tujuan unik: "nama.ext", kalau sudah ada -> "nama (1).ext", "nama (2).ext" ... */
function unique_path(string $name): ?string {
    $i = pathinfo($name);
    $base = $i['filename'] !== '' ? $i['filename'] : $name;
    $ext  = ($i['filename'] !== '' && isset($i['extension'])) ? '.' . $i['extension'] : '';
    for ($n = 0; $n < 1000; $n++) {
        $p = UPLOAD_DIR . '/' . ($n ? "$base ($n)$ext" : $name);
        if ($fh = @fopen($p, 'x')) { fclose($fh); return $p; }      // dipesan atomik, tidak menimpa
    }
    return null;
}

/** Nama file -> path di folder upload (anti path traversal). */
function file_path(string $name): ?string {
    if ($name === '' || $name !== basename($name) || in_array(strtolower($name), RESERVED, true)) return null;
    $p = realpath(UPLOAD_DIR . '/' . $name);
    return ($p && dirname($p) === realpath(UPLOAD_DIR) && is_file($p)) ? $p : null;
}

/** "10G" -> byte (nilai php.ini) */
function ini_bytes(string $v): int {
    $n = (int)$v;
    switch (strtoupper(substr(trim($v), -1))) { case 'G': $n *= 1024; case 'M': $n *= 1024; case 'K': $n *= 1024; }
    return $n;
}

/** Peringatan setting yang kurang (ditampilkan di halaman upload & admin). */
function setup_warnings(): array {
    $w = [];
    if (ini_bytes(ini_get('upload_max_filesize')) < 10 * 1024 ** 3) $w[] = 'php.ini: upload_max_filesize masih ' . ini_get('upload_max_filesize') . ' (set ke 10G lalu restart Apache)';
    $post = ini_bytes(ini_get('post_max_size'));
    if ($post !== 0 && $post < 10 * 1024 ** 3) $w[] = 'php.ini: post_max_size masih ' . ini_get('post_max_size') . ' (set ke 0 atau 10G)';
    if (empty($_SERVER['HTACCESS_OK']) && empty($_SERVER['REDIRECT_HTACCESS_OK'])) $w[] = '.htaccess tidak aktif: folder files_upload & data bisa dibuka langsung, dan upload > 1 GB bisa gagal. Aktifkan "AllowOverride All" di Apache.';
    if (!is_writable(UPLOAD_DIR) || !is_writable(dirname(TOKENS_JSON))) $w[] = 'Folder files_upload/ atau data/ tidak bisa ditulis web server (Linux: sudo chown -R www-data files_upload data)';
    return $w;
}

function size_text($b): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $i ? 2 : 0) . ' ' . $u[$i];
}

/* ---------- Login admin (data/admin.json) ---------- */
function session_on(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('UPADMIN');
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
    }
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function csrf(): string { session_on(); return $_SESSION['csrf']; }
function csrf_ok(): bool { session_on(); return hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? '')); }
function logged_in(): bool { session_on(); return !empty($_SESSION['admin']); }

function check_login(string $user, string $pass): bool {
    $a = json_decode((string)@file_get_contents(ADMIN_JSON), true);
    if (!isset($a['username'], $a['password'])) return false;
    $okPass = str_starts_with($a['password'], '$2y$') ? password_verify($pass, $a['password']) : hash_equals((string)$a['password'], $pass);
    return hash_equals((string)$a['username'], $user) && $okPass;
}

/* ---------- Link download bertoken (data/tokens.json) ---------- */
/** Buka tokens.json dengan kunci, buang yang kedaluwarsa, jalankan $fn(&$tokens), simpan. */
function tokens(?callable $fn = null) {
    $fh = fopen(TOKENS_JSON, 'c+');
    flock($fh, LOCK_EX);
    $t = json_decode(stream_get_contents($fh) ?: '{}', true) ?: [];
    // buang token yang kedaluwarsa / filenya sudah tidak ada
    $t = array_filter($t, fn($x) => (!$x['expires'] || $x['expires'] > time()) && is_file(UPLOAD_DIR . '/' . $x['file']));
    $res = $fn ? $fn($t) : $t;
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($t, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}

/** URL folder aplikasi, mis. http://192.168.1.5/10gb-file_upload */
function app_url(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function token_url(string $file, string $tok): string {
    return app_url() . '/download.php?f=' . rawurlencode($file) . '&t=' . $tok;
}

/** Perintah siap-copy. (escapeshellarg tidak dipakai: di Windows ia merusak tanda % di URL) */
function download_cmds(string $file, string $url): array {
    $sh = fn($s) => "'" . str_replace("'", "'\\''", $s) . "'";   // bash
    $ps = fn($s) => "'" . str_replace("'", "''", $s) . "'";      // PowerShell
    return [
        'curl' => 'curl -fL -C - -o ' . $sh($file) . ' ' . $sh($url),
        'wget' => 'wget -c -O ' . $sh($file) . ' ' . $sh($url),
        'win'  => 'curl.exe -fL -C - -o ' . $ps($file) . ' ' . $ps($url),
    ];
}

function ttl_text(int $exp): string {
    if (!$exp) return 'Permanen';
    $s = $exp - time();
    if ($s < 3600)  return ceil($s / 60) . ' menit lagi';
    if ($s < 86400) return round($s / 3600, 1) . ' jam lagi';
    return round($s / 86400, 1) . ' hari lagi';
}
