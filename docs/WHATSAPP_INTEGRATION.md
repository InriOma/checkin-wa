# CheckIn WA — Integración WhatsApp Cloud API

> **Principio:** WhatsApp es exclusivamente un canal de transporte e interacción conversacional. Toda regla de asistencia, geocerca y horario reside en el dominio central.

---

## 1. Arquitectura Híbrida de Canales

| Característica | Canal Central (`central`) | Canal Dedicado (`dedicated`) |
|---|---|---|
| `whatsapp_channels.tenant_id` | `NULL` | `tenant_id` obligatorio (`NOT NULL`) |
| Resolución inicial | Por `metadata.phone_number_id` | Por `metadata.phone_number_id` -> resuelve `tenant_id` directo |
| Identidad del empleado | Teléfono E.164 -> busca membresía verificada en `employee_whatsapp_contacts` | Teléfono E.164 dentro del `tenant_id` del canal |
| Múltiples empresas por teléfono | Soportado; si hay >1 membresía activa, solicita selección explícita | N/A (el canal ya fija la empresa) |
| Onboarding / Alta | `tenant_code` + `employee_code` + OTP de un solo uso | Vinculación directa con `employee_code` + OTP (o código de verificación generado por RRHH) |

### Regla crítica de seguridad
- **Nunca** resolver un tenant usando únicamente el número de teléfono del remitente.
- **Nunca** confiar en un `tenant_id` escrito manualmente en un mensaje de texto.
- Toda conversación y respuesta saliente queda ligada al `whatsapp_channel_id` por el cual ingresó, utilizando su propio `phone_number_id` y `access_token_encrypted`.

---

## 2. Seguridad del Webhook

### Endpoint `GET /api/v1/webhooks/whatsapp` (Verificación Meta)
1. Leer parámetros `hub.mode` (`hub_mode` en PHP), `hub.verify_token` y `hub.challenge`.
2. Verificar `hub.mode === 'subscribe'`.
3. Comparar `hub.verify_token` usando `hash_equals(config('whatsapp.verify_token'), $token)`.
4. Si coincide, responder `200 OK` con `hub.challenge` en `text/plain`. De lo contrario, `403 Forbidden`.

### Endpoint `POST /api/v1/webhooks/whatsapp` (Recepción de eventos)
1. **Validación HMAC obligatoria antes de parsear/confiar en JSON:**
   - Leer el cuerpo crudo (`$request->getContent()`).
   - Extraer cabecera `X-Hub-Signature-256` (formato `sha256=<hex>`).
   - Calcular `hash_hmac('sha256', $rawBody, config('whatsapp.app_secret'))`.
   - Comparar con `hash_equals()`. Si falta la cabecera o no coincide, responder `401/403` y registrar evento de seguridad redactado.
2. **Extracción e idempotencia síncrona (rápida):**
   - Iterar `entry[].changes[].value`.
   - Resolver canal activo por `metadata.phone_number_id`. Si es desconocido, loguear advertencia y no procesar dominio.
   - Por cada elemento en `messages[]`:
     - Insertar registro idempotente en `whatsapp_messages` usando `wamid` único (`INSERT ... ON CONFLICT DO NOTHING` / `firstOrCreate` protegido).
     - Si el `wamid` ya existía, omitir el despacho a cola y retornar `200 OK`.
   - Por cada elemento en `statuses[]`:
     - Actualizar `delivery_status` del `whatsapp_messages` saliente correspondiente (manejando eventos fuera de orden: ej. no degradar `read` a `delivered` o `sent`).
3. **Respuesta HTTP inmediata:** Responder `200 OK` en < 200 ms para evitar reintentos agresivos de Meta.
4. **Procesamiento asíncrono:** Despachar `ProcessInboundWhatsAppMessageJob` a Redis Queue.

---

## 3. Flujo Conversacional y Ubicación Puntual

### Flujo de registro con ubicación (Entrada / Salida)
1. Empleado envía comando o selecciona botón interactivo (`Registrar entrada`, `Iniciar descanso`, `Terminar descanso`, `Registrar salida`).
2. Para **Entrada** o **Salida** (cuando la sucursal requiera geocerca):
   - El sistema verifica estado actual del empleado. Si la transición es inválida (ej. ya tiene turno abierto), rechaza inmediatamente sin pedir GPS.
   - Crea `pending_action` con `expires_at = now() + 2 minutes` (configurable) y `consumed_at = NULL`.
   - Envía mensaje interactivo de **solicitud de ubicación** oficial de WhatsApp Cloud API (`type: "interactive"`, `interactive.type: "location_request_message"`).
3. Empleado comparte su ubicación actual voluntariamente.
4. Worker recibe mensaje `type: "location"`:
   - Busca `pending_action` activa, no consumida y vigente (`expires_at > now()`).
   - Si no existe o expiró, informa al empleado que debe iniciar nuevamente la acción.
   - Ejecuta validación Haversine contra las sucursales autorizadas.
   - Marca `pending_action.consumed_at = now()` dentro de la transacción.
   - Si pasa geocerca -> ejecuta transición en `AttendanceEngine` y confirma con hora local de sucursal.
   - Si falla geocerca -> registra `attendance_events` (`rejected_geofence`) + `incidents` (`outside_geofence`) para revisión de RRHH y notifica al empleado la distancia detectada vs. radio permitido.

---

## 4. Onboarding y Seguridad OTP (Canal Central)

1. Contacto no vinculado escribe al número central.
2. El sistema solicita código de empresa (`tenant.code`) y código de empleado (`employee_code`), o los recibe en formato guiado.
3. Si el par `(tenant.code, employee_code)` existe y está activo (y sin revelar enumeración de empleados con mensajes ambiguos bajo rate limit):
   - Genera OTP numérico de 6 dígitos con `random_int(100000, 999999)`.
   - Almacena `hash_hmac('sha256', $otp, config('app.key'))` en `whatsapp_link_otps` con expiración de 10 minutos y máximo 5 intentos.
   - Envía el OTP al teléfono solicitante (o canal secundario autorizado según política de la empresa) respetando rate limit por teléfono e IP/canal.
4. Al validar correctamente el OTP:
   - Se crea/actualiza `employee_whatsapp_contacts` con `verified_at = now()`.
   - Se registra en `audit_logs`.

---

## 5. Configuración, Ventanas de Servicio y Privacidad

- **Graph API configurable:** `WHATSAPP_GRAPH_BASE_URL` (default `https://graph.facebook.com`) y `WHATSAPP_GRAPH_VERSION` (ej. `v21.0`, nunca hardcodeada en servicios).
- **Ventana de 24 horas:** Mensajes libres/interactivos solo dentro de la ventana de atención al cliente abierta tras mensaje del usuario. Notificaciones proactivas fuera de ventana (ej. recordatorio de turno o falta) requerirán **Meta Message Templates** aprobadas.
- **Redacción en logs:** Enmascarar teléfonos parcialmente en logs de aplicación (`+52******5678`), nunca imprimir `access_token_encrypted` ni `WHATSAPP_APP_SECRET`.
- **Fixtures de prueba obligatorios (`backend/tests/Fixtures/WhatsApp/`):**
  1. Mensaje de texto inbound (`text`).
  2. Respuesta interactiva de botón (`interactive.button_reply`).
  3. Ubicación compartida (`location`).
  4. Callback de estado (`statuses`: `sent`, `delivered`, `read`, `failed`).
  5. `wamid` duplicado (idempotencia).
  6. Firma `X-Hub-Signature-256` ausente o inválida.
  7. `phone_number_id` desconocido.
