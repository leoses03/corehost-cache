# Implementation Plans — corehost-cache

Generados por la skill `improve` el 2026-07-02 (commit base `54391ec`). Ejecutar en orden salvo que las dependencias digan otra cosa. Cada ejecutor: lee el plan completo antes de empezar, respeta sus STOP conditions, y actualiza su fila al terminar.

## Orden de ejecución & estado

| Plan | Título | Prioridad | Esfuerzo | Depende de | Estado |
|------|--------|-----------|----------|------------|--------|
| 001 | Toggle "No cachear esta página" + purga tras actualizaciones | P1 | S | — | DONE |
| 002 | Purga de Cloudflare al invalidar | P1 | M | 001 | DONE (E2E real de CF pendiente, ver nota) |
| 003 | Cachear con solo params de tracking (utm/gclid/fbclid) | P1 | M | — | DONE |

Estados: TODO | IN PROGRESS | DONE | BLOCKED (motivo) | REJECTED (motivo)

**Nota 002:** implementación + tests puros + verificación de wiring (CF desactivado, purga local intacta, sin errores) hechos en Keypro. La purga real contra la API de Cloudflare queda **pendiente de verificar** en un sitio de la flota que sí esté tras Cloudflare (Keypro no lo está) — falta definir `cf_enabled`/`cf_zone`/token ahí y confirmar en el dashboard de CF.

## Notas de dependencia
- 002 depende de 001 porque ambos extienden `includes/class-purge.php`; hacer 001 primero evita conflictos y 002 construye sobre el registro de hooks.

## Direction findings considerados (del análisis 2026-07-02)
Elegidos para plan: #1 (Cloudflare → 002), #4 (invalidación + toggle → 001), #2 (tracking params → 003).
Pendientes/diferidos (no rechazados, se pueden planificar después):
- **#3 Portabilidad nginx (drop-in `advanced-cache.php`)** — diseño ya discutido; sirve los mismos archivos sin `.htaccess`. Esfuerzo M. Diferido hasta aterrizar en un host nginx.
- **#5 Auto-warm tras purga (WP-Cron)** — mantener el cache caliente. Esfuerzo M.

## Rechazados (para no re-auditar)
- **Caché móvil separada** — los sitios de la flota son responsive (mismo HTML); no aporta.
- **Dashboard de hit/miss** — contar hits requiere PHP en cada visita, lo que mata el beneficio de servir sin PHP.
