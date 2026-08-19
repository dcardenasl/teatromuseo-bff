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
- [x] **BFF-CONTRACT-02 — Slugs completos de detalle.** Cerrada 2026-08-15.
  Los lectores SQL-first de Event y Catalog no aplican el filtro de locale
  para detalles, incluido `fields=[]`; los listados no cambian su proyección.
  Las pruebas rápidas usan el builder SQL real sobre SQLite y CI añade una
  suite MySQL 8 efímera mediante `composer test:integration`.
- [x] **BFF-CONTRACT-03 — Compatibilidad de plataforma.** Cerrada 2026-08-15.
  La matriz PHP 8.2–8.5 se mantiene y el CI verifica explícitamente
  `composer check-platform-reqs --no-dev`.

## ✅ Completadas

- [x] **BFF-TOTEM-01 — Identidad de llamador confiable + curación de kiosco
  en public-read.** Cerrada 2026-08-18. Parte del plan cross-repo para migrar
  `teatromuseo-totem-ci4` del proxy Hub que nunca se implementó
  (`/api/v1/totem/*`) al seam `public-read` que ya usa `teatromuseo-web` (ver
  ADR-010). `WebAppKeyRequiredFilter` ahora acepta múltiples llaves
  confiables (`BFF_API_KEY`/`WEB_API_KEY` → `web`, `TOTEM_BFF_API_KEY` →
  `totem`) sin agregar rutas nuevas, registrando el llamador resuelto en
  `App\Support\PublicReadCallerContext` (nunca confía en un parámetro del
  cliente). `PublicReadCollectionItemReader` aplica
  `collection_items.show_in_totem` solo cuando el llamador es `totem` — Web
  sigue viendo todo lo publicado. `CatalogFacetReader::categories()`/
  `techniques()` ganan `with_counts`, con el mismo predicado de curación,
  para reemplazar el antipatrón de traer toda la colección solo para
  chequear un booleano. Verificado con `composer quality` (292 tests,
  1009 asserts, 1 skip).

- [x] **CMS-EDITOR-03 — Contrato BFF de lectura editorial.** Cerrada
  2026-08-18. `CmsWorkspaceProjectionQuery` filtra block types por owner y
  proyecta idiomas, colecciones, páginas, entries y forms sólo con el permiso
  de lectura correspondiente; se agregó una regresión de no exposición de
  catálogos. Verificado con `composer quality` (282 tests, 986 asserts,
  1 skip).

- [x] **BFF-ADMINREAD-05 — Lector de analytics + adaptación de traducciones.**
  Cerrada 2026-08-17. Se añadió `CmsAnalyticsDashboardSource` con consultas
  `SELECT` explícitas y ventana fija de 7 días, más un adapter BFF→CMS para
  reutilizar el auditor de traducciones con `cms.languages.read`; ambos
  quedan detrás de factories `AdminRead` y sin duplicar la lógica del CMS.
  Verificado con `composer quality` (201 tests, 631 assertions; PHPStan,
  CS-Fixer y arquitectura verdes).

- [x] **BFF-ADMINREAD-06 — Extender `/me/admin-dashboard`.** Cerrada
  2026-08-17. El contrato ahora entrega `analytics` y `translations` como
  fuentes independientes, conserva `sections/source.state` y usa
  `effectivepermissionsauth` porque la proyección cruza Hub/CMS/Catalog/Event;
  `translations` mantiene la llamada autenticada al CMS. Verificado con
  202 tests / 650 assertions, degradación parcial independiente, OpenAPI,
  `php spark routes` y `composer quality` verdes. El smoke HTTP externo se
  intentó con el stack local, pero este entorno aislado no permite conectar al
  proceso PHP local.

- [x] **BFF-ADMINREAD-07 — `CmsAnalyticsSource` completa.** Cerrada
  2026-08-17. Se añadió la proyección completa de overview, top pages,
  referrers, devices y timeseries con periodos cerrados, límites server-side,
  porcentajes y ceros solo tras consultas exitosas. El filtro temporal usa el
  índice `page_views.created_at` ya definido por CMS; no se añadió migración al
  BFF. Verificado con `composer quality` (205 tests, 659 assertions).

- [x] **WEB-BFF-NAV-01 — Contrato de destinos opcionales del menú.** Cerrada
  2026-08-16. `PageEnvelope` delega la resolución a
  `PublicMenuUrlResolver`, publica `custom_url: null` e `is_clickable: false`
  cuando el CMS no define un destino y preserva rutas CMS, rutas conocidas y
  URLs editoriales válidas. Se cubren destinos válidos, ausentes, `#` y datos
  malformados sin conversiones inseguras. Quality BFF quedó verde.
  Fuente: [`../docs/plan/2026-08-15-plan-robustez-web-bff.md`](../docs/plan/2026-08-15-plan-robustez-web-bff.md).

- [x] **BFF-ROBUST-01 — Nombres de composición interna.** Cerrada
  2026-08-15. `PublicReadLayoutReader` y `PublicReadPageBootstrapReader` se
  renombraron a `LayoutCompositionReader` y `PageBootstrapCompositionReader`;
  se actualizaron el container, el bundle y `PageEnvelope`. No se agregaron ni
  retiraron rutas HTTP.

- [x] **BFF-ROBUST-02 — Soporte compartido de PublicRead.** Cerrada
  2026-08-15. Se extrajeron `MediaHydrator` y `PublicReadPagination` a
  `app/PublicRead/Support/`; Catalog, Event y CMS reutilizan las mismas
  proyecciones, offsets y envelopes sin cambiar el contrato JSON. Verificado
  nuevamente el 2026-08-16 con `composer quality`: 181 tests, 535 assertions,
  2 deprecations y 1 skipped; PHPStan, CS-Fixer y arquitectura verdes.

- [x] **BFF-ROBUST-03 — Semántica de `aggregate()`.** Cerrada
  2026-08-15. Se dejó explícito en `BaseProxyController` y esta guía que el
  helper es secuencial y fail-fast; no se introduce concurrencia mientras el
  consumidor real tenga una sola llamada upstream.

- [x] **BFF-DASH-01 — Lectura JSON con bearer en `DomainClient`.** Cerrada
  2026-08-16. Se añadió `get()` para lecturas JSON autenticadas sin alterar
  `forward()`; verificado con test unitario de URL, bearer y envelope.

- [x] **BFF-DASH-02 — Configurar `BFF_DOMAINS` real.** Cerrada 2026-08-16.
  Se configuraron cms/catalog/event en `.env` y `.env.example`; verificado
  con test de parseo y resolución de las tres instancias de `DomainClient`.

- [x] **BFF-DASH-03 — `aggregatePartial()`.** Cerrada 2026-08-16. Se añadió
  la primitiva secuencial con estado por fuente, aislando fallos sin cambiar
  el contrato fail-fast de `aggregate()`; verificado con 3 tests / 10 asserts.

- [x] **BFF-DASH-04 — `GET /api/v1/me/admin-dashboard`.** Cerrada
  2026-08-16. Se añadió el agregador introspectado de Hub/CMS/Catalog/Event,
  con clientes de dominio aislados por instancia para evitar contaminación del
  cliente compartido; tests, `composer quality` y smoke HTTP autenticado real
  devolvieron 200 con las cuatro fuentes `ok`.

- [x] **BFF-DASH-05 — Tests de integración.** Cerrada 2026-08-16. Se cubren
  401 sin token, 401 por introspección inválida, cuatro fuentes sanas,
  degradación parcial y cuatro fuentes caídas; verificado con 5 tests / 25
  asserts.

- [x] **BFF-DASH-06 — Documentación.** Cerrada 2026-08-16. `CLAUDE.md`
  distingue el agregador fail-fast de referencia `/me/dashboard` del
  consumidor real `/me/admin-dashboard` con degradación parcial.

- [x] **BFF-ADMINREAD-01 — Seam administrativo directo y contrato de permisos.**
  Cerrada 2026-08-16. Se documentó ADR-009, se aisló `app/AdminRead/**` de
  `PublicRead` y se mantuvieron modelos, escrituras y JWT fuera del BFF;
  verificado con arquitectura y PHPStan.

- [x] **BFF-ADMINREAD-02 — Lectores directos CMS/Catalog/Event.** Cerrada
  2026-08-16. Se añadieron proyecciones `SELECT`-only, filtros de permisos y
  soft delete con fail-closed ante errores; verificado con 4 tests / 9 asserts.

- [x] **BFF-ADMINREAD-03 — Corte del endpoint Admin.** Cerrada 2026-08-16.
  `/me/admin-dashboard` conserva el Hub autenticado y reemplaza las tres
  llamadas HTTP de dominio por lectores directos, manteniendo el shape y la
  degradación parcial; feature tests verdes.

- [x] **BFF-ADMINREAD-04 — Tests, gates y smoke.** Cerrada 2026-08-16.
  `composer test:unit` (155 tests / 464 asserts), `composer quality` (198 /
  614), `php spark routes` y smoke local de `/health` pasaron.

- [x] **BFF-ADMINREAD-15 — Contexto de permisos efectivos para Admin.** Cerrada
  2026-08-16. `/me/admin-dashboard` usa el contexto canónico de Hub
  `/auth/me` mediante un filtro dedicado, mantiene la `hub.apiKey` propia del
  BFF y entrega permisos cross-app sin duplicarlos; verificado con tests del
  cliente, endpoint, regresión de `/me/dashboard` y `composer quality`.

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

- [x] **BFF-ADMINREAD-08 — `GET /api/v1/me/admin-analytics`.** Cerrada
  2026-08-17. Se añadió la proyección completa CMS en una sola lectura,
  con períodos cerrados, límites server-side, validación de
  `cms.analytics.read`, contrato OpenAPI y respuestas sanitizadas para
  `403/422/503`. Se eligió `introspectauth` porque la seam consulta una sola
  aplicación (CMS) y no necesita permisos efectivos cross-app. Verificado
  con tests de payload/período/permiso/fuente caída, `php spark routes` y
  `composer quality` (211 tests, 683 assertions; PHPStan, CS-Fixer y
  arquitectura verdes; 2 deprecations y 1 skip informativos).

- [x] **BFF-ADMINREAD-09 — Verificar el alcance real antes de codificar.**
  Cerrada 2026-08-17. Se confirmó en código que el Hub agrega
  `cms`/`catalog`/`event` mediante `internal/files/{id}/usage`, normalizando
  las filas a cinco campos y descartando `context`; el CMS conserva ese
  contexto para `block_instance`. También se confirmó que el Admin hacía una
  segunda llamada CMS y `array_merge()` sin deduplicar. El diseño ejecutable
  queda documentado en §13 del plan: merge por
  `(source, resource, resource_id, role)`, preferencia de la variante con
  `context` y `complete=false` ante cualquier fuente caída.

- [x] **BFF-ADMINREAD-10 — `AdminFileUsageSource`.** Cerrada 2026-08-17.
  Se implementó el lector Hub autenticado + CMS `SELECT`-only, con permisos
  antes de consultar, proyección explícita, dedupe estable por
  `(source, resource, resource_id, role)` y preferencia por `context`; cero
  filas solo después de consultas exitosas, sin stale silencioso. Verificado
  con tests SQLite y `composer quality`.

- [x] **BFF-ADMINREAD-11 — `GET /api/v1/me/admin-files/{fileId}/usages`.**
  Cerrada 2026-08-17. El endpoint usa `effectivepermissionsauth` porque cruza
  Hub y CMS, conserva solo las fuentes consultadas, entrega `complete` y
  `source.state`, y nunca presenta un resultado parcial como completo. Tests
  de contrato, vacío, dedupe y fuente caída; `php spark routes` y
  `composer quality` verdes (218 tests, 714 assertions; 2 deprecations y 1
  skip informativos).

## 🟡 Próximo

### Tótem consume Cartelera / TeatroEscuela / Catálogo vía BFF (2026-08-18)

Fuente de verdad:
[`../docs/plan/2026-08-18-plan-totem-via-bff.md`](../docs/plan/2026-08-18-plan-totem-via-bff.md).
El Tótem está llamando `/api/v1/totem/*` en el Hub, pero esas rutas nunca se
implementaron y el cliente convierte 404, timeout y JSON inválido en `[]`.
Este track reutiliza los lectores `PublicRead` existentes del BFF, no crea un
proxy nuevo en el Hub ni modifica el contrato de Web sin regresiones explícitas.

El baseline `BFF-TOTEM-01` (identidad multi-llamador, clave dedicada,
curación `show_in_totem`, `with_counts` y quality) ya está marcado como
cerrado arriba con los cambios locales existentes. La verificación
cross-repo de rutas, envelope, límites, ausencia de escrituras, paridad con
Web y smoke con el Tótem queda integrada en `TOTEM-BFF-06`; no se crea una
segunda tarea BFF para duplicar ese gate.

### Lecturas compuestas del Admin vía BFF (2026-08-16) — ver `../docs/plan/2026-08-16-plan-admin-lecturas-compuestas-via-bff.md`

El seam `AdminRead` ya cubre dashboard, analytics, traducciones, usos de
archivos, lookups de Event y bootstraps/workspaces CMS. Las nuevas pantallas
del Admin deben reutilizar esos contratos o crear un módulo profundo dedicado;
no se agregan ramas genéricas a un bootstrap existente. CMS bootstrap solo se
reabre con evidencia runtime nueva. Fuente arquitectónica: ADR-010. Cada tarea
se ejecuta solo tras mover su feature a `🔴 En progreso` y con el BFF verificado
antes de que Admin empiece a consumirla.

**Feature 1 — Dashboard: widgets de analytics y traducciones completos**


**Feature 2 — Analytics administrativo compuesto**

**Feature 3 — Usos de archivos cross-domain**

El lector y endpoint de esta feature están cerrados arriba; el Admin puede
comenzar su mitad (`ADM-BFF-05/06`).

**Feature 4 — Lookups administrativos de Event**
- [x] **BFF-ADMINREAD-12 — `EventAdminLookupSource`.** Cerrada 2026-08-17.
  Se añadieron cinco contextos cerrados, permisos antes de consultar, columnas
  explícitas con límite 100 y cache de 30 s por contexto + scope de permisos;
  las pruebas unitarias cubren permiso, contexto y cache.

- [x] **BFF-ADMINREAD-13 — `GET /api/v1/me/admin-event-lookups/{context}`.**
  Cerrada 2026-08-17. El endpoint usa `effectivepermissionsauth` porque el
  BFF cruza el límite de aplicación para leer el alcance Event del usuario;
  `introspectauth` devolvía solo el scope propio de la aplicación BFF y
  provocaba `403 event.events.read` aunque el JWT tuviera ese permiso.
  Publica estado `event:ok`, distingue catálogo vacío de fuente caída y cubre
  401/403/422/503. Tras reiniciar el servidor, el smoke real con superadmin
  devolvió `200/source=ok` en los cinco contextos; los catálogos actuales
  contienen eventos/ocurrencias y los catálogos de reservas/tipos están
  vacíos de forma válida. `php spark routes`, tests focales y quality quedan
  verdes.

**Feature 5 — Bootstrap de editores CMS (condicionada a medición)**

- [x] **BFF-ADMINREAD-14 — Medición Fase 0 de las pantallas candidatas.**
  Cerrada 2026-08-17 tras corregir la colisión local que había desplazado CMS
  desde `8190`; la medición runtime válida y las decisiones están en §13 del
  plan raíz.

- [x] **BFF-ADMINREAD-15 — Bootstraps CMS aprobados.**
  Cerrada 2026-08-17. Se agregaron las proyecciones autenticadas
  `entry-form-options`, `page-form-options`, `menu-editor-bootstrap` y
  `site-identity-bootstrap`, además de `entry-workspace` y
  `wizard-bootstrap`, con permisos efectivos, caché corto por scope, pruebas
  de endpoint y pruebas unitarias de composición. BlockInstance comparte el
  workspace para páginas y entradas; Wizard usa su propio bundle.

- [x] **BFF-OBS-01 — Telemetría operacional de lecturas compuestas.** Cerrada
  2026-08-17. El BFF registra request id, ruta, estado, duración, bytes,
  errores y estado de cada fuente; los lectores Admin añaden hit/miss de caché
  sin registrar payloads, tokens ni datos sensibles. Incluye filtro global,
  instrumentación de proxies y `page-resolve`, regresiones unitarias y
  `composer quality` verde (241 tests, 819 aserciones, 1 skip; 2
  deprecations preexistentes).

### Dashboard de Admin como consumidor real del BFF — cerrado 2026-08-16

El BFF ahora expone `/api/v1/me/admin-dashboard` como consumidor real del
dashboard de `teatromuseo-admin`. Conserva `/api/v1/me/dashboard` como ejemplo
canónico de `aggregate()` fail-fast, y la nueva ruta usa `aggregatePartial()`
secuencial para degradar por fuente sin cambiar el contrato existente. El
detalle está en `../docs/plan/2026-08-16-plan-admin-dashboard-via-bff.md`.

- [x] **INFRA-ROBUST-02 — Aplicación de configuración del host.** Diferida y
  descartada el 2026-08-16 por falta de control sobre el hosting. La cuenta
  expone PHP Selector para PHP 8.2 y opciones PHP de usuario, pero no muestra
  `opcache.*` ni `pm.max_children`; no se añadieron configuraciones locales ni
  se harán cambios especulativos. Ver el
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
