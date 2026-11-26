# ADR-0002: Multi-tenancy con Base Compartida, FKs Compuestas y Defensa RLS

- **Estado:** Aceptado
- **Fecha:** 2026-11-25

## Contexto
Cada empresa cliente debe mantener aislamiento estricto de empleados, sucursales, horarios, asistencias, incidencias y auditoría. Un fallo de autorización o de referencia cruzada entre tenants comprometería la viabilidad comercial y legal del SaaS.

## Decisión
1. Usar una base de datos PostgreSQL compartida con `tenant_id` explícito en todas las tablas propiedad de empresa.
2. Resolver el `tenant_id` únicamente desde el contexto autenticado (`tenant_user`) o desde el enrutamiento verificado de WhatsApp, nunca desde payloads de cliente.
3. Aplicar **foreign keys compuestas `(tenant_id, entity_id)`** en tablas relacionadas dentro de un tenant para garantizar integridad referencial a nivel de motor de base de datos.
4. Preparar políticas **PostgreSQL Row-Level Security (RLS)** como capa de defensa en profundidad (complementaria a Policies y Scopes, nunca sustituta).

## Consecuencias
- **Positivas:** Imposibilidad física de vincular un empleado del Tenant A con una sucursal del Tenant B; migraciones simples y costo eficiente en RDS.
- **Negativas / Mitigación:** Las consultas y workers en cola deben inicializar explícitamente `TenantContext` (y `SET LOCAL app.current_tenant_id` cuando aplique RLS); se exigirán pruebas automatizadas de aislamiento cruzado en cada dominio.
