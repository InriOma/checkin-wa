# ADR-0001: Monolito Modular Laravel 12 y Entorno Docker Compose

- **Estado:** Aceptado
- **Fecha:** 2026-11-25

## Contexto
CheckIn WA requiere desarrollo rápido para MVP comercial (empresas de 20–100 empleados con múltiples sucursales), consistencia transaccional estricta entre mensajes WhatsApp, geocercas, máquina de estados de asistencia e incidencias, y un entorno reproducible en macOS (`x86_64`) donde actualmente no existe PostgreSQL instalado localmente ni extensión `phpredis` en el PHP del sistema.

## Decisión
1. Implementar el backend como **monolito modular en Laravel 12** organizado por dominios (`app/Domains/*`), evitando microservicios en el MVP.
2. Utilizar **Docker Compose** (`nginx`, `api`, `worker`, `scheduler`, `postgres:16-alpine`, `redis:7-alpine`, `frontend`) como entorno estándar de desarrollo y pruebas.

## Consecuencias
- **Positivas:** Transacciones ACID simples, menor complejidad operativa, paridad entre desarrolladores y compatibilidad directa con despliegue futuro en ECS/Fargate.
- **Negativas / Mitigación:** Disciplina arquitectónica necesaria para evitar acoplamiento indebido entre dominios; se mitigará manteniendo fronteras claras de servicios y pruebas de arquitectura/integración.
