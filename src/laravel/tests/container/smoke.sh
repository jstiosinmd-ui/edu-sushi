#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/../.."

docker network create sushi-smoke
docker volume create sushi-smoke-storage
docker run -d --name sushi-smoke-db --network sushi-smoke \
  -e POSTGRES_USER=sushi -e POSTGRES_PASSWORD=sushi-ci \
  -e POSTGRES_DB=sushi_container_smoke postgres:18
for i in {1..30}; do
  if docker exec sushi-smoke-db pg_isready -U sushi -d sushi_container_smoke; then break; fi
  sleep 2
done

export APP_KEY="base64:$(openssl rand -base64 32)"
app_args=(--network sushi-smoke --env APP_KEY
  -e APP_NAME=SUSHI -e APP_ENV=production -e APP_DEBUG=false
  -e APP_URL=https://sushi.example -e TRUSTED_PROXIES=*
  -e SESSION_SECURE_COOKIE=true -e SESSION_ENCRYPT=true -e SESSION_DRIVER=database
  -e DB_CONNECTION=pgsql -e DB_HOST=sushi-smoke-db -e DB_PORT=5432
  -e DB_DATABASE=sushi_container_smoke -e DB_USERNAME=sushi -e DB_PASSWORD=sushi-ci
  -e QUEUE_CONNECTION=database -e DB_QUEUE_RETRY_AFTER=360 -e CACHE_STORE=database
  -e LOG_CHANNEL=stderr -e LOG_LEVEL=warning -e PORT=8080
  -v sushi-smoke-storage:/var/www/html/storage)

docker run --rm "${app_args[@]}" edu-sushi:ci php artisan migrate --force
docker run --rm "${app_args[@]}" edu-sushi:ci php artisan db:seed --force
docker run -d --name sushi-smoke-app -p 127.0.0.1:18080:8080 "${app_args[@]}" edu-sushi:ci
trap 'docker logs sushi-smoke-app' ERR

check_http() {
  curl --retry 30 --retry-all-errors --retry-delay 2 --fail --silent --show-error \
    -H 'Host: sushi.example' -H 'X-Forwarded-Proto: https' -H 'X-Forwarded-Port: 443' \
    -D /tmp/sushi-headers -o /tmp/sushi-login http://127.0.0.1:18080/login
  python3 - <<'PY'
from pathlib import Path
headers = Path('/tmp/sushi-headers').read_text().lower()
html = Path('/tmp/sushi-login').read_text()
assert 'secure;' in headers, 'Session cookie must be secure'
assert 'https://sushi.example/build/assets/' in html, 'Asset URLs must use forwarded HTTPS'
assert 'http://sushi.example' not in html, 'Mixed content URL'
assert 'Login' in html, 'Missing login page'
PY
}

check_http
docker exec sushi-smoke-app supervisorctl -c /etc/supervisor/conf.d/sushi.conf status
docker exec --user www-data sushi-smoke-app php tests/container/smoke.php create
docker restart sushi-smoke-app
check_http
docker exec --user www-data sushi-smoke-app php tests/container/smoke.php verify
docker exec sushi-smoke-app php artisan queue:failed
