# TASKS — teatromuseo-bff

> Este repositorio está **congelado a propósito** desde el 2026-08-05 porque
> no tiene consumidores en el workspace. Se conserva para una futura SPA o app
> móvil. Los cierres anteriores están en [`TASKS_ARCHIVE.md`](TASKS_ARCHIVE.md).

## 🟢 Estado: congelado

- No hay tareas activas ni deuda que deba reportarse en cada auditoría.
- Si aparece una SPA/móvil, reabrir el tracker con un plan propio y revisar
  primero Docker, despliegue, rutas públicas y contratos de forwarding.
- El BFF no participa en `QA-01..04` del plan PublicRead.

## 🏗️ Contratos preservados

- Stateless; no decodifica JWT localmente.
- Reenvía `Authorization` y delega introspección al Hub.
- Los controladores usan `proxy()`/`aggregate()` y no reinventan forwarding.
