# CheckIn WA — Plan de Implementación por Fases

> **Estado actual:** Fase 0 (Auditoría y Documentación Técnica)  
> **Requisito transversal:** Uso de Docker Compose (PostgreSQL 16, Redis 7, PHP 8.4/Laravel 12, Next.js) y pruebas automatizadas Pest/PHPUnit antes de cerrar cada fase.

---

## Resumen de Diagnóstico Inicial (Fase 0)

- **Estado del repo:** Workspace inicial con documentación base (`INSTRUCCIONES.md`, `PROMPT_MAESTRO_CODEX.md`, `docs/*.md`), sin código fuente (`backend/`, `frontend/`, `infra/`), sin `.git` inicializado y sin contenedores configurados.
- **Entorno host detectado (macOS `x86_64`):**
  - PHP `8.4.11` + Composer `2.10.0` (sin extensión `phpredis`).
  - Node `v23.7.0` + npm `11.5.2`.
  - Docker `29.5.3` + Docker Compose `5.1.4` + Colima `0.10.3`.
  - Redis `7.2.7` instalado localmente; **PostgreSQL no instalado en el host**.
- **Conclusión de arranque:** Inicializar el monorepo con Docker Compose para garantizar PostgreSQL 16 + Redis 7 + PHP 8.4 (con `pdo_pgsql`, `redis`, `pcntl`) + Next.js sin contaminar el host.

---

## Roadmap Detallado (Fases 0 a 6)

### Fase 0: Auditoría y Diseño Base (ACTUAL)
- [x] Inspección del workspace, binarios y herramientas disponibles.
- [x] Análisis de requisitos multi-tenant, WhatsApp híbrido, asistencia y geolocalización.
- [x] Creación/actualización de `docs/ARCHITECTURE.md`, `docs/DATA_MODEL.md`, `docs/WHATSAPP_INTEGRATION.md`, `docs/IMPLEMENTATION_PLAN.md` y ADRs iniciales en `docs/adr/`.
- [ ] **Pendiente:** Autorización explícita del usuario para iniciar Fase 1.

---

### Fase 1: Infraestructura Docker y Backend Core (Tenancy, Auth, CRUD Base)
**Objetivo:** Tener el entorno Docker operativo, Laravel 12 instalado con PostgreSQL y Redis, aislamiento multi-tenant probado y API CRUD base para empresas, sucursales, empleados y horarios.

- **1.1 Setup Docker & Monorepo**
  - Inicializar Git (`.gitignore` estricto sin `.env` ni secretos) y `.env.example`.
  - Crear `docker-compose.yml` y `infra/docker/*` (`nginx`, `php-fpm` 8.4 con `pdo_pgsql` + `redis` + `pcntl`, `worker`, `scheduler`, `postgres:16-alpine`, `redis:7-alpine`, `frontend`).
  - Instalar Laravel 12 en `backend/` con Pest, Sanctum y paquete OpenAPI (`dedoc/scramble` o `darkaonline/l5-swagger`).
- **1.2 Multi-tenancy, Autenticación y Roles**
  - Implementar `TenantContext`, middleware `ResolveTenant`, Global Scopes explícitos y soporte opcional de PostgreSQL RLS (`SET LOCAL app.current_tenant_id`).
  - Tablas: `tenants`, `users`, `tenant_user`, `audit_logs`.
  - Endpoints `/api/v1/auth/login`, `logout`, `me`, cambio de tenant autorizado.
  - Roles: `platform_admin`, `company_owner`, `hr_admin`, `branch_manager`, `employee` con Policies.
- **1.3 Dominios Base (Empresas, Sucursales, Empleados, Horarios)**
  - Migraciones con FKs compuestas `(tenant_id, id)` para: `branches`, `employees`, `employee_branch_assignments`, `schedules`, `shift_assignments`.
  - CRUD `/api/v1/branches`, `/api/v1/employees`, `/api/v1/schedules`, asignaciones de sucursal/horario.
  - Seeders de desarrollo y Factories multi-tenant.
- **Criterio de verificación Fase 1:** Suite Pest verde probando que Tenant A no puede leer/crear/editar/eliminar recursos de Tenant B, validaciones de coordenadas y horarios, y documentación OpenAPI generada.

---

### Fase 2: Integración WhatsApp Cloud API (Híbrida)
**Objetivo:** Recepción segura de webhooks de Meta, idempotencia por `wamid`, procesamiento en colas Redis, routing central/dedicado y vinculación OTP.

- **2.1 Canales y Webhooks**
  - Migraciones: `whatsapp_channels` (constraint central/dedicated), `whatsapp_contacts`, `employee_whatsapp_contacts`, `whatsapp_messages`, `whatsapp_link_otps`.
  - `GET /api/v1/webhooks/whatsapp` (verificación `hub.verify_token` timing-safe).
  - `POST /api/v1/webhooks/whatsapp` (validación `X-Hub-Signature-256` sobre RAW body, persistencia idempotente por `wamid`, respuesta `200`, despacho a Redis Queue).
- **2.2 Cliente Graph API y Routing Híbrido**
  - `WhatsAppCloudClient` configurable por canal (`WHATSAPP_GRAPH_VERSION`, token cifrado por canal).
  - Router: canal dedicado resuelve tenant directo; canal central busca membresía verificada o maneja múltiples membresías.
  - Flujo de onboarding por OTP (`tenant_code` + `employee_code` + OTP con hash, TTL, máx. intentos y rate limiting).
- **Criterio de verificación Fase 2:** Tests con fixtures oficiales Meta (firma válida/inválida, `wamid` duplicado, `phone_number_id` desconocido, status callbacks, onboarding central OTP y canal dedicado).

---

### Fase 3: Motor de Asistencia y Geolocalización
**Objetivo:** Ciclo completo entrada/descanso/fin descanso/salida con máquina de estados, locks transaccionales y validación de geocerca por Haversine.

- **3.1 Geolocalización**
  - `HaversineDistanceCalculator` puro y testeado unitariamente.
  - `GeofenceEvaluator` sobre sucursales autorizadas del empleado eligiendo la más cercana.
- **3.2 Máquina de Estados y `pending_actions`**
  - Migraciones: `attendance_records` (índice único parcial de turno abierto), `attendance_events` (append-only), `breaks`, `pending_actions`.
  - Transiciones `no_shift_open -> working -> on_break -> working -> closed` bajo transacción + `SELECT ... FOR UPDATE`.
  - Solicitud de ubicación puntual (`location_request_message`) con TTL de 2 minutos y consumo único.
  - Rechazo de duplicados, descansos anidados, salida con descanso abierto y ubicación fuera de geocerca.
- **Criterio de verificación Fase 3:** Tests de concurrencia/duplicados, Haversine dentro/fuera de radio, expiración de `pending_actions` y flujo completo vía simulador de webhook WhatsApp.

---

### Fase 4: Recursos Humanos, Horarios e Incidencias
**Objetivo:** Cálculo automático de retardos, faltas, jornadas incompletas, exceso de descanso, flujo de aprobación y correcciones auditadas.

- **4.1 Evaluación de Jornada**
  - Evaluador de turnos con zona IANA de sucursal/horario, soporte de turnos nocturnos y cambios DST.
  - Cálculo de `late_minutes`, `early_departure_minutes`, `excess_break`.
- **4.2 Jobs Programados y Flujo de Incidencias**
  - Laravel Scheduler: detección de faltas tras umbral configurado y cierre/alerta de jornadas incompletas.
  - Endpoints `/api/v1/incidents` (`pending`, `approve`, `reject` con nota de justificación).
  - Endpoints de corrección administrativa en `attendance_records` generando evento `correction` en `attendance_events` + `audit_logs` (sin sobrescribir eventos originales).
- **Criterio de verificación Fase 4:** Tests de horarios con DST y turno nocturno, generación de faltas/retardos, aprobación/rechazo de incidencias y trazabilidad completa en `audit_logs`.

---

### Fase 5: Dashboard Administrativo (Next.js) y Reportes
**Objetivo:** Interfaz web moderna, responsiva y accesible en Next.js App Router + TypeScript + Tailwind CSS conectada a `/api/v1`.

- **5.1 Base Frontend y Auth**
  - Scaffold Next.js App Router, TypeScript estricto, Tailwind CSS, cliente API tipado, manejo de sesión Sanctum y selector de empresa/sucursal autorizado.
- **5.2 Módulos de Gestión y Estadísticas**
  - Resumen diario (asistencias en tiempo real, retardos, faltas, incidencias pendientes).
  - CRUD Empleados (y vinculación WhatsApp), Sucursales (con visualizador/coordenadas/radio de geocerca) y Horarios/Asignaciones.
  - Monitor de asistencias con detalle de eventos y geocerca + flujo de corrección e incidencias.
  - Gestión de canales WhatsApp autorizados.
- **5.3 Reportes y Exportación**
  - Filtros por rango de fechas, sucursal y empleado; exportación CSV/XLSX desde backend con Streaming o Queue.
- **Criterio de verificación Fase 5:** Tests de componentes/integración frontend (`npm run build`, typecheck, lint) y verificación end-to-end contra la API Dockerizada.

---

### Fase 6: Producción, AWS, Seguridad y Hardening
**Objetivo:** Preparar despliegue seguro en AWS, observabilidad, backups verificados y CI/CD.

- **6.1 CI/CD y Hardening**
  - Pipeline GitHub Actions (lint, static analysis, Pest suite, Next.js build).
  - Rate limiting, cabeceras de seguridad, redacción de PII en logs, política de retención y aviso de privacidad.
- **6.2 Infraestructura AWS y Continuidad**
  - Definición de arquitectura/plantillas para ECS Fargate (API, Worker, Scheduler), ALB + ACM, RDS PostgreSQL, ElastiCache Redis, S3 y Secrets Manager.
  - Runbook de backups automáticos y **prueba documentada de restauración (`pg_restore`)** antes de piloto.
- **Criterio de salida a piloto:** Cumplimiento de los 10 criterios de aceptación definidos en `PROMPT_MAESTRO_CODEX.md`.
