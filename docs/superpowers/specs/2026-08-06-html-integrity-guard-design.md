# Guardia de integridad HTML (no cachear páginas rotas) — v1.7.0

**Fecha:** 2026-08-06 · **Contexto:** Keypro (aldeahostlatam.com/key) sirvió durante ~90 min páginas "en texto plano". Causa raíz: pérdida de la meta `_elementor_edit_mode` en 2 posts; agravante: corehost-cache capturó el HTML roto y lo siguió sirviendo. Además existe la carrera conocida de Elementor 4.2 que borra/reescribe `uploads/elementor/css/*` y produce 404 transitorios de CSS.

## Objetivo

1. corehost-cache **nunca debe almacenar** una captura de HTML que se sabe rota.
2. En Keypro, si una página vuelve a perder `_elementor_edit_mode`, debe **auto-repararse** en la misma petición y quedar registrado **quién** borró la meta.

## Parte A — chequeo de integridad en corehost-cache (genérico)

Nueva clase pura `CHC_Html_Integrity` (testeable sin WP):

- `local_upload_styles(html, uploads_url, uploads_dir): array` — extrae los `<link rel="stylesheet">` cuyo `href` cae bajo `uploads_url` (los CSS generados por Elementor) y los mapea a rutas de disco (sin query string).
- `assets_veto(html, uploads_url, uploads_dir, file_exists): ?string` — si algún CSS referenciado no existe en disco, devuelve el motivo del veto (`assets:<ruta>`); si no, `null`. `file_exists` inyectable para tests.
- `elementor_veto(html, post_id, data_len, mode_exists, mode): ?string` — reglas:
  - `mode === 'builder'` y el HTML no contiene `data-elementor-id="<id>"` → veto (`elementor:sin-wrapper`).
  - meta **inexistente** y `data_len > 100` y sin wrapper → veto (`elementor:meta-perdida`, el caso Keypro).
  - meta existe con valor ≠ builder (cambio deliberado al editor de WP) → sin veto.

Integración en `CHC_Page_Generator::finish()`: condición adicional antes de `write()`. El generador aporta el contexto WP (queried object, metas, `content_url()`/`WP_CONTENT_DIR`). Al vetar: se guarda `chc_last_veto` (option, sin autoload: `{ts, uri, reason}`), se dispara `do_action('chc_store_vetoed', reason, uri)` y el HTML se devuelve igual al visitante. Filtro `chc_html_complete` (bool, html) para chequeos por sitio. `wp corehost-cache status` muestra el último veto.

Costo: regex + `file_exists` solo al escribir caché (evento raro). Fallo del chequeo = no cachear (fail-safe: nunca rompe la respuesta).

## Parte B — mu-plugin `keypro-elementor-guard.php` (solo Keypro)

- **Auto-sanación** (hook `wp`, front, singular): post con `_elementor_data` > 100 chars y **sin** la meta `_elementor_edit_mode` → `update_post_meta(id, '_elementor_edit_mode', 'builder')` + `delete_post_meta(id, '_elementor_css')` (fuerza regeneración solo de ese post) + purga de su URL en corehost-cache. Como `wp` corre antes del render, la misma petición ya sale bien. Evento registrado en option `kpg_heal_log` (últimos 20).
- **Telemetría del culpable**: hooks `delete_post_meta` y `update_post_meta` para esa meta clave → si se borra o se cambia a algo ≠ `builder`, registrar `wp_debug_backtrace_summary()`, usuario, URI y hook actual en option `kpg_meta_log` (últimos 20; en options para no exponer un .log bajo el webroot).

## Verificación

- Tests puros nuevos en `tests/` (extracción de hrefs, mapeo, ambos vetos) — la suite corre en el server (`php tests/run.php`).
- E2E en Keypro sobre `/plantillas/` (post 75, bajo tráfico):
  1. Renombrar `base-desktop.css` → petición anónima → veto `assets:*`, sin `index.html` escrito → restaurar.
  2. Con el guard desactivado: borrar la meta de 75 → petición anónima → veto `elementor:*`, sin caché.
  3. Guard activo: misma petición → meta restaurada, página bien, se cachea normal, `kpg_heal_log` con entrada.

## Fuera de alcance

- Portar a walnutje y a la variante CoreHost Cache+OLS de chilenut (pendiente aparte).
- Arreglar la causa que borra la meta (se hará cuando la telemetría la identifique).
