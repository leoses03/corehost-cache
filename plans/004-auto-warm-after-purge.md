# Plan 004: Auto-precarga tras purga total + centralizar la purga total

> **Executor instructions**: sigue el plan paso a paso; corre cada verificación. Al terminar actualiza la fila 004 en `plans/README.md`.
>
> **Drift check (primero)**: `git -C "C:/Users/Luis Oses/Tools/corehost-cache" diff --stat 3efbd8f..HEAD -- corehost-cache.php includes/class-purge.php includes/class-cli.php admin/class-admin-page.php admin/class-admin-bar.php` — si cambió algo, compara con "Current state".

## Status
- **Priority**: P2
- **Effort**: M
- **Risk**: LOW-MED (refactor de 4 call-sites de purga; verificar que todas las purgas siguen funcionando)
- **Depends on**: none (parte de `main` a `3efbd8f`)
- **Category**: direction (feature) + tech-debt (centralizar purga)
- **Planned at**: commit `3efbd8f`, 2026-07-02

## Why this matters
Dos cosas: (1) Tras una **purga total** el cache queda vacío y el primer visitante de cada página paga el render completo; una auto-precarga en segundo plano lo mantiene caliente. (2) Hoy las purgas totales están **duplicadas e inconsistentes**: `CHC_Purge::all()` purga local **y** Cloudflare, pero el botón AJAX, la barra de admin y el WP-CLI llaman a `chc_store()->purge_all()` directo — **sin purgar Cloudflare** y repitiendo `update_option('chc_last_purge')`. Este plan centraliza todo en una función `chc_purge_all()` (local + Cloudflare + `chc_last_purge` + un `do_action`), enruta los 4 call-sites por ella (arreglando la purga de CF en las manuales), y engancha la auto-precarga en ese action.

## Decisiones de diseño (ya tomadas)
- **`chc_purge_all()`** (nueva, en `corehost-cache.php`): `chc_store()->purge_all(); (new CHC_Cloudflare())->purge_all(); update_option('chc_last_purge', time(), false); do_action('chc_purge_all_done');`. Todos los call-sites de purga TOTAL la usan.
- **Auto-precarga:** toggle `auto_warm` (default **OFF**). Al dispararse `chc_purge_all_done`, si está activada y no hay ya un evento agendado, `wp_schedule_single_event(time()+30, 'chc_auto_warm')` (debounce: varias purgas seguidas coalescen en una). El handler `chc_auto_warm` recorre `CHC_Admin_Page::warm_urls()` con `wp_remote_get` (contexto cron, sin timeout de request).
- **Solo purgas TOTALES** disparan auto-warm (no las de una URL puntual, para no estampidar en cada guardado de post).
- Default OFF por prudencia (en sitios con purgas frecuentes evita carga inesperada); el usuario lo activa.

## Current state
- `corehost-cache.php` — `chc_settings()` defaults `['enabled','ttl_hours','excluded_urls','cf_enabled','cf_zone','cf_token', + excluded_roles]`. Deactivation hook existente hace `CHC_Htaccess::remove(...); wp_clear_scheduled_hook('chc_ttl_sweep'); chc_store()->purge_all();`. `CHC_Admin_Page::warm_urls()` es **estática y pública** y la clase se `require`a siempre (solo su `register()` está tras `is_admin()`), así que es llamable desde cron.
- `includes/class-purge.php` — `public function all(): void { chc_store()->purge_all(); (new CHC_Cloudflare())->purge_all(); }`
- `admin/class-admin-page.php` — `ajax_purge()`: `check_ajax_referer('chc_purge'); if (!current_user_can('manage_options')) {...}; chc_store()->purge_all(); update_option('chc_last_purge', time(), false); wp_send_json_success(['ok'=>true]);`. `sanitize()` y `render()` con la tabla de ajustes.
- `admin/class-admin-bar.php` — `handle()`: `... check_admin_referer(...); chc_store()->purge_all(); update_option('chc_last_purge', time(), false); wp_safe_redirect(...); exit;`
- `includes/class-cli.php` — `purge()`: `chc_store()->purge_all(); update_option('chc_last_purge', time(), false); WP_CLI::success('Cache purgada.');`

**Convenciones:** funciones `chc_*` en el bootstrap; clases `CHC_*`; guarda ABSPATH.

## Commands
Como el plan 001 (DEPLOY, tests, `php -l`, WP-CLI). Filtra SSH con `grep -v "post-quantum\|store now\|upgraded\|openssh.com"`; DEPLOY y tests separados.

## Scope
**In scope:** `corehost-cache.php`, `includes/class-purge.php`, `admin/class-admin-page.php`, `admin/class-admin-bar.php`, `includes/class-cli.php`.
**Out of scope:** cache-store, htaccess, request-rules, role-gate, page-generator, cloudflare, post-meta. No cambiar el esquema de cache. No hay tests puros nuevos (es glue de cron/WP; se verifica por integración).

## Git workflow
Rama `feat/004-auto-warm`. Commit por unidad. No push/merge.

## Steps

### Step 1: `chc_purge_all()` + settings + handlers de warm (en `corehost-cache.php`)
- `chc_settings()` defaults: añade `'auto_warm' => 0`.
- Añade estas funciones y hooks:
```php
/** Purga TOTAL centralizada: cache local + Cloudflare + marca + evento. */
function chc_purge_all(): void
{
    chc_store()->purge_all();
    (new CHC_Cloudflare())->purge_all();
    update_option('chc_last_purge', time(), false);
    do_action('chc_purge_all_done');
}

/** Agenda una auto-precarga (una sola vez, con debounce) si está activada. */
function chc_maybe_schedule_warm(): void
{
    if (empty(chc_settings()['auto_warm'])) { return; }
    if (!wp_next_scheduled('chc_auto_warm')) {
        wp_schedule_single_event(time() + 30, 'chc_auto_warm');
    }
}
add_action('chc_purge_all_done', 'chc_maybe_schedule_warm');

/** Handler del cron: recorre las URLs y las regenera en cache. */
function chc_run_auto_warm(): void
{
    if (empty(chc_settings()['auto_warm'])) { return; }
    foreach (CHC_Admin_Page::warm_urls() as $url) {
        wp_remote_get($url, ['timeout' => 15, 'sslverify' => false, 'user-agent' => 'CoreHostCache-Warmer']);
    }
}
add_action('chc_auto_warm', 'chc_run_auto_warm');
```
- En el **deactivation hook** añade: `wp_clear_scheduled_hook('chc_auto_warm');`
**Verify**: `php -l corehost-cache.php`.

### Step 2: Enrutar los 4 call-sites por `chc_purge_all()`
- `includes/class-purge.php` `all()`: reemplaza el cuerpo por `chc_purge_all();`
- `admin/class-admin-page.php` `ajax_purge()`: reemplaza `chc_store()->purge_all(); update_option('chc_last_purge', time(), false);` por `chc_purge_all();` (deja el `check_ajax_referer` + `current_user_can` + `wp_send_json_success`).
- `admin/class-admin-bar.php` `handle()`: reemplaza `chc_store()->purge_all(); update_option('chc_last_purge', time(), false);` por `chc_purge_all();` (deja nonce/cap/redirect).
- `includes/class-cli.php` `purge()`: reemplaza `chc_store()->purge_all(); update_option('chc_last_purge', time(), false);` por `chc_purge_all();` (deja el `WP_CLI::success`).
**Verify**: `php -l` limpio en los 4 archivos.

### Step 3: Toggle en Ajustes
En `admin/class-admin-page.php`: `sanitize()` añade `'auto_warm' => empty($input['auto_warm']) ? 0 : 1,`. `render()` añade una fila:
```php
<tr><th scope="row">Auto-precarga</th><td>
    <label><input type="checkbox" name="chc_settings[auto_warm]" value="1" <?php checked($s['auto_warm']); ?>> Precargar el cache automáticamente tras una purga total</label>
    <p class="description">Agenda una precarga en segundo plano (WP-Cron) unos segundos después de purgar todo.</p>
</td></tr>
```
**Verify**: `php -l admin/class-admin-page.php`.

### Step 4: Verificación funcional en Keypro
```bash
P=/home/corehost/public_html/key
# activar auto_warm por opción (simula guardar el toggle)
ssh aldeahostlatam "wp --path=$P --allow-root eval '\$s=(array)get_option(\"chc_settings\",[]); \$s[\"auto_warm\"]=1; update_option(\"chc_settings\",\$s);'"
# purga total vía CLI (ahora enruta por chc_purge_all -> agenda el evento)
ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache purge"
echo '¿evento agendado?'; ssh aldeahostlatam "wp --path=$P --allow-root cron event list --fields=hook --format=csv | grep -c chc_auto_warm"   # espera 1
echo 'páginas antes de correr el warm:'; ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache status | head -1"   # ~0
ssh aldeahostlatam "wp --path=$P --allow-root cron event run chc_auto_warm"
echo 'páginas después:'; ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache status | head -1"   # > 0 (recalentado)
# desactivar y confirmar que ya NO agenda
ssh aldeahostlatam "wp --path=$P --allow-root eval '\$s=(array)get_option(\"chc_settings\",[]); \$s[\"auto_warm\"]=0; update_option(\"chc_settings\",\$s);'"
ssh aldeahostlatam "wp --path=$P --allow-root corehost-cache purge"
ssh aldeahostlatam "wp --path=$P --allow-root cron event list --fields=hook --format=csv | grep -c chc_auto_warm"   # espera 0
```
**Verify**: con `auto_warm=1` → evento agendado tras purgar, y correrlo repuebla el cache (páginas > 0). Con `auto_warm=0` → no agenda. El sitio sigue en 200.

## Test plan
- No hay tests puros nuevos (es glue de cron). Verificación por integración (Step 4). Los tests puros existentes deben seguir en `0 failed` tras el refactor: DEPLOY + `php tests/run.php`.

## Done criteria
- [ ] `php tests/run.php` → `0 failed` (los existentes, no rotos por el refactor).
- [ ] `php -l` limpio en los 5 archivos.
- [ ] Step 4: con `auto_warm=1`, purgar agenda `chc_auto_warm` y correrlo repuebla el cache; con `auto_warm=0`, no agenda.
- [ ] Purga manual (CLI) sigue funcionando y ahora pasa por `chc_purge_all()`.
- [ ] `plans/README.md` fila 004 = DONE.

## STOP conditions
- Drift en "Current state".
- Tras el refactor, alguna purga (botón/barra/CLI/evento) deja de purgar el cache local — STOP y reporta.
- Una verificación falla dos veces tras un intento razonable.

## Maintenance notes
- Este refactor hace que **todas** las purgas totales purguen también Cloudflare (antes solo las de evento) — beneficio colateral; el reviewer debe confirmarlo.
- En sitios enormes, `chc_run_auto_warm` recorre hasta 2000 URLs en un solo evento cron; si aparece un caso con miles de páginas, considerar trocear en varios eventos. Documentado, no implementado (YAGNI).
- WP-Cron depende de tráfico; en sitios de muy bajo tráfico la auto-precarga puede tardar. El botón manual "Precargar" sigue disponible para inmediato.
