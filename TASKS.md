# TASKS — teatromuseo-bff

> Este repositorio estuvo **congelado a propósito** desde el 2026-08-05 por no
> tener consumidores en el workspace. Se reactivó el 2026-08-13 con un plan
> propio: agregación por lectura directa a las bases de datos de los 3 dominios
> (CMS, Catalog, Event) más el Hub (solo metadata de archivos), para que
> `teatromuseo-web` toque un solo proceso PHP por página en vez de hasta 4. Ver
> [`../docs/plan/2026-08-13-plan-bff-completo.md`](../docs/plan/2026-08-13-plan-bff-completo.md)
> y [ADR-010](docs/adr/010-hosting-constrained-bff-read-architecture.md) (la
> restricción de hosting que motiva la arquitectura de lectura directa). Los
> cierres están en [`TASKS_ARCHIVE.md`](TASKS_ARCHIVE.md).
>
> El contrato HTTP vigente de página completa es `page-resolve`.

## ✅ Completadas

_(vacío — cierres hasta 2026-08-19 en [`TASKS_ARCHIVE.md`](TASKS_ARCHIVE.md))_

## 🟡 Próximo

### Tótem consume Cartelera / TeatroEscuela / Catálogo vía BFF (2026-08-18)

Fuente de verdad:
[`../docs/plan/2026-08-18-plan-totem-via-bff.md`](../docs/plan/2026-08-18-plan-totem-via-bff.md).
El baseline BFF (identidad multi-llamador, clave dedicada, curación
`show_in_totem`, `with_counts`) ya está cerrado (ver `TASKS_ARCHIVE.md`). La
verificación cross-repo restante (rutas, envelope, límites, ausencia de
escrituras, paridad con Web, smoke con el Tótem) queda integrada en
`TOTEM-BFF-06` de `teatromuseo-totem-ci4/TASKS.md`; no se crea una segunda
tarea BFF para duplicar ese gate.

### Soporte de request condicional (`ETag`/`If-None-Match`) en `public-read` (2026-08-19, pointer)

Fuente de verdad:
[`../docs/plan/2026-08-19-plan-totem-endurecimiento-post-bff.md`](../docs/plan/2026-08-19-plan-totem-endurecimiento-post-bff.md)
(Fase 4, `TOTEM-BFF-12` en `teatromuseo-totem-ci4/TASKS.md`). Cada envelope de
`public-read` ya calcula `meta.source_revision` (hash de contenido, ver
`PublicReadCollectionItemReader::revision()`), pero no está conectado a
ningún short-circuit `304 Not Modified` — cada llamada, incluso un resync
sin cambios reales tras un apagón, paga el costo completo de query +
serialización. Afecta tanto a Web como al Tótem (ambos consumidores de
`public-read`), por lo que no se ejecuta como cambio solo-tótem — queda
pendiente de triage propio en este repo. No se abre como tarea numerada
todavía porque no hay decisión de diseño tomada (¿`ETag` HTTP estándar vs.
un parámetro `?since_revision=` explícito en el envelope?).

### Lecturas compuestas del Admin vía BFF (2026-08-16) — ver `../docs/plan/2026-08-16-plan-admin-lecturas-compuestas-via-bff.md`

El seam `AdminRead` ya cubre dashboard, analytics, traducciones, usos de
archivos, lookups de Event y bootstraps/workspaces CMS (todas las Features
1-5 cerradas — ver `TASKS_ARCHIVE.md`). Regla vigente para trabajo futuro:
las nuevas pantallas del Admin deben reutilizar esos contratos o crear un
módulo profundo dedicado; no se agregan ramas genéricas a un bootstrap
existente. CMS bootstrap solo se reabre con evidencia runtime nueva. Fuente
arquitectónica:
[ADR-010](docs/adr/010-hosting-constrained-bff-read-architecture.md).

### El BFF resuelve la página pública completa — cerrado 2026-08-15

El BFF compone routing + layout + bloques de una página en una sola
respuesta (`page-resolve`), reemplazando hasta 2 llamadas HTTP paralelas de
`teatromuseo-web` por una. Enmienda ADR-004 §6 vía
[ADR-008](../docs/adr/008-bff-full-page-resolution.md). Detalle de cierre en
`TASKS_ARCHIVE.md`.

### BFF de lectura directa a 4 BDs — cerrado 2026-08-14

Cuatro conexiones `SELECT`-only propias por dominio, sin paquetes Composer
compartidos (decisión de diseño explícita: un solo consumidor final no
justifica publicar un paquete). Ver
[`../docs/plan/2026-08-13-plan-bff-completo.md`](../docs/plan/2026-08-13-plan-bff-completo.md)
y `TASKS_ARCHIVE.md` para el detalle de cierre.

## 🏗️ Contratos preservados

- Stateless por defecto; no decodifica JWT localmente.
- Reenvía `Authorization` y delega introspección al Hub.
- Los controladores de proxy/aggregate usan `proxy()`/`aggregate()` y no
  reinventan forwarding.
- **Excepción acotada (2026-08-13):** `app/PublicRead/**` y `app/AdminRead/**`
  pueden abrir conexiones de solo lectura vía `BaseConnection` — nunca
  `Model`, nunca escritura, nunca fuera de esos directorios. Ver
  [ADR-010](docs/adr/010-hosting-constrained-bff-read-architecture.md).
