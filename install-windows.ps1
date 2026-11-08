# 10gb-file_upload - setup Windows (XAMPP)
# Pakai (PowerShell "Run as Administrator", dari folder repo):
#   powershell -ExecutionPolicy Bypass -File .\install-windows.ps1                 # port 4000, XAMPP di C:\xampp
#   powershell -ExecutionPolicy Bypass -File .\install-windows.ps1 -Port 4001 -Xampp D:\xampp
#   powershell -ExecutionPolicy Bypass -File .\install-windows.ps1 -Uninstall
param(
    [int]$Port = 4000,
    [string]$Xampp = 'C:\xampp',
    [switch]$Uninstall
)
$ErrorActionPreference = 'Stop'
$Utf8 = New-Object System.Text.UTF8Encoding($false)   # UTF-8 tanpa BOM (aman untuk Apache)
function Write-Conf($path, $text) { [IO.File]::WriteAllText($path, $text, $Utf8) }

$App   = $PSScriptRoot
$Vhost = Join-Path $Xampp 'apache\conf\extra\httpd-vhosts.conf'
$Httpd = Join-Path $Xampp 'apache\bin\httpd.exe'
$Begin = '# BEGIN 10gb-file_upload'
$End   = '# END 10gb-file_upload'

if (-not (Test-Path $Httpd)) { throw "XAMPP tidak ditemukan di $Xampp. Install dulu: winget install -e --id ApacheFriends.Xampp.8.2" }

# Hapus blok lama (idempotent)
$old = if (Test-Path $Vhost) { Get-Content $Vhost -Raw } else { '' }
$clean = [regex]::Replace($old, "(?s)\r?\n?$([regex]::Escape($Begin)).*?$([regex]::Escape($End))\r?\n?", "`r`n")
Get-NetFirewallRule -DisplayName '10gb-file_upload' -ErrorAction SilentlyContinue | Remove-NetFirewallRule

if ($Uninstall) {
    Write-Conf $Vhost ($clean.TrimEnd() + "`r`n")
    Write-Host 'Konfigurasi dihapus. Restart Apache dari XAMPP Control Panel.'
    exit 0
}

# Folder kerja
$A = $App -replace '\\', '/'
New-Item -ItemType Directory -Force -Path "$App\tmp", "$App\public\files_upload" | Out-Null
if (-not (Test-Path "$App\data\tokens.json")) { Write-Conf "$App\data\tokens.json" '{}' }

$block = @"
$Begin
Listen $Port
<VirtualHost *:$Port>
    DocumentRoot "$A/public"
    LimitRequestBody 0
    Timeout 3600
    <Directory "$A/public">
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        php_value upload_max_filesize 10G
        php_value post_max_size 0
        php_value max_file_uploads 200
        php_value max_execution_time 0
        php_value max_input_time -1
        php_value memory_limit 256M
        php_value upload_tmp_dir "$A/tmp"
    </Directory>
    <Directory "$A/public/files_upload">
        Require all denied
        php_admin_flag engine off
    </Directory>
</VirtualHost>
$End
"@

Write-Conf $Vhost ($clean.TrimEnd() + "`r`n`r`n" + $block + "`r`n")

# Pastikan httpd-vhosts.conf di-include
$conf = Join-Path $Xampp 'apache\conf\httpd.conf'
$c = Get-Content $conf -Raw
if ($c -match '(?m)^\s*#\s*Include conf/extra/httpd-vhosts.conf') {
    Write-Conf $conf ($c -replace '(?m)^\s*#\s*(Include conf/extra/httpd-vhosts.conf)', '$1')
}

# Cek konfigurasi
& $Httpd -t
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi Apache error (lihat pesan di atas).' }

# Buka port di Windows Firewall (agar bisa diakses dari HP/PC lain di LAN)
New-NetFirewallRule -DisplayName '10gb-file_upload' -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow | Out-Null

# Restart Apache (service atau proses dari XAMPP Control Panel)
$svc = Get-Service -Name 'Apache2.4' -ErrorAction SilentlyContinue
if ($svc) { Restart-Service $svc } else {
    Get-Process httpd -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Process -FilePath $Httpd -WindowStyle Hidden
}

$ip = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1).IPAddress
Write-Host ''
Write-Host 'Selesai.'
Write-Host "  Upload : http://${ip}:$Port/"
Write-Host "  Admin  : http://${ip}:$Port/admin/   (login: $(Join-Path $App 'data\admin.json'))"
Write-Host "  File   : $(Join-Path $App 'public\files_upload')"
