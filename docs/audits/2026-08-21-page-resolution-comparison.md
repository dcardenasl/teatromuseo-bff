# Auditoría comparativa: resolución de páginas y límites del hosting

## Objetivo

Comprobar si las optimizaciones recientes hacen más robusta la resolución
pública de TeatroMuseo y comparar evidencia local repetible con los baselines
históricos de Web/BFF. El foco es separar el camino de snapshot fresco del
camino síncrono BFF, porque tienen costos y límites completamente distintos.

## Entorno

- BFF: `teatromuseo-bff`, PHP local `8.5.5`; el proyecto declara compatibilidad
  mínima con PHP `8.2`.
- Web: `teatromuseo-web`, utilizado para revisar snapshot-first y el manifest.
- Hosting de referencia: cPanel compartido, con límites históricos de CPU, I/O
  y Entry Processes documentados en las auditorías del 2026-08-15.
- No se registran claves, tokens, dumps ni valores sensibles.

## Baseline histórico

- BFF antes de la última tanda de optimizaciones: `304` tests, `1.061`
  assertions; quality gate verde.
- Web snapshot-first: matriz histórica válida de `204/204` respuestas `200`
  bajo concurrencias 1–4, con TTFB promedio de `77,5 ms` a `153,8 ms`; esos
  datos corresponden a homepage con snapshot y no son equivalentes a un
  render síncrono BFF.
- La primera matriz concurrente antigua se descartó por error del arnés y por
  respuestas `508`; no se usa como baseline válido.

## Registro del proceso

### 2026-08-21 — preparación

- Se seleccionaron como comparación la suite de calidad, la resolución de
  aliases CMS y el fixture SQLite real de `PublicReadPageReader`.
- Se evita atribuir una mejora local de consultas a un cambio de TTFB del
  hosting sin repetir una ventana operativa limpia.

### 2026-08-21 — verificación y comparación

- BFF quality gate: verde; PHPStan sin errores y `307` tests, `1.069`
  assertions, `1` skipped.
- Web quality gate: verde; PHPStan sin errores, CS Fixer sin cambios,
  contratos e i18n correctos y `370` tests, `1.381` assertions, `5` skipped.
- Laboratorio Docker con host limitado a `768 MiB`, `0,80` CPU, `100` PIDs,
  Apache `MaxRequestWorkers=5` y MariaDB limitado a `256 MiB`/`0,20` CPU.
- Corrida comparativa k6 sobre seis rutas públicas y hasta `10` VUs: `851`
  solicitudes, `0` fallidas, `100%` de checks, p95 `24,6 ms`, p99
  `11,7 s`, máximo `45,8 s`.
- La corrida terminó sin errores HTTP, pero registró en Apache:
  `AH00161: server reached MaxRequestWorkers setting`. Esto confirma que el
  máximo extremo es cola por concurrencia, no una tasa de errores de la
  aplicación.

### 2026-08-21 — investigación de acciones siguientes

- El manifest de snapshots por defecto sólo incluía `home`, `events` y
  `catalog`, aunque el contrato de rutas ya define también `contact`,
  `history` y `theatre_school`. Las tres rutas adicionales son identidades
  canónicas, sin búsqueda libre en la navegación normal, y por tanto son
  candidatas acotadas para snapshot-first.
- La configuración de producción falla cerrada cuando falta un snapshot, pero
  `cache:warmup` podía reportar una composición síncrona como calentada cuando
  el backend de snapshots estaba deshabilitado. Eso podía dejar un despliegue
  con cron "verde" y visitantes recibiendo 503; se seleccionó como corrección
  operativa prioritaria.
- El laboratorio Docker usa deliberadamente `WEB_PAGE_DELIVERY_MODE=sync`
  para medir el peor camino de composición. La validación de snapshot-first
  debe ser una fase separada y explícita; mezclar ambas mediciones ocultaría el
  costo real del warm-up.

### 2026-08-21 — política canónica y warmup seguro

- El manifiesto público por defecto se amplió de tres a seis rutas estables:
  home, events, contact, history, theatre school y catalog.
- La elegibilidad de snapshot quedó limitada a requests canónicos. Cualquier
  variante con query string permanece síncrona hasta que exista, en conjunto,
  una allow-list por ruta, valores finitos, cobertura de warmup e invalidación.
  Esto evita crear identidades de snapshot no publicadas o no acotadas.
- `cache:warmup --strict` ahora falla cerrado cuando el backend de snapshots
  está deshabilitado o una ruta del manifest no puede calentarse. En producción
  con modo snapshot se aplica el comportamiento estricto por defecto; el modo
  síncrono local sigue siendo una comprobación de composición.
- El laboratorio Docker fue alineado con el contrato de la aplicación. El
  warmup se ejecuta como `www-data`, igual que Apache, y el entrypoint prepara
  los directorios de snapshots con permisos explícitos.

### 2026-08-21 — comparación final de tres perfiles

Se repitió el laboratorio con la misma mezcla de seis rutas, 10 VUs máximos y
una etapa de carga de 3m10s, aproximadamente 4m20s contando ramp-up y
ramp-down:

| Perfil | Requests | Error rate | p95 | p99 | Promedio |
|---|---:|---:|---:|---:|---:|
| Síncrono, sin HTML cache | 1.230 | 0% | 35,37 ms | 4.825,71 ms | 315,82 ms |
| Snapshot, sin HTML cache | 1.228 | 0% | 36,59 ms | 4.863,16 ms | 316,82 ms |
| Snapshot + HTML cache | 1.242 | 0% | 23,08 ms | 4.328,48 ms | 305,01 ms |

En los dos perfiles snapshot, el access log del BFF permaneció en seis
requests, correspondientes al warmup: la carga de visitantes no compuso contra
el BFF. La primera ejecución snapshot devolvió 503 porque el warmup se había
ejecutado como `root` y creó rutas `root:root`/`750` ilegibles para `www-data`;
eso fue un defecto del laboratorio, no un resultado de la aplicación. La
ejecución corregida usó `docker exec --user www-data ... php spark cache:warmup
--locale es --force --strict` y obtuvo 100% de checks exitosos.

En la validación final de la imagen se produjo además un fallo transitorio al
invocar warmup mientras el contenedor todavía ejecutaba migraciones y seeders;
Apache aún no escuchaba. El protocolo quedó corregido para exigir el estado
`healthy` antes de calentar, separando readiness de resultados de rendimiento.

El perfil final redujo el p95 aproximadamente 37% frente a snapshot sin HTML
cache, pero el p99 siguió en varios segundos y Apache registró `AH00161` una
vez por perfil. La evidencia separa dos efectos: el snapshot elimina la
composición BFF/DB de la visita, mientras que el HTML response cache reduce el
trabajo PHP repetido; ninguno elimina la cola de cinco workers en misses.

## Hallazgos

1. La robustez funcional es buena: los gates de BFF y Web están verdes y la
   prueba de seis rutas no produjo errores ni respuestas 5xx.
2. El cambio reciente de resolución set-based es correcto y reduce consultas
   repetidas cuando una ruta necesita probar aliases. La prueba unitaria
   SQLite verifica tanto la selección por múltiples candidatos como la
   compatibilidad de los metadatos de una ruta única.
3. El cuello de botella dominante sigue siendo estructural: cinco workers de
   Apache para un host con CPU limitada. En la prueba nueva el p95 se mantuvo
   bajo, pero el p99 y el máximo muestran que una fracción pequeña de usuarios
   puede quedar esperando decenas de segundos.
4. Comparación con la corrida anterior usando datos importados y la misma
   restricción: cron habilitado produjo p95 `21,09 ms` y máximo `41,15 s`;
   cron deshabilitado produjo p95 `21,20 ms` y máximo `41,37 s`. La nueva
   corrida queda en el mismo orden de magnitud: p95 `24,6 ms` y máximo
   `45,8 s`. No hay evidencia de una regresión funcional, pero tampoco de que
   una micro-optimización elimine la cola de workers.
5. Por ruta, `/es/teatroescuela` fue estable (p99 `26,3 ms`, máximo `94,8
   ms`), mientras `/es/cartelera`, `/es/contacto` y `/es/museo/coleccion`
   concentraron outliers de entre `27,6` y `45,8 s`. El patrón es consistente
   con espera compartida bajo saturación, no necesariamente con una consulta
   lenta en cada petición.
6. El uso final observado de MariaDB fue `245,4 MiB / 256 MiB`; por ello no
   conviene aumentar concurrencia en este laboratorio sin revisar primero el
   reparto de memoria. La cifra es una observación final, no un perfil máximo
   durante toda la corrida.
7. Snapshot-only no es suficiente para abaratar la respuesta pública. El perfil
   snapshot sin HTML cache obtuvo p95 `36,59 ms`, similar al síncrono de
   `35,37 ms`: el snapshot abarata la fuente de datos, pero no pre-renderiza la
   respuesta HTTP.
8. Snapshot más HTML response cache es el perfil recomendado para este hosting.
   Bajó el p95 a `23,08 ms` y mantuvo el BFF fuera del camino de lectura, pero
   el p99 siguió en `4,33 s`. Es una mejora medible, no evidencia de que el
   techo del hosting haya desaparecido.
9. El usuario del warmup es parte del contrato de despliegue. Un árbol de
   snapshots propiedad de root provocó 503 bajo Apache. En cPanel el warmup
   debe ejecutarse con el usuario de la cuenta que posee y sirve la aplicación,
   verificando permisos como parte de la publicación.
10. La política canónica-only reduce deuda técnica: las variantes con query no
    se aceptan silenciosamente como snapshots. Para habilitarlas después se
    requiere política por ruta, entradas finitas, warmup explícito,
    invalidación y revisión del presupuesto de almacenamiento.

## Correcciones aplicadas

- `PublicReadPageReader` expone lectura de candidatos múltiples en una sola
  operación set-based, manteniendo el contrato existente para una ruta única.
- `PageResolver` conserva el orden de prioridad de la ruta canónica y sus
  aliases, pero evita el bucle de lecturas secuenciales cuando el reader real
  soporta la nueva interfaz.
- `BlockInstanceSerializer` y `PublicReadFormReader` dejaron de depender de
  selecciones amplias y agrupan traducciones de campos en lugar de repetir
  consultas por campo.
- Se añadieron pruebas para candidatos múltiples, aliases y compatibilidad de
  metadatos. No se cambió el snapshot-first ni se agregó carga sobre
  producción.

## Evidencia

- Resumen reproducible de la nueva corrida:
  `docker/performance/results/k6-comparison-20260821-summary.md`.
- Comparación final de los tres perfiles y decisión operativa:
  `docker/performance/results/k6-final-comparison-20260821.md`.
- Comparación previa con datos importados:
  `docker/performance/results/k6-imported-route-mix-summary.md`.
- Resultados históricos de snapshot-first y límites del hosting:
  `teatromuseo-web/docs/audits/2026-08-15-rel-01-homepage-cutover.md`.
- Tests unitarios del cambio:
  `teatromuseo-bff/tests/Unit/PublicRead/PublicReadPageReaderTest.php` y
  `teatromuseo-bff/tests/Unit/PublicRead/PublicPagePathsTest.php`.

## Referencias oficiales contrastadas

- [CloudLinux: límites LVE](https://docs.cloudlinux.com/cloudlinuxos/limits/):
  Entry Processes representa entradas concurrentes al entorno; al alcanzar el
  límite pueden aparecer 508, mientras CPU/I/O se ralentizan y memoria/NPROC
  pueden provocar 500/503.
- [cPanel: Cron Jobs](https://docs.cpanel.net/cpanel/advanced/cron-jobs/):
  advierte que una frecuencia demasiado alta puede solapar ejecuciones y
  degradar el rendimiento; respalda el lock y el warmup serial.
- [cPanel: Optimize Website](https://docs.cpanel.net/cpanel/software/optimize-website/):
  la compresión depende de la configuración del proveedor y de `mod_deflate`,
  por lo que no se convirtió gzip en una dependencia de la aplicación.
- [PHP: configuración de OPcache](https://www.php.net/manual/en/opcache.configuration.php):
  desactivar la validación de timestamps exige invalidar OPcache o reiniciar el
  servidor al desplegar cambios; por eso el laboratorio lo parametriza y no se
  impone ciegamente en cPanel.

## Trabajo pendiente

- Repetir un canario remoto serial, no una carga concurrente, cuando cPanel
  tenga una ventana limpia y se pueda observar Resource Usage.
- Medir en el BFF real el conteo de consultas y latencia de `page-resolve` para
  una ruta canónica, un alias y una ruta dinámica.
- Priorizar la reducción de trabajo síncrono en rutas que todavía no estén
  cubiertas por snapshot; después, si el negocio lo permite, elevar el límite
  de Entry Processes/workers mediante un plan de hosting superior. El código
  no puede eliminar una cola creada por el límite externo de Apache.

## Automatización futura

- Añadir un benchmark reproducible que compare explícitamente candidatos
  secuenciales contra la lectura set-based y publique p50/p95/p99 sin tocar
  producción.

## Resumen provisional

La arquitectura y el código están bastante más robustos: los gates están
verdes, las rutas públicas sobreviven la corrida y la resolución de aliases ya
no multiplica lecturas secuenciales en el reader real. El límite práctico aún
es el hosting compartido: con cinco workers, diez usuarios virtuales pueden
generar una cola extrema aunque el p95 y la tasa de errores sigan siendo
aceptables. La conclusión no es “ya está resuelto”, sino “el código está listo
para operar con una estrategia snapshot-first y concurrencia controlada; la
capacidad adicional requiere menos trabajo síncrono o más recursos del plan”.
