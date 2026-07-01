# CoreHost Cache — Design Spec

- **Fecha:** 2026-07-01
- **Estado:** Diseño aprobado
- **Slug del plugin:** `corehost-cache` · **Prefijo:** `CHC_` / `chc_`
- **Entorno objetivo:** WordPress sobre LiteSpeed/Apache (docroot con `.htaccess`), portable a cualquier sitio.

## 1. Propósito

Plugin de **page cache** propio y portable: guarda cada página renderizada como archivos estáticos (`.html` + `.html.gz` + `.html.br`) y los sirve **directamente desde el servidor vía reglas `.htaccess`, sin cargar PHP ni WordPress** en un cache hit. Reusable tal cual en cualquier sitio WordPress (single-site, consciente del host para servir múltiples dominios sobre un mismo docroot).

## 2. Decisiones (del brainstorming)

| Tema | Decisión |
|---|---|
| Servicio | HTML estático servido por `.htaccess` (bypass PHP) |
| Compresión | Brotli + gzip pre-generados; se elige por `Accept-Encoding` |
| Invalidación | Por evento **+** TTL (barrido por cron) |
| Seguridad dinámica | Seguro para WooCommerce: excluye admin/login/logueados/POST/carrito/checkout/mi-cuenta/sesión-con-carrito |

## 3. Arquitectura y componentes

```
corehost-cache/
  corehost-cache.php          # bootstrap: defines, requires, activación/desactivación, wiring de hooks
  includes/
    class-cache-store.php     # rutas de cache + escribir/leer/borrar (.html/.gz/.br); barrido TTL
    class-page-generator.php  # output buffering en template_redirect → escribe en shutdown
    class-request-rules.php   # is_cacheable(): espejo en PHP de las exclusiones del .htaccess
    class-htaccess.php        # genera e instala/quita el bloque de reglas de servicio
    class-purge.php           # hooks de invalidación por evento + purga (una URL / todo)
    class-cli.php             # WP-CLI: wp corehost-cache purge|status|warm
  admin/
    class-admin-page.php      # ajustes + botón "Purgar todo" + estadísticas (AJAX)
    admin.js
  uninstall.php               # quitar reglas, borrar cache dir + opciones
```

**Flujo de datos:**
1. Request anónima GET → `.htaccess` busca el archivo estático. Si existe y pasan las condiciones → lo sirve (HIT, sin PHP). Si no → pasa a WordPress (MISS).
2. En un MISS cacheable, `Page_Generator` bufferiza la salida y en `shutdown` escribe los 3 archivos.
3. Un cambio de contenido dispara `Purge`, que borra los archivos afectados. Un cron periódico borra los expirados (TTL).

## 4. Servicio — bloque `.htaccess` (el corazón)

**Ubicación:** en el `.htaccess` de la **raíz del sitio**, insertado **ANTES del `# BEGIN WordPress`** (si no, la regla `index.php` de WP atrapa la request primero).

**Se sirve el archivo estático solo si se cumple TODO:**
- `%{REQUEST_METHOD} = GET`
- `%{QUERY_STRING}` vacío
- `%{HTTP_COOKIE}` **no** contiene: `wordpress_logged_in_`, `comment_author_`, `wp-postpass_`, `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`
- `%{REQUEST_URI}` **no** empieza por `wp-admin`, `wp-login`, `wp-json`, `wp-cron`, `xmlrpc`
- El archivo cacheado existe (`-f`)

**Mapeo de ruta:** `wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}/index.html`
El segmento `%{HTTP_HOST}` permite servir **cualquier dominio** que apunte a ese docroot.

**Selección de variante por `Accept-Encoding`** (en orden): `.html.br` → `.html.gz` → `.html`. Se sirve con `Content-Encoding` correcto (`br`/`gzip`/ninguno), `Content-Type: text/html` y `Vary: Accept-Encoding`. Marca de observabilidad: **`X-CoreHost-Cache: HIT`**.

**Home / trailing slash:** `/` → `.../index.html` en la raíz del host; se cachea la forma canónica con slash final (WP redirige a ella).

## 5. Generación — `Page_Generator` + `Request_Rules`

- Engancha en `template_redirect` (prioridad alta): si `Request_Rules::is_cacheable()` → `ob_start(callback)`.
- En `shutdown`/callback del buffer: si la respuesta es HTML **200**, escribe `index.html` (+ marca HTML `<!-- corehost-cache ts -->`), `index.html.gz` (gzip nivel ~7) e `index.html.br` (Brotli si la extensión existe; si no, se salta sin romper).
- **`Request_Rules::is_cacheable()` = fuente única de exclusiones en PHP** (espejo del .htaccess):
  no `is_admin()`, no `is_user_logged_in()`, método GET, query vacío, HTTP 200, `Content-Type text/html`, no feed/REST/AJAX/cron, respeta la constante `DONOTCACHEPAGE`, la lista de URLs excluidas del admin, y con WooCommerce activo salta `is_cart()`, `is_checkout()`, `is_account_page()` y sesiones con carrito no vacío. 404 no se cachea por defecto (configurable).

## 6. Invalidación — `Purge`

**Por evento:**
| Evento | Purga |
|---|---|
| `save_post` / `wp_trash_post` / `delete_post` (post público) | URL del post + home + sus archivos/feeds relevantes |
| `comment_post` / `edit_comment` / `wp_set_comment_status` | El post comentado |
| `switch_theme` / `customize_save_after` / `wp_update_nav_menu` / cambios de widgets | Todo |
| Woo: cambio de stock/precio/producto | Producto + página de tienda |
| Manual | Botón "Purgar todo" + `wp corehost-cache purge [<url>]` |

**Por TTL:** como el `.htaccess` solo comprueba existencia del archivo, el TTL lo aplica un **evento WP-Cron** (`chc_ttl_sweep`) que borra archivos más viejos que `ttl_hours`.
*Caveat: WP-Cron depende de tráfico; en sitios de muy bajo tráfico el TTL puede retrasarse. Aceptable en v1.*

## 7. Admin (`class-admin-page.php`)

Ajustes → **CoreHost Cache**:
- On/off del cache, `ttl_hours`, cachear 404 (off por defecto), textarea de URLs excluidas, niveles de compresión (gzip/brotli).
- Botón **Purgar todo** (AJAX con nonce + `manage_options`).
- Estadísticas: páginas cacheadas, tamaño total en disco, última purga.
- Aviso si el `.htaccess` de la raíz no es escribible, con el bloque para pegar a mano (mismo patrón que corehost-image-converter).

## 8. Almacenamiento

```
wp-content/cache/corehost-cache/<host>/<uri-path>/index.html
                                               .../index.html.gz
                                               .../index.html.br
```
Un `index.php` vacío y reglas evitan el listado del directorio.

## 9. Activación / desactivación / desinstalación

- **Activación:** instala el bloque en el `.htaccess` raíz (arriba de WordPress), crea el dir de cache, agenda el cron TTL, guarda `chc_htaccess_writable`.
- **Desactivación:** quita el bloque del `.htaccess`, desagenda el cron, purga todo.
- **Uninstall:** quita el bloque, borra el dir de cache y las opciones (`chc_settings`, `chc_htaccess_writable`, stats).

## 10. Riesgos y verificaciones (primer deploy)

1. **Orden en `.htaccess`** — el bloque DEBE quedar antes de `# BEGIN WordPress`. Se maneja en `class-htaccess.php` insertando al inicio.
2. **LiteSpeed re-comprimiendo** el `.br`/`.gz` → servir con `Content-Encoding` explícito; **verificar por curl** que no doble-comprime.
3. **Extensión Brotli de PHP** (`brotli_compress`) — verificar en el server; si falta, degradar a solo gzip sin romper.
4. **Mapeo home / trailing slash** — verificar `/` y rutas con/sin slash.
5. **Query strings** — v1 solo cachea query string vacío (documentado).

## 11. Testing

- `Request_Rules`: dado (método, cookies, query, login, tipo de página) → ¿cacheable? (casos: anónimo GET home = sí; logueado = no; POST = no; `?x=1` = no; carrito Woo = no).
- `Cache_Store`: mapeo URL→ruta, escribir/leer/borrar los 3 archivos, barrido TTL por mtime.
- `Htaccess`: el bloque generado contiene las condiciones esperadas; install/remove idempotente.
- Integración en Keypro: activar → visitar página anónima → confirmar los 3 archivos en disco → `curl` con/ sin cookies y con `Accept-Encoding: br,gzip` → confirmar `X-CoreHost-Cache: HIT` y `Content-Encoding` correcto → logueado hace bypass → `save_post` purga.

## 12. YAGNI — fuera de v1

Precarga/warmup por sitemap, cache por query-string, integración con CDN, cache móvil separada, object cache. Se evalúan como fase 2 si hacen falta.
