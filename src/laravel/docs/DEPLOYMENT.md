# Deploying SUSHI

---

## Runtime

Use PHP 8.4 or newer, PostgreSQL 16 or newer, a web server pointed at src/laravel/public, a queue worker, and a scheduler. GitHub hosts the code; GitHub Pages cannot run the Laravel backend. Use HTTPS for learner access.

On the server, install the locked dependencies and build assets:

~~~sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
~~~

Copy .env.example to .env and configure the database, APP_URL, APP_ENV=production, APP_DEBUG=false, and SESSION_SECURE_COOKIE=true under HTTPS. Generate a unique APP_KEY with php artisan key:generate, then keep it stable and back it up securely. Do not commit .env or database files.

~~~sh
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
~~~

Run seeding on the first deployment to create the requested accounts and released bank. Later runs are idempotent and preserve passwords. The four bootstrap passwords require a change on initial login.

Make storage and bootstrap/cache writable by the PHP service account. Keep storage/app/private outside the public web root. Provision at least 512 MB PHP memory for larger PDF exports. Run a supervised worker with php artisan queue:work --tries=2 --timeout=300 and either schedule:work or a cron entry invoking schedule:run every minute. Keep DB_QUEUE_RETRY_AFTER=360 greater than the worker timeout so a running export is not picked up by a second worker. Restart queue workers after deploying code.

## Container option

A Dockerfile and compose.yaml are included. The application container runs Apache, the queue worker, and the scheduler under Supervisor. The worker and web server share the same private PDF storage. The GitHub Actions container job checks PostgreSQL seeding, forwarded HTTPS, scheduled expiry, queued PDF generation, and file persistence after a restart.

1. Copy .env.example to .env and set a nonempty DB_PASSWORD.
2. Build with docker compose build.
3. Generate an application key with docker compose run --rm --no-deps web php artisan key:generate --show and put that value in .env as APP_KEY.
4. Run docker compose up -d db.
5. Run docker compose run --rm web php artisan migrate --force and docker compose run --rm web php artisan db:seed --force.
6. Run docker compose up -d web. The queue worker and scheduler start automatically.
7. Place an HTTPS reverse proxy in front of the localhost-bound port 8080. Set the real APP_URL, SESSION_SECURE_COOKIE=true, and TRUSTED_PROXIES=* in .env, then recreate web. Only trust all forwarded headers when requests must pass through that proxy.

The compose database is private to the container network. Persistent volumes retain PostgreSQL data and private exports.

## Railway

The configuration in .railway/railway.ts defines the GitHub source, a PostgreSQL service, and one application replica with a persistent storage volume. Railway detects the Dockerfile in the service root, src/laravel. This uses Railway's current Infrastructure as Code workflow; the older railway.json format is deprecated. See the [Railway configuration reference](https://docs.railway.com/infrastructure-as-code/reference).

1. Authenticate with the Railway CLI and link a new edu-sushi project in the intended account.
2. Create the sushi service. Generate a unique APP_KEY and save it through Railway Variables. Set APP_URL to the generated HTTPS domain. These two values are preserved by the configuration and must exist before applying it. Keep the key private and stable across deployments.
3. Run railway config plan from src/laravel. Review the services, volume, region, and variables before railway config apply. The configuration requests a 1 GB application volume in Singapore and one application replica; confirm that the hosting plan supports those resources.
4. Confirm the GitHub source uses main and root directory src/laravel. The pre-deploy command runs migrations and idempotent seeding. Do not override the Docker start command. Confirm PostgreSQL provisioning and its private DB_URL reference before deploying.
5. Generate a public Railway domain for sushi and verify APP_URL uses that exact HTTPS URL. The entrypoint binds Apache to Railway's PORT and starts all three processes. The /login readiness check exercises the database-backed session and frontend assets.
6. Verify the deployment logs, login page, worker, scheduler, and private PDF downloads. Configure database and storage backups in the hosting account. Never run tests/container/smoke.php against the production database; it is restricted to its isolated test database.

Keep a single replica while exports use a local volume. Multiple application replicas require shared object storage and a separate worker topology. Persistent storage is mounted at /var/www/html/storage; Laravel keeps exports outside the public web root.

## Release checks

- Sign in as each role and complete initial password setup.
- Confirm a saved response and flashcard rating survive refresh and a second device.
- Verify the student cannot open the admin workspace or another student's attempt/deck.
- Confirm the queue produces all three PDFs and the download links require authorization.
- Check every PDF page for the creator credit and matching exam code.
- Check scheduler execution with php artisan schedule:list and run sushi:expire.
- Check php artisan queue:failed and storage/logs/laravel.log. Logs can contain request context; restrict access and retention.

The seeded bank contains 1,047 questions. Source SHA-256 recorded in the bank is 3cae311b133fe775562ce8d377a7fb41ee8faa3f65d64000ddfa966f4d48585d.

## Backups and restore

Back up PostgreSQL with pg_dump -Fc, the private storage volume, and the APP_KEY/environment through the host's secret-management process. Keep database dumps private. Choose a backup frequency and retention appropriate to the teaching schedule.

Restore a dump into a separate empty database using pg_restore, point a temporary application instance at it, and verify user, question, attempt, progress, and deck counts plus representative logins and exports. Repeat the restore test on the chosen production host.

## Rollback

Record the deployed Git commit before each release. Take a database backup before schema migrations. If deployment fails, stop the queue worker and scheduler, switch to the prior application image/commit, and restore the matching database/storage snapshot when schema compatibility requires it. Do not use migrate:fresh or a test database command against production.

The offline v1.3 HTML remains available as an archival reviewer; it does not write learner analytics to Laravel.

Jon Bryan S. Tiosin, MD
(c) jstiosin 2026
