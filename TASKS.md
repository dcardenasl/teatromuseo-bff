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
>
> Las comprobaciones de `layout` y `page-bootstrap` registradas antes de
> BFF-PAGE-09 son evidencia histórica; el contrato HTTP vigente de página
> completa es `page-resolve`.
>
> El saneamiento posterior al corte BFF-only se gestiona en
> [`../docs/plan/2026-08-15-plan-robustez-web-bff.md`](../docs/plan/2026-08-15-plan-robustez-web-bff.md).

## ✅ Robustez de contrato localizada

- [x] **BFF-CONTRACT-01 — Paridad de rutas públicas.** Cerrada 2026-08-15.
  `PublicPagePaths` exporta el contrato versionado de rutas y aliases; el CI
  lo compara contra `teatromuseo-web/docs/contracts/public-routes.json` en
  PHP 8.2. La independencia de despliegue se conserva.

## ✅ Completadas

- [x] **BFF-ROBUST-01 — Nombres de composición interna.** Cerrada
  2026-08-15. `PublicReadLayoutReader` y `PublicReadPageBootstrapReader` se
  renombraron a `LayoutCompositionReader` y `PageBootstrapCompositionReader`;
  se actualizaron el container, el bundle y `PageEnvelope`. No se agregaron ni
  retiraron rutas HTTP.

- [x] **BFF-ROBUST-02 — Soporte compartido de PublicRead.** Cerrada
  2026-08-15. Se extrajeron `MediaHydrator` y `PublicReadPagination` a
  `app/PublicRead/Support/`; Catalog, Event y CMS reutilizan las mismas
  proyecciones, offsets y envelopes sin cambiar el contrato JSON. Verificado
  con `composer quality`: 176 tests, 518 assertions, 2 deprecations y 1
  skipped; PHPStan, CS-Fixer y arquitectura verdes.

- [x] **BFF-ROBUST-03 — Semántica de `aggregate()`.** Cerrada
  2026-08-15. Se dejó explícito en `BaseProxyController` y esta guía que el
  helper es secuencial y fail-fast; no se introduce concurrencia mientras el
  consumidor real tenga una sola llamada upstream.

- [x] **INFRA-ROBUST-01 — Modelo de despliegue confirmado.** Cerrada
  2026-08-15. La evidencia de beta y los artefactos `.deploy` confirman FTP
  sobre hosting/cPanel; el BFF no necesita un Dockerfile productivo para ese
  mecanismo. La creación de `www.conf` y el dimensionamiento de FPM quedan
  fuera de lo que puede decidirse desde el repositorio.

- [x] **BFF-PAGE-05 — Índice de colección de respaldo.** Cerrada
  2026-08-14. `PageResolver` sintetiza `collection_fallback_index` solo para
  una colección sin `index_page`, preservando el shape de Web: título/intro,
  canonical y URLs localizadas, más un bloque `collection_listing` con
  `items_limit=12`, `published_at desc` y variante `cards`. La lectura de
  colecciones se comparte entre el intento de entry y el fallback. Verificado
  con 16 tests / 55 assertions focales, `composer quality` completo (161
  tests / 441 assertions, 1 skip, PHPStan sin errores, CS-Fixer y arquitectura
  verdes). El smoke real de `/es/cartelera` confirmó la precedencia de su CMS
  `cms_page`; el fixture local no contiene una colección sin página CMS para
  ejecutar el caso sintético vía HTTP sin alterar datos.

- [x] **BFF-PAGE-06 — `BlockTreeResolver`.** Cerrada 2026-08-14. Se portó el
  collector recursivo, la traducción de queries por fuente, las dependencias
  CMS colección/categoría de Catalog, facets, forms, detalles sembrados y la
  materialización compatible con Web detrás de `BlockTreeSourceInterface` y
  `PublicReadBlockTreeSource`; no usa `WebApiClient`, oleadas HTTP ni modifica
  `PageDelivery`/snapshots. Cada bloque conserva `ok`, `status`, `data`,
  `meta`, `stale`, `messages` e `instance`, y los fallos quedan aislados.
  Verificado con 3 tests / 18 assertions focales, `composer quality` completo
  (164 tests / 459 assertions, 1 skip, 1 deprecation, PHPStan sin errores,
  CS-Fixer y arquitectura verdes) y smoke read-only real sobre la home CMS:
  los tres bloques dinámicos de Event/CMS devolvieron `200` con 3 elementos
  cada uno.

- [x] **BFF-PAGE-07 — `PageEnvelope` + `PageResolutionController` + ruta.**
  Cerrada 2026-08-14. `GET /api/v1/public-read/{locale}/page-resolve/{route}`
  compone routing, layout y `block_context` en una respuesta; la ruta usa
  `webappkey`, conserva redirects `301/302` y devuelve `404` para
  `not_found`. Verificado con la matriz real `inicio`, `cartelera`,
  `museo/coleccion`, `contacto`, `historia`, `cursos`, una entrada de
  colección, ruta inexistente y redirect `/public/es` — todos los estados
  esperados. La comparación real BFF/Web sobre `home` dejó
  `page_equal`, `layout_equal`, `block_context_equal`, `meta_equal` y
  `source_state_equal` en `true`, excluyendo únicamente `generated_at` y la
  normalización intencional `source_page_type` + `page_type=cms_page`.
  `composer quality`: 164 tests / 459 assertions, 1 skip, 1 deprecation,
  PHPStan sin errores, CS-Fixer y arquitectura verdes. Commit de paridad:
  `579a6a0`.

- [x] **BFF-PAGE-08 — Preview extendido a bloques.** Cerrada 2026-08-14.
  El preview HMAC verificado por el controlador ahora se propaga a la
  resolución de entries y a los bloques CMS (`BlockTreeResolver` →
  `PublicReadEntryReader`), que omiten los filtros de publicación solo en el
  camino firmado; Catalog/Event y forms permanecen fuera del preview CMS.
  Verificado con 2 pruebas nuevas de forwarding, 21 tests / 86 assertions
  focales del routing/bloques, quality BFF completo (166 tests / 472
  assertions, 1 skip, 1 deprecation, PHPStan/CS-Fixer/arquitectura verdes) y
  smoke HTTP real: plantilla CMS `template_event_item` visible con firma
  válida (`page`, `cms_page`, `source_page_type=template_event_item`) y
  `not_found` con firma inválida.

- [x] **BFF-PAGE-04 — Entrada de colección + `related()`.** Cerrada
  2026-08-14. `PageResolver` ahora identifica el prefijo localizado de una
  colección, resuelve la entrada por slug y adjunta `collection` y
  `related_entries`. `PublicReadEntryReader::related()` porta el algoritmo de
  Web: primera pasada por categoría, relleno genérico acotado, exclusión del
  elemento actual y deduplicación estable; usa el mismo builder SQL y la
  proyección existente. La proyección localizada también queda disponible
  como `localized` para las tarjetas públicas. Verificado con 15 tests / 47
  assertions focales, `composer quality` completo (160 tests / 433
  assertions, 1 skip, PHPStan sin errores, CS-Fixer y arquitectura verdes) y
  smoke directo contra MySQL local: `show()` `200` para
  `noticias/lanzamiento-del-libro-los-horribles` y `related()` devuelve tres
  slugs distintos sin incluir el actual. La composición final queda verificada
  en BFF-PAGE-07.

- [x] **BFF-PAGE-03 — `PageResolver`: routing sin bloques.** Cerrada
  2026-08-14. Se añadió `PageResolver` con el orden verificable
  redirect → homepage/página CMS → aliases conocidos → `not_found`, usando
  `PublicRedirectResolver` y `PublicReadPageReader` mediante interfaces. La
  homepage puede resolver por tipo `home`; los tipos CMS originales se
  conservan en `source_page_type` mientras el discriminante de entrega pasa a
  `cms_page`. Se portó la matriz de aliases, el redirect legado
  `public/{locale}` y la regla de no tocar destinos externos. Verificado con
  13 tests / 30 assertions focales, `composer quality` completo (158 tests /
  416 assertions, 1 skip, PHPStan sin errores, CS-Fixer y arquitectura
  verdes), y smoke HTTP real del lector existente
  `/api/v1/public-read/es/page-bootstrap/inicio` con `200` contra el stack
  local y datos CMS. La ruta final `page-resolve` y la paridad byte a byte
  quedan cerradas en BFF-PAGE-07.

- [x] **BFF-PAGE-01 — Contrato `page.page_type` y aliases.** Cerrada
  2026-08-14 como Fase 0. La comparación contra
  `teatromuseo-web/app/Controllers/PageController.php`,
  `BasePublicWebController.php`, `PageDeliveryResponse.php` y las vistas
  confirmó tres discriminantes de página válida: `cms_page`,
  `collection_entry` y `collection_fallback_index`; el cuarto desenlace es
  `outcome=not_found` con `page=null`, preservando el contrato existente de
  `PageDeliveryResponse` y evitando publicar un snapshot sintético de 404.
  Las páginas CMS deben conservar además su `source_page_type` original
  (`home`, `events`, `catalog_listing`, `collection_index`, `generic`, etc.)
  porque la capa de presentación lo usa para canonicalización y SEO. Para
  entries la página debe incluir los campos que consume
  `collection/show` —traducción, colección, imágenes, taxonomías, bloques,
  SEO, URLs localizadas— más `related_entries`; el fallback debe conservar el
  shape de `page` + `blocks` que hoy produce `renderFallbackCollectionIndex()`.
  La matriz de canonicalización portada como referencia de lectura cubre:
  homepage (`home`, `inicio`, `accueil` y el segmento localizado), eventos
  (`cartelera`, `events`, `programme`, `eventos`, `programming`,
  `programmation`, `programacao`), catálogo (`museo/coleccion`,
  `museum/collection`, `musee/collection`, `museu/colecao`), contacto
  (`contacto`, `contact`, `contato`), historia (`historia`, `history`,
  `histoire`, `nossa-historia`) y teatroescuela (`cursos`, `teatroescuela`,
  `theaterschool`, `theatreecole`, `escola-de-teatro`). Se conserva también
  el redirect especial de `public/{locale}` a la homepage; los destinos
  externos no se canonicalizan.

- [x] **BFF-PAGE-02 — ADR-008.** Cerrada 2026-08-14. Revisada contra el
  contrato confirmado: documenta el endpoint `page-resolve`, la relación
  1:1 con `PageDeliveryResponse`, `block_context` por bloque y el aislamiento
  `ok`/`error`/`stale` sin convertir un fallo de Catalog/Event en `5xx`. La
  Fase 1 puede reutilizar los lectores existentes sin modificar el sistema
  de snapshots.

- [x] **BFF-DB-10 — Contrato completo de listados CMS en la lectura directa.**
  Cerrada después del smoke real de Fase 2: el BFF ahora acepta y aplica
  `filter_by`, `filter_value`, `filter_operator`, `order_by=field:*`,
  `order_direction=UPCOMING` e `include=listing_content.*` sin volver a
  depender del paquete Composer retirado. Se copió la proyección verificada
  `EntryListingContentResolver` al namespace propio `App\PublicRead\Cms`, con
  filtros y ordenamiento clasificados en SQL y sin interpolar campos arbitrarios;
  la respuesta respeta además `fields` y evita serializar bloques completos
  cuando la Web pide una proyección parcial. Las cuatro declaraciones de
  paquetes fueron retiradas en los repositorios consumidores y las carpetas
  físicas se eliminaron en `PKG-CLEANUP-01` después del grep cross-repo final.
  También se portó el preview firmado de páginas (`CMS_PREVIEW_SECRET`) al
  bootstrap y al detalle, con verificación HMAC en el borde y resolución de
  páginas no publicadas solo cuando el token es válido; sin secreto o con firma
  inválida el BFF cae a `preview=false` y no expone borradores.
  Verificado contra MySQL Docker real: la query exacta de Web devuelve `200`,
  12 entradas y las siete claves de `listing_content`; el filtro exacto devuelve
  `200`, total 1; la selección parcial devuelve solo `image/date_fields` y el
  preview inválido devuelve `200` con `meta.query.preview=false`.
  `X-App-Key` ausente devuelve `401` y fecha inválida `422`.
  `composer quality`: CS-Fixer limpio, PHPStan sin errores, StatelessArchitecture
  verde, 145 tests / 386 assertions / 1 skipped. `/ready` quedó `ready` con
  CMS/Catalog/Event/Hub-files `healthy`; `/health` solo reporta `degraded` por
  el disco del host al 95,78%, no por las conexiones. **Bloqueo operativo
  documentado:** `CMS_PREVIEW_SECRET` está vacío en dev y no se simula; antes
  de retirar el HTTP público CMS en Fase 3 debe configurarse el mismo secreto
  en BFF/CMS/Admin y repetirse una prueba positiva de preview firmado.

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
  el paquete publicado durante un update limpio. Las carpetas físicas
  superseded de `/Users/davidcardenas/Developer/PHP/ci4-platform/` se
  eliminaron en `PKG-CLEANUP-01` después de retirar las declaraciones de los
  tres dominios. Verificado: `composer quality` verde,
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

- [ ] **INFRA-ROBUST-02 — Aplicación de configuración del host.** Fijar
  OPcache o ajustar PHP-FPM únicamente mediante el mecanismo que el proveedor
  cPanel confirme como efectivo. No se resuelve con un Dockerfile local ni
  con un `www.conf` que el hosting no cargue. Ver el
  [`plan detallado`](../docs/plan/2026-08-15-plan-robustez-web-bff.md).

### El BFF resuelve la página pública completa (2026-08-14) — ver `../docs/plan/2026-08-14-plan-bff-page-resolution.md`

Extiende la lectura directa (abajo, ya cerrada) al objetivo final: el BFF
compone routing + layout + bloques de una página en una sola respuesta, para
que `teatromuseo-web` haga una sola llamada HTTP por página en vez de hasta 2
en paralelo. Enmienda ADR-004 §6 una tercera vez vía ADR-008
(`../docs/adr/008-bff-full-page-resolution.md`). Reutiliza tal cual los
lectores ya construidos (`LayoutCompositionReader`, `PageBootstrapCompositionReader`,
lectores de Catalog/Event, `DirectDbFileMetaResolver`) — no los reescribe.

- [x] **BFF-PAGE-09 — Fase 3: retiro del HTTP público de `layout`/
  `page-bootstrap`.** Cerrada 2026-08-15 después del gate de estabilidad de
  Web. Se retiraron ambas rutas y el controlador ya no las expone; los
  lectores `LayoutCompositionReader` y `PageBootstrapCompositionReader` se
  conservan únicamente como colaboradores internos de `PageResolver`. El
  contrato público único de composición es ahora `page-resolve`. Verificado
  con quality completo y smoke posterior al despliegue: endpoints retirados
  devuelven `404`, mientras `page-resolve` y las páginas beta permanecen
  operativos.

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
