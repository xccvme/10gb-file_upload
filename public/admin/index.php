<?php
require __DIR__ . '/../../lib/common.php';
start_session();

$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$error = '';
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$action = $_POST['action'] ?? '';

/* ---------- LOGIN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Sesi kedaluwarsa, coba lagi.';
    } elseif ($wait = login_locked($ip)) {
        $error = "Terlalu banyak percobaan. Coba lagi dalam $wait detik.";
    } else {
        $admin = load_admin();
        $u = (string)($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        $stored = (string)($admin['password'] ?? '');
        // Mendukung password plain di JSON, atau hash bcrypt ($2y$...) jika nanti mau di-hash.
        $passOk = $admin && (str_starts_with($stored, '$2y$') ? password_verify($p, $stored) : hash_equals($stored, $p));
        if ($admin && hash_equals((string)$admin['username'], $u) && $passOk) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $u;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            login_reset($ip);
            header('Location: /admin/');
            exit;
        }
        login_failed($ip);
        usleep(400000);
        $error = 'Username atau password salah.';
    }
}

/* ---------- LOGOUT ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout' && csrf_ok($_POST['csrf'] ?? null)) {
    $_SESSION = [];
    session_destroy();
    header('Location: /admin/');
    exit;
}

/* ---------- HAPUS FILE ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && is_logged_in()) {
    if (csrf_ok($_POST['csrf'] ?? null) && ($path = storage_path((string)($_POST['f'] ?? '')))) {
        $ok = @unlink($path);
        if ($ok) tokens_revoke_file(basename($path));
        $_SESSION['flash'] = $ok ? 'File dihapus: ' . basename($path) : 'Gagal menghapus file.';
    } else {
        $_SESSION['flash'] = 'Permintaan tidak valid.';
    }
    header('Location: /admin/');
    exit;
}

/* ---------- BUAT LINK BERTOKEN (AJAX/JSON) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'token') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!is_logged_in()) { http_response_code(401); exit(json_encode(['ok' => false, 'error' => 'Sesi habis, silakan login ulang.'])); }
    if (!csrf_ok($_POST['csrf'] ?? null)) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'CSRF tidak valid, refresh halaman.'])); }
    $f = (string)($_POST['f'] ?? '');
    if (storage_path($f) === null) { http_response_code(404); exit(json_encode(['ok' => false, 'error' => 'File tidak ditemukan.'])); }
    $ttl = (int)($_POST['ttl'] ?? -1);
    $maxTtl = 365 * 86400;
    if ($ttl < 0 || $ttl > $maxTtl) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Masa aktif tidak valid (maks 365 hari).'])); }
    $t = token_create($f, $ttl);
    $url = token_url($f, $t['token']);
    $out = basename($f);
    exit(json_encode([
        'ok' => true,
        'url' => $url,
    ] + download_cmds($out, $url) + [
        'expires' => $t['expires'],
        'expires_text' => $t['expires'] ? date('d M Y H:i T', $t['expires']) : 'Permanen',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ---------- CABUT TOKEN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'revoke' && is_logged_in()) {
    $_SESSION['flash'] = (csrf_ok($_POST['csrf'] ?? null) && token_revoke((string)($_POST['t'] ?? '')))
        ? 'Link dicabut.' : 'Link tidak ditemukan.';
    header('Location: /admin/#links');
    exit;
}

$logged = is_logged_in();
$files = [];
$totalSize = 0;
if ($logged && is_dir(STORAGE_DIR)) {
    foreach (scandir(STORAGE_DIR) as $f) {
        if ($f === '.' || $f === '..' || in_array(strtolower($f), RESERVED_NAMES, true)) continue; // file proteksi internal
        $p = STORAGE_DIR . '/' . $f;
        if (!is_file($p)) continue;
        $sz = filesize($p);
        if ($sz === 0 && filemtime($p) > time() - 5) continue; // slot yg sedang ditulis
        $files[] = ['name' => $f, 'size' => $sz, 'mtime' => filemtime($p)];
        $totalSize += $sz;
    }
    usort($files, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}
$disk = @disk_free_space(STORAGE_DIR);
$activeTokens = [];
if ($logged) {
    foreach (tokens_all() as $k => $v) $activeTokens[] = ['token' => $k] + $v;
    usort($activeTokens, fn($a, $b) => $b['created'] <=> $a['created']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin · File Uploader</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="bg-slate-100 min-h-screen">

<?php if (!$logged): ?>
<div class="min-h-screen flex items-center justify-center p-4">
    <form method="post" class="bg-white w-full max-w-sm p-8 rounded-2xl shadow-xl border border-slate-200 space-y-5">
        <div class="text-center">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 mb-3">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Login Admin</h1>
            <p class="text-slate-500 text-sm mt-1">Kelola file yang diupload</p>
        </div>
        <?php if ($error): ?>
            <div class="p-3 text-sm bg-red-50 text-red-700 border border-red-200 rounded-lg"><?= h($error) ?></div>
        <?php endif; ?>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div>
            <label class="block mb-1 text-sm font-medium text-slate-700">Username</label>
            <input name="username" required autofocus autocomplete="username" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <div>
            <label class="block mb-1 text-sm font-medium text-slate-700">Password</label>
            <input name="password" type="password" required autocomplete="current-password" class="w-full border border-slate-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <button class="w-full text-white bg-indigo-600 hover:bg-indigo-700 font-bold rounded-xl text-sm px-5 py-3 shadow-lg shadow-indigo-500/30 transition">Masuk</button>
        <p class="text-center text-xs"><a href="/" class="text-slate-500 hover:text-indigo-600">&larr; Kembali ke halaman upload</a></p>
    </form>
</div>

<?php else: ?>
<div class="max-w-5xl mx-auto p-4 md:p-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Daftar File</h1>
            <p class="text-sm text-slate-500">
                <?= count($files) ?> file &middot; total <?= h(format_bytes($totalSize)) ?>
                <?php if ($disk !== false): ?> &middot; sisa disk <?= h(format_bytes($disk)) ?><?php endif; ?>
            </p>
        </div>
        <div class="flex gap-2">
            <a href="/" class="px-4 py-2 text-sm font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">+ Upload</a>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <button class="px-4 py-2 text-sm font-semibold rounded-lg bg-white border border-slate-300 text-slate-700 hover:bg-slate-50">Logout (<?= h($_SESSION['admin']) ?>)</button>
            </form>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="mb-4 p-3 text-sm bg-green-50 text-green-700 border border-green-200 rounded-lg"><?= h($flash) ?></div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200">
            <input id="q" type="search" placeholder="Cari nama file..." class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <?php if (!$files): ?>
            <p class="p-10 text-center text-slate-400 text-sm">Belum ada file.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                <tr><th class="text-left px-4 py-3">Nama File</th><th class="text-right px-4 py-3">Ukuran</th><th class="text-left px-4 py-3">Diupload</th><th class="text-right px-4 py-3">Aksi</th></tr>
            </thead>
            <tbody id="rows">
            <?php foreach ($files as $f): $link = '/download.php?f=' . rawurlencode($f['name']); ?>
                <tr class="border-t border-slate-100 hover:bg-slate-50" data-name="<?= h(mb_strtolower($f['name'])) ?>">
                    <td class="px-4 py-3 font-medium text-slate-800 break-all"><?= h($f['name']) ?></td>
                    <td class="px-4 py-3 text-right font-mono text-xs text-slate-600 whitespace-nowrap"><?= h(format_bytes($f['size'])) ?></td>
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= date('d M Y H:i', $f['mtime']) ?></td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="<?= h($link) ?>" class="inline-block px-3 py-1.5 rounded-md bg-indigo-50 text-indigo-700 hover:bg-indigo-100 font-semibold text-xs">Download</a>
                        <button type="button" data-file="<?= h($f['name']) ?>" class="share px-3 py-1.5 rounded-md bg-slate-100 text-slate-700 hover:bg-slate-200 font-semibold text-xs">Salin Link</button>
                        <form method="post" class="inline" onsubmit="return confirm('Hapus file ini?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="f" value="<?= h($f['name']) ?>">
                            <button class="px-3 py-1.5 rounded-md bg-red-50 text-red-600 hover:bg-red-100 font-semibold text-xs">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div id="links" class="mt-8 bg-white rounded-2xl shadow border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-bold text-slate-800">Link Aktif (Token)</h2>
            <span class="text-xs text-slate-500"><?= count($activeTokens) ?> link</span>
        </div>
        <?php if (!$activeTokens): ?>
            <p class="p-8 text-center text-slate-400 text-sm">Belum ada link bertoken. Klik <b>Salin Link</b> pada file untuk membuat.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-slate-500 text-xs uppercase tracking-wide">
                <tr><th class="text-left px-4 py-3">File</th><th class="text-left px-4 py-3">Dibuat</th><th class="text-left px-4 py-3">Berlaku</th><th class="text-right px-4 py-3">Unduhan</th><th class="text-right px-4 py-3">Aksi</th></tr>
            </thead>
            <tbody>
            <?php foreach ($activeTokens as $t): $u = token_url($t['file'], $t['token']); ?>
                <tr class="border-t border-slate-100 hover:bg-slate-50">
                    <td class="px-4 py-3 font-medium text-slate-800 break-all"><?= h($t['file']) ?><div class="font-mono text-[11px] text-slate-400">…<?= h(substr($t['token'], -8)) ?></div></td>
                    <td class="px-4 py-3 text-slate-500 whitespace-nowrap"><?= date('d M Y H:i', $t['created']) ?></td>
                    <td class="px-4 py-3 whitespace-nowrap"><span class="px-2 py-0.5 rounded-full text-xs font-semibold <?= $t['expires'] ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' ?>"><?= h(human_ttl((int)$t['expires'])) ?></span><?php if ($t['expires']): ?><div class="text-[11px] text-slate-400 mt-0.5">s/d <?= date('d M Y H:i', $t['expires']) ?></div><?php endif; ?></td>
                    <td class="px-4 py-3 text-right font-mono text-xs"><?= (int)($t['hits'] ?? 0) ?>x</td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <button type="button" data-copy="<?= h($u) ?>" class="copyraw px-3 py-1.5 rounded-md bg-slate-100 text-slate-700 hover:bg-slate-200 font-semibold text-xs">Salin URL</button>
                        <?php $c = download_cmds($t['file'], $u); ?>
                        <button type="button" data-copy="<?= h($c['curl']) ?>" class="copyraw px-3 py-1.5 rounded-md bg-slate-800 text-white hover:bg-slate-700 font-semibold text-xs" title="Linux / macOS">Salin curl</button>
                        <button type="button" data-copy="<?= h($c['win']) ?>" class="copyraw px-3 py-1.5 rounded-md bg-sky-700 text-white hover:bg-sky-600 font-semibold text-xs" title="Windows PowerShell">curl Win</button>
                        <form method="post" class="inline" onsubmit="return confirm('Cabut link ini? Link langsung tidak bisa dipakai.')">
                            <input type="hidden" name="action" value="revoke">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="t" value="<?= h($t['token']) ?>">
                            <button class="px-3 py-1.5 rounded-md bg-red-50 text-red-600 hover:bg-red-100 font-semibold text-xs">Cabut</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal buat link bertoken -->
<div id="shareModal" class="hidden fixed inset-0 z-50 bg-slate-900/50 flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-lg rounded-2xl shadow-2xl border border-slate-200 p-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-800">Buat Link Download</h3>
                <p id="smFile" class="text-sm text-slate-500 break-all"></p>
            </div>
            <button type="button" id="smClose" aria-label="Tutup" class="w-8 h-8 -mr-2 -mt-1 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-700 hover:bg-slate-100 text-2xl leading-none">&times;</button>
        </div>

        <div id="smStep1">
            <label class="block mb-2 text-sm font-medium text-slate-700">Masa aktif link</label>
            <div class="grid grid-cols-3 gap-2" id="ttlBtns">
                <button type="button" data-ttl="3600" class="ttl">1 jam</button>
                <button type="button" data-ttl="21600" class="ttl">6 jam</button>
                <button type="button" data-ttl="86400" class="ttl">1 hari</button>
                <button type="button" data-ttl="604800" class="ttl">7 hari</button>
                <button type="button" data-ttl="2592000" class="ttl">30 hari</button>
                <button type="button" data-ttl="0" class="ttl">Permanen</button>
            </div>
            <div class="mt-3 flex items-center gap-2">
                <span class="text-sm text-slate-600">Custom:</span>
                <input id="ttlNum" type="number" min="1" placeholder="mis. 12" class="flex-1 min-w-0 h-9 border border-slate-300 rounded-lg px-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <select id="ttlUnit" class="w-28 h-9 bg-white border border-slate-300 rounded-lg px-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="60">menit</option><option value="3600" selected>jam</option><option value="86400">hari</option>
                </select>
            </div>
            <p id="smErr" class="hidden mt-3 p-2 text-sm bg-red-50 text-red-700 border border-red-200 rounded-lg"></p>
            <button type="button" id="smCreate" class="mt-5 w-full text-white bg-indigo-600 hover:bg-indigo-700 font-bold rounded-xl text-sm px-5 py-3 shadow-lg shadow-indigo-500/30 transition disabled:opacity-50">Buat &amp; Salin Link</button>
        </div>

        <div id="smStep2" class="hidden space-y-4">
            <div class="p-3 text-sm bg-green-50 text-green-700 border border-green-200 rounded-lg"><span id="smCopied">Perintah curl</span> sudah tersalin ✓ &middot; berlaku: <b id="smExp"></b></div>
            <div>
                <div class="flex justify-between items-center mb-1"><span class="text-xs font-bold text-slate-500 uppercase">URL</span><button type="button" class="copyraw text-xs font-semibold text-indigo-600 hover:underline" id="cpUrl">Salin</button></div>
                <input id="smUrl" readonly class="w-full font-mono text-xs bg-slate-50 border border-slate-200 rounded-lg px-3 py-2" onclick="this.select()">
            </div>
            <div>
                <div class="flex justify-between items-center mb-1"><span class="text-xs font-bold text-slate-500 uppercase">curl &middot; Linux/macOS</span><button type="button" class="copyraw text-xs font-semibold text-indigo-600 hover:underline" id="cpCurl">Salin</button></div>
                <textarea id="smCurl" readonly rows="3" class="w-full font-mono text-xs bg-slate-900 text-green-300 rounded-lg px-3 py-2 resize-none" onclick="this.select()"></textarea>
            </div>
            <div>
                <div class="flex justify-between items-center mb-1"><span class="text-xs font-bold text-slate-500 uppercase">wget</span><button type="button" class="copyraw text-xs font-semibold text-indigo-600 hover:underline" id="cpWget">Salin</button></div>
                <textarea id="smWget" readonly rows="2" class="w-full font-mono text-xs bg-slate-900 text-green-300 rounded-lg px-3 py-2 resize-none" onclick="this.select()"></textarea>
            </div>
            <div>
                <div class="flex justify-between items-center mb-1"><span class="text-xs font-bold text-slate-500 uppercase">curl &middot; Windows PowerShell</span><button type="button" class="copyraw text-xs font-semibold text-indigo-600 hover:underline" id="cpWin">Salin</button></div>
                <textarea id="smWin" readonly rows="3" class="w-full font-mono text-xs bg-slate-900 text-sky-300 rounded-lg px-3 py-2 resize-none" onclick="this.select()"></textarea>
            </div>
            <button type="button" id="smDone" class="w-full text-indigo-600 bg-white border border-indigo-200 hover:bg-indigo-50 font-semibold rounded-lg text-sm px-5 py-2.5">Selesai</button>
        </div>
    </div>
</div>
<style>
.ttl{padding:.6rem;border:1px solid #cbd5e1;border-radius:.6rem;font-size:.85rem;font-weight:600;color:#334155;background:#fff;transition:.15s}
.ttl:hover{background:#f1f5f9}
.ttl.active{background:#eef2ff;border-color:#6366f1;color:#4338ca;box-shadow:0 0 0 1px #6366f1 inset}
#ttlNum::placeholder{color:#94a3b8}
#ttlNum::-webkit-outer-spin-button,#ttlNum::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
#ttlNum{-moz-appearance:textfield}
</style>
<script>
document.getElementById('q')?.addEventListener('input', e => {
    const q = e.target.value.toLowerCase();
    document.querySelectorAll('#rows tr').forEach(tr => tr.style.display = tr.dataset.name.includes(q) ? '' : 'none');
});
const CSRF = <?= json_encode(csrf_token()) ?>;
function copyText(text, btn) {
    const done = () => { if (!btn) return; const t = btn.innerText; btn.innerText = 'Tersalin ✓'; setTimeout(() => btn.innerText = t, 1200); };
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(done, () => fallback());
    fallback();
    function fallback() { const ta = document.createElement('textarea'); ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
}
document.querySelectorAll('.copyraw[data-copy]').forEach(b => b.addEventListener('click', () => copyText(b.dataset.copy, b)));

const modal = document.getElementById('shareModal'), s1 = document.getElementById('smStep1'), s2 = document.getElementById('smStep2');
const errBox = document.getElementById('smErr'), createBtn = document.getElementById('smCreate');
const ttlNum = document.getElementById('ttlNum'), ttlUnit = document.getElementById('ttlUnit');
let curFile = '', curTtl = 86400, created = false;

function selectTtl(v) { curTtl = v; document.querySelectorAll('.ttl').forEach(b => b.classList.toggle('active', +b.dataset.ttl === v)); }
document.querySelectorAll('.ttl').forEach(b => b.addEventListener('click', () => { ttlNum.value = ''; selectTtl(+b.dataset.ttl); }));
ttlNum.addEventListener('input', () => { document.querySelectorAll('.ttl').forEach(b => b.classList.remove('active')); });

function openModal(file) {
    curFile = file; created = false;
    document.getElementById('smFile').innerText = file;
    s1.classList.remove('hidden'); s2.classList.add('hidden'); errBox.classList.add('hidden');
    ttlNum.value = ''; selectTtl(86400); createBtn.disabled = false;
    modal.classList.remove('hidden');
}
function closeModal() { modal.classList.add('hidden'); if (created) location.reload(); }
document.querySelectorAll('.share').forEach(b => b.addEventListener('click', () => openModal(b.dataset.file)));
document.getElementById('smClose').onclick = closeModal;
document.getElementById('smDone').onclick = closeModal;
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeModal(); });

createBtn.addEventListener('click', async () => {
    let ttl = curTtl;
    if (ttlNum.value !== '') {
        const n = parseInt(ttlNum.value, 10);
        if (!(n > 0)) { errBox.innerText = 'Isi angka masa aktif yang valid.'; errBox.classList.remove('hidden'); return; }
        ttl = n * parseInt(ttlUnit.value, 10);
    }
    errBox.classList.add('hidden'); createBtn.disabled = true; createBtn.innerText = 'Membuat...';
    try {
        const body = new URLSearchParams({ action: 'token', csrf: CSRF, f: curFile, ttl: String(ttl) });
        const r = await fetch('/admin/', { method: 'POST', body, credentials: 'same-origin' });
        const j = await r.json();
        if (!j.ok) throw new Error(j.error || 'Gagal membuat link.');
        created = true;
        document.getElementById('smUrl').value = j.url;
        document.getElementById('smCurl').value = j.curl;
        document.getElementById('smWget').value = j.wget;
        document.getElementById('smWin').value = j.win;
        document.getElementById('smExp').innerText = j.expires_text;
        document.getElementById('smCopied').innerText = /Windows/i.test(navigator.userAgent) ? 'curl Windows' : 'curl Linux';
        document.getElementById('cpUrl').onclick = e => copyText(j.url, e.target);
        document.getElementById('cpCurl').onclick = e => copyText(j.curl, e.target);
        document.getElementById('cpWget').onclick = e => copyText(j.wget, e.target);
        document.getElementById('cpWin').onclick = e => copyText(j.win, e.target);
        copyText(/Windows/i.test(navigator.userAgent) ? j.win : j.curl);
        s1.classList.add('hidden'); s2.classList.remove('hidden');
    } catch (e) {
        errBox.innerText = e.message; errBox.classList.remove('hidden');
    } finally {
        createBtn.disabled = false; createBtn.innerText = 'Buat & Salin Link';
    }
});
</script>
<?php endif; ?>
</body>
</html>
