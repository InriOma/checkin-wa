# PROMPT MAESTRO PARA CODEX — CHECKIN WA

Actúa como arquitecto de software y desarrollador senior full-stack. Trabaja directamente en el workspace abierto en Codex. Construiremos CheckIn WA, un SaaS multiempresa de control de asistencia por WhatsApp. No te limites a proponer: inspecciona, implementa, ejecuta pruebas y documenta. Trabaja por fases pequeñas y verificables.

## PRIMERA INSTRUCCIÓN
Comienza exclusivamente por Fase 0: inspecciona el workspace y reporta lo encontrado. No sobrescribas ni elimines archivos, no ejecutes migraciones destructivas y no implementes cambios masivos. Crea/actualiza la documentación técnica base y espera mi autorización para Fase 1.

## Producto
- Empresas de 20–100 empleados inicialmente, con múltiples sucursales.
- Empleados registran entrada, salida y descansos desde WhatsApp.
- El empleado comparte su ubicación actual cuando se le solicita.
- Dashboard web para administración, RRHH, sucursales, horarios, incidencias y reportes.
- Arquitectura híbrida WhatsApp: número central compartido y números dedicados por empresa.
- Ambos canales comparten el mismo motor de asistencia.

## Reglas de trabajo
1. Inspecciona `pwd`, Git status, archivos, versiones, estructura y documentación antes de cambiar nada.
2. Conserva código existente si es razonable. No reemplaces archivos sin explicar el motivo.
3. Si el workspace está vacío, crea monorepo: `backend/`, `frontend/`, `docs/`, `infra/`.
4. No ejecutes `migrate:fresh`, `db:wipe`, `git reset --hard` ni borres volúmenes.
5. No inventes credenciales, tokens, IDs de Meta ni datos AWS. Usa `.env.example`.
6. No afirmes que algo funciona si no lo probaste. Si faltan credenciales externas, crea integración configurable y documenta los pasos pendientes.
7. Después de cada etapa ejecuta tests, corrige errores y resume archivos, comandos, resultados y pendientes.
8. Mantén cambios pequeños, legibles y testeables. No introduzcas microservicios en el MVP.
9. En macOS no presupongas Docker Desktop. Considera Colima o instalación local de PHP/PostgreSQL/Redis.
10. Antes de instalar dependencias o hacer operaciones que alteren datos, explica el impacto.

## Stack objetivo
Backend: PHP 8.3+, Laravel 12, PostgreSQL, Redis, Sanctum, Queues, Scheduler, Pest/PHPUnit, OpenAPI.
Frontend: Next.js App Router, TypeScript estricto, Tailwind CSS, componentes accesibles.
Infra: desarrollo macOS; Docker Compose opcional compatible con Colima. Producción futura AWS: ALB, ECS/EC2, RDS PostgreSQL, ElastiCache, S3, CloudWatch, Secrets Manager.
Verifica compatibilidad de versiones antes de instalar. No actualices major versions sin justificarlo.

## Arquitectura
Monolito modular Laravel con dominios:
- Tenancy
- Identity/Access
- Employees
- Branches
- Schedules
- Attendance
- Geolocation
- Incidents
- WhatsApp
- Reports
- Audit
- Billing (solo preparar límites, no implementar pagos aún)

WhatsApp es un adaptador de transporte, no contiene reglas de asistencia. El motor de asistencia debe ser independiente del canal.

## Multi-tenancy
Base PostgreSQL compartida con `tenant_id` en todas las entidades propiedad de empresa.
Entidades globales: `users` del panel según diseño elegido y `whatsapp_contacts`.
Entidades tenant-owned: employees, branches, schedules, assignments, attendance_records, attendance_events, breaks, incidents, audit_logs y employee_whatsapp_contacts.

Reglas:
- Nunca confiar en `tenant_id` enviado por el navegador.
- Resolver tenant desde usuario autenticado y membresía autorizada.
- Usar middleware/contexto, Policies y scopes explícitos.
- Índices únicos compuestos por tenant.
- Evitar relaciones cruzadas entre tenants; considerar FKs compuestas o validaciones transaccionales.
- Probar aislamiento: tenant A no puede leer/modificar/borrar datos de B.
- PostgreSQL RLS puede añadirse como defensa adicional, con contexto tenant por transacción correctamente implementado.
Roles: `platform_admin`, `company_owner`, `hr_admin`, `branch_manager`, `employee`.

## Modelo WhatsApp híbrido
Tablas esenciales:

`tenants`: id, name, code único, timezone IANA, status, timestamps.

`whatsapp_channels`: id, tenant_id nullable, channel_type (`central` o `dedicated`), phone_number_id único, business_account_id, display_phone_number, access_token_encrypted, status.
Restricciones: central => tenant_id NULL; dedicated => tenant_id NOT NULL. Tokens cifrados y nunca registrados en logs.

`whatsapp_contacts`: directorio global de números E.164 únicos. Un teléfono no identifica por sí solo una empresa.

`employee_whatsapp_contacts`: tenant_id, employee_id, whatsapp_contact_id, is_primary, verified_at. Un mismo contacto global puede tener membresías en varias empresas.

### Enrutamiento
1. Meta envía `metadata.phone_number_id`.
2. Buscar el canal activo.
3. Si es dedicated, el canal resuelve tenant.
4. Si es central, buscar una membresía verificada del remitente. Si no existe o hay varias, solicitar vinculación/selección.
5. Alta central: código de empresa + código de empleado + OTP de un solo uso enviado al teléfono, con hash, expiración, límite de intentos y rate limit.
6. Nunca resolver tenant solo por teléfono ni confiar en tenant ID escrito en un mensaje.
7. Toda conversación queda asociada al `whatsapp_channel_id`.

### Cloud API
- GET webhook: validar `hub.mode`, `hub.verify_token`, devolver `hub.challenge`.
- POST webhook: validar `X-Hub-Signature-256` con HMAC SHA-256 sobre el cuerpo RAW y Meta App Secret; comparación timing-safe.
- Persistir evento/mensaje idempotentemente antes de responder 200.
- Procesar por Queue.
- Idempotencia por `wamid` de Meta.
- Gestionar duplicados, reintentos, estados de entrega y eventos fuera de orden.
- Graph API version configurable, no hardcodeada.
- Token y phone_number_id resueltos por canal.
- Respetar ventanas de conversación y plantillas aprobadas de Meta.
- Redactar datos personales en logs.
- Ubicación: usar el mecanismo oficial compatible de solicitud de ubicación. El empleado comparte voluntariamente una ubicación puntual. No implementar tracking continuo ni afirmar que GPS no se puede falsificar.

## Asistencia
Estados: `no_shift_open -> working -> on_break -> working -> closed`.
Eventos: `clock_in`, `break_start`, `break_end`, `clock_out`, `correction`.
- Rechazar doble entrada, salida sin sesión abierta, descansos anidados y salida con descanso abierto.
- Usar transacciones y bloqueo de fila (`SELECT ... FOR UPDATE`) para operaciones concurrentes.
- Historial `attendance_events` append-only.
- Correcciones generan evento y audit log; nunca sobrescriben silenciosamente eventos originales.
- Guardar source, canal, wamid, hora de recepción y hora del evento.
- Guardar timestamps en UTC; calcular/mostrar con zona IANA de sucursal/horario.
- No confiar en la hora del dispositivo como hora autoritativa.

## Geolocalización
- Cada sucursal tiene latitud, longitud, radio en metros y timezone.
- Validar rangos de latitud [-90,90] y longitud [-180,180].
- Haversine en servicio independiente.
- Solo sucursales autorizadas al empleado.
- Si hay varias elegibles, elegir la más cercana y guardar la decisión.
- Guardar coordenadas, distancia, radio aplicado, branch_id y resultado para auditoría.
- Ubicación solo se acepta si existe `pending_action` vigente y no consumida. Expiración inicial configurable, sugerencia 2 minutos.
- Fuera de geocerca: no marcar como asistencia válida; generar intento/incidencia para revisión.
- No asumir precisión GPS si Meta no la entrega. La ubicación es señal puntual, no prueba criptográfica de presencia.

## Horarios e incidencias
- Horarios por día de semana, hora local de entrada/salida, tolerancia, descansos, timezone y asignaciones efectivas.
- Soportar turnos nocturnos y cambios DST.
- Retardo según tolerancia.
- Falta solo después del umbral configurado y comprobando permisos/ausencias justificadas.
- Jornada incompleta, salida anticipada y exceso de descanso.
- Incidencias pending/approved/rejected con responsable y auditoría.
- No implementar descuentos salariales ni nómina en MVP.

## Dashboard y API
Dashboard: login, resumen diario, empleados, sucursales/geocercas, horarios/asignaciones, asistencias, incidencias, reportes y gestión de canales autorizada.
API `/api/v1`: Form Requests, Resources, Policies, paginación, filtros, respuestas consistentes y OpenAPI. No exponer tokens ni modelos internos.

## Seguridad y privacidad
HTTPS, rate limits, OTP seguro, firma webhook, secretos fuera de Git, logs redactados, auditoría, backups y prueba de restauración, política de retención, aviso de privacidad y reglas de tratamiento de ubicación. No seguimiento continuo.

## Fases de ejecución
Fase 0: reconocer workspace, versiones y Git; crear `docs/ARCHITECTURE.md`, `docs/DATA_MODEL.md`, `docs/WHATSAPP_INTEGRATION.md`, `docs/IMPLEMENTATION_PLAN.md` y ADR iniciales. No implementar cambios masivos.
Fase 1: Laravel/Postgres/Redis, tenancy, memberships, roles, policies, migraciones, factories y tests de aislamiento.
Fase 2: CRUD empresas/empleados/sucursales/horarios y dashboard inicial.
Fase 3: canales WhatsApp, webhook, firma, persistencia idempotente, queues y cliente Graph API.
Fase 4: onboarding central OTP y vinculación dedicada.
Fase 5: pending actions, ubicación, geofence y máquina de estados.
Fase 6: retardos, faltas, incidencias, reportes y exportación.
Fase 7: CI, AWS, secretos, backups, monitoreo y piloto.

## Criterios de aceptación para piloto
- Dos tenants aislados por pruebas.
- Vinculación central y dedicada probadas.
- Firma webhook validada.
- Mensajes duplicados no duplican asistencia.
- Ciclo entrada/descanso/fin descanso/salida funciona.
- Geocerca dentro/fuera probada.
- Horarios y tolerancias respetan zona horaria.
- Correcciones administrativas auditables.
- Dashboard aislado por tenant.
- Backup y restauración probados.

## Ejecuta ahora
Comienza con Fase 0 solamente. Inspecciona el workspace real, informa qué encontraste, documenta arquitectura/modelo/plan adaptados al código existente y espera mi autorización para Fase 1.
