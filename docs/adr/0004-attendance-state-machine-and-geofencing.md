# ADR-0004: Máquina de Estados Append-Only y Geocerca Puntual para Asistencia

- **Estado:** Aceptado
- **Fecha:** 2026-11-25

## Contexto
Las condiciones reales de red móvil provocan reintentos de webhooks, doble envío de mensajes y concurrencia. Adicionalmente, RRHH requiere auditoría histórica confiable aunque cambien las coordenadas/radio de una sucursal o se realice una corrección manual.

## Decisión
1. Modelar `attendance_records` con máquina de estados estricta (`no_shift_open -> working -> on_break -> working -> closed`), protegida por transacciones DB, `SELECT ... FOR UPDATE` e índice único parcial para impedir más de un turno abierto por empleado.
2. Registrar todos los eventos (`clock_in`, `break_start`, `break_end`, `clock_out`, `correction`, `rejected_geofence`) en `attendance_events` de forma **append-only**. Las correcciones nunca sobrescriben el evento original.
3. Validar ubicación GPS únicamente contra `pending_actions` vigentes (TTL sugerido 2 min) mediante fórmula Haversine sobre sucursales autorizadas, guardando snapshot de distancia y radio en el evento.

## Consecuencias
- **Positivas:** Trazabilidad legal/operativa intacta y ausencia de turnos duplicados por latencia o reintentos.
- **Negativas / Mitigación:** Cualquier ajuste administrativo requiere generar un evento compensatorio de corrección; la interfaz del dashboard deberá mostrar claramente el historial original y las correcciones aplicadas.
