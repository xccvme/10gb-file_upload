<?php
// Halaman upload. File disimpan ke files_upload/ dengan nama & format asli.
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    $up = $_FILES['filesToUpload'] ?? null;
    if (!$up || !is_array($up['name'])) {
        // POST lebih besar dari post_max_size -> $_FILES kosong
        exit(json_encode(['status' => 'error', 'message' => 'Tidak ada file diterima. Cek upload_max_filesize & post_max_size di php.ini.', 'files' => []]));
    }
    $files = []; $errors = [];
    foreach ($up['name'] as $i => $orig) {
        if ($up['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$orig (error {$up['error'][$i]}" . ($up['error'][$i] === UPLOAD_ERR_INI_SIZE ? ': melebihi upload_max_filesize php.ini' : '') . ')';
            continue;
        }
        $name = clean_filename($orig);
        $dest = $name !== '' ? unique_path($name) : null;
        if ($dest && move_uploaded_file($up['tmp_name'][$i], $dest)) {
            $files[] = basename($dest);
        } else {
            if ($dest) @unlink($dest);
            $errors[] = "$orig (gagal disimpan)";
        }
    }
    $total = count($up['name']);
    exit(json_encode([
        'status'  => $files ? 'success' : 'error',
        'message' => $files ? 'Berhasil mengupload ' . count($files) . " dari $total file." : 'Gagal mengupload file.',
        'files'   => $files,
        'errors'  => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Uploader 10GB</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        /* Animasi smooth untuk progress bar */
        #progressBarFill { transition: width 0.2s ease; }
    </style>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">

    <div class="bg-white w-full max-w-lg p-6 md:p-8 rounded-2xl shadow-xl border border-slate-200">
        
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-full bg-indigo-100 text-indigo-600 mb-3 shadow-sm">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-7 h-7">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">File Uploader</h1>
            <p class="text-slate-500 text-sm mt-1"><a href="admin.php" class="text-indigo-600 hover:underline">Admin</a></p>
            <div class="mt-2 inline-block px-3 py-1 bg-yellow-50 text-yellow-700 text-xs font-semibold rounded-full border border-yellow-200">
                Max Upload: <?= h(ini_get('upload_max_filesize')) ?> / File
            </div>
        </div>

        <?php foreach (setup_warnings() as $w): ?>
            <div class="mb-3 p-2.5 text-xs bg-amber-50 text-amber-800 border border-amber-200 rounded-lg">⚠ <?= h($w) ?></div>
        <?php endforeach; ?>

        <div id="uploadFormArea">
            <form id="uploadForm" class="space-y-5">
                
                <div class="w-full relative group">
                    <label class="block mb-2 text-sm font-medium text-slate-700" for="file_input">Pilih File (Bisa Banyak)</label>
                    <div id="dropZone" class="relative border-2 border-dashed border-slate-300 rounded-lg p-6 hover:bg-slate-50 transition-colors text-center cursor-pointer" onclick="document.getElementById('file_input').click()">
                        <p class="text-sm text-slate-500 pointer-events-none">Klik untuk pilih file atau Drag & Drop</p>
                        <p class="text-xs text-slate-400 mt-1 pointer-events-none">Semua jenis file diizinkan</p>
                    </div>
                    <input 
                        class="hidden" 
                        id="file_input" 
                        type="file" 
                        name="filesToUpload[]" 
                        multiple 
                        onchange="updateFileCount()"
                    >
                </div>

                <div id="fileInfo" class="hidden text-sm text-slate-600 bg-slate-100 p-3 rounded-md flex justify-between items-center">
                    <span id="fileCountText">0 file dipilih</span>
                    <span id="totalSizeText" class="font-mono text-xs bg-slate-200 px-2 py-1 rounded">0 MB</span>
                </div>

                <button type="submit" class="w-full text-white bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-300 font-bold rounded-xl text-sm px-5 py-3.5 text-center shadow-lg shadow-indigo-500/30 transition duration-200 ease-in-out transform hover:-translate-y-0.5">
                    Mulai Upload 🚀
                </button>
            </form>
        </div>

        <div id="progressArea" class="hidden space-y-4">
            <h3 class="text-sm font-semibold text-slate-700">Mengupload...</h3>
            
            <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden">
                <div id="progressBarFill" class="bg-indigo-600 h-4 rounded-full" style="width: 0%"></div>
            </div>

            <div class="flex justify-between text-xs font-mono text-slate-500 mt-2">
                <span id="progressPercent">0%</span>
                <span id="uploadSpeed">0 MB/s</span>
                <span id="uploadSize">0 / 0 MB</span>
            </div>

            <button id="cancelBtn" type="button" class="w-full text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 focus:ring-2 focus:ring-red-200 font-medium rounded-lg text-sm px-5 py-2.5 text-center mt-2 transition">
                Batalkan Upload ✕
            </button>
        </div>

        <div id="resultArea" class="hidden text-center space-y-6">
            <div id="resultBox" class="p-4 bg-green-50 text-green-700 rounded-xl border border-green-200">
                <p id="resultTitle" class="font-bold text-lg">Upload Selesai! 🎉</p>
                <p id="resultMessage" class="text-sm mt-1"></p>
            </div>

            <div class="text-left bg-slate-50 p-4 rounded-lg border border-slate-200 max-h-40 overflow-y-auto">
                <p class="text-xs font-bold text-slate-500 mb-2 uppercase tracking-wide">File Tersimpan:</p>
                <ul id="fileList" class="text-sm text-slate-700 space-y-1 list-disc list-inside">
                    </ul>
            </div>

            <button onclick="resetForm()" class="w-full text-indigo-600 bg-white border border-indigo-200 hover:bg-indigo-50 font-semibold rounded-lg text-sm px-5 py-3 text-center transition">
                Upload File Lain
            </button>
        </div>

        <div class="mt-8 text-center text-xs text-slate-400">
            &copy; <?php echo date("Y"); ?> Server Lokal - High Speed Transfer
        </div>
    </div>

    <script>
        const form = document.getElementById('uploadForm');
        const fileInput = document.getElementById('file_input');
        const uploadFormArea = document.getElementById('uploadFormArea');
        const progressArea = document.getElementById('progressArea');
        const resultArea = document.getElementById('resultArea');
        
        // Element Progress
        const progressBarFill = document.getElementById('progressBarFill');
        const progressPercent = document.getElementById('progressPercent');
        const uploadSpeed = document.getElementById('uploadSpeed');
        const uploadSize = document.getElementById('uploadSize');
        const cancelBtn = document.getElementById('cancelBtn');

        let xhr = new XMLHttpRequest();
        let startTime = 0;
        let lastLoaded = 0;

        // Update Info saat file dipilih
        function updateFileCount() {
            const files = fileInput.files;
            const infoDiv = document.getElementById('fileInfo');
            
            if (files.length > 0) {
                infoDiv.classList.remove('hidden');
                document.getElementById('fileCountText').innerText = files.length + " file dipilih";
                
                let totalBytes = 0;
                for(let file of files) totalBytes += file.size;
                
                // Konversi ke MB/GB
                let sizeStr = "";
                if(totalBytes > 1024 * 1024 * 1024) {
                    sizeStr = (totalBytes / (1024 * 1024 * 1024)).toFixed(2) + " GB";
                } else {
                    sizeStr = (totalBytes / (1024 * 1024)).toFixed(2) + " MB";
                }
                document.getElementById('totalSizeText').innerText = sizeStr;
            } else {
                infoDiv.classList.add('hidden');
            }
        }

        // Drag & Drop
        const dropZone = document.getElementById('dropZone');
        ['dragenter', 'dragover'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('bg-indigo-50', 'border-indigo-400'); }));
        ['dragleave', 'drop'].forEach(ev => dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('bg-indigo-50', 'border-indigo-400'); }));
        dropZone.addEventListener('drop', e => { fileInput.files = e.dataTransfer.files; updateFileCount(); });

        // Handle Submit Form
        form.addEventListener('submit', (e) => {
            e.preventDefault();

            const files = fileInput.files;
            if (files.length === 0) return alert("Pilih file dulu bos!");

            // Persiapan UI
            uploadFormArea.classList.add('hidden');
            progressArea.classList.remove('hidden');
            
            // Persiapan Data
            const formData = new FormData();
            for (let i = 0; i < files.length; i++) {
                formData.append('filesToUpload[]', files[i]);
            }

            // Reset XHR baru
            xhr = new XMLHttpRequest();
            startTime = new Date().getTime();
            lastLoaded = 0;

            // --- EVENT: PROGRESS UPLOAD ---
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    // 1. Hitung Persentase
                    const percent = (e.loaded / e.total) * 100;
                    progressBarFill.style.width = percent + '%';
                    progressPercent.innerText = Math.round(percent) + '%';

                    // 2. Hitung Kecepatan (MBps)
                    const currentTime = new Date().getTime();
                    const timeDiff = (currentTime - startTime) / 1000; // dalam detik
                    
                    // Kita hitung rata-rata kecepatan dari awal
                    const bps = e.loaded / timeDiff; 
                    const mbps = (bps / (1024 * 1024)).toFixed(2);
                    uploadSpeed.innerText = mbps + " MB/s";

                    // 3. Info Size (Uploaded / Total)
                    const loadedMB = (e.loaded / (1024 * 1024)).toFixed(1);
                    const totalMB = (e.total / (1024 * 1024)).toFixed(1);
                    uploadSize.innerText = `${loadedMB} MB / ${totalMB} MB`;
                }
            });

            // --- EVENT: SELESAI ---
            xhr.onload = () => {
                if (xhr.status === 200) {
                    try {
                        const txt = xhr.responseText, res = JSON.parse(txt.slice(Math.max(0, txt.indexOf('{"status"')))); // abaikan warning PHP di depan JSON
                        showResult(res);
                    } catch (err) {
                        alert("Upload selesai tapi respon server aneh. Cek folder.");
                        console.log(xhr.responseText);
                        resetForm();
                    }
                } else {
    // Tampilkan pesan error lengkap dari server
    alert("Gagal upload! \nStatus: " + xhr.status + " " + xhr.statusText + "\nRespon Server: " + xhr.responseText);
    console.error(xhr.responseText); // Cek console jika di desktop
    resetForm();
}
            };

            // --- EVENT: ERROR ---
            xhr.onerror = () => {
                alert("Koneksi terputus atau error jaringan.");
                resetForm();
            };

            // --- EKSEKUSI ---
            xhr.open('POST', '', true); // Post ke file ini sendiri
            xhr.send(formData);
        });

        // Tombol Cancel
        cancelBtn.addEventListener('click', () => {
            if(confirm("Yakin ingin membatalkan upload?")) {
                xhr.abort();
                alert("Upload dibatalkan oleh pengguna.");
                resetForm();
            }
        });

        // Tampilkan Hasil Akhir
        function showResult(response) {
            progressArea.classList.add('hidden');
            resultArea.classList.remove('hidden');

            const resultMessage = document.getElementById('resultMessage');
            const fileListUl = document.getElementById('fileList');

            const okAll = response.status === 'success';
            document.getElementById('resultTitle').innerText = okAll ? 'Upload Selesai! 🎉' : 'Upload Gagal ✕';
            document.getElementById('resultBox').className = okAll
                ? 'p-4 bg-green-50 text-green-700 rounded-xl border border-green-200'
                : 'p-4 bg-red-50 text-red-700 rounded-xl border border-red-200';
            resultMessage.innerText = response.message;
            resultMessage.className = okAll ? "text-sm mt-1 text-green-600" : "text-sm mt-1 text-red-600";

            // Bersihkan list lama dan isi baru
            fileListUl.innerHTML = '';
            response.files.forEach(filename => {
                const li = document.createElement('li');
                li.innerText = filename;
                fileListUl.appendChild(li);
            });
            (response.errors || []).forEach(err => {
                const li = document.createElement('li');
                li.innerText = '✕ ' + err;
                li.className = 'text-red-600';
                fileListUl.appendChild(li);
            });
        }

        // Reset Kembali ke Awal
        function resetForm() {
            form.reset();
            document.getElementById('fileInfo').classList.add('hidden');
            progressBarFill.style.width = '0%';
            
            uploadFormArea.classList.remove('hidden');
            progressArea.classList.add('hidden');
            resultArea.classList.add('hidden');
        }
    </script>
</body>
</html>
