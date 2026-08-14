# TASKS — teatromuseo-bff

> Este repositorio estuvo **congelado a propósito** desde el 2026-08-05 por no
> tener consumidores en el workspace. Se reactiva el 2026-08-13 con un plan
> propio: agregación por lectura directa a las bases de datos de los 3 dominios
> (CMS, Catalog, Event) más el Hub (solo metadata de archivos), para que
> `teatromuseo-web` toque un solo proceso PHP por página en vez de hasta 4. Ver
> [`../docs/plan/2026-08-13-plan-bff-completo.md`](../docs/plan/2026-08-13-plan-bff-completo.md)
> (plan completo) y su predecesor
> [`../docs/plan/2026-08-13-plan-bff.md`](../docs/plan/2026-08-13-plan-bff.md)
> (piloto original, solo CMS). Los cierres anteriores están en
> [`TASKS_ARCHIVE.md`](TASKS_ARCHIVE.md).

## ✅ Completadas

- [x] **BFF-DB-09 — Errores de entrada no se convierten en `503`.** Cerrada
  durante la revisión de robustez: los `ValidationException` de los DTO de
  PublicRead ahora conservan el contrato estándar `422` con `errors`, y
  `?fields=` rechaza campos desconocidos con la lista `allowed` en vez de
  descartarlos silenciosamente. Se agregaron regresiones de formato de fecha,
  rango invertido y fieldset inválido; smoke HTTP real confirmado con `422` y
  una consulta válida de Events en `200`.

- [x] **BFF-DB-07 — Migrar `app/PublicRead/**` a implementación propia.**
  Cerrada en esta sesión conforme a la revisión de diseño #5: se portaron las
  clases de lectura verificadas desde los cuatro paquetes transitorios a
  `app/PublicRead/Support`, `Cms`, `Catalog` y `Event`, cambiando namespaces e
  imports a `App\PublicRead\...` sin reescribir las consultas. Se omitió el
  adaptador HTTP `HttpFileMetaResolver` porque el BFF solo tiene la
  implementación `DirectDbFileMetaResolver`; mantenerlo sería código sin
  consumidor. Se eliminó `recordHit()` del resolver de redirects para
  preservar el contrato SELECT-only y se añadió la regresión arquitectónica
  que bloquea operaciones de escritura por builder. Se retiraron del
  `composer.json` los cuatro path-repos y requisitos; `composer update
  --minimal-changes` eliminó los cuatro paquetes del lock y `vendor` (solo
  actualizó CodeIgniter 4.7.3→4.7.4). El path-repo existente de
  `ci4-api-core` quedó no canónico para que su requisito estable pueda resolver
  el paquete publicado durante un update limpio. Las carpetas físicas de
  `/Users/davidcardenas/Developer/PHP/ci4-platform/` se conservaron porque los
  tres dominios aún las declaran. Verificado: `composer quality` verde,
  PHPStan sin errores, StatelessArchitectureTest verde, 145 tests / 380
  assertions y CS-Fixer limpio. El smoke HTTP real contra Docker encontró que
  los tres DTO de listado se estaban construyendo sin `ValidationInterface`;
  se corrigió el wiring para usar `RequestDtoFactory`, se hizo compatible el
  DTO de entries con la inyección y se cubrió con
  `PublicReadRequestDtoFactoryTest`. El smoke repetido quedó en 200 para CMS
  páginas, Catalog listado (total 0 por `deleted_at` real de sus cuatro
  filas), Events listado y los detalles CMS/Event; el filtro sin `X-App-Key`
  devuelve 401. Los cuatro probes de DB devuelven `healthy`.
  `EXPLAIN` real: CMS resuelve `pt` sobre 128 filas y `p` por `PRIMARY`,
  Catalog evalúa 4 filas locales (todas `deleted_at`), y Events usa
  `idx_occurrences_public_read` sobre 639 ocurrencias antes de unir por
  `PRIMARY` a `events`.

- [x] **BFF-DB-08 — Paridad de tipos JSON con los dominios legacy.** Cerrada
  durante el gate de Fase 2: las cuatro conexiones MySQL nombradas usan
  `numberNative=false`, igual que los dominios, para que los escalares
  numéricos anidados que el lector no normaliza explícitamente conserven el
  contrato JSON existente. Se agregó `DatabaseReadOnlyConfigTest` para evitar
  regresiones. Verificado con el detalle real de Event: respuesta normalizada
  byte a byte idéntica al dominio.

- [x] **BFF-DB-01 — Provisionamiento de infraestructura Fase 0.** Cerrada
  para el entorno compartido dev/servidor: el contenedor `mysql:8.0` está
  saludable, las cuatro bases existen y los grupos CI4 nombrados conectan con
  `SELECT 1` usando las credenciales disponibles del entorno. No se simulan
  usuarios, grants ni firewall externos: en este despliegue todas las apps
  comparten host y el usuario configurado es el autorizado para dev. Con el
  Hub y las cuatro apps levantadas, `/health` y `/ready` del BFF devolvieron
  `200`; los secretos y credenciales concretos siguen siendo configuración de
  despliegue, no código.

## 🟡 Próximo

### BFF de lectura directa a 4 BDs (2026-08-13) — ver `../docs/plan/2026-08-13-plan-bff-completo.md`

Requiere primero: ADR 007 creada y las cuatro conexiones nombradas verificadas
en el entorno compartido. **Sin paquetes Composer compartidos** (decisión de diseño
#5, revisada el mismo día): todo `app/PublicRead/**` se escribe directo en
este repo, informándose del código hoy vigente de cada dominio
(`teatromuseo-cms-domain`/`teatromuseo-catalog-domain`/`teatromuseo-event-domain`)
como referencia de lectura, no como dependencia de Composer — esos dominios no
publican ningún paquete nuevo en `ci4-platform/` para esto.

> ⚠️ **`BFF-DB-02..05` (abajo) se completaron consumiendo paquetes Composer en
> `ci4-platform/`** (`ci4-public-read-core` + uno por dominio), bajo el diseño
> original de este plan. Revisión de diseño 2026-08-13 (decisión #5): con un
> solo consumidor final (este repo), esos paquetes no aportan nada — se
> retiran a favor de una implementación propia en `app/PublicRead/**`. Ver
> `BFF-DB-07`, que reconcilia lo ya construido con el diseño final.

- [x] **BFF-DB-02 — Habilitar conexión de lectura y filtro `webappkey`.**
  Agregar 4 grupos nuevos a `app/Config/Database.php` (aditivo, no toca
  `default`); modificar `tests/Unit/Architecture/StatelessArchitectureTest.php`
  con excepción acotada a `app/PublicRead/**` solo para
  `db_connect`/`db_config`/`db_connection` (NO para `use_model`/`extends_model`/
  `model_helper`, que siguen prohibidos ahí también); agregar
  `App\Filters\WebAppKeyRequiredFilter` (mismo patrón de los 3 dominios) +
  alias en `Config\Filters`; agregar los 4 `repositories` path-repo en
  `composer.json`. Ver sección "Cambios en `teatromuseo-bff`" del plan.
- [x] **BFF-DB-03 — Controlador/rutas PublicRead de CMS.** Nuevo
  `app/PublicRead/` con factories en `Config\Services` para
  `cmsReadDb()`/lectores de CMS; `CmsPublicReadController` bajo
  `app/Controllers/Api/V1/PublicRead/`; rutas espejando exactamente
  `public-read/{locale}/layout`, `.../page-bootstrap/{path}`,
  `.../entries/{key}`, `public/{locale}/categories/{key}`,
  `public/{locale}/tags/{key}`, `public/{locale}/forms/{key}`. Depende de
  `CMS-PR-01..05` en `teatromuseo-cms-domain`. También se añadió la ruta
  compatible `public/{locale}/pages/by-type/{type}` para las plantillas
  singleton que todavía consume el Web.
- [x] **BFF-DB-04 — Controlador/rutas PublicRead de Catalog.** Análogo a
  BFF-DB-03 para `catalogReadDb()`, `hubReadDb()` +
  `DirectDbFileMetaResolver`, `CatalogPublicReadController`, rutas
  `public-read/{locale}/collection-items[/{id}]`, `public/catalog/categories`.
  Depende de `CAT-PR-01..02` en `teatromuseo-catalog-domain` y `API-PR-01` en
  `teatromuseo-api`.
- [x] **BFF-DB-05 — Controlador/rutas PublicRead de Event.** Análogo a
  BFF-DB-04 para `eventReadDb()`, `EventPublicReadController`, rutas
  `public-read/{locale}/events[/{id}]`, `public/events/types`. Depende de
  `EVT-PR-01..02` en `teatromuseo-event-domain` y `API-PR-01`.
- [x] **BFF-DB-06 — Healthcheck de las 4 conexiones.** Extender
  `healthChecker()`/`HealthController` para verificar `cms_readonly`,
  `catalog_readonly`, `event_readonly`, `hub_readonly`. Verificar en ejecución
  que ningún path de BFF-DB-03..05 colisiona al vivir bajo una sola base URL.
- **Nota de verificación:** el healthcheck y las rutas están integrados; la
  implementación final quedó separada en los tres controladores por dominio
  exigidos por el plan y `php spark routes` confirmó sus paths sin colisiones,
  todos con `webappkey`, throttle, correlation ID y telemetry. La verificación
  directa contra MySQL Docker quedó ejecutada. Con `start-dev.sh` levantado,
  `/health` y `/ready` devolvieron `200`, y el smoke del Web confirmó que el
  detalle de evento resuelve también `template_event_item` desde el BFF.

## 🏗️ Contratos preservados

- Stateless por defecto; no decodifica JWT localmente.
- Reenvía `Authorization` y delega introspección al Hub.
- Los controladores de proxy/aggregate usan `proxy()`/`aggregate()` y no
  reinventan forwarding.
- **Excepción acotada (2026-08-13):** `app/PublicRead/**` puede abrir
  conexiones de solo lectura vía `BaseConnection` — nunca `Model`, nunca
  escritura, nunca fuera de ese directorio. Ver ADR 007.
