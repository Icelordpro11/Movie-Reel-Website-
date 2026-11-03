#!/usr/bin/env bash
#
# One-shot installer for a Debian/Ubuntu LXC. Run as root inside the project folder:
#   bash install.sh
# Optional: export TMDB_API_KEY=xxxx beforehand to enable the live movie catalogue.
#
set -e

DB_NAME="${DB_NAME:-reelmovies}"
DB_USER="${DB_USER:-reel}"
DB_PASS="${DB_PASS:-reelpass}"
DEPLOY="${DEPLOY:-/var/www/reelmovies}"
SRC="$(cd "$(dirname "$0")" && pwd)"

echo "==> Installing Movie Discovery"
echo "    source: $SRC   deploy: $DEPLOY   db: $DB_NAME"

if [ "$(id -u)" != "0" ]; then echo "Run as root (no sudo needed in an LXC)."; exit 1; fi

# Fail early if the archive extracted incompletely (nested-folder issues, etc.)
for f in api/index.php api/lib/Database.php db/schema.sql index.html; do
  [ -f "$SRC/$f" ] || { echo "ERROR: missing $SRC/$f — re-extract the zip INSIDE the container with: unzip <file>.zip"; exit 1; }
done

export DEBIAN_FRONTEND=noninteractive
echo "==> Packages…"
apt-get update -y
apt-get install -y apache2 php php-mysql libapache2-mod-php default-mysql-server

echo "==> Starting MySQL…"
service mariadb start 2>/dev/null || service mysql start 2>/dev/null || true
sleep 3

echo "==> Database + user…"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost'  IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

echo "==> Deploying files…"
mkdir -p "$DEPLOY"
cp -a "$SRC"/. "$DEPLOY"/
mkdir -p "$DEPLOY/data"
chown -R www-data:www-data "$DEPLOY"

echo "==> Schema + seed (this REPLACES old tables)…"
mysql "$DB_NAME" < "$DEPLOY/db/schema.sql"
( cd "$DEPLOY" && DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" php db/seed.php )

if [ -n "$TMDB_API_KEY" ]; then
  echo "==> Writing TMDB key to Apache env…"
  echo "SetEnv TMDB_API_KEY \"$TMDB_API_KEY\"" > "$DEPLOY/.htaccess"
  chown www-data:www-data "$DEPLOY/.htaccess"
fi

echo "==> Apache…"
cp "$DEPLOY/apache-reelmovies.conf" /etc/apache2/sites-available/movies.conf
sed -i "s#/var/www/reelmovies#$DEPLOY#g" /etc/apache2/sites-available/movies.conf
a2ensite movies >/dev/null
a2dissite 000-default >/dev/null 2>&1 || true
a2enmod env >/dev/null 2>&1 || true
service apache2 reload 2>/dev/null || service apache2 restart

IP=$(hostname -I | awk '{print $1}')
echo
echo "============================================================"
echo " Installed.  http://${IP:-<container-ip>}/"
echo " No default account — the FIRST account you register becomes the admin."
[ -z "$TMDB_API_KEY" ] && echo " (Running in offline demo mode — set TMDB_API_KEY for the full live catalogue.)"
echo "============================================================"
