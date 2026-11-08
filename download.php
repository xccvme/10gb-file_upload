<?php
// Download file: admin yang login, atau link bertoken ?t=... yang masih aktif.
// Dukung resume (Range) -> curl -C - / wget -c
require __DIR__ . '/config.php';

$name = (string)($_GET['f'] ?? '');
$tok  = (string)($_GET['t'] ?? '');

if ($tok !== '') {
    $valid = tokens(function (&$t) use ($tok, $name) {
        if (!isset($t[$tok]) || $t[$tok]['file'] !== $name) return false;
        $t[$tok]['hits']++;
        return true;
    });
    if (!$valid) { http_response_code(403); exit("Link tidak valid atau sudah kedaluwarsa.\n"); }
} elseif (!logged_in()) {
    header('Location: admin.php'); exit;
}

$path = file_path($name);
if (!$path) { http_response_code(404); exit("File tidak ditemukan.\n"); }
session_write_close();

$size = filesize($path);
$start = 0; $end = $size - 1;

if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
    if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($size ? $end - $start + 1 : 0));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD' || !$size) exit;

set_time_limit(0);
while (ob_get_level()) ob_end_clean();
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !connection_aborted() && ($buf = fread($fh, min(8 << 20, $left))) !== false && $buf !== '') {
    echo $buf; flush();
    $left -= strlen($buf);
}
fclose($fh);
