# Plan 002: Purga de Cloudflare al invalidar el cache

> **Executor instructions**: sigue el plan paso a paso; corre cada verificación. Al terminar actualiza la fila 002 en `plans/README.md`.
>
> **Drift check (primero)**: `git -C "C:/Users/Luis Oses/Tools/corehost-cache" diff --stat 54391ec..HEAD -- includes/class-purge.php corehost-cache.php admin/class-admin-page.php` — si cambió algo, compara con "Current state".

## Status
- **Priority**: P1
- **Effort**: M
- **Risk**: MED (llama una API externa; debe ser no-fatal si falla)
- **Depends on**: 001 (ambos extienden `CHC_Purge`; hacer 001 primero y construir sobre él)
- **Category**: direction (feature) + correctness (frescura tras CDN)
- **Planned at**: commit `54391ec`, 2026-07-02

## Why this matters
Cuando el sitio está detrás de **Cloudflare** (varios sitios de la flota lo están), purgar el cache local no basta: el edge de CF sigue sirviendo la versión vieja hasta que expire su TTL. Al editar contenido el visitante ve HTML desactualizado. Este plan hace que, al invalidar localmente, también se purgue CF (por URL en cambios puntuales, todo en cambios estructurales), manteniendo el edge en sync.

## Decisiones de diseño (ya tomadas — no re-preguntar)
- **Auth CF:** API Token con permiso *Zone → Cache Purge* + **Zone ID**. El token se lee de la constante `CHC_CF_TOKEN` (si está definida en `wp-config.php`) y si no, de la opción `chc_settings['cf_token']`. El Zone ID de `chc_settings['cf_zone']`. NUNCA loguear ni imprimir el token.
- **Estrategia de purga:** por URL con el endpoint `purge_cache` `{"files":[...]}` (máx 30 por llamada) en cambios puntuales; `{"purge_everything":true}` en purga total/estructural.
- **Gating:** solo actúa si `chc_settings['cf_enabled']` y hay zone y token.
- **Ejecución:** `wp_remote_post` bloqueante con `timeout=5`, **no-fatal** ante error (no romper el guardado del post). Guarda el último error en la opción `chc_cf_last_error` (texto corto) para mostrarlo en el admin.
- **Endpoint:** `https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache`, header `Authorization: Bearer <token>`, `Content-Type: application/json`.

## Current state
- `includes/class-purge.php` — centraliza la invalidación. Métodos: `url($permalink)` (borra local vía `chc_store()->delete_all_hosts(path)`), `all()` (`chc_store()->purge_all()`), `on_post`, `on_comment`, `ttl_sweep`. Aquí se enganchan también los hooks de CF (llamando al nuevo `CHC_Cloudflare`).
- `corehost-cache.php` — `chc_settings()` arma defaults + `excluded_roles`. Excerpt:
  ```php
  function chc_settings(): array {
      $d = ['enabled' => 1, 'ttl_hours' => 10, 'excluded_urls' => ''];
      $s = array_merge($d, (array) get_option('chc_settings', []));
      if (!isset($s['excluded_roles'])) { $s['excluded_roles'] = ...; }
      return $s;
  }
  ```
- `admin/class-admin-page.php` — `sanitize($input)` y `render()` con la tabla `form-table`. Aquí van los campos CF nuevos.

**Convenciones:** clases `CHC_*`, guarda ABSPATH, escapado, nonces. El patrón de "llamar API externa desde un componente y ser no-fatal" no existe aún — sé conservador (try/catch alrededor de `wp_remote_post`, chequear `is_wp_error`).

## Commands
Igual que el plan 001 (DEPLOY, tests puros, `php -l`, WP-CLI). Ver 001 "Commands you will need".

## Scope
**In scope:** `includes/class-cloudflare.php` (crear), `includes/class-purge.php` (modificar), `corehost-cache.php` (defaults + require), `admin/class-admin-page.php` (campos + sanitize), `tests/test-cloudflare.php` (crear).
**Out of scope:** `.htaccess`, cache-store, page-generator, role-gate, admin-bar. No cambiar el esquema de cache local.

## Git workflow
Rama `feat/002-cloudflare`. Commit por unidad. No push/PR salvo orden.

## Steps

### Step 1: `CHC_Cloudflare` con un builder de request PURO + testeable
Crea `includes/class-cloudflare.php`. Método público estático **puro** `build_request(string $zone, string $token, array $body): array` que devuelve `['url'=>..., 'args'=>...]` (url del endpoint + args para `wp_remote_post`, con header Authorization Bearer y JSON body). Y métodos de instancia `purge_urls(array $urls): void` y `purge_all(): void` que construyen el body (`files` en lotes de 30, o `purge_everything`) y hacen `wp_remote_post` **no-fatal** (guardan error en `chc_cf_last_error`). Gating: si no está `cf_enabled`/zone/token → return temprano.
Test `tests/test-cloudflare.php` (bootstrap incluye la clase — añádela a `tests/bootstrap.php` si hace falta un stub de `wp_remote_post`; para `build_request` NO se necesita WP):
```php
$r = CHC_Cloudflare::build_request('ZONEID', 'TOK', ['purge_everything'=>true]);
t_assert(str_contains($r['url'], '/zones/ZONEID/purge_cache'), 'endpoint con zone');
t_assert(($r['args']['headers']['Authorization'] ?? '') === 'Bearer TOK', 'auth bearer');
t_assert(str_contains($r['args']['body'], 'purge_everything'), 'body correcto');
t_assert(str_contains($r['args']['body'], 'ZONEID')===false, 'zone no va en el body');
```
Añade en `tests/bootstrap.php` el require de `includes/class-cloudflare.php`.
**Verify**: DEPLOY + tests → pasan, incluidas las nuevas.

### Step 2: Settings CF
En `corehost-cache.php` `chc_settings()` defaults: añade `'cf_enabled'=>0, 'cf_zone'=>''` (el token NO va en defaults; se lee de constante u opción). En `admin/class-admin-page.php` `sanitize()`: añade `cf_enabled` (0/1), `cf_zone` (sanitize_text_field), `cf_token` (sanitize_text_field, y si viene vacío conservar el guardado previo para no borrarlo sin querer). En `render()`: una fila "Cloudflare" con checkbox Activar + input Zone ID + input token (type=password) + mostrar `chc_cf_last_error` si existe. `require_once` de la clase en el bootstrap.
**Verify**: `php -l` de los dos archivos; el panel de Ajustes carga sin fatal (`wp ... eval 'echo class_exists("CHC_Cloudflare")?"OK\n":"NO\n";'`).

### Step 3: Enganchar en `CHC_Purge`
En `CHC_Purge::url()`: tras el borrado local, `(new CHC_Cloudflare())->purge_urls([$permalink])` (usa la URL completa, no solo el path). En `CHC_Purge::all()`: tras `purge_all()` local, `(new CHC_Cloudflare())->purge_all()`. (El gating vive dentro de `CHC_Cloudflare`, así que llamar siempre es seguro.)
**Verify**: `php -l includes/class-purge.php`.

### Step 4: Verificación (ver CAVEAT)
- **Unit (seguro en Keypro):** los tests de `build_request` pasan.
- **Wiring (seguro):** con CF desactivado, editar un post NO debe romper nada ni llamar a CF (gating). Confirmar que `save_post` sigue purgando local (crear post publicado → home purgada, como ya se probó en el plugin).
- **E2E real de CF — NO es posible en Keypro** (Keypro va por LiteSpeed, sin Cloudflare delante). Para verificar la purga real de CF: en un sitio de la flota que SÍ esté tras Cloudflare, definir `cf_enabled`, `cf_zone` y token (permiso Zone.Cache Purge), editar un post y confirmar en el dashboard de CF (Caching → verificar) o que `chc_cf_last_error` quede vacío y la API responda `success:true`. **STOP y reporta** que la verificación E2E de CF queda pendiente de un sitio con CF; no inventes una verificación falsa en Keypro.

## Test plan
- `tests/test-cloudflare.php`: `build_request` (endpoint con zone, header Bearer, body correcto, token/zone no filtrados donde no deben). Modela el estilo de `tests/test-htaccess.php`.
- Verificación: `php tests/run.php` → todas pasan.

## Done criteria
- [ ] `php tests/run.php` → `0 failed`, con los tests nuevos de `build_request`.
- [ ] `php -l` limpio en los archivos tocados.
- [ ] `class_exists("CHC_Cloudflare")` = true.
- [ ] Con CF desactivado, editar/guardar un post no lanza errores y la purga local sigue funcionando.
- [ ] Reportado explícitamente que el E2E de CF queda pendiente de un sitio con Cloudflare (no falseado).
- [ ] `plans/README.md` fila 002 actualizada.

## STOP conditions
- Drift en "Current state".
- Intentar verificar la purga real de CF en Keypro (no tiene CF) — reporta pendiente en vez de fingir.
- El token aparece en algún log/output — detente y quita ese log.
- Una verificación falla dos veces tras un intento razonable.

## Maintenance notes
- Guardar el token en `chc_settings` lo deja en la BD en texto plano; es un token *scoped* solo a purga de caché (riesgo acotado), pero recomienda al usuario preferir la constante `CHC_CF_TOKEN` en `wp-config.php`. Nunca loguear el valor.
- Si más adelante se añade el drop-in nginx (portabilidad), la purga de CF es independiente y sigue aplicando.
- El reviewer debe confirmar: gating correcto (no llama sin enabled/zone/token), no-fatal ante error de red, y que el token nunca se imprime/loguea.
