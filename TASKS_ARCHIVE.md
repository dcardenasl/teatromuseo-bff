# TASKS_ARCHIVE — teatromuseo-bff

## ✅ Cierres 2026-08-13..19 — archivados 2026-08-20

### Robustez de contrato localizada (BFF-CONTRACT-01/02/03, BFF-ADMINREAD-16)

Cerradas 2026-08-15/19. `PublicPagePaths` exporta el contrato versionado de
rutas comparado por CI contra `teatromuseo-web`; los lectores SQL-first de
Event/Catalog no aplican filtro de locale en detalles; matriz PHP 8.2–8.5
verificada con `composer check-platform-reqs`. El lector de usos de archivos
pasó a consumir el snapshot autorizado del Hub sin segunda lectura CMS.
Verificado con `composer quality` (291 tests, 1.005 assertions).

### Tótem vía BFF — baseline + campos + CMS-EDITOR-03 (BFF-TOTEM-01/02/03)

Cerradas 2026-08-18/19. `WebAppKeyRequiredFilter` acepta múltiples llaves
confiables (`web`/`totem`) resueltas en `PublicReadCallerContext`, nunca por
parámetro del cliente; `show_in_totem` y `with_counts` curan el catálogo solo
para el llamador `totem`. Se expusieron `last_occurrence_at` en eventos y
`DETAIL_FIELDS` vía `?fields=` en catálogo. `CmsWorkspaceProjectionQuery`
filtra block types por owner y proyecta solo con el permiso de lectura
correspondiente. Verificado con `composer quality` (282–292 tests según
paso).

### AdminRead — analytics + extensión de `/me/admin-dashboard` (BFF-ADMINREAD-05/06/07/08)

Cerradas 2026-08-17. `CmsAnalyticsDashboardSource`/`CmsAnalyticsSource`
completa (overview, top pages, referrers, devices, timeseries) con períodos
cerrados y límites server-side; `/me/admin-dashboard` entrega
`analytics`/`translations` como fuentes independientes vía
`effectivepermissionsauth`; `GET /api/v1/me/admin-analytics` con
`introspectauth` (una sola aplicación). Verificado con `composer quality`
(201→211 tests según paso).

### Robustez Web+BFF (WEB-BFF-NAV-01, BFF-ROBUST-01/02/03)

Cerradas 2026-08-15/16. `PageEnvelope` resuelve destinos de menú opcionales
(`custom_url`/`is_clickable`) sin enlaces falsos; `PublicReadLayoutReader`/
`PublicReadPageBootstrapReader` renombrados a
`LayoutCompositionReader`/`PageBootstrapCompositionReader`; `MediaHydrator`/
`PublicReadPagination` extraídos a `PublicRead/Support`; `aggregate()`
documentado como secuencial/fail-fast por diseño.

### Dashboard vía BFF — cimientos (BFF-DASH-01..06, BFF-ADMINREAD-01..04/15, INFRA-ROBUST-01)

Cerradas 2026-08-15/16. `DomainClient::get()` para lecturas JSON
autenticadas; `aggregatePartial()`; `GET /api/v1/me/admin-dashboard`
(agregador introspectado Hub/CMS/Catalog/Event, clientes de dominio
aislados); seam `app/AdminRead/**` documentado en ADR-009, aislado de
`PublicRead`, sin modelos/escrituras/JWT; contexto de permisos efectivos
canónico vía Hub `/auth/me`. Modelo de despliegue (hosting/cPanel, FTPS)
confirmado sin necesidad de Dockerfile productivo. Verificado con
`composer test:unit` (155 tests/464 asserts), `composer quality`
(198 tests/614 asserts) y smoke HTTP autenticado real (200, cuatro fuentes
`ok`).

### El BFF resuelve la página pública completa (BFF-PAGE-01..09)

Cerradas 2026-08-14/15. `PageResolver` (routing: redirect → homepage/CMS →
aliases → `not_found`), `related()` de entradas, índice de respaldo de
colección, `BlockTreeResolver` (bloques sin oleadas HTTP), `PageEnvelope` +
`PageResolutionController` + ruta única `page-resolve`, preview HMAC
extendido a bloques, y Fase 3 (retiro del HTTP público de
`layout`/`page-bootstrap`, colaboradores internos conservados). Paridad real
BFF/Web sobre `home` (`page_equal`/`layout_equal`/`block_context_equal`/
`meta_equal`/`source_state_equal` en `true`, excluyendo solo
`generated_at`). Quality final: 166 tests / 472 assertions, PHPStan 0
errores.

### BFF de lectura directa a 4 BDs (BFF-DB-01..10)

Cerradas 2026-08-13/14. Cuatro conexiones `SELECT`-only (`cms_readonly`,
`catalog_readonly`, `event_readonly`, `hub_readonly`), `WebAppKeyRequiredFilter`,
controladores/rutas `PublicRead` propios por dominio (sin paquetes Composer
compartidos — decisión de diseño #5), paridad de tipos JSON
(`numberNative=false`), `?fields=` sin colapsar a `503`, contrato completo de
listados CMS (`filter_by`/`order_by`/`include=listing_content.*`) y preview
firmado de páginas portado al bootstrap/detalle. Verificado contra MySQL
Docker real: `200` con 12 entradas y las 7 claves de `listing_content`,
`401` sin `X-App-Key`, `422` en fecha inválida; `/health`/`/ready` en `200`
con las 4 conexiones `healthy`. `composer quality`: 145 tests / 386
assertions, StatelessArchitectureTest verde.

### AdminRead — usos de archivos cross-domain (BFF-ADMINREAD-09/10/11)

Cerradas 2026-08-17. `AdminFileUsageSource` combina Hub autenticado + CMS
`SELECT`-only con dedupe estable por `(source, resource, resource_id, role)`
y preferencia por `context`; `GET /api/v1/me/admin-files/{fileId}/usages`
vía `effectivepermissionsauth`, nunca presenta un resultado parcial como
completo. Verificado con `composer quality` (218 tests, 714 assertions).

### AdminRead — lookups de Event + bootstraps CMS + telemetría (BFF-ADMINREAD-12/13/14/15, BFF-OBS-01)

Cerradas 2026-08-17. `EventAdminLookupSource` (5 contextos, caché 30s por
contexto+scope); `GET /api/v1/me/admin-event-lookups/{context}` vía
`effectivepermissionsauth`; bootstraps CMS aprobados tras medición runtime
(`entry-form-options`, `page-form-options`, `menu-editor-bootstrap`,
`site-identity-bootstrap`, `entry-workspace`, `wizard-bootstrap`);
telemetría operacional (request id, ruta, estado, duración, hit/miss de
caché, sin payloads/tokens). Verificado con `composer quality`
(241 tests, 819 assertions).

### INFRA-ROBUST-02 — Aplicación de configuración del host

Diferida y descartada el 2026-08-16 por falta de control sobre el hosting
(la cuenta expone PHP Selector pero no `opcache.*`/`pm.max_children`); no se
añadieron configuraciones especulativas.

---

## ✅ Cierres previos — archivados 2026-08-10

- `CFG-06`: instalación de `pre-push` cableada en Composer y verificada en un
  checkout fresco.
- `CORE-03`: `Config/Api.php` pasó a extender `ci4-api-core`.
- Corrección de `CorsHeadersTest` dependiente del entorno.
- Forwarding de headers de webhooks, throttling desde el core, propagación de
  `app_id`, soporte multi-domain y generador de proxies.

El repositorio sigue congelado; cualquier trabajo futuro requiere una nueva
decisión de activación.
