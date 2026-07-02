# Plan 001: Toggle "No cachear esta página" por post + purga tras actualizaciones

> **Executor instructions**: sigue el plan paso a paso. Corre cada comando de verificación y confirma el resultado esperado antes de avanzar. Si ocurre algo de "STOP conditions", detente y reporta. Al terminar, actualiza la fila de este plan en `plans/README.md`.
>
> **Drift check (córrelo primero)**: `git -C "C:/Users/Luis Oses/Tools/corehost-cache" diff --stat 54391ec..HEAD -- includes/class-request-rules.php includes/class-purge.php corehost-cache.php tests/test-request-rules.php` — si algún archivo en scope cambió, compara los excerpts de "Current state" con el código vivo antes de seguir; si no coinciden, es STOP.

## Status
- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: direction (feature) + correctness
- **Planned at**: commit `54391ec`, 2026-07-02

## Why this matters
Hoy solo se puede excluir del cache por lista global de URLs (Ajustes). Falta (a) un control por página en el editor para marcar "esta página no se cachea" (formularios, páginas muy dinámicas), y (b) purgar el cache cuando se actualiza un plugin/tema/core (cambian markup/assets y quedan páginas viejas servidas desde disco). Ambos cierran huecos de frescura con muy poco código.

## Current state
Repo: `C:\Users\Luis Oses\Tools\corehost-cache` (WordPress plugin, PHP 8.1). Sin Composer. Tests puros en `tests/` corren con `php tests/run.php` (aserciones `t_eq`/`t_assert`; ver `tests/run.php` y `tests/bootstrap.php`).

- `includes/class-request-rules.php` — `should_cache(array $c): bool` (pura) e `is_cacheable(): bool` (glue WP que arma el contexto). Excerpt actual de `should_cache` (las guardas):
  ```php
  public static function should_cache(array $c): bool
  {
      if (!empty($c['is_admin']))  { return false; }
      if (!empty($c['logged_in'])) { return false; }
      if (($c['method'] ?? 'GET') !== 'GET') { return false; }
      if (($c['query'] ?? '') !== '') { return false; }
      if ((int) ($c['status'] ?? 200) !== 200) { return false; }
      if (stripos((string) ($c['content_type'] ?? 'text/html'), 'text/html') === false) { return false; }
      if (!empty($c['is_feed']))    { return false; }
      if (!empty($c['donotcache'])) { return false; }
      if (!empty($c['excluded_url'])) { return false; }
      if (!empty($c['woo_dynamic'])) { return false; }
      if (!empty($c['password_required'])) { return false; }
      if (!empty($c['bypass_cookie']))     { return false; }
      return true;
  }
  ```
  Y en `is_cacheable()` se arma el array de contexto (`is_admin`, `logged_in`, …, `password_required`, `bypass_cookie`).
- `includes/class-purge.php` — `CHC_Purge::register()` engancha `save_post/deleted_post/trashed_post`, comentarios, `switch_theme/customize_save_after/wp_update_nav_menu`, `chc_ttl_sweep`, y (fix reciente) hooks WC de stock. Métodos: `on_post`, `on_comment`, `url($permalink)`, `all()`, `ttl_sweep()`.
- `corehost-cache.php` — bootstrap; al final registra componentes: `(new CHC_Page_Generator())->register(); (new CHC_Purge())->register(); (new CHC_Role_Gate())->register(); (new CHC_Admin_Bar())->register(); if (is_admin()) { (new CHC_Admin_Page())->register(); }`.

**Convenciones a seguir:** clases `CHC_*`, prefijo `chc_`, guarda `if (!defined('ABSPATH')) { exit; }` al inicio de cada archivo, escapado WP (`esc_html`/`esc_attr`), nonces + `current_user_can` en todo lo de admin. Mira `admin/class-admin-bar.php` como exemplar de nonce + capability + hooks.

## Commands you will need
| Purpose | Command | Expected |
|---|---|---|
| Deploy al server | `cd "C:/Users/Luis Oses/Tools/corehost-cache" && tar --exclude='./.git' --exclude='./docs' --exclude='./plans' -cf - . \| ssh aldeahostlatam "tar -xf - -C /home/corehost/public_html/key/wp-content/plugins/corehost-cache"` | sin error |
| Tests puros | `ssh aldeahostlatam 'php /home/corehost/public_html/key/wp-content/plugins/corehost-cache/tests/run.php'` | `N passed, 0 failed` |
| Lint | `ssh aldeahostlatam 'php -l <ruta-en-server>'` | "No syntax errors" |
| WP-CLI | `ssh aldeahostlatam 'wp --path=/home/corehost/public_html/key --allow-root <args>'` | — |

Nota: correr DEPLOY y los tests como comandos SEPARADOS (el `grep -v ...` de limpieza de warnings SSH sale con código 1 si filtra todo). Filtra la salida SSH con `2>&1 | grep -v "post-quantum\|store now\|upgraded\|openssh.com"`.

## Scope
**In scope:**
- `includes/class-request-rules.php` (modificar)
- `includes/class-purge.php` (modificar)
- `admin/class-post-meta.php` (crear)
- `corehost-cache.php` (require + register)
- `tests/test-request-rules.php` (modificar)

**Out of scope:** `includes/class-htaccess.php`, `class-cache-store.php`, `class-page-generator.php`, `class-role-gate.php`, `admin/class-admin-page.php`, `admin/class-admin-bar.php`. No cambiar el esquema de la clave de cache ni el `.htaccess`.

## Git workflow
Rama: `feat/001-page-toggle`. Commit por unidad lógica. NO push ni PR salvo que te lo pidan.

## Steps

### Step 1: `should_cache()` respeta el meta por página
En `includes/class-request-rules.php`, dentro de `should_cache`, agrega antes del `return true;`:
```php
if (!empty($c['no_cache_meta'])) { return false; }
```
En `tests/test-request-rules.php` agrega (junto a las demás aserciones con `$ok`):
```php
t_assert(CHC_Request_Rules::should_cache(['no_cache_meta'=>true] + $ok) === false, 'meta no-cache => no');
```
**Verify**: DEPLOY, luego tests → cuenta sube en 1 y `0 failed`.

### Step 2: `is_cacheable()` calcula `no_cache_meta`
En `is_cacheable()`, antes del `return self::should_cache([...])`, calcula:
```php
$no_cache_meta = false;
if (is_singular()) {
    $qid = get_queried_object_id();
    if ($qid) { $no_cache_meta = (bool) get_post_meta($qid, '_chc_no_cache', true); }
}
```
y añade al array de contexto: `'no_cache_meta' => $no_cache_meta,`.
**Verify**: `php -l` del archivo → sin errores. (El efecto se prueba en Step 6.)

### Step 3: Meta box en el editor (`admin/class-post-meta.php`)
Crea la clase `CHC_Post_Meta` (guarda `if (!defined('ABSPATH')) { exit; }`). Registra `add_meta_box` en los post types públicos (`get_post_types(['public'=>true])` menos `attachment`), lado `side`, con un checkbox "No cachear esta página en CoreHost Cache" (nonce `chc_no_cache_meta`), y un handler `save_post` que:
- verifica nonce, `current_user_can('edit_post', $post_id)`, no autosave/revision;
- guarda `update_post_meta($post_id, '_chc_no_cache', 1)` si marcado, o `delete_post_meta($post_id, '_chc_no_cache')` si no.
Forma objetivo del render:
```php
public function box($post) {
    wp_nonce_field('chc_no_cache_meta', 'chc_no_cache_nonce');
    $on = (bool) get_post_meta($post->ID, '_chc_no_cache', true);
    echo '<label><input type="checkbox" name="chc_no_cache" value="1" ' . checked($on, true, false) . '> '
       . esc_html__('No cachear esta página en CoreHost Cache', 'corehost-cache') . '</label>';
}
```
**Verify**: `php -l admin/class-post-meta.php` → sin errores.

### Step 4: Registrar la clase
En `corehost-cache.php`: `require_once CHC_DIR . 'admin/class-post-meta.php';` con los demás require, y dentro de `if (is_admin()) { ... }` añade `(new CHC_Post_Meta())->register();`.
**Verify**: `wp ... eval 'echo class_exists("CHC_Post_Meta")?"OK\n":"NO\n";'` → OK.

### Step 5: Purga tras actualizaciones
En `includes/class-purge.php` `register()`, añade: `add_action('upgrader_process_complete', [$this, 'all'], 10, 0);`
**Verify**: `php -l includes/class-purge.php` → sin errores.

### Step 6: Verificación funcional en Keypro
```bash
P=/home/corehost/public_html/key
# crear página con el toggle activado
PID=$(ssh aldeahostlatam "wp --path=$P --allow-root post create --post_type=page --post_status=publish --post_title='CHC NoCache Test' --porcelain")
ssh aldeahostlatam "wp --path=$P --allow-root post meta update $PID _chc_no_cache 1"
ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache purge >/dev/null"
URL=$(ssh aldeahostlatam "wp --path=$P --allow-root post url $PID")  # o construir con get_permalink
curl -s -o /dev/null "$URL"
# la página marcada NO debe generar archivo de cache:
ssh aldeahostlatam "find $P/wp-content/cache/corehost-cache -path '*chc-nocache-test*' -name index.html | wc -l"   # espera 0
# quitar el meta y confirmar que ahora SÍ cachea:
ssh aldeahostlatam "wp --path=$P --allow-root post meta delete $PID _chc_no_cache"
curl -s -o /dev/null "$URL"
ssh aldeahostlatam "find $P/wp-content/cache/corehost-cache -path '*chc-nocache-test*' -name index.html | wc -l"   # espera 1
# limpiar
ssh aldeahostlatam "wp --path=$P --allow-root post delete $PID --force"
```
**Verify**: con meta=1 → 0 archivos; sin meta → 1 archivo.

## Test plan
- `tests/test-request-rules.php`: 1 aserción nueva (`no_cache_meta` ⇒ false). Modela el estilo de las aserciones existentes con `$ok`.
- Verificación: `php tests/run.php` → todas pasan (una más que antes).
- Funcional: Step 6 (en Keypro).

## Done criteria
- [ ] `php tests/run.php` → `0 failed`, con la aserción nueva de `no_cache_meta`.
- [ ] `php -l` limpio en los 4 archivos PHP tocados/creados.
- [ ] `class_exists("CHC_Post_Meta")` = true en el server.
- [ ] Step 6: página con `_chc_no_cache=1` NO se cachea; sin el meta SÍ.
- [ ] `plans/README.md` fila 001 actualizada.

## STOP conditions
- El código en "Current state" no coincide con el vivo (drift).
- Una verificación falla dos veces tras un intento razonable de arreglo.
- Necesitas tocar un archivo fuera de scope.

## Maintenance notes
- Si en el futuro se añade caché por query-string o multisite, revisar que `is_singular()`/`get_queried_object_id()` sigan aplicando.
- El reviewer debe confirmar nonce + `current_user_can('edit_post')` en el guardado del meta box.
- `upgrader_process_complete` purga TODO en cualquier update (plugin/tema/core/traducción). Es intencional (seguro, se regenera).
