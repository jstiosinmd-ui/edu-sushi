import { defineRailway, github, postgres, preserve, project, service, volume } from 'railway/iac';

export default defineRailway(() => {
  const db = postgres('Postgres', { region: 'asia-southeast1-eqsg3a' });
  const storage = volume('sushi-storage', { region: 'asia-southeast1-eqsg3a', sizeMB: 500 });
  const web = service('sushi', {
    source: github('jstiosinmd-ui/edu-sushi', { branch: 'main', rootDirectory: 'src/laravel' }),
    replicas: { 'asia-southeast1-eqsg3a': 1 },
    healthcheck: '/login',
    healthcheckTimeout: 180,
    preDeploy: 'sh deploy/release.sh',
    volumeMounts: { '/var/www/html/storage': storage },
    env: {
      APP_NAME: 'SUSHI',
      APP_ENV: 'production',
      APP_DEBUG: 'false',
      APP_KEY: preserve(),
      APP_URL: preserve(),
      TRUSTED_PROXIES: '*',
      DB_CONNECTION: 'pgsql',
      DB_URL: db.env.DATABASE_URL,
      SESSION_DRIVER: 'database',
      SESSION_ENCRYPT: 'true',
      SESSION_SECURE_COOKIE: 'true',
      QUEUE_CONNECTION: 'database',
      DB_QUEUE_RETRY_AFTER: '360',
      CACHE_STORE: 'database',
      FILESYSTEM_DISK: 'local',
      LOG_CHANNEL: 'stderr',
      LOG_LEVEL: 'warning',
    },
  });
  return project('edu-sushi', { resources: [db, storage, web] });
});
