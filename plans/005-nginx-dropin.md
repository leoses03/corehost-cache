# Plan 005: Drop-in `advanced-cache.php` para servir en hosts sin .htaccess (nginx)

> **Executor instructions**: sigue el plan paso a paso; corre cada verificación. Al terminar actualiza la fila 005 en `plans/README.md`.
>
> **Drift check (primero)**: `git -C "C:/Users/Luis Oses/Tools/corehost-cache" diff --stat ae5cd43..HEAD -- corehost-cache.php admin/class-admin-page.php includes/class-htaccess.php` — si cambió algo, compara con "Current state".

## Status
- **Priority**: P3
- **Effort**: L
- **Risk**: MED-HIGH (el drop-in corre en CADA request antes de WP; editar wp-config puede tumbar el sitio). **MITIGACIÓN: se construye pero NO se activa en Keypro** (no se instala el drop-in ni se toca el wp-config del sitio). El drop-in solo aporta en nginx; Keypro es LiteSpeed y ya sirve por .htaccess.
- **Depends on**: none (parte de `main` a `ae5cd43`)
- **Category**: direction (feature/portabilidad)
- **Planned at**: commit `ae5cd43`, 2026-07-02

## Why this matters
Hoy el plugin **solo sirve** en Apache/LiteSpeed (por `.htaccess`, sin PHP). En **nginx** el `.htaccess` se ignora: se generan los archivos pero nunca se sirven. Un drop-in `advanced-cache.php` (que WordPress carga al inicio del bootstrap si `WP_CACHE` es true) sirve el MISMO archivo estático antes de cargar WP entero, dando portabilidad a nginx. En Apache/LiteSpeed el `.htaccess` responde antes que PHP, así que el drop-in queda como fallback inofensivo. **En este plan se construye y se prueba su lógica de forma reversible; queda OFF por defecto y NO se activa en Keypro.**

## Decisiones de diseño (ya tomadas)
- **Alcance v1 del drop-in:** sirve requests con query **VACÍA** (los params de tracking del plan 003 quedan como mejora futura del drop-in — el `.htaccess` de LiteSpeed ya los cubre). Mismas exclusiones que el `.htaccess`: GET, barra final, sin cookies de bypass (`chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_`), no rutas backend, no `..`, host con forma de hostname.
- **Clave:** `WP_CONTENT_DIR/cache/corehost-cache/<host><path>/index.html` (host = `$_SERVER['HTTP_HOST']`, path = del `REQUEST_URI` sin query). Sirve con `Content-Type: text/html`, gzip al vuelo si el cliente lo acepta (`Content-Encoding: gzip`), `X-CoreHost-Cache: HIT`, y `exit`.
- **Lógica pura compartida sin duplicar:** el template define la función `chc_dropin_cache_file(array $server, array $cookies, string $base): ?string` y SOLO ejecuta el bloque de servicio si NO está definida la constante `CHC_DROPIN_TEST` (así el test incluye el archivo y prueba la función sin que se dispare el `exit`).
- **Instalación (`CHC_Dropin`):** copia el template a `wp-content/advanced-cache.php` (solo si no existe uno ajeno — detecta el marcador `CoreHost Cache drop-in`) y añade `define('WP_CACHE', true);` a `wp-config.php` (con backup; si no es escribible, devuelve false y el admin muestra la línea para pegar a mano). Desinstalar hace lo inverso (solo borra nuestro drop-in).
- **Toggle `dropin_enabled`** (default **0**). Al guardar Ajustes: si pasa a 1 → `CHC_Dropin::install()`, si pasa a 0 → `remove()`. **En Keypro se deja en 0.**
- **Seguridad anti-fatal:** el drop-in comprueba `is_dir($base)`/`is_file($file)` antes de servir; si algo falta, retorna sin romper (WP sigue). La desactivación del plugin quita el drop-in + WP_CACHE.

## Current state
- `corehost-cache.php` — `chc_settings()` defaults (incluye `auto_warm`, `cf_*`, etc.); `chc_cache_url_path()`; activation/deactivation hooks (deactivation ya quita htaccess + crons + purga). `WP_CONTENT_DIR` disponible.
- `includes/class-htaccess.php` `rules()` — referencia de las condiciones de servicio a replicar en PHP (skip_cookies, skip_uris, host guard, trailing slash, `..`).
- `admin/class-admin-page.php` — `sanitize()` y `render()`.
- Tests: `tests/bootstrap.php` (define `ABSPATH`, requiere las clases puras) + `php tests/run.php`.

## Commands
Como el plan 001 (DEPLOY, tests, `php -l`, WP-CLI). Filtra SSH; DEPLOY y tests separados.

## Scope
**In scope:** `dropin/advanced-cache.php` (crear, template), `includes/class-dropin.php` (crear), `corehost-cache.php` (require + default `dropin_enabled` + desinstalar drop-in en deactivation), `admin/class-admin-page.php` (toggle + sanitize + status/warnings), `tests/test-dropin.php` (crear), `tests/bootstrap.php` (requerir lo necesario).
**Out of scope:** `.htaccess`, cache-store, request-rules, purge, cloudflare, page-generator, role-gate, post-meta, admin-bar. **NO activar el toggle en Keypro; NO editar el `wp-config.php` de Keypro.**

## Git workflow
Rama `feat/005-nginx-dropin`. Commit por unidad. No push/merge.

## Steps

### Step 1: Template del drop-in con función pura testeable
Crea `dropin/advanced-cache.php`:
```php
<?php
/* CoreHost Cache drop-in — sirve el HTML cacheado antes de cargar WP (para hosts sin .htaccess, p.ej. nginx). */
if (!defined('ABSPATH') && !defined('CHC_DROPIN_TEST')) { return; }

/** Ruta del archivo cacheado a servir, o null. PURA (sin deps de WP). Query debe ir vacía en v1. */
function chc_dropin_cache_file(array $server, array $cookies, string $base): ?string
{
    if (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return null; }
    if (($server['QUERY_STRING'] ?? '') !== '') { return null; }
    $uri = $server['REQUEST_URI'] ?? '/';
    if (strpos($uri, '?') !== false) { $uri = substr($uri, 0, strpos($uri, '?')); }
    if (substr($uri, -1) !== '/') { return null; }                 // solo directorios/index.html
    if (strpos($uri, '..') !== false) { return null; }
    if (preg_match('#(^|/)(wp-admin|wp-login|wp-cron|wp-json|xmlrpc)([/.]|$)#i', $uri)) { return null; }
    $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);
    if (!preg_match('/^[a-z0-9.\-]+$/', $host)) { return null; }
    $cookie = $server['HTTP_COOKIE'] ?? '';
    if ($cookie === '' && $cookies) { $cookie = implode('; ', array_keys($cookies)); }
    if (preg_match('/(chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_)/', (string) $cookie)) { return null; }
    $file = rtrim($base, '/') . '/' . $host . rtrim($uri, '/') . '/index.html';
    return is_file($file) ? $file : null;
}

if (!defined('CHC_DROPIN_TEST')) {
    $chc_base = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : (defined('ABSPATH') ? ABSPATH . 'wp-content' : '')) . '/cache/corehost-cache';
    $chc_file = @chc_dropin_cache_file($_SERVER, $_COOKIE, $chc_base);
    if ($chc_file !== null) {
        $html = @file_get_contents($chc_file);
        if ($html !== false && $html !== '') {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-CoreHost-Cache: HIT');
            header('Cache-Control: public, max-age=600');
            header('Vary: Accept-Encoding');
            $ae = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if (stripos($ae, 'gzip') !== false && function_exists('gzencode')) {
                $gz = gzencode($html, 6);
                if ($gz !== false) { header('Content-Encoding: gzip'); $html = $gz; }
            }
            header('Content-Length: ' . strlen($html));
            echo $html;
            exit;
        }
    }
    // sin cache aplicable: WP continúa normal.
}
```
**Verify**: `php -l dropin/advanced-cache.php`.

### Step 2: Test puro de `chc_dropin_cache_file`
En `tests/bootstrap.php` añade (en modo test, sin que el drop-in sirva): `define('CHC_DROPIN_TEST', true); require dirname(__DIR__).'/dropin/advanced-cache.php';`
Crea `tests/test-dropin.php`:
```php
$base = sys_get_temp_dir().'/chc-dropin-'.getmypid();
@mkdir($base.'/ej.com/about', 0777, true);
file_put_contents($base.'/ej.com/about/index.html', '<html>hola</html>');
$S = ['REQUEST_METHOD'=>'GET','QUERY_STRING'=>'','REQUEST_URI'=>'/about/','HTTP_HOST'=>'ej.com'];
t_eq(chc_dropin_cache_file($S, [], $base), $base.'/ej.com/about/index.html', 'sirve archivo existente');
t_assert(chc_dropin_cache_file(['QUERY_STRING'=>'x=1']+$S, [], $base) === null, 'con query => null');
t_assert(chc_dropin_cache_file(['REQUEST_METHOD'=>'POST']+$S, [], $base) === null, 'POST => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/about']+$S, [], $base) === null, 'sin barra final => null');
t_assert(chc_dropin_cache_file($S, ['chc_nocache'=>'1'], $base) === null, 'cookie bypass => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/wp-admin/']+$S, [], $base) === null, 'backend => null');
t_assert(chc_dropin_cache_file(['REQUEST_URI'=>'/nada/']+$S, [], $base) === null, 'archivo inexistente => null');
```
**Verify**: DEPLOY + `php tests/run.php` → 0 failed (sube el conteo).

### Step 3: `CHC_Dropin` (instalar/quitar drop-in + WP_CACHE)
Crea `includes/class-dropin.php` (`CHC_Dropin`, guarda ABSPATH). Métodos:
- `dropin_path(): string` → `WP_CONTENT_DIR . '/advanced-cache.php'`.
- `is_ours(): bool` → el drop-in existe y contiene `CoreHost Cache drop-in`.
- `foreign_exists(): bool` → existe un advanced-cache.php SIN nuestro marcador.
- `install(): bool` → si `foreign_exists()` return false; copia `CHC_DIR/dropin/advanced-cache.php` a `dropin_path()`; luego `set_wp_cache(true)`.
- `remove(): void` → si `is_ours()` borra el archivo; `set_wp_cache(false)`.
- `set_wp_cache(bool $on): bool` → **puro-ish sobre archivo**: edita `ABSPATH.'wp-config.php'` (o el que reciba por parámetro, para tests): quita cualquier `define('WP_CACHE', ...)` previo y, si `$on`, inserta `define('WP_CACHE', true); // CoreHost Cache` justo después de la primera línea `<?php`. Backup a `wp-config.php.chc-bak`. Devuelve false si no es escribible. **Hazlo con un método estático que reciba la RUTA del wp-config para poder testearlo en un archivo temporal.**
**Verify**: `php -l includes/class-dropin.php`. Test en `tests/test-dropin.php`: crea un wp-config temporal (`<?php\n// stuff\n`), llama `CHC_Dropin::set_wp_cache_in($tmp, true)` → contiene `define('WP_CACHE', true)`; con `false` → ya no lo contiene. (Nombra el método testeable p.ej. `set_wp_cache_in(string $file, bool $on): bool` y que `set_wp_cache` lo llame con `ABSPATH.'wp-config.php'`.)

### Step 4: Toggle en Ajustes + wiring + deactivation
- `corehost-cache.php`: `require_once CHC_DIR . 'includes/class-dropin.php';`; default `'dropin_enabled' => 0`; en el deactivation hook añade `(new CHC_Dropin())->remove();`.
- `admin/class-admin-page.php`: `sanitize()` → `'dropin_enabled' => empty($input['dropin_enabled']) ? 0 : 1,`. Al detectar cambio de estado en el guardado, llama a `install()`/`remove()` (puedes hacerlo en un `add_action('update_option_chc_settings', ...)` que compare, o directo en sanitize comparando con el valor previo). `render()` → fila "Compatibilidad sin .htaccess (nginx)" con el checkbox + aviso: si `foreign_exists()` muestra que hay otro advanced-cache.php y NO se instalará; si `set_wp_cache` falló, muestra la línea `define('WP_CACHE', true);` para pegar a mano.
**Verify**: `php -l` de ambos; `class_exists('CHC_Dropin')` OK en el server.

### Step 5: Verificación en Keypro (SIN activar; controlada y reversible)
```bash
P=/home/corehost/public_html/key
# tests puros (incluyen los del drop-in)
ssh aldeahostlatam "php $P/wp-content/plugins/corehost-cache/tests/run.php | tail -1"   # 0 failed
# la función encuentra el archivo real de la home (modo test, NO sirve):
ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache purge >/dev/null"; curl -s -o /dev/null 'https://aldeahostlatam.com/key/'   # genera home
ssh aldeahostlatam "php -r 'define(\"CHC_DROPIN_TEST\",true); define(\"WP_CONTENT_DIR\",\"$P/wp-content\"); require \"$P/wp-content/plugins/corehost-cache/dropin/advanced-cache.php\"; \$f=chc_dropin_cache_file([\"REQUEST_METHOD\"=>\"GET\",\"QUERY_STRING\"=>\"\",\"REQUEST_URI\"=>\"/key/\",\"HTTP_HOST\"=>\"aldeahostlatam.com\"],[],WP_CONTENT_DIR.\"/cache/corehost-cache\"); echo \$f?\"DROPIN_FILE_OK: \".basename(dirname(\$f)).\"/index.html\n\":\"NULL\n\"; echo (\$f && strpos(file_get_contents(\$f),\"corehost-cache\")!==false)?\"MARCADOR_OK\n\":\"sin marcador\n\";'"
# confirmar que NO se activó nada en el sitio:
ssh aldeahostlatam "test -f $P/wp-content/advanced-cache.php && echo 'OJO: advanced-cache.php PRESENTE (no debería)' || echo 'OK: sin advanced-cache.php'"
ssh aldeahostlatam "grep -c 'WP_CACHE' $P/wp-config.php || true"   # esperamos 0 (o el que ya hubiera; NO debemos añadirlo)
echo "sitio:"; curl -s -o /dev/null -w '%{http_code}\n' 'https://aldeahostlatam.com/key/'   # 200
```
**Verify**: tests 0 failed; `DROPIN_FILE_OK` + `MARCADOR_OK` (la función localiza el index.html real de la home y tiene el marcador del cache); `OK: sin advanced-cache.php`; wp-config sin `WP_CACHE` añadido por nosotros; home 200.

## Test plan
- `tests/test-dropin.php`: `chc_dropin_cache_file` (sirve/null en 7 casos) + `set_wp_cache_in` sobre archivo temporal (añade/quita el define). Modela el estilo de `tests/test-cache-store.php`.
- Verificación: `php tests/run.php` → 0 failed; Step 5.

## Done criteria
- [ ] `php tests/run.php` → 0 failed, con los tests nuevos del drop-in.
- [ ] `php -l` limpio en los archivos tocados/creados.
- [ ] Step 5: la función localiza el archivo real de la home; **NO** se instaló `advanced-cache.php` ni se añadió `WP_CACHE` en Keypro; home 200.
- [ ] `dropin_enabled` queda en 0 (OFF) en Keypro.
- [ ] `plans/README.md` fila 005 = DONE.

## STOP conditions
- Drift en "Current state".
- Cualquier intento de que el paso de verificación **active** el drop-in en Keypro (instalar advanced-cache.php o editar el wp-config real) — NO hacerlo; el drop-in queda OFF.
- El sitio deja de dar 200 en cualquier momento — STOP y reporta.
- Una verificación falla dos veces tras un intento razonable.

## Maintenance notes
- El drop-in v1 sirve solo query vacía; añadir params de tracking (paridad con el plan 003) es mejora futura.
- La edición de wp-config es el punto más delicado: si se activa en un host real (nginx), verificar 200 justo después y tener el `wp-config.php.chc-bak` a mano.
- La desactivación del plugin quita el drop-in + WP_CACHE (evita un advanced-cache.php huérfano que corra sin el plugin).
- El reviewer debe confirmar: en Keypro NO se activó nada; el drop-in retorna sin romper cuando no hay cache; y `set_wp_cache_in` no corrompe un wp-config (probar en temporal).
