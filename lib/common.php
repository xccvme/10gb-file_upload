<?php
// Helper bersama — di luar webroot (public/), tidak bisa diakses via HTTP

define('BASE_DIR', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');
define('STORAGE_DIR', BASE_DIR . '/public/files_upload'); // file upload disimpan di sini (nama asli)
define('ADMIN_JSON', BASE_DIR . '/data/admin.json'); // kredensial admin
define('LOCK_JSON', BASE_DIR . '/data/login_attempts.json');

// File internal di folder upload (proteksi) — tidak bisa ditimpa, diunduh, atau dihapus
const RESERVED_NAMES = ['index.php', '.htaccess', '.htpasswd', '.user.ini', 'web.config'];

// Fallback bila ekstensi mbstring tidak aktif (mis. PHP Windows dari winget tanpa php.ini)
if (!function_exists('mb_strcut'))    { function mb_strcut($s, $start, $len = null, $enc = null) {
    $r = substr($s, $start, $len ?? strlen($s));
    while ($r !== '' && !preg_match('//u', $r)) $r = substr($r, 0, -1); // jangan potong di tengah karakter UTF-8
    return $r;
} }
if (!function_exists('mb_strtolower')) { function mb_strtolower($s, $enc = null) { return strtolower($s); } }

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Pertahankan nama & ekstensi asli. Hanya buang hal yang berbahaya:
 * path separator, karakter kontrol, nama "." / "..", dan panjang > 255 byte.
 */
function clean_filename(string $name): string {
    $name = str_replace("\\", '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim($name);
    if (PHP_OS_FAMILY === 'Windows') {
        // Karakter yang dilarang NTFS + titik/spasi di akhir nama
        $name = rtrim(preg_replace('/[<>:"|?*]/', '_', $name), '. ');
        if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$/i', $name)) $name = '_' . $name;
    }
    if ($name === '' || $name === '.' || $name === '..') return '';
    // Nama file konfigurasi server yang bisa disalahgunakan -> beri awalan "_"
    if (in_array(strtolower($name), RESERVED_NAMES, true)) $name = '_' . $name;
    if (strlen($name) > 255) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? '.' . mb_strcut($ext, 0, 20, 'UTF-8') : '';
        $name = mb_strcut(pathinfo($name, PATHINFO_FILENAME), 0, 255 - strlen($ext), 'UTF-8') . $ext;
    }
    return $name;
}

/**
 * Pesan path secara atomik. Jika nama sudah ada → "nama (1).ext", "nama (2).ext", ...
 * File lama tidak pernah tertimpa. Mengembalikan path, atau null bila gagal.
 */
function reserve_path(string $dir, string $name): ?string {
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $info = pathinfo($name);
    $base = $info['filename'];
    $ext  = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
    if ($base === '') { $base = $name; $ext = ''; } // contoh: ".bashrc"
    for ($n = 0; $n < 10000; $n++) {
        $candidate = $n === 0 ? $name : "$base ($n)$ext";
        $path = $dir . '/' . $candidate;
        $fh = @fopen($path, 'x');
        if ($fh !== false) { fclose($fh); return $path; }
    }
    return null;
}

/** Resolve nama file → path aman di dalam STORAGE_DIR (anti path traversal). */
function storage_path(string $name): ?string {
    if ($name === '' || $name !== basename($name) || $name === '.' || $name === '..') return null;
    if (in_array(strtolower($name), RESERVED_NAMES, true)) return null;
    $path = STORAGE_DIR . '/' . $name;
    $real = realpath($path);
    $root = realpath(STORAGE_DIR);
    if ($real === false || $root === false || dirname($real) !== $root || !is_file($real)) return null;
    return $real;
}

function format_bytes($bytes): string {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $p = min((int)floor(log($bytes, 1024)), count($u) - 1);
    return round($bytes / pow(1024, $p), $p === 0 ? 0 : 2) . ' ' . $u[$p];
}

/* ---------------- Session & Auth ---------------- */

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('UPADMIN');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrf_token(): string { start_session(); return $_SESSION['csrf']; }

function csrf_ok(?string $t): bool { start_session(); return is_string($t) && hash_equals($_SESSION['csrf'], $t); }

function load_admin(): ?array {
    if (!is_file(ADMIN_JSON)) return null;
    $d = json_decode((string)file_get_contents(ADMIN_JSON), true);
    return (is_array($d) && isset($d['username'], $d['password'])) ? $d : null;
}

function is_logged_in(): bool { start_session(); return !empty($_SESSION['admin']); }

function require_login(): void {
    if (!is_logged_in()) { header('Location: /admin/'); exit; }
}

/* Brute-force guard sederhana: 5x gagal per IP → kunci 5 menit */
function attempts_load(): array {
    $d = is_file(LOCK_JSON) ? json_decode((string)file_get_contents(LOCK_JSON), true) : [];
    return is_array($d) ? $d : [];
}
function attempts_save(array $d): void {
    $now = time();
    $d = array_filter($d, fn($v) => ($v['until'] ?? 0) > $now || ($v['last'] ?? 0) > $now - 900);
    file_put_contents(LOCK_JSON, json_encode($d), LOCK_EX);
}
function login_locked(string $ip): int {
    $d = attempts_load();
    $until = $d[$ip]['until'] ?? 0;
    return $until > time() ? $until - time() : 0;
}
function login_failed(string $ip): void {
    $d = attempts_load();
    $e = $d[$ip] ?? ['count' => 0];
    if (($e['last'] ?? 0) < time() - 900) $e['count'] = 0;
    $e['count']++;
    $e['last'] = time();
    if ($e['count'] >= 5) { $e['until'] = time() + 300; $e['count'] = 0; }
    $d[$ip] = $e;
    attempts_save($d);
}
function login_reset(string $ip): void { $d = attempts_load(); unset($d[$ip]); attempts_save($d); }

/* ---------------- Token link download per file ---------------- */
define('TOKENS_JSON', BASE_DIR . '/data/tokens.json');

/** Baca-ubah-tulis tokens.json secara atomik (flock). $fn menerima &$tokens; nilai return $fn diteruskan. */
function tokens_tx(callable $fn) {
    $fh = fopen(TOKENS_JSON, 'c+');
    if (!$fh) return null;
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $tokens = json_decode($raw ?: '[]', true);
    if (!is_array($tokens)) $tokens = [];
    $now = time();
    $before = count($tokens);
    $tokens = array_filter($tokens, fn($t) => empty($t['expires']) || $t['expires'] > $now); // buang yg kedaluwarsa
    $dirty = count($tokens) !== $before;
    $result = $fn($tokens, $dirty);
    if ($dirty) {
        ftruncate($fh, 0); rewind($fh);
        fwrite($fh, json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    }
    flock($fh, LOCK_UN); fclose($fh);
    return $result;
}

function tokens_all(): array { return tokens_tx(function (&$t, &$d) { return $t; }) ?? []; }

/** $ttl detik; 0 = permanen. */
function token_create(string $file, int $ttl): array {
    $tok = bin2hex(random_bytes(16));
    $rec = ['file' => $file, 'created' => time(), 'expires' => $ttl > 0 ? time() + $ttl : 0, 'hits' => 0];
    tokens_tx(function (&$t, &$d) use ($tok, $rec) { $t[$tok] = $rec; $d = true; });
    return ['token' => $tok] + $rec;
}

/** Valid jika token ada, belum kedaluwarsa, dan terikat ke file yg sama. Menambah hitungan unduhan. */
function token_check(string $tok, string $file): bool {
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return false;
    return (bool)tokens_tx(function (&$t, &$d) use ($tok, $file) {
        if (!isset($t[$tok]) || !hash_equals($t[$tok]['file'], $file)) return false;
        $t[$tok]['hits'] = ($t[$tok]['hits'] ?? 0) + 1; $d = true;
        return true;
    });
}

function token_revoke(string $tok): bool {
    return (bool)tokens_tx(function (&$t, &$d) use ($tok) {
        if (!isset($t[$tok])) return false; unset($t[$tok]); $d = true; return true;
    });
}

function tokens_revoke_file(string $file): void {
    tokens_tx(function (&$t, &$d) use ($file) {
        foreach ($t as $k => $v) if ($v['file'] === $file) { unset($t[$k]); $d = true; }
    });
}

function base_url(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function token_url(string $file, string $tok): string {
    return base_url() . '/download.php?f=' . rawurlencode($file) . '&t=' . $tok;
}

function human_ttl(int $expires): string {
    if ($expires === 0) return 'Permanen';
    $s = $expires - time();
    if ($s <= 0) return 'Kedaluwarsa';
    if ($s < 3600) return ceil($s / 60) . ' menit lagi';
    if ($s < 86400) return round($s / 3600, 1) . ' jam lagi';
    return round($s / 86400, 1) . ' hari lagi';
}

/** Quote untuk bash/sh (Linux/macOS) — tidak pakai escapeshellarg() karena di PHP Windows ia merusak '%' di URL. */
function sh_quote(string $s): string { return "'" . str_replace("'", "'\\''", $s) . "'"; }

/** Quote untuk PowerShell (Windows 10/11). */
function ps_quote(string $s): string { return "'" . str_replace("'", "''", $s) . "'"; }

/** Perintah download siap-copy untuk Linux & Windows. */
function download_cmds(string $file, string $url): array {
    return [
        'curl' => 'curl -fL -C - -o ' . sh_quote($file) . ' ' . sh_quote($url),
        'wget' => 'wget -c -O ' . sh_quote($file) . ' ' . sh_quote($url),
        'win'  => 'curl.exe -fL -C - -o ' . ps_quote($file) . ' ' . ps_quote($url),
    ];
}
