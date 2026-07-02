# Plan 003: Cachear páginas con solo parámetros de tracking (utm_*, gclid, fbclid…)

> **Executor instructions**: sigue el plan paso a paso; corre cada verificación. Al terminar actualiza la fila 003 en `plans/README.md`.
>
> **Drift check (primero)**: `git -C "C:/Users/Luis Oses/Tools/corehost-cache" diff --stat 2d26ffe..HEAD -- includes/class-request-rules.php includes/class-htaccess.php corehost-cache.php tests/test-request-rules.php tests/test-htaccess.php` — si cambió algo, compara con "Current state".

## Status
- **Priority**: P1
- **Effort**: M
- **Risk**: MED (toca la condición de query en el `.htaccess`; un regex malo rompería el servicio del cache — verificar que el sitio sigue vivo)
- **Depends on**: none (parte de `main` a `2d26ffe`)
- **Category**: direction (feature) + performance (hit-rate)
- **Planned at**: commit `2d26ffe`, 2026-07-02

## Why this matters
Hoy **cualquier** query string salta el cache (`RewriteCond %{QUERY_STRING} ^$` en el `.htaccess`, y `query !== ''` en `is_cacheable()`). Los enlaces de campañas (Meta/Google/email) llevan `?utm_source=…`, `?gclid=…`, `?fbclid=…`, así que **todo el tráfico pagado pega en PHP (0% hit)**. Estos parámetros son solo para analítica (los lee JS en el cliente) y **no cambian el HTML renderizado en servidor**, así que se puede servir la misma página cacheada. Este plan cachea/sirve las requests cuya query esté **vacía O compuesta solo de parámetros de tracking**, usando la clave sin query (la ruta). Sube muchísimo el hit-rate en tráfico de campañas.

## Decisiones de diseño (ya tomadas — no re-preguntar)
- **Lista de params de tracking (fuente única):** una función `chc_tracking_params(): array` en `corehost-cache.php`, filtrable con `apply_filters('chc_tracking_params', $list)`. Default: cualquiera con prefijo `utm_`, más exactos `gclid, gclsrc, dclid, wbraid, gbraid, fbclid, msclkid, mc_cid, mc_eid, ttclid, twclid, igshid, yclid`. La MISMA lista alimenta el `.htaccess` y `is_cacheable()`.
- **Clave de cache = la ruta (sin query).** Apache `%{REQUEST_URI}` ya excluye la query; y `CHC_Cache_Store::dir_for()` hace `parse_url(..., PHP_URL_PATH)` (quita la query). Así, servir/generar para `/x/?utm=1` usa `.../x/index.html`.
- **Se relaja tanto SERVIR como GENERAR.** Servir: el `.htaccess` acepta query vacía o solo-tracking. Generar: `is_cacheable()` acepta lo mismo (así las páginas que solo reciben tráfico de campaña también se cachean).
- **Sin cambios de UI.** Es transparente.

## Current state
- `includes/class-request-rules.php`:
  - `should_cache()` tiene: `if (($c['query'] ?? '') !== '') { return false; }`
  - `is_cacheable()` arma el contexto con `'query' => $_SERVER['QUERY_STRING'] ?? ''`.
- `includes/class-htaccess.php` — `rules(string $cache_url_path): string`. Excerpt de las condiciones (dentro de `<IfModule mod_rewrite.c>`):
  ```php
  . "RewriteCond %{HTTP_HOST} ^[a-zA-Z0-9.\\-]+$\n"
  . "RewriteCond %{REQUEST_METHOD} GET\n"
  . "RewriteCond %{QUERY_STRING} ^$\n"
  . "RewriteCond %{REQUEST_URI} /$\n"
  . "RewriteCond %{HTTP_COOKIE} !($skip_cookies) [NC]\n"
  . "RewriteCond %{REQUEST_URI} !$skip_uris [NC]\n"
  . "RewriteCond %{REQUEST_URI} !\\.\\. [NC]\n"
  . "RewriteCond %{DOCUMENT_ROOT}{$file} -f\n"
  . "RewriteRule .* {$file} [E=CHC_HIT:1,L,T=text/html]\n"
  ```
  `rules()` es pura y se testea en `tests/test-htaccess.php`. La llama `chc_refresh_htaccess()` en `corehost-cache.php`: `CHC_Htaccess::install(chc_root_htaccess(), CHC_Htaccess::rules(chc_cache_url_path()))`.
- Tests puros: `php tests/run.php` (79 passed actualmente). Helpers `t_eq`/`t_assert`.

**Convenciones:** clase `CHC_*`, guarda ABSPATH, funciones puras testeables sin WP (ver `should_cache`, `CHC_Htaccess::rules`, `matches_any`).

## Commands
Igual que el plan 001 (ver ese archivo, sección "Commands you will need"): DEPLOY (tar→ssh), tests (`php .../tests/run.php`), `php -l`, WP-CLI. Filtra la salida SSH con `grep -v "post-quantum\|store now\|upgraded\|openssh.com"`. Corre DEPLOY y tests como comandos separados. Tras cambiar reglas: `wp ... eval 'chc_refresh_htaccess();'`.

## Scope
**In scope:** `corehost-cache.php` (añadir `chc_tracking_params()` + pasar la lista a `rules()` en `chc_refresh_htaccess()`), `includes/class-request-rules.php`, `includes/class-htaccess.php`, `tests/test-request-rules.php`, `tests/test-htaccess.php`.
**Out of scope:** cache-store, page-generator, role-gate, admin, cloudflare, purge, post-meta. No cambiar la clave de cache ni el esquema de archivos.

## Git workflow
Rama `feat/003-tracking-params`. Commit por unidad. No push/merge.

## Steps

### Step 1: `chc_tracking_params()` (fuente única)
En `corehost-cache.php`, añade:
```php
/** Parámetros de query que son solo de tracking (no cambian el HTML de servidor). */
function chc_tracking_params(): array
{
    $list = ['gclid','gclsrc','dclid','wbraid','gbraid','fbclid','msclkid','mc_cid','mc_eid','ttclid','twclid','igshid','yclid'];
    return array_values(array_unique(array_map('strval', (array) apply_filters('chc_tracking_params', $list))));
}
```
(El prefijo `utm_` se maneja aparte en los regex; no va en esta lista de exactos.)
**Verify**: `php -l corehost-cache.php`.

### Step 2: Helper puro `query_only_tracking()` + usar en `should_cache`
En `includes/class-request-rules.php` añade método puro:
```php
/** true si la query está vacía o SOLO tiene params de tracking (utm_* o de la lista). */
public static function query_only_tracking(string $qs, array $params): bool
{
    if ($qs === '') { return true; }
    parse_str($qs, $parsed);
    if (!$parsed) { return true; }
    foreach (array_keys($parsed) as $key) {
        $key = (string) $key;
        if (strpos($key, 'utm_') === 0) { continue; }
        if (!in_array($key, $params, true)) { return false; }
    }
    return true;
}
```
Cambia en `should_cache()` la línea de query por:
```php
if (($c['query'] ?? '') !== '' && empty($c['query_only_tracking'])) { return false; }
```
**Verify**: en `tests/test-request-rules.php` agrega:
```php
$tp = ['gclid','fbclid'];
t_assert(CHC_Request_Rules::query_only_tracking('', $tp) === true, 'query vacía');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=fb&utm_medium=cpc', $tp) === true, 'solo utm');
t_assert(CHC_Request_Rules::query_only_tracking('fbclid=abc', $tp) === true, 'fbclid');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=x&fbclid=y', $tp) === true, 'utm+fbclid');
t_assert(CHC_Request_Rules::query_only_tracking('p=1', $tp) === false, 'param real => no');
t_assert(CHC_Request_Rules::query_only_tracking('utm_source=x&p=1', $tp) === false, 'mezcla con real => no');
t_assert(CHC_Request_Rules::should_cache(['query'=>'utm_source=x','query_only_tracking'=>true] + $ok) === true, 'solo-tracking => cacheable');
t_assert(CHC_Request_Rules::should_cache(['query'=>'p=1'] + $ok) === false, 'query real sigue => no');
```
DEPLOY + tests → pasan (sube el conteo).

### Step 3: `is_cacheable()` calcula `query_only_tracking`
En `is_cacheable()`, antes del `return self::should_cache([...])`:
```php
$query = $_SERVER['QUERY_STRING'] ?? '';
```
(si ya existe una variable para la query, reutilízala) y añade al contexto:
```php
'query'               => $query,
'query_only_tracking' => self::query_only_tracking($query, chc_tracking_params()),
```
**Verify**: `php -l includes/class-request-rules.php`.

### Step 4: `.htaccess` acepta query vacía o solo-tracking
En `includes/class-htaccess.php`, cambia la firma a `rules(string $cache_url_path, array $tracking_params = [])`. Construye el fragmento de key del regex:
```php
$keys = array_merge(['utm_[^=&]*'], array_map('preg_quote', $tracking_params));
$k    = implode('|', $keys);
$q_re = '^$|^(?:' . $k . ')=[^&]*(?:&(?:' . $k . ')=[^&]*)*$';
```
y reemplaza la línea `"RewriteCond %{QUERY_STRING} ^$\n"` por:
```php
. "RewriteCond %{QUERY_STRING} {$q_re} [NC]\n"
```
(Deja el resto de condiciones igual.) En `corehost-cache.php` `chc_refresh_htaccess()`, pasa la lista: `CHC_Htaccess::rules(chc_cache_url_path(), chc_tracking_params())`.
**Verify**: en `tests/test-htaccess.php`, cambia/añade:
```php
$block = CHC_Htaccess::rules('/key/wp-content/cache/corehost-cache', ['gclid','fbclid']);
t_assert(str_contains($block, 'utm_[^=&]*'), 'acepta utm_ en query');
t_assert(str_contains($block, 'gclid'), 'acepta gclid en query');
t_assert(!str_contains($block, 'RewriteCond %{QUERY_STRING} ^$\n') || str_contains($block,'utm_'), 'la cond de query ya no es solo-vacía');
```
(Ajusta las aserciones existentes de test-htaccess que asumían `%{QUERY_STRING} ^$` exacto, si las hay.) DEPLOY + tests → pasan.

### Step 5: Verificación funcional en Keypro
```bash
P=/home/corehost/public_html/key; U="https://aldeahostlatam.com/key/"
# regenerar htaccess con las reglas nuevas y calentar la home
ssh aldeahostlatam "wp --path=$P --allow-root eval 'chc_refresh_htaccess();'"
ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache purge >/dev/null"
curl -s -o /dev/null "$U"                                  # genera clean
echo "clean:"; curl -s -o /dev/null -D - --compressed "$U"            | grep -i x-corehost-cache   # HIT
echo "utm:";   curl -s -o /dev/null -D - --compressed "$U?utm_source=fb&utm_medium=cpc" | grep -i x-corehost-cache   # HIT
echo "fbclid:";curl -s -o /dev/null -D - --compressed "$U?fbclid=abc123" | grep -i x-corehost-cache                  # HIT
echo "real (NO debe HIT):"; curl -s -o /dev/null -D - --compressed "$U?p=1" | grep -i x-corehost-cache || echo "sin HIT = correcto"
echo "sitio vivo:"; curl -s -o /dev/null -w "%{http_code}\n" "$U"   # 200
# también: una request utm a una página no cacheada aún la genera (clave sin query)
```
**Verify**: clean/utm/fbclid → `X-CoreHost-Cache: HIT`; `?p=1` → sin HIT; home → 200 (el sitio no se rompió por el regex).

## Test plan
- `tests/test-request-rules.php`: casos de `query_only_tracking` (vacía, solo-utm, fbclid, mezcla, param real, mezcla-con-real) + should_cache con solo-tracking.
- `tests/test-htaccess.php`: el bloque acepta utm_/gclid en la query.
- Verificación: `php tests/run.php` → 0 failed; funcional Step 5.

## Done criteria
- [ ] `php tests/run.php` → 0 failed, con los tests nuevos.
- [ ] `php -l` limpio en los archivos tocados.
- [ ] Step 5: clean/utm/fbclid = HIT; `?p=1` = sin HIT; home = 200.
- [ ] `plans/README.md` fila 003 = DONE.

## STOP conditions
- Drift en "Current state".
- El sitio devuelve 500/no carga tras instalar el `.htaccess` nuevo (regex mal) — **revierte el bloque** (`wp ... eval 'chc_refresh_htaccess();'` tras corregir, o restaura el `.htaccess.bak-chc` si existe) y reporta.
- Una verificación falla dos veces tras un intento razonable.

## Maintenance notes
- Riesgo residual: una página que en SERVIDOR varíe su HTML por un param de tracking (mal uso, raro) serviría contenido cacheado igual. Los params de tracking por definición no cambian el render de servidor.
- Si se añaden params vía el filtro `chc_tracking_params`, hay que **regenerar el `.htaccess`** (guardar Ajustes o `wp ... eval 'chc_refresh_htaccess();'`) para que el regex del server se actualice.
- El reviewer debe revisar el regex de query del `.htaccess` (que no permita params reales) y que el sitio siga vivo.
