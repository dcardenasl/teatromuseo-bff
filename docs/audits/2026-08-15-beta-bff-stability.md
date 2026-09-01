# Auditoría: estabilidad BFF/Web en beta

> Nota de corte: las comprobaciones de `layout` y `page-bootstrap` que siguen
> son evidencia histórica del pre-cutover del 2026-08-15. Desde BFF-PAGE-09
> ambas rutas HTTP están retiradas; el contrato público vigente es
> `page-resolve`, verificado en la sección de despliegue posterior.

## Objetivo

Confirmar que `beta.teatromuseo.cl` resuelve páginas públicas mediante el BFF,
que el BFF puede leer las bases de datos públicas y que la autorización
Web→BFF sigue alineada después del cutover de la Fase 3 del plan de resolución
de páginas.

## Entorno

- Web: `https://beta.teatromuseo.cl`
- BFF: `https://bff.teatromuseo.cl`
- Rutas verificadas: `home`, `contacto`, `teatroescuela`, `cartelera`,
  `museo/coleccion`, una entrada CMS, una ficha de evento y una muestra
  distribuida del sitemap.
- La bitácora no contiene claves, tokens ni configuración sensible.

## Registro del proceso

### 2026-08-15 — diagnóstico inicial

- `/health` de beta devolvía `500` y las páginas públicas no cargaban.
- El `.env` remoto de Web no contenía las variables obligatorias
  `WEB_TRACKING_API_BASE_URL`, `BFF_API_BASE_URL` y `BFF_API_KEY`.
- Se agregaron las variables faltantes sin exponer sus valores.

### 2026-08-15 — autorización Web→BFF

- Tras corregir el arranque, el BFF respondía `401 Unauthorized` al usar la
  clave configurada en Web.
- La misma consulta respondía `200` al usar la clave efectiva del BFF.
- Comparación interna: las claves Web/BFF no coincidían.
- Se alineó únicamente `BFF_API_KEY` del `.env` remoto de Web con la clave del
  BFF.

## Hallazgos

### Hallazgo 1 — datos de base de datos disponibles (pre-cutover)

- `page-resolve/contacto` respondió `200` con una página CMS.
- `page-resolve/teatroescuela` respondió `200` con una página de colección.
- `layout` respondió `200` con navegación, colecciones y settings (pre-cutover).
- Conclusión: no era una ausencia de registros ni un fallo de conexión a las
  bases de datos del BFF.

### Hallazgo 2 — desalineación de credenciales

- Síntoma: Web mostraba `404` para páginas que existían.
- Causa confirmada: el BFF rechazaba la petición Web con `401`; Web trataba el
  resultado no resoluble como `404`.
- Corrección: alinear `BFF_API_KEY` en la configuración remota de Web.

## Evidencia posterior a la corrección

- BFF `page-resolve/home`: `200`.
- BFF `page-resolve/contacto`: `200`.
- BFF `page-resolve/teatroescuela`: `200`.
- BFF `page-bootstrap/contacto`: `200`, página presente (pre-cutover).
- BFF `page-bootstrap/teatroescuela`: `200`, página presente (pre-cutover).
- BFF `layout`: `200`, datos de layout presentes (pre-cutover).
- Beta `/health`: `200`.
- Beta `/es`: `200`.
- Beta `/es/contacto`: `200`.
- Beta `/es/teatroescuela`: `200`.
- Beta `/es/cartelera`: `200`.
- Beta `/es/museo/coleccion`: `200`.

### Preview y observabilidad

- Se configuró un secreto aleatorio de 64 caracteres, idéntico en los `.env`
  remotos de BFF, CMS y Admin. El valor no se registró.
- `page-bootstrap/contacto` con firma válida respondió `200` y marcó
  `meta.query.preview=true`.
- La misma ruta con firma inválida respondió `200` pero marcó
  `meta.query.preview=false`, demostrando el cierre seguro del camino no
  autorizado. La prueba usó una página publicada; no se creó ni modificó un
  borrador en producción.
- El diagnóstico protegido de Web confirmó `200`, cache probe `passed` y
  bases CMS/Catalog/Event `healthy`.

### Corrección adicional del contrato de health

- El diagnóstico de Web consultaba `/api/v1/health`, pero el BFF solo exponía
  el mismo contrato en `/health`; la ruta versionada devolvía `404`.
- Se cargó el mismo archivo de rutas de health en raíz y bajo `/api/v1`, con
  una regresión específica. Commit `8e3282e`; se desplegó únicamente
  `app/Config/Routes.php`.
- Verificación remota: `/health`, `/api/v1/health`, `/ready` y
  `/api/v1/ready` devuelven `200`.

### Canary de estabilidad

- Cinco iteraciones consecutivas de home, contacto, TeatroEscuela, Cartelera y
  Catálogo devolvieron `200` con SHA-256 estable dentro de cada ruta.
- Una segunda ronda de tres iteraciones confirmó tamaños estables:
  home `87361 B`, contacto `50160 B`, TeatroEscuela `101575 B`, Cartelera
  `109338 B` y Catálogo `44328 B`.

### 2026-08-15 — cutover completo Web→BFF

- Se desplegaron los lectores de detalle y la composición de página del BFF,
  junto con los controladores de detalle de Event y Catalog del Web.
- El BFF reutiliza la plantilla singleton `template_event_item` o
  `template_catalog_item` y entrega el detalle como contexto presembrado; el
  Web renderiza el envelope sin volver a consultar el dominio ni la plantilla.
- Todas las rutas públicas localizadas quedaron bajo `page-resolve`. Las rutas
  fuera del manifest de snapshots continúan siendo síncronas y no generan
  snapshots ilimitados.
- La invalidación posterior al despliegue devolvió `200`, invalidó 17
  snapshots y eliminó 39 respuestas HTML registradas para `es`.
- El canario final devolvió `200` para nueve rutas públicas: home, contacto,
  TeatroEscuela, Cartelera, catálogo, nosotros, historia, una entrada CMS y
  una ficha de evento. Cada una registró exactamente una llamada Web→BFF al
  endpoint `page-resolve`; el runtime no preserva siempre `X-Request-ID`, por
  lo que la correlación operativa usa `request_path` y el último evento
  exitoso.
- El sitemap español contiene 842 URLs. Una muestra serial de 32 URLs devolvió
  32/32 `200`; la auditoría de telemetría encontró 32/32 resoluciones BFF
  exitosas. La muestra incluyó 14 fichas de Cartelera, entradas CMS,
  compañías, vídeos y TeatroEscuela. No se afirma un crawl completo de las
  842 URLs.
- El listado de catálogo real respondió `200` con colección vacía; el
  identificador `TMP-001` usado por la prueba hermética no existe en beta y
  su `404` es el resultado esperado, no una falla de despliegue.

### 2026-08-15 — retiro de endpoints legacy

- `GET /api/v1/public-read/es/layout`: `404` esperado.
- `GET /api/v1/public-read/es/page-bootstrap/contacto`: `404` esperado.
- `GET /api/v1/public-read/es/page-resolve/contacto`: `200`.
- Las páginas beta siguen devolviendo `200` y el Web mantiene una sola llamada
  BFF por página pública.

### 2026-08-15 — verificación posterior al último deploy

- El deploy incremental de BFF y Web terminó correctamente por FTP; se
  actualizaron las rutas/controladores del BFF y el adaptador, cliente,
  renderizadores y controladores públicos del Web.
- Beta `/health`, `/es`, `/es/contacto` y `/es/cartelera` devolvieron `200`.
- Las rutas retiradas continuaron devolviendo `404` después del deploy.
- La prueba directa de `page-resolve` contra `bff.teatromuseo.cl` con las
  claves BFF locales disponibles devolvió `401`; esas claves no son la fuente
  autoritativa del hosting y no se registraron sus valores. El Web, después de
  purgar caché, siguió devolviendo `200`, por lo que su configuración remota sí
  está autorizando el flujo público.
- `GET /cache/status` respondió `200` y la invalidación posterior al deploy
  respondió `200`: se invalidaron 50 snapshots y se eliminaron 3.236 respuestas
  de caché para los scopes públicos solicitados.

## Gates de calidad

- BFF `composer quality`: `170` tests, `504` assertions, salida `0`; PHPStan,
  CS-Fixer y arquitectura sin errores. PHPUnit reportó una deprecación y un
  test omitido ya conocidos por la suite.
- Web `composer quality`: `372` tests, `1.378` assertions, salida `0`; PHPStan,
  CS-Fixer, i18n y fixture policy sin errores. La suite reportó cinco tests
  omitidos ya conocidos.
- Ambos gates se ejecutaron con PHP `8.5.5`; CS-Fixer mostró la advertencia
  informativa de que el proyecto soporta PHP mínimo `8.2`, sin detectar cambios
  ni fallos.

## Próximos gates

1. Mantener la ventana de estabilidad con los canarios y la telemetría de
   `page-resolve`.
2. Repetir, si se requiere una garantía estadística mayor, el muestreo del
   sitemap sin convertirlo en un crawl permanente de producción.

## Automatización pendiente

- Agregar a los smoke/deploy checks una validación no sensible que confirme que
  Web y BFF aceptan la misma aplicación autorizada, evitando que una
  desalineación de `BFF_API_KEY` se manifieste como un `404` de contenido.
