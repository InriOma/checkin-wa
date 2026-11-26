# ADR-0003: Arquitectura Híbrida de WhatsApp e Identidad Independiente del Canal

- **Estado:** Aceptado
- **Fecha:** 2026-11-25

## Contexto
Algunas empresas comenzarán usando un número central compartido de CheckIn WA, mientras que otras conectarán su propio número de WhatsApp Business (WABA). Además, una misma persona (número E.164) podría trabajar o tener vínculo con más de una empresa.

## Decisión
1. Separar el directorio global de teléfonos (`whatsapp_contacts` único por `phone_e164`) de las membresías por empresa (`employee_whatsapp_contacts`).
2. Modelar `whatsapp_channels` con `channel_type` (`central` con `tenant_id IS NULL`, o `dedicated` con `tenant_id NOT NULL`), indexado por `phone_number_id`.
3. En canal central, requerir vinculación explícita (`tenant.code` + `employee_code` + OTP hasheado con expiración y rate limit) y selección de empresa en caso de múltiples membresías activas.
4. Garantizar idempotencia por `wamid` antes de encolar el procesamiento asíncrono.

## Consecuencias
- **Positivas:** Un mismo motor de asistencia atiende ambos modelos de negocio sin duplicar lógica ni asumir 1 teléfono = 1 empresa.
- **Negativas / Mitigación:** Mayor cuidado en la máquina conversacional del canal central para desambiguar empresas; mitigado mediante `pending_actions` expirables y fixtures de prueba completos.
