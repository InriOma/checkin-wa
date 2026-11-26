# Desarrollo local en macOS

## Inspección
```bash
uname -m
php -v
composer --version
node -v
npm -v
docker version
colima status
docker compose version
```

No presupongas Docker Desktop. Puedes usar Colima o instalar PHP/PostgreSQL/Redis localmente. No borres volúmenes ni bases existentes sin autorización.

## Variables de entorno sugeridas
```dotenv
APP_NAME="CheckIn WA"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=checkin_wa
DB_USERNAME=checkin
DB_PASSWORD=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

WHATSAPP_VERIFY_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_GRAPH_VERSION=
```

No subir `.env` a Git. Para webhooks locales se requiere endpoint HTTPS de desarrollo/túnel seguro; nunca exponer PostgreSQL o Redis públicamente. Usar migraciones normales, no `migrate:fresh` sobre datos que deban conservarse.
