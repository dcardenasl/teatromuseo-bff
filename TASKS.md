# TASKS — teatromuseo-bff

> Seguimiento cross-repo: [`../TASKS.md`](../TASKS.md).

---

## 🟢 Estado: congelado a propósito (decisión del 2026-08-05)

La auditoría arquitectónica del 2026-08-05 constató que **este repo no tiene ningún consumidor
dentro del workspace**, y David decidió **mantenerlo tal cual**, sin trabajo asignado, conservándolo
para una futura SPA o app móvil.

**Esto no es deuda pendiente: es una decisión tomada. No re-reportarlo en auditorías futuras.**

Evidencia del estado actual, para que quien retome el repo sepa dónde está parado:

- El único punto de cableado está comentado y apunta al puerto equivocado:
  `teatromuseo-admin/.env:132` → `# bffApiClient.baseUrl = 'http://localhost:8088'` (el BFF corre
  en **8188**). Con eso vacío, `DashboardController::widgetHealth()` del admin omite la tarjeta de
  salud del BFF.
- Cero referencias a `8188` o `BFF_API_BASE_URL` en `teatromuseo-web` y `teatromuseo-totem-ci4`.
- Las rutas siguen siendo el scaffold de kickstart (`users/{id}`, `me/dashboard`). No hay ningún
  endpoint de dominio Teatro Museo (eventos, catálogo, CMS).
  `app/Config/Routes/v1/public.php` es un comentario sin rutas, y
  `app/Controllers/Api/V1/PublicProxyController.php` no está ruteado.
- `AGENTS.md:36` declara el flujo `SPA/mobile → BFF → Hub o domain app`; no existe ninguna SPA ni
  app móvil en el workspace.
- Sin `Dockerfile`, sin `docker-compose.yml`, sin `.dockerignore` y sin `deploy.py` en `.deploy/`:
  es la única app de la flota sin ruta de construcción o despliegue. **Consecuencia aceptada de
  congelarlo** — si algún día se activa, esto es lo primero que hay que resolver.
- `start-dev.sh:60` lo sigue levantando en 8188 en cada `bash start-dev.sh`.

Notas menores, sin acción mientras siga congelado:

- `app/Filters/IntrospectAuthFilter.php:23` referencia `{@see \App\Libraries\Hub\HubClient::introspect()}`,
  una clase que ya no existe aquí (se movió al paquete vendorizado).
- `app/Config/Api.php` es una copia verbatim de 148 líneas de la que publica `ci4-api-core`,
  arrastrando configuración JWT muerta. Si el repo se reactiva, entra en `CORE-03`.
- `builds/` es un directorio vacío no ignorado por `.gitignore`.
- Archivo de respaldo suelto: `.env.bak.1785113767`.

**Lo que este repo hace bien** y conviene no perder si se retoma: es la única app que blinda su
apatridia a nivel de configuración (`app/Config/Database.php:35` fija `:memory:` + SQLite3 con
comentario explícito), `BaseProxyController` expone exactamente dos primitivas (`proxy()` y
`aggregate()`) sin que ningún controlador reinvente el forwarding, y consume el `HubClient`
vendorizado en vez de duplicarlo. Es el código más limpio de toda la auditoría.

---

### ✅ Completadas recientemente

- **CFG-06 — fix de cableado de instalación del hook `pre-push` (2026-08-07)** — el `pre-push`
  creado para CFG-06 (root `TASKS.md`) existía en la raíz del repo y estaba copiado a mano en
  `.git/hooks/pre-push`, pero su propio comentario de cabecera afirmaba falsamente "Installed by
  composer's post-install-cmd/post-update-cmd": `composer.json` solo copiaba `pre-commit`, nunca
  `pre-push`, así que un `git clone` + `composer install` fresco nunca lo instalaba. Corregido
  `composer.json` (`post-install-cmd`/`post-update-cmd`) para copiar `pre-push` con el mismo
  mecanismo/permisos que `pre-commit` (`chmod +x` + `[ -d .git/hooks ] && cp ... || true`).
  Verificado borrando `.git/hooks/pre-push` y corriendo `composer install`: se recrea con 0755 y
  contenido idéntico al de la raíz. El comentario de cabecera del hook ya no hacía falta tocarlo —
  ahora es verídico. Contenido del hook (no-op `exit 0`) sin cambios, como corresponde.

- **CORE-03 + fix de test dependiente del entorno (2026-08-06)** — dos cambios acotados, hechos
  mientras se migraba el resto de la flota; no alteran la decisión de congelar este repo.
  - `app/Config/Api.php` pasa de 148 líneas copiadas verbatim (byte-idénticas a las de cms, catalog
    y event) a extender `dcardenasl\Ci4ApiCore\Config\Api`. Se documenta que las propiedades JWT
    heredadas son inertes aquí: el BFF nunca tiene el secreto, reenvía el `Authorization` e
    introspecta contra el hub.
  - `ci4-api-core` subido a v1.2.0, y el 2026-08-06 otra vez a **v1.3.0** (publicada por David en
    el checkout que mantiene activamente, `ci4-platform/ci4-api-core`) — todo aditivo, sin cambios
    de código en este repo más allá de la subida de versión.
  - **`CorsHeadersTest` llevaba fallando por dependencia del entorno.** Usaba `putenv()`, pero el
    `env()` de CI4 resuelve `$_ENV[$k] ?? $_SERVER[$k] ?? getenv($k)`, y el `.env` de este repo
    define `BFF_ALLOWED_ORIGINS` — DotEnv ya había poblado `$_ENV`, así que el `putenv()` se
    ignoraba y la lista de permitidos del test nunca se aplicaba. Ahora controla las tres fuentes,
    resetea `Factories` (Config\Bff y Config\Cors parsean en sus constructores) y restaura todo en
    `tearDown()`.
  - Observación sin acción: el `.env` local lista `http://localhost:8186` como origen permitido —
    ese es el puerto del **tótem**, no de un cliente del BFF. Resto de copia/pega.

  **Verificación:** `composer quality` ✅ — 139 tests / 352 assertions, PHPStan sin errores.

- **Forwarding de headers de firma de webhooks**: `DomainClient::buildForwardedHeaders()` reenvía `X-Twilio-Email-Event-Webhook-Signature/-Timestamp` y `X-Webhook-Token` para que los domains puedan verificar firmas de webhooks proxied. Backport del stack multi-subscription (auditoría 2026-06-10, H-2).
- **Unificación de Throttling (BFF-M1)**: `ThrottleFilter` local eliminado en favor de la implementación del core. `RateLimitResponseHelpers` eliminado (ahora consumido desde `ci4-api-core`).
- **Propagación de `app_id`**: El BFF ahora es consciente de la aplicación a través de la propagación automática en `IntrospectAuthFilter` y `ContextHolder`.
- **Soporte Multi-Domain (BFF-M2)**: `Config/Bff.php` y `Services.php` refactorizados para admitir un array asociativo de dominios dinámicos mapeados vía `DomainClient`.
- **Generador de Proxy Dinámico (BFF-M3)**: Implementado el comando CLI Spark `bff:make-proxy` para generar automáticamente controladores de proxy transparentes y archivos de rutas.

### 🚀 Roadmap

*(todos los objetivos planificados para este hito han sido completados)*
