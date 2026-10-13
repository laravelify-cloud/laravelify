#!/usr/bin/env bash
# First boot inside the Ubuntu test container. Does not create a panel account.
set -euo pipefail

APP=/opt/laravelify
exec > >(tee -a /var/log/laravelify-bootstrap.log) 2>&1
echo "Laravelify bootstrap started at $(date -Is)"

sed -i 's/\r$//' "${APP}/scripts/"*.sh || true
chmod 755 "${APP}/scripts/"*.sh || true

systemctl enable --now mysql || systemctl enable --now mariadb || true
systemctl enable --now redis-server || true
systemctl enable --now nginx || true
systemctl enable --now supervisor || true

for _ in $(seq 1 40); do
  if mysqladmin --protocol=socket ping --silent; then
    break
  fi
  sleep 2
done

# Socket login as the Linux root user ignores -p while auth_socket is active.
if mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
  mysql --protocol=socket -uroot <<'SQL'
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY 'root';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'root';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
else
  mysql --protocol=socket -uroot -proot <<'SQL'
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'root';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
fi

mysql --protocol=socket -uroot -proot -e "CREATE DATABASE IF NOT EXISTS laravelify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

cd "${APP}"
install -d -o www-data -g www-data storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

if [[ ! -f vendor/autoload.php ]]; then
  composer install --no-interaction --prefer-dist
fi
if [[ ! -f public/build/manifest.json ]]; then
  npm install
  npm run build
fi
php artisan migrate --force --seed --no-interaction
php artisan storage:link || true

supervisorctl reread || true
supervisorctl update || true

echo "Laravelify bootstrap finished at $(date -Is)"
echo "Open http://127.0.0.1:8124/setup and create your own password."
