# TASKS — ci4-bff-starter

### ✅ Completadas recientemente

- **Forwarding de headers de firma de webhooks**: `DomainClient::buildForwardedHeaders()` reenvía `X-Twilio-Email-Event-Webhook-Signature/-Timestamp` y `X-Webhook-Token` para que los domains puedan verificar firmas de webhooks proxied. Backport del stack multi-subscription (auditoría 2026-06-10, H-2).
- **Unificación de Throttling (BFF-M1)**: `ThrottleFilter` local eliminado en favor de la implementación del core. `RateLimitResponseHelpers` eliminado (ahora consumido desde `ci4-api-core`).
- **Propagación de `app_id`**: El BFF ahora es consciente de la aplicación a través de la propagación automática en `IntrospectAuthFilter` y `ContextHolder`.
- **Soporte Multi-Domain (BFF-M2)**: `Config/Bff.php` y `Services.php` refactorizados para admitir un array asociativo de dominios dinámicos mapeados vía `DomainClient`.
- **Generador de Proxy Dinámico (BFF-M3)**: Implementado el comando CLI Spark `bff:make-proxy` para generar automáticamente controladores de proxy transparentes y archivos de rutas.

### 🚀 Roadmap

*(todos los objetivos planificados para este hito han sido completados)*
