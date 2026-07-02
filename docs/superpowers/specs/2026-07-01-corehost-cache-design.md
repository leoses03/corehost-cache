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
| Exclusión por rol | Panel que lista **todos los roles del sitio**; por rol se elige excluir (bypass, siempre fresco) o permitir servir cache. Default: **todos excluidos** |

## 3. Arquitectura y componentes

```
corehost-cache/
  corehost-cache.php          # bootstrap: defines, requires, activación/desactivación, wiring de hooks
  includes/
    class-cache-store.php     # rutas de cache + escribir/leer/borrar (.html/.gz/.br); barrido TTL
    class-page-generator.php  # output buffering en template_redirect → escribe en shutdown
    class-request-rules.php   # is_cacheable(): espejo en PHP de las exclusiones del .htaccess
    class-role-gate.php       # setea/borra la cookie chc_nocache según los roles excluidos
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
- `%{HTTP_COOKIE}` **no** contiene: `chc_nocache` (marca de sesión que NO debe cachearse — la pone el plugin a los logueados con un rol excluido; ver §5.1), `comment_author_`, `wp-postpass_`, `woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_`
  *(Nota: `chc_nocache` reemplaza el bypass genérico por `wordpress_logged_in_`; así los roles permitidos SÍ pueden recibir cache y los excluidos no.)*
- `%{REQUEST_URI}` **no** contiene `wp-admin`/`wp-login`/`wp-json`/`wp-cron`/`xmlrpc` (anclado con `([/.]|$)`)
- `%{REQUEST_URI}` termina en `/` (esquema directorio/index.html; evita doble barra)
- `%{HTTP_HOST}` con forma de hostname (`^[a-zA-Z0-9.\-]+$`)
- El archivo cacheado existe (`-f`)

**Mapeo de ruta:** `wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}index.html`
El segmento `%{HTTP_HOST}` permite servir **cualquier dominio** que apunte a ese docroot. El prefijo de subdirectorio (p.ej. `/key`) se deriva de `content_url()` (fiable en web y WP-CLI).

**Servicio y compresión — REALIDAD LiteSpeed (desviación del diseño, verificada e2e 2026-07-01):**
El diseño original planteaba servir variantes pre-comprimidas `.br`/`.gz` por `Accept-Encoding`. En LiteSpeed eso **no funciona**: (a) las cabeceras de `mod_headers` con `env=REDIRECT_*` NO se aplican tras el rewrite interno, así que la variante comprimida se serviría **sin `Content-Encoding`** (bytes basura); (b) el test `-f` no normaliza `//`. **Solución adoptada:** se sirve **`index.html` PLANO** y **LiteSpeed lo comprime al vuelo** (brotli/gzip dinámico) — mismo resultado para el cliente. Por eso la **pre-compresión (`gzip`/`brotli`) queda OFF por defecto** (generarla sería desperdicio: no se sirve). La capacidad sigue en `Cache_Store` por si en el futuro se añade servicio pre-comprimido para Apache sin compresión dinámica.

Cabecera de observabilidad **`X-CoreHost-Cache: HIT`** + `Cache-Control: public, max-age=600` se setean con `Header ... env=CHC_HIT` **y** `env=REDIRECT_CHC_HIT` (LiteSpeed usa el primero; Apache el segundo). `Vary: Accept-Encoding` lo pone el propio servidor al comprimir.

**Home / trailing slash:** solo se sirven URLs con barra final; `/key/` → `.../key/index.html`. WP redirige las URLs sin barra a la forma canónica con barra.

## 5. Generación — `Page_Generator` + `Request_Rules`

- Engancha en `template_redirect` (prioridad alta): si `Request_Rules::is_cacheable()` → `ob_start(callback)`.
- En `shutdown`/callback del buffer: si la respuesta es HTML **200**, escribe `index.html` (+ marca HTML `<!-- corehost-cache ts -->`), `index.html.gz` (gzip nivel ~7) e `index.html.br` (Brotli si la extensión existe; si no, se salta sin romper).
- **`Request_Rules::is_cacheable()` = fuente única de exclusiones en PHP** (espejo del .htaccess):
  no `is_admin()`, no `is_user_logged_in()`, método GET, query vacío, HTTP 200, `Content-Type text/html`, no feed/REST/AJAX/cron, respeta la constante `DONOTCACHEPAGE`, la lista de URLs excluidas del admin, y con WooCommerce activo salta `is_cart()`, `is_checkout()`, `is_account_page()` y sesiones con carrito no vacío. 404 no se cachea por defecto (configurable).
- **La generación siempre es solo-anónima.** Los roles NO afectan qué se genera (nunca se cachea salida de un logueado); los roles solo deciden a quién se le *sirve* la cache anónima (§5.1).

### 5.1 Exclusión de cache por rol (gestión de roles)

El admin gestiona **todos los roles registrados en el sitio** (leídos dinámicamente con `wp_roles()->get_names()`, así aparecen también roles custom como `customer`, `shop_manager` o los propios de cada sitio). Por cada rol se elige:

- **Excluido (bypass):** los usuarios con ese rol **siempre reciben la página fresca** desde PHP (nunca cache).
- **Permitido (cacheable):** a esos usuarios se les **sirve la página cacheada anónima**.

**Default: todos los roles excluidos** (equivale al comportamiento seguro: ningún logueado recibe cache).

**Mecanismo (compatible con el servicio por `.htaccess`):** como el `.htaccess` no puede leer el rol desde la cookie de sesión, `Role_Gate` pone una cookie **`chc_nocache=1`** a los usuarios logueados cuyo rol esté excluido —en `set_logged_in_cookie` (al iniciar sesión) y en `init` (para sesiones ya abiertas)— y la borra en `wp_logout`. La cookie usa `COOKIE_DOMAIN`, `path=/` y `secure` cuando el sitio es HTTPS. El `.htaccess` hace bypass ante `chc_nocache`; los roles permitidos no reciben la cookie y por eso se les sirve la cache anónima.

**Implicación (clara):** un logueado de un rol *permitido* verá la **versión anónima** de la página (sin barra de admin ni UI personalizada), porque solo se cachea salida anónima. Por eso el default es "todos excluidos"; permitir un rol tiene sentido para roles de baja personalización (p. ej. un `customer` que ve lo mismo que un anónimo). **Solo se cachea contenido anónimo**, así que el peor caso de servir cache a un logueado es cosmético (falta la admin bar), nunca una fuga de datos privados.

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
- **Roles:** lista de **todos los roles del sitio** (`wp_roles()->get_names()`) con checkbox "Excluir del cache" por rol (default: todos marcados). Se guarda en `chc_settings['excluded_roles']` y alimenta a `Role_Gate` (§5.1).
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
- **Desactivación:** quita el bloque del `.htaccess`, desagenda el cron, purga todo. La cookie `chc_nocache` se autolimpia por expiración/logout.
- **Uninstall:** quita el bloque, borra el dir de cache y las opciones (`chc_settings`, `chc_htaccess_writable`, stats).

## 10. Riesgos y verificaciones (primer deploy)

1. **Orden en `.htaccess`** — el bloque DEBE quedar antes de `# BEGIN WordPress`. Se maneja en `class-htaccess.php` insertando al inicio.
2. **LiteSpeed re-comprimiendo** el `.br`/`.gz` → servir con `Content-Encoding` explícito; **verificar por curl** que no doble-comprime.
3. **Extensión Brotli de PHP** (`brotli_compress`) — verificar en el server; si falta, degradar a solo gzip sin romper.
4. **Mapeo home / trailing slash** — verificar `/` y rutas con/sin slash.
5. **Query strings** — v1 solo cachea query string vacío (documentado).
6. **Ventana de la cookie de rol** — un usuario ya logueado al activar el plugin podría, en su *primera* request, recibir la cache anónima antes de que `Role_Gate` fije `chc_nocache` (se corrige a la siguiente request). Riesgo solo cosmético: nunca se cachea contenido privado.

## 11. Testing

- `Request_Rules`: dado (método, cookies, query, login, tipo de página) → ¿cacheable? (casos: anónimo GET home = sí; logueado = no; POST = no; `?x=1` = no; carrito Woo = no).
- `Cache_Store`: mapeo URL→ruta, escribir/leer/borrar los 3 archivos, barrido TTL por mtime.
- `Htaccess`: el bloque generado contiene las condiciones esperadas; install/remove idempotente.
- `Role_Gate`: rol excluido → cookie `chc_nocache` presente (bypass en .htaccess); rol permitido → sin cookie (servido de cache); `wp_logout` borra la cookie; roles custom aparecen en la lista.
- Integración en Keypro: activar → visitar página anónima → confirmar los 3 archivos en disco → `curl` con/ sin cookies y con `Accept-Encoding: br,gzip` → confirmar `X-CoreHost-Cache: HIT` y `Content-Encoding` correcto → logueado hace bypass → `save_post` purga.

## 12. YAGNI — fuera de v1

Precarga/warmup por sitemap, cache por query-string, integración con CDN, cache móvil separada, object cache. Se evalúan como fase 2 si hacen falta.
