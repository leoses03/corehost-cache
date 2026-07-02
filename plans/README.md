# Implementation Plans — corehost-cache

Generados por la skill `improve` el 2026-07-02 (commit base `54391ec`). Ejecutar en orden salvo que las dependencias digan otra cosa. Cada ejecutor: lee el plan completo antes de empezar, respeta sus STOP conditions, y actualiza su fila al terminar.

## Orden de ejecución & estado

| Plan | Título | Prioridad | Esfuerzo | Depende de | Estado |
|------|--------|-----------|----------|------------|--------|
| 001 | Toggle "No cachear esta página" + purga tras actualizaciones | P1 | S | — | TODO |
| 002 | Purga de Cloudflare al invalidar | P1 | M | 001 | TODO |

Estados: TODO | IN PROGRESS | DONE | BLOCKED (motivo) | REJECTED (motivo)

## Notas de dependencia
- 002 depende de 001 porque ambos extienden `includes/class-purge.php`; hacer 001 primero evita conflictos y 002 construye sobre el registro de hooks.

## Direction findings considerados (del análisis 2026-07-02)
Elegidos para plan: #1 (Cloudflare → 002), #4 (invalidación + toggle → 001).
Pendientes/diferidos (no rechazados, se pueden planificar después):
- **#2 Parámetros de tracking (`utm_*`/`fbclid`/`gclid`)** — normalizar para subir hit-rate en tráfico de campañas. Esfuerzo M.
- **#3 Portabilidad nginx (drop-in `advanced-cache.php`)** — diseño ya discutido; sirve los mismos archivos sin `.htaccess`. Esfuerzo M. Diferido hasta aterrizar en un host nginx.
- **#5 Auto-warm tras purga (WP-Cron)** — mantener el cache caliente. Esfuerzo M.

## Rechazados (para no re-auditar)
- **Caché móvil separada** — los sitios de la flota son responsive (mismo HTML); no aporta.
- **Dashboard de hit/miss** — contar hits requiere PHP en cada visita, lo que mata el beneficio de servir sin PHP.
