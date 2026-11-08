<?php
// Download file asli. Akses: (a) admin yang login, atau (b) link bertoken ?t=... yang masih aktif.
// Apache + mod_xsendfile (SetEnv XSENDFILE_ON 1) -> dikirim Apache langsung (cepat, hemat RAM).
// Tanpa xsendfile (Windows/XAMPP, php -S)        -> streaming PHP per 8 MB, dukung Range/resume.
require __DIR__ . '/../lib/common.php';

$name = (string)($_GET['f'] ?? '');
$tok  = (string)($_GET['t'] ?? '');

if ($tok !== '') {
    if (!token_check($tok, $name)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit("Link tidak valid atau sudah kedaluwarsa.\n");
    }
} else {
    require_login();
}

$path = storage_path($name);
if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("File tidak ditemukan.\n");
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); // jangan kunci sesi selama download

$fallback = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$xs = getenv('XSENDFILE_ON') ?: ($_SERVER['XSENDFILE_ON'] ?? ($_SERVER['REDIRECT_XSENDFILE_ON'] ?? ''));
if ($xs === '1') {
    header('X-Sendfile: ' . $path);
    exit;
}

/* ---------- Fallback: streaming PHP + Range ---------- */
@set_time_limit(0);
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) ob_end_clean();

$size  = filesize($path);
$start = 0;
$end   = $size - 1;
header('Accept-Ranges: bytes');

if ($size > 0 && isset($_SERVER['HTTP_RANGE'])
    && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)
    && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                                  // bytes=-N  -> N byte terakhir
        $start = max(0, $size - (int)$m[2]);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$length = max(0, $end - $start + 1);
header('Content-Length: ' . $length);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) exit;

$fh = fopen($path, 'rb');
fseek($fh, $start);
while ($length > 0 && !feof($fh) && !connection_aborted()) {
    $buf = fread($fh, (int)min(8 * 1024 * 1024, $length));
    if ($buf === false || $buf === '') break;
    echo $buf;
    flush();
    $length -= strlen($buf);
}
fclose($fh);
exit;
