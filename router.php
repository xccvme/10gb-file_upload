<?php
// Router untuk mode cepat `php -S` (tanpa Apache/XAMPP).
// php -S tidak membaca .htaccess, jadi blokir akses langsung ke folder upload di sini.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#^/files_upload(/|$)#i', $path)) {
    http_response_code(403);
    exit("Forbidden\n");
}
return false; // selain itu: layani file seperti biasa
