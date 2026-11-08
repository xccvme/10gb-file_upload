# 10gb-file_upload

Upload file sampai **10 GB per file**, bisa banyak sekaligus. File disimpan dengan **nama & format asli**.
Admin panel: daftar file, download, hapus, dan **link bertoken** (masa aktif bisa diatur) yang bisa langsung di-`curl`.

**Login default:** `admin` / `x6dnMKpewVWNXDym` &nbsp;→ ganti di `data/admin.json`

---

## Linux (Ubuntu / Debian)

```bash
# 1. Ambil source
git clone https://github.com/xccvme/10gb-file_upload.git
cd 10gb-file_upload

# 2. Install (Apache + PHP + mod_xsendfile, vhost, izin folder) — default port 4000
sudo ./install.sh

# 2b. Atau pakai port lain
sudo ./install.sh 8080

# 3. Buka di browser
#    Upload : http://IP-SERVER:4000/
#    Admin  : http://IP-SERVER:4000/admin/
```

```bash
# Cek status / restart / log
sudo systemctl status apache2
sudo systemctl restart apache2
sudo tail -f /var/log/apache2/10gb-file_upload_error.log

# Buka port di firewall (kalau ufw aktif)
sudo ufw allow 4000/tcp

# Lihat file yang sudah diupload
ls -lh public/files_upload/

# Hapus konfigurasi Apache (file upload TIDAK ikut terhapus)
sudo ./install.sh --uninstall
```

---

## Windows 10 / 11

### Opsi A — XAMPP (disarankan, untuk dipakai terus)

```powershell
# 1. Install XAMPP + Git (sekali saja)
winget install -e --id ApacheFriends.Xampp.8.2
winget install -e --id Git.Git

# 2. Ambil source (buka PowerShell baru setelah install Git)
cd C:\
git clone https://github.com/xccvme/10gb-file_upload.git
cd C:\10gb-file_upload

# 3. Setup vhost port 4000 + buka firewall + restart Apache
#    (PowerShell -> klik kanan -> "Run as Administrator")
powershell -ExecutionPolicy Bypass -File .\install-windows.ps1

# 3b. Port lain / XAMPP bukan di C:\xampp
powershell -ExecutionPolicy Bypass -File .\install-windows.ps1 -Port 8080 -Xampp D:\xampp

# 4. Buka di browser
#    http://localhost:4000/        http://localhost:4000/admin/
```

```powershell
# Lihat IP PC (untuk diakses dari HP / PC lain di jaringan yang sama)
ipconfig | findstr IPv4

# Hapus konfigurasi (file upload tidak ikut terhapus)
powershell -ExecutionPolicy Bypass -File .\install-windows.ps1 -Uninstall
```

> Apache bisa juga di-start/stop dari **XAMPP Control Panel**. Jalankan sebagai service (tombol ✕ → ✓ di kolom *Service*) supaya otomatis jalan saat Windows boot.

### Opsi B — Cepat tanpa XAMPP (PHP built-in server)

```powershell
# Install PHP (sekali saja), lalu buka PowerShell baru
winget install -e --id PHP.PHP.8.3

# Jalankan dari folder repo (Ctrl+C untuk berhenti)
cd C:\10gb-file_upload
mkdir tmp -Force
php -d upload_max_filesize=10G -d post_max_size=0 -d max_file_uploads=200 -d upload_tmp_dir="$PWD\tmp" -S 0.0.0.0:4000 -t public router.php
```

> `php -S` hanya untuk pemakaian ringan/sementara (satu proses). Untuk banyak user / file besar pakai XAMPP atau Linux.
> Perintah yang sama juga jalan di Linux: `php -d upload_max_filesize=10G -d post_max_size=0 -S 0.0.0.0:4000 -t public router.php`

---

## Login admin

```bash
# Ganti username/password (langsung berlaku, tanpa restart)
nano data/admin.json                 # Linux
notepad data\admin.json              # Windows
```

```json
{
    "username": "admin",
    "password": "x6dnMKpewVWNXDym"
}
```

```bash
# (opsional) simpan password sebagai hash bcrypt, tempel hasilnya ke "password"
php -r 'echo password_hash("PasswordBaru", PASSWORD_BCRYPT), PHP_EOL;'
```

> Salah login 5x dari IP yang sama → dikunci 5 menit. Buka kunci: hapus `data/login_attempts.json`.

---

## Upload lewat CLI

```bash
# Upload 1 file
curl -F "filesToUpload[]=@video.mp4" http://IP-SERVER:4000/

# Upload banyak file sekaligus
curl -F "filesToUpload[]=@a.zip" -F "filesToUpload[]=@b.iso" -F "filesToUpload[]=@foto saya.jpg" http://IP-SERVER:4000/

# Upload file besar + tampilkan progress bar
curl --progress-bar -F "filesToUpload[]=@ubuntu.iso" http://IP-SERVER:4000/ | cat
```

```powershell
# Windows: pakai curl.exe (bukan alias "curl" milik PowerShell)
curl.exe -F "filesToUpload[]=@C:\Users\boss\Videos\video.mp4" http://IP-SERVER:4000/
```

Respon: `{"status":"success","message":"Berhasil mengupload 1 dari 1 file.","files":["video.mp4"]}`
Nama sudah ada → otomatis jadi `video (1).mp4` (file lama tidak tertimpa).

---

## Download lewat CLI (link bertoken)

Admin → **Salin Link** pada file → pilih masa aktif (1 jam … 30 hari / Permanen / custom) → **Buat & Salin Link**.
Perintah langsung tersalin. Bisa dipakai tanpa login sampai masa aktif habis atau dicabut (**Cabut** di tabel *Link Aktif*).

```bash
# Linux / macOS — -C - = lanjutkan download yang terputus
curl -fL -C - -o 'video.mp4' 'http://IP-SERVER:4000/download.php?f=video.mp4&t=TOKEN'

# atau wget
wget -c -O 'video.mp4' 'http://IP-SERVER:4000/download.php?f=video.mp4&t=TOKEN'
```

```powershell
# Windows PowerShell (tombol "curl Win" di admin)
curl.exe -fL -C - -o 'video.mp4' 'http://IP-SERVER:4000/download.php?f=video.mp4&t=TOKEN'
```

> Link kedaluwarsa/dicabut → `403 Link tidak valid atau sudah kedaluwarsa.`

---

## Struktur

```
public/                 # web root
  index.php             # halaman upload
  admin/index.php       # login + daftar file + link token
  download.php          # download (login / token), dukung resume
  files_upload/         # <- FILE HASIL UPLOAD DI SINI (akses langsung diblokir)
lib/common.php          # helper
data/admin.json         # login admin
data/tokens.json        # link token aktif (otomatis)
install.sh              # installer Linux
install-windows.ps1     # setup XAMPP Windows
router.php              # router untuk mode php -S
```

## Masalah umum

```bash
# Upload gagal di tengah jalan -> cek sisa disk & log
df -h .
sudo tail -n 50 /var/log/apache2/10gb-file_upload_error.log

# 403 di semua halaman (app di /root atau /home) -> jalankan ulang installer (memperbaiki izin)
sudo ./install.sh

# Port sudah dipakai -> pakai port lain
sudo ./install.sh 4001
```

```powershell
# Windows: Apache tidak mau start -> cek error konfigurasi
C:\xampp\apache\bin\httpd.exe -t

# Lihat program yang memakai port 4000
netstat -ano | findstr :4000
```
