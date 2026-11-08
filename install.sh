#!/usr/bin/env bash
# 10gb-file_upload — installer Linux (Debian/Ubuntu)
# Pakai : sudo ./install.sh [PORT]        (default PORT=4000)
# Hapus : sudo ./install.sh --uninstall
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE=10gb-file_upload
CONF=/etc/apache2/sites-available/$SITE.conf

[ "$(id -u)" -eq 0 ] || { echo "Jalankan dengan sudo/root."; exit 1; }

if [ "${1:-}" = "--uninstall" ]; then
  PORT=$(grep -oP '(?<=<VirtualHost \*:)\d+' "$CONF" 2>/dev/null || true)
  a2dissite -q $SITE 2>/dev/null || true
  rm -f "$CONF"
  [ -n "$PORT" ] && sed -i "/^Listen $PORT\$/d" /etc/apache2/ports.conf
  systemctl reload apache2 2>/dev/null || true
  echo "Site $SITE dihapus (file di $APP_DIR tidak disentuh)."
  exit 0
fi

PORT="${1:-4000}"
[[ "$PORT" =~ ^[0-9]+$ ]] || { echo "PORT harus angka."; exit 1; }

echo "==> Install Apache + PHP + mod_xsendfile"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq apache2 libapache2-mod-php php-cli php-mbstring libapache2-mod-xsendfile acl >/dev/null

if ss -ltn "sport = :$PORT" | grep -q LISTEN && ! grep -q "^Listen $PORT\$" /etc/apache2/ports.conf; then
  echo "Port $PORT sudah dipakai program lain. Pilih port lain: sudo ./install.sh 4001"; exit 1
fi

echo "==> Folder & izin"
mkdir -p "$APP_DIR/tmp" "$APP_DIR/public/files_upload"
[ -f "$APP_DIR/data/tokens.json" ] || echo '{}' > "$APP_DIR/data/tokens.json"
chown -R www-data:www-data "$APP_DIR/data" "$APP_DIR/tmp" "$APP_DIR/public/files_upload"
chmod 750 "$APP_DIR/data" "$APP_DIR/tmp"
chmod 640 "$APP_DIR"/data/*.json

# Kalau app ada di /root atau /home/user (izin ketat), beri www-data izin "lewat" (x) saja
d="$APP_DIR"
while [ "$d" != "/" ]; do
  d="$(dirname "$d")"
  runuser -u www-data -- test -x "$d" 2>/dev/null || setfacl -m u:www-data:x "$d"
done
runuser -u www-data -- test -r "$APP_DIR/public/index.php" || { echo "www-data tidak bisa membaca $APP_DIR"; exit 1; }

echo "==> Virtual host port $PORT"
cat > "$CONF" <<EOF
<VirtualHost *:$PORT>
    DocumentRoot $APP_DIR/public
    LimitRequestBody 0
    Timeout 3600
    SetEnv XSENDFILE_ON 1
    XSendFile On
    XSendFilePath $APP_DIR/public/files_upload

    <Directory $APP_DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        php_value upload_max_filesize 10G
        php_value post_max_size 0
        php_value max_file_uploads 200
        php_value max_execution_time 0
        php_value max_input_time -1
        php_value memory_limit 256M
        php_value upload_tmp_dir $APP_DIR/tmp
    </Directory>

    # Folder upload: tidak bisa diakses langsung, PHP tidak dieksekusi
    <Directory $APP_DIR/public/files_upload>
        Require all denied
        php_admin_flag engine off
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/${SITE}_error.log
    CustomLog \${APACHE_LOG_DIR}/${SITE}_access.log combined
</VirtualHost>
EOF
grep -q "^Listen $PORT\$" /etc/apache2/ports.conf || echo "Listen $PORT" >> /etc/apache2/ports.conf

PHPV=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
a2enmod -q "php$PHPV" xsendfile >/dev/null
a2ensite -q $SITE
apache2ctl configtest
systemctl enable -q apache2
systemctl restart apache2

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo
echo "Selesai."
echo "  Upload : http://${IP:-127.0.0.1}:$PORT/"
echo "  Admin  : http://${IP:-127.0.0.1}:$PORT/admin/   (login: $APP_DIR/data/admin.json)"
echo "  File   : $APP_DIR/public/files_upload/"
