# CheckIn WA

Plataforma SaaS multi-tenant para **control de asistencia laboral mediante WhatsApp** con validación de geocercas GPS, máquina de estados transaccional, detección automática de incidencias de Recursos Humanos y panel administrativo web.

---

## Stack Tecnológico

| Capa | Tecnologías |
|---|---|
| **Backend** | PHP 8.4, Laravel 12 (Monolito Modular), Laravel Sanctum, Laravel Queues, Laravel Scheduler, Dedoc Scramble (OpenAPI 3.1), Pest 3, Laravel Pint |
| **Base de datos y colas** | PostgreSQL 16 (FKs compuestas + Row-Level Security), Redis 7 (`phpredis`) |
| **Frontend** | Next.js 16 (App Router), React 19, TypeScript estricto, Tailwind CSS v4, Lucide Icons |
| **Integraciones** | WhatsApp Business Cloud API (Meta), geolocalización puntual GPS (Haversine) |
| **Infraestructura local** | Docker Compose (`postgres`, `redis`, `api`, `worker`, `scheduler`, `nginx`, `frontend`) |

---

## Arquitectura y Documentación Técnica

- [Arquitectura del sistema](docs/ARCHITECTURE.md)
- [Modelo de datos y máquina de estados](docs/DATA_MODEL.md)
- [Integración híbrida WhatsApp Cloud API](docs/WHATSAPP_INTEGRATION.md)
- [Plan de implementación por fases](docs/IMPLEMENTATION_PLAN.md)
- [Desarrollo local en macOS](docs/LOCAL_DEVELOPMENT_MACOS.md)
- [Decisiones de arquitectura (ADR)](docs/adr/)

---

## Requisitos Previos

- **Docker** y **Docker Compose** (en macOS puedes usar Docker Desktop o **Colima**).
- **Git**

> **Nota para macOS:** Si usas Colima, asegúrate de iniciarlo antes de levantar los contenedores:
> ```bash
> colima start --vm-type=vz --cpu 2 --memory 4
> ```

---

## Puesta en Marcha Rápida (Docker)

### 1. Clonar el repositorio y preparar variables de entorno

```bash
git clone git@github.com:InriOma/checkin-wa.git
cd checkin-wa

cp .env.example .env
cp backend/.env.example backend/.env
```

Edita `.env` en la raíz si necesitas ajustar credenciales locales o las variables de Meta Cloud API:

```dotenv
DB_DATABASE=checkin_wa
DB_USERNAME=checkin
DB_PASSWORD=checkin_local_dev

WHATSAPP_GRAPH_BASE_URL=https://graph.facebook.com
WHATSAPP_GRAPH_VERSION=v21.0
WHATSAPP_VERIFY_TOKEN=tu_verify_token_secreto
WHATSAPP_APP_SECRET=tu_app_secret_de_meta
```

*(Nunca subas `.env` ni `backend/.env` al repositorio.)*

### 2. Construir y levantar todos los contenedores

```bash
docker compose up -d --build
```

Servicios incluidos en `docker-compose.yml`:
- `postgres`: PostgreSQL 16 (`127.0.0.1:5432`)
- `redis`: Redis 7 (`127.0.0.1:6379`)
- `api`: PHP 8.4-FPM (Laravel 12)
- `worker`: Cola Redis (`php artisan queue:work redis`)
- `scheduler`: Tareas programadas (`php artisan schedule:work`, detección de faltas y jornadas incompletas cada 15 min)
- `nginx`: Proxy HTTP hacia la API (`http://localhost:8080`)
- `frontend`: Dashboard Next.js (`http://localhost:3000`)

### 3. Instalar dependencias PHP (si es clone limpio), generar `APP_KEY` y migrar + poblar BD

```bash
docker compose exec api composer install
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate --seed
```

### 4. Verificar que todo esté arriba

```bash
docker compose ps
curl -s http://localhost:8080/api/v1/health
```

Respuesta esperada de `/api/v1/health`:
```json
{"status":"ok","service":"checkin-wa-api","checks":{"database":"up","redis":"up"}}
```

---

## URLs y Credenciales Demo

| Recurso | URL |
|---|---|
| **Dashboard Web** | `http://localhost:3000/login` |
| **API v1** | `http://localhost:8080/api/v1` |
| **Documentación OpenAPI (Swagger/Scramble)** | `http://localhost:8080/docs/api` |
| **Spec OpenAPI JSON** | `http://localhost:8080/docs/api.json` |
| **Webhook WhatsApp (Meta)** | `http://localhost:8080/api/v1/webhooks/whatsapp` |

### Usuario precargado por `DatabaseSeeder`
- **Correo:** `owner@acme.local`
- **Contraseña:** `CheckInLocal123!`
- **Empresa demo:** `Acme Retail MX` (código `ACME01`)
- **Sucursal demo:** `Sucursal Reforma` (`CDMX01`, `19.42847, -99.16283`, radio `120 m`)
- **Empleado demo:** `Ana García` (`EMP001`, horario `09:00–18:00`)

---

## Configuración de WhatsApp Cloud API

El sistema opera con una **arquitectura híbrida** (canal central compartido + canales dedicados por empresa) y vinculación verificada por OTP.

### 1. Exponer webhook local con HTTPS (para pruebas con celular real)
Meta requiere HTTPS público para enviar webhooks. Puedes exponer el puerto `8080` con `cloudflared` o `ngrok`:

```bash
cloudflared tunnel --url http://localhost:8080
```

En **Meta for Developers -> WhatsApp -> Configuration -> Webhook**:
- **Callback URL:** `https://<tu-subdominio>/api/v1/webhooks/whatsapp`
- **Verify token:** el mismo valor de `WHATSAPP_VERIFY_TOKEN` en tu `.env`
- Suscribe el campo **`messages`**.

### 2. Conectar un canal de WhatsApp Business (empresa)
1. Ingresa al Dashboard en `http://localhost:3000/channels` (**WhatsApp** -> **Conectar canal dedicado**).
2. Captura los datos de **Meta for Developers -> WhatsApp -> API Setup**:
   - Número visible (`E.164`)
   - `Phone Number ID`
   - `WhatsApp Business Account ID (WABA)`
   - `Access Token` (se cifra en reposo en PostgreSQL y nunca se expone en respuestas API).

*(Para registrar un canal central compartido de plataforma, usa `POST /api/v1/platform/whatsapp/channels` con un usuario `is_platform_admin = true` y `"channel_type": "central"`).*

### 3. Vincular el celular de un empleado y registrar asistencia
1. Desde WhatsApp, el empleado escribe al número conectado:
   - **Canal dedicado:** `VINCULAR EMP001`
   - **Canal central:** `VINCULAR ACME01 EMP001`
2. El bot responde con un **OTP de 6 dígitos** (con expiración, hash HMAC, límite de intentos y rate limit).
3. El empleado responde con los 6 dígitos y queda verificado.
4. Una vez vinculado, puede enviar **`ENTRADA`**, **`DESCANSO`**, **`FIN DESCANSO`** o **`SALIDA`** (para entrada y salida el sistema solicita su ubicación actual mediante `location_request_message` y valida la geocerca Haversine).

---

## Pruebas Automatizadas y Calidad

### Backend (Pest contra PostgreSQL 16 + Laravel Pint)

Crea una base de datos exclusiva para pruebas (para no tocar `checkin_wa`):

```bash
docker compose exec postgres psql -U checkin -d checkin_wa -c "CREATE DATABASE checkin_wa_test;"
```

Ejecuta la suite Pest contra PostgreSQL 16 y valida formato con Pint:

```bash
docker compose exec \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=checkin_wa_test \
  -e DB_USERNAME=checkin \
  -e DB_PASSWORD=checkin_local_dev \
  api ./vendor/bin/pest

docker compose exec api ./vendor/bin/pint --test
```

### Frontend (TypeScript, ESLint y Build de producción)

```bash
docker compose exec frontend sh -c "npx tsc --noEmit && npm run lint && npm run build"
```

---

## Estado del Proyecto

- [x] **Fase 0:** Auditoría, arquitectura, modelo de datos, integración WhatsApp y ADRs
- [x] **Fase 1:** Docker Compose, Laravel 12, PostgreSQL 16, Redis 7, multi-tenancy (FKs compuestas + RLS), Sanctum, RBAC y CRUD base
- [x] **Fase 2:** Webhooks Meta (HMAC timing-safe, idempotencia por `wamid`, colas Redis), Graph API client, routing híbrido y onboarding OTP
- [x] **Fase 3:** Geolocalización Haversine, `GeofenceEvaluator`, máquina de estados transaccional y eventos append-only
- [x] **Fase 4:** Horarios nocturnos/DST, incidencias automáticas, detección programada, justificaciones, correcciones auditadas y exportación CSV
- [x] **Fase 5:** Dashboard web Next.js App Router + TypeScript + Tailwind CSS
- [ ] **Fase 6:** CI/CD, despliegue AWS, backups/restauración verificada y hardening de producción
