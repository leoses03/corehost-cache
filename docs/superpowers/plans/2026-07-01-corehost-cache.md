# CoreHost Cache — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir `corehost-cache`, un plugin WordPress de page-cache que guarda cada página anónima como archivos estáticos (`.html`/`.html.gz`/`.html.br`) y los sirve directo por `.htaccess` (sin PHP), con exclusión configurable por rol, invalidación por evento+TTL y seguro para WooCommerce.

**Architecture:** Lógica pura aislada en clases sin dependencias de WP (mapeo de rutas, generación del bloque `.htaccess`, decisión de cacheabilidad, decisión de bypass por rol) → testeadas con un runner PHP plano. El pegamento WP (output buffering, hooks de purga, cookie de rol, admin, CLI) se verifica por integración con `wp-cli`/`curl` contra el sitio real Keypro.

**Tech Stack:** PHP 8.1, WordPress, WP-CLI, GD/brotli, `.htaccess`/mod_rewrite (LiteSpeed). Sin Composer: runner de tests en PHP plano.

**Spec:** `docs/superpowers/specs/2026-07-01-corehost-cache-design.md`

---

## Tooling y ubicaciones (léelo antes de empezar)

- **Repo local (fuente de verdad):** `C:\Users\Luis Oses\Tools\corehost-cache`
  - El código del plugin vive en la **raíz del repo** (`corehost-cache.php`, `includes/`, `admin/`, `uninstall.php`).
  - Tests en `tests/`. Spec/plan en `docs/` (NO se despliega).
- **Servidor de pruebas:** alias SSH `aldeahostlatam` (cuenta `corehost`, LiteSpeed, PHP 8.1, WP-CLI).
  - WP path: `/home/corehost/public_html/key` (sitio "Keypro", **instalación en subdirectorio** `/key/` — buen caso de prueba).
  - Dir del plugin: `/home/corehost/public_html/key/wp-content/plugins/corehost-cache`.

**Comando DEPLOY** (sincroniza el repo — sin `.git`/`docs` — al dir del plugin; incluye `tests/`, inofensivo para WP):
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && \
tar --exclude='./.git' --exclude='./docs' -cf - . | \
ssh aldeahostlatam "mkdir -p /home/corehost/public_html/key/wp-content/plugins/corehost-cache && tar -xf - -C /home/corehost/public_html/key/wp-content/plugins/corehost-cache" 2>&1 | grep -v "post-quantum\|store now\|upgraded\|openssh.com"
```

**Comando UNIT** (corre los tests puros en el server):
```bash
ssh aldeahostlatam 'php /home/corehost/public_html/key/wp-content/plugins/corehost-cache/tests/run.php' 2>&1 | grep -v "post-quantum\|store now\|upgraded\|openssh.com"
```

**Comando WP** (WP-CLI en Keypro), usado en pasos de integración:
```bash
ssh aldeahostlatam 'wp --path=/home/corehost/public_html/key --allow-root <args>' 2>&1 | grep -v "post-quantum\|store now\|upgraded\|openssh.com"
```

Cada "correr test" = **DEPLOY && UNIT**. Commits son **locales** en el repo.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `corehost-cache.php` | Bootstrap: header, defines, `chc_settings()`/`chc_store()`/`chc_cache_url_path()`, activación/desactivación, wiring de componentes, registro CLI. |
| `includes/class-cache-store.php` | **(puro)** Mapeo URL→ruta, escribir/borrar/`purge_all`/`sweep`/`stats` de los 3 archivos. |
| `includes/class-htaccess.php` | **(puro)** `rules()` (bloque), `install()`/`remove()` (arriba de `# BEGIN WordPress`). |
| `includes/class-request-rules.php` | **(puro)** `should_cache(ctx)`, `matches_any()`; `is_cacheable()` reúne contexto WP. |
| `includes/class-role-gate.php` | **(puro)** `should_bypass()`; setea/borra la cookie `chc_nocache` (glue). |
| `includes/class-page-generator.php` | Output buffering en `template_redirect`; escribe en el callback. |
| `includes/class-purge.php` | Hooks de invalidación por evento + TTL sweep. |
| `includes/class-cli.php` | `wp corehost-cache purge|status|warm`. |
| `admin/class-admin-page.php` | Ajustes + lista de roles + botón purgar + stats. |
| `admin/admin.js` | AJAX de purga. |
| `uninstall.php` | Quita reglas, borra cache dir + opciones. |
| `tests/bootstrap.php` | Define `ABSPATH` y carga las clases puras. |
| `tests/run.php` | Runner de aserciones (`t_eq`/`t_assert`). |
| `tests/test-*.php` | Tests de las clases puras. |

---

## Task 0: Scaffold + runner de tests

**Files:**
- Create: `tests/bootstrap.php`, `tests/run.php`, `tests/test-smoke.php`

- [ ] **Step 1: Escribir el runner y bootstrap**

`tests/run.php`:
```php
<?php
require __DIR__ . '/bootstrap.php';
$GLOBALS['chc_t'] = ['pass' => 0, 'fail' => 0, 'msgs' => []];
function t_assert(bool $cond, string $msg): void {
    if ($cond) { $GLOBALS['chc_t']['pass']++; }
    else { $GLOBALS['chc_t']['fail']++; $GLOBALS['chc_t']['msgs'][] = "FAIL: $msg"; }
}
function t_eq($got, $exp, string $msg): void {
    t_assert($got === $exp, "$msg (esperado " . var_export($exp, true) . ", got " . var_export($got, true) . ")");
}
foreach (glob(__DIR__ . '/test-*.php') as $f) { require $f; }
$r = $GLOBALS['chc_t'];
echo "\n{$r['pass']} passed, {$r['fail']} failed\n";
foreach ($r['msgs'] as $m) { echo "  $m\n"; }
exit($r['fail'] > 0 ? 1 : 0);
```

`tests/bootstrap.php`:
```php
<?php
// Contexto mínimo para probar la lógica pura sin cargar WordPress.
if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/chc-abspath/'); }
$root = dirname(__DIR__);
foreach ([
    '/includes/class-cache-store.php',
    '/includes/class-htaccess.php',
    '/includes/class-request-rules.php',
    '/includes/class-role-gate.php',
] as $f) {
    if (is_file($root . $f)) { require $root . $f; }
}
```

`tests/test-smoke.php`:
```php
<?php
t_eq(1 + 1, 2, 'smoke');
```

- [ ] **Step 2: Deploy + correr (debe pasar el smoke)**

Run: DEPLOY && UNIT
Expected: `1 passed, 0 failed`

- [ ] **Step 3: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "test: runner de pruebas puras + smoke"
```

---

## Task 1: `CHC_Cache_Store` — mapeo de ruta + escribir/borrar/sweep

**Files:**
- Create: `includes/class-cache-store.php`, `tests/test-cache-store.php`

- [ ] **Step 1: Escribir el test**

`tests/test-cache-store.php`:
```php
<?php
$base = sys_get_temp_dir() . '/chc-store-' . getmypid();
$s = new CHC_Cache_Store($base);

// Mapeo de ruta
t_eq($s->dir_for('ejemplo.com', '/'), "$base/ejemplo.com", 'home');
t_eq($s->dir_for('ejemplo.com', '/about/'), "$base/ejemplo.com/about", 'trailing slash normalizado');
t_eq($s->dir_for('ejemplo.com', '/a/b/'), "$base/ejemplo.com/a/b", 'anidado');
t_eq($s->dir_for('ejemplo.com', '/x/?q=1'), "$base/ejemplo.com/x", 'ignora query');
t_assert(!str_contains($s->dir_for('e.com', '/../../etc'), '..'), 'sin traversal');

// Escribir + variantes + leer de vuelta
$html = '<html>hola ' . str_repeat('x', 500) . '</html>';
$w = $s->write('ejemplo.com', '/about/', $html, true, true);
t_assert(in_array('html', $w, true), 'escribió html');
t_assert(in_array('gz', $w, true), 'escribió gz');
t_eq(file_get_contents("$base/ejemplo.com/about/index.html"), $html, 'html intacto');
t_eq(gzdecode(file_get_contents("$base/ejemplo.com/about/index.html.gz")), $html, 'gz descomprime al original');
if (function_exists('brotli_compress')) {
    t_assert(in_array('br', $w, true), 'escribió br (ext disponible)');
    t_eq(brotli_uncompress(file_get_contents("$base/ejemplo.com/about/index.html.br")), $html, 'br descomprime');
}

// Borrar una URL
$s->delete('ejemplo.com', '/about/');
t_assert(!is_file("$base/ejemplo.com/about/index.html"), 'delete quita html');
t_assert(!is_file("$base/ejemplo.com/about/index.html.gz"), 'delete quita gz');

// Sweep por TTL
$s->write('ejemplo.com', '/viejo/', $html);
$s->write('ejemplo.com', '/nuevo/', $html);
touch("$base/ejemplo.com/viejo/index.html", time() - 7200);
$n = $s->sweep(3600); // borra >1h
t_eq($n, 1, 'sweep borra 1 página vieja');
t_assert(!is_file("$base/ejemplo.com/viejo/index.html"), 'vieja borrada');
t_assert(is_file("$base/ejemplo.com/nuevo/index.html"), 'nueva permanece');

// purge_all
$s->purge_all();
t_assert(!is_dir($base) || count(glob("$base/*")) === 0, 'purge_all vacía');
```

- [ ] **Step 2: Deploy + correr (debe FALLAR: clase no existe)**

Run: DEPLOY && UNIT
Expected: FAIL (`Class "CHC_Cache_Store" not found`)

- [ ] **Step 3: Implementar la clase**

`includes/class-cache-store.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Almacén de páginas cacheadas: mapeo URL→ruta y operaciones de archivo. */
class CHC_Cache_Store
{
    public function __construct(private string $base_dir) {}

    public function base(): string { return $this->base_dir; }

    /** Directorio de cache para host+URI (sin el archivo). */
    public function dir_for(string $host, string $uri): string
    {
        $host = preg_replace('/[^a-z0-9.\-]/i', '_', $host) ?: 'host';
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = str_replace(["\0", '..'], '', $path);
        $path = '/' . trim($path, '/');           // normaliza; home => '/'
        $rel  = $path === '/' ? '' : $path;
        return rtrim($this->base_dir, '/') . '/' . $host . $rel;
    }

    /** Escribe index.html + variantes .gz/.br. Devuelve formatos escritos. */
    public function write(string $host, string $uri, string $html, bool $gzip = true, bool $brotli = true): array
    {
        $dir = $this->dir_for($host, $uri);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { return []; }
        $out = [];
        if (file_put_contents($dir . '/index.html', $html, LOCK_EX) !== false) { $out[] = 'html'; }
        if ($gzip && ($gz = gzencode($html, 7)) !== false
            && file_put_contents($dir . '/index.html.gz', $gz, LOCK_EX) !== false) { $out[] = 'gz'; }
        if ($brotli && function_exists('brotli_compress')
            && ($br = brotli_compress($html, 6)) !== false
            && file_put_contents($dir . '/index.html.br', $br, LOCK_EX) !== false) { $out[] = 'br'; }
        return $out;
    }

    /** Borra las variantes de una URL. */
    public function delete(string $host, string $uri): void
    {
        $dir = $this->dir_for($host, $uri);
        foreach (['index.html', 'index.html.gz', 'index.html.br'] as $f) {
            if (is_file("$dir/$f")) { @unlink("$dir/$f"); }
        }
    }

    /** Vacía todo el cache. */
    public function purge_all(): void { $this->rrmdir($this->base_dir); }

    /** Borra páginas (index.html + variantes) con mtime más viejo que $ttl seg. Nº de páginas. */
    public function sweep(int $ttl): int
    {
        if ($ttl <= 0 || !is_dir($this->base_dir)) { return 0; }
        $cutoff = time() - $ttl;
        $count  = 0;
        foreach ($this->iter() as $file) {
            if ($file->getFilename() === 'index.html' && $file->getMTime() < $cutoff) {
                $b = $file->getPathname();
                foreach (['', '.gz', '.br'] as $ext) { if (is_file($b . $ext)) { @unlink($b . $ext); } }
                $count++;
            }
        }
        return $count;
    }

    public function stats(): array
    {
        $pages = 0; $bytes = 0;
        if (is_dir($this->base_dir)) {
            foreach ($this->iter() as $f) {
                if ($f->isFile()) {
                    $bytes += $f->getSize();
                    if ($f->getFilename() === 'index.html') { $pages++; }
                }
            }
        }
        return ['pages' => $pages, 'bytes' => $bytes];
    }

    private function iter(): RecursiveIteratorIterator
    {
        return new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base_dir, FilesystemIterator::SKIP_DOTS)
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    }
}
```

- [ ] **Step 4: Deploy + correr (debe PASAR)**

Run: DEPLOY && UNIT
Expected: todos pass (incluye br solo si la ext existe)

- [ ] **Step 5: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: CHC_Cache_Store (mapeo de ruta, write/delete/sweep/stats)"
```

---

## Task 2: `CHC_Htaccess` — bloque de reglas + install/remove

**Files:**
- Create: `includes/class-htaccess.php`, `tests/test-htaccess.php`

- [ ] **Step 1: Escribir el test**

`tests/test-htaccess.php`:
```php
<?php
$block = CHC_Htaccess::rules('/key/wp-content/cache/corehost-cache');

t_assert(str_contains($block, '# BEGIN CoreHost Cache'), 'marcador BEGIN');
t_assert(str_contains($block, '# END CoreHost Cache'), 'marcador END');
t_assert(str_contains($block, 'RewriteCond %{REQUEST_METHOD} GET'), 'solo GET');
t_assert(str_contains($block, 'RewriteCond %{QUERY_STRING} ^$'), 'query vacío');
t_assert(str_contains($block, 'chc_nocache'), 'bypass por cookie de rol');
t_assert(str_contains($block, 'woocommerce_items_in_cart'), 'bypass carrito woo');
t_assert(str_contains($block, '/index.html.br'), 'sirve brotli');
t_assert(str_contains($block, '/index.html.gz'), 'sirve gzip');
t_assert(str_contains($block, '%{DOCUMENT_ROOT}/key/wp-content/cache/corehost-cache/%{HTTP_HOST}%{REQUEST_URI}/index.html'), 'ruta de existencia con prefijo subdir');
t_assert(str_contains($block, 'X-CoreHost-Cache'), 'header de HIT');
t_assert(str_contains($block, 'Content-Encoding'), 'header content-encoding');

// install: nuestro bloque va ANTES de # BEGIN WordPress; remove lo quita idempotente
$tmp = sys_get_temp_dir() . '/chc-ht-' . getmypid() . '.htaccess';
file_put_contents($tmp, "# BEGIN WordPress\nRewriteRule . /index.php [L]\n# END WordPress\n");
CHC_Htaccess::install($tmp, $block);
$c = file_get_contents($tmp);
t_assert(strpos($c, '# BEGIN CoreHost Cache') < strpos($c, '# BEGIN WordPress'), 'bloque arriba de WordPress');
t_assert(str_contains($c, '# END WordPress'), 'conserva bloque WordPress');

CHC_Htaccess::install($tmp, $block); // idempotente
t_eq(substr_count(file_get_contents($tmp), '# BEGIN CoreHost Cache'), 1, 'install idempotente (un solo bloque)');

CHC_Htaccess::remove($tmp);
$c2 = file_get_contents($tmp);
t_assert(!str_contains($c2, 'CoreHost Cache'), 'remove quita nuestro bloque');
t_assert(str_contains($c2, '# BEGIN WordPress'), 'remove conserva WordPress');
@unlink($tmp);
```

- [ ] **Step 2: Deploy + correr (FALLA: clase no existe)**

Run: DEPLOY && UNIT
Expected: FAIL

- [ ] **Step 3: Implementar la clase**

`includes/class-htaccess.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Genera y administra el bloque de reglas de servicio en el .htaccess raíz. */
class CHC_Htaccess
{
    public const BEGIN = '# BEGIN CoreHost Cache';
    public const END   = '# END CoreHost Cache';

    /**
     * @param string $cache_url_path Ruta URL desde docroot al dir de cache, sin barra final.
     *   Root install: '/wp-content/cache/corehost-cache'
     *   Subdir /key:  '/key/wp-content/cache/corehost-cache'
     */
    public static function rules(string $cache_url_path): string
    {
        $c   = '/' . trim($cache_url_path, '/');
        $doc = '%{DOCUMENT_ROOT}' . $c . '/%{HTTP_HOST}%{REQUEST_URI}';
        $srv = $c . '/%{HTTP_HOST}%{REQUEST_URI}';

        $skip_cookies = 'chc_nocache|comment_author_|wp-postpass_|woocommerce_items_in_cart|woocommerce_cart_hash|wp_woocommerce_session_';
        $skip_uris    = '(^|/)(wp-admin|wp-login|wp-cron|wp-json|xmlrpc)';

        $common =
              "RewriteCond %{REQUEST_METHOD} GET\n"
            . "RewriteCond %{QUERY_STRING} ^$\n"
            . "RewriteCond %{HTTP_COOKIE} !($skip_cookies) [NC]\n"
            . "RewriteCond %{REQUEST_URI} !$skip_uris [NC]\n";

        $mk = function (string $enc, string $ext, string $envenc) use ($doc, $srv, $common): string {
            $r = $common;
            if ($enc !== '') { $r .= "RewriteCond %{HTTP:Accept-Encoding} $enc\n"; }
            $r .= "RewriteCond {$doc}/index.html{$ext} -f\n";
            $flags = 'E=CHC_HIT:1';
            if ($envenc !== '') { $flags .= ',E=' . $envenc . ':1'; }
            $flags .= ',L,T=text/html';
            return $r . "RewriteRule .* {$srv}/index.html{$ext} [{$flags}]\n";
        };

        return self::BEGIN . "\n"
            . "<IfModule mod_rewrite.c>\n"
            . "RewriteEngine On\n"
            . $mk('br',   '.br', 'CHC_ENC_BR')
            . $mk('gzip', '.gz', 'CHC_ENC_GZ')
            . $mk('',     '',    '')
            . "</IfModule>\n"
            . "<IfModule mod_headers.c>\n"
            // Tras el rewrite interno los env llevan prefijo REDIRECT_.
            . "Header set Content-Encoding \"br\"   env=REDIRECT_CHC_ENC_BR\n"
            . "Header set Content-Encoding \"gzip\" env=REDIRECT_CHC_ENC_GZ\n"
            . "Header set X-CoreHost-Cache \"HIT\"  env=REDIRECT_CHC_HIT\n"
            . "Header set Vary \"Accept-Encoding\"  env=REDIRECT_CHC_HIT\n"
            . "Header set Cache-Control \"public, max-age=600\" env=REDIRECT_CHC_HIT\n"
            . "</IfModule>\n"
            . self::END . "\n";
    }

    /** Instala el bloque justo ANTES de `# BEGIN WordPress` (o al inicio si no existe). */
    public static function install(string $file, string $block): bool
    {
        $current  = is_file($file) ? (string) file_get_contents($file) : '';
        $stripped = self::strip($current);
        if (str_contains($stripped, '# BEGIN WordPress')) {
            $new = preg_replace('/# BEGIN WordPress/', rtrim($block) . "\n\n# BEGIN WordPress", $stripped, 1);
        } else {
            $new = rtrim($block) . "\n\n" . ltrim($stripped);
        }
        return @file_put_contents($file, $new) !== false;
    }

    public static function remove(string $file): bool
    {
        if (!is_file($file)) { return true; }
        $current  = (string) file_get_contents($file);
        $stripped = self::strip($current);
        return $stripped === $current ? true : (@file_put_contents($file, $stripped) !== false);
    }

    private static function strip(string $content): string
    {
        $p = '/' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . "\n?/s";
        return (string) preg_replace($p, '', $content);
    }
}
```

- [ ] **Step 4: Deploy + correr (PASA)**

Run: DEPLOY && UNIT
Expected: todos pass

- [ ] **Step 5: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: CHC_Htaccess (bloque de reglas + install/remove sobre WordPress)"
```

---

## Task 3: `CHC_Request_Rules` — decisión pura de cacheabilidad

**Files:**
- Create: `includes/class-request-rules.php`, `tests/test-request-rules.php`

- [ ] **Step 1: Escribir el test**

`tests/test-request-rules.php`:
```php
<?php
$ok = ['is_admin'=>false,'logged_in'=>false,'method'=>'GET','query'=>'','status'=>200,
       'content_type'=>'text/html','is_feed'=>false,'donotcache'=>false,'excluded_url'=>false,'woo_dynamic'=>false];

t_assert(CHC_Request_Rules::should_cache($ok) === true, 'anónimo GET html 200 => sí');
t_assert(CHC_Request_Rules::should_cache(['logged_in'=>true] + $ok) === false, 'logueado => no');
t_assert(CHC_Request_Rules::should_cache(['is_admin'=>true] + $ok) === false, 'admin => no');
t_assert(CHC_Request_Rules::should_cache(['method'=>'POST'] + $ok) === false, 'POST => no');
t_assert(CHC_Request_Rules::should_cache(['query'=>'p=1'] + $ok) === false, 'query => no');
t_assert(CHC_Request_Rules::should_cache(['status'=>500] + $ok) === false, '500 => no');
t_assert(CHC_Request_Rules::should_cache(['content_type'=>'application/json'] + $ok) === false, 'json => no');
t_assert(CHC_Request_Rules::should_cache(['is_feed'=>true] + $ok) === false, 'feed => no');
t_assert(CHC_Request_Rules::should_cache(['donotcache'=>true] + $ok) === false, 'DONOTCACHEPAGE => no');
t_assert(CHC_Request_Rules::should_cache(['excluded_url'=>true] + $ok) === false, 'url excluida => no');
t_assert(CHC_Request_Rules::should_cache(['woo_dynamic'=>true] + $ok) === false, 'woo cart/checkout => no');
// 404 solo si cache_404
t_assert(CHC_Request_Rules::should_cache(['status'=>404] + $ok) === false, '404 sin flag => no');
t_assert(CHC_Request_Rules::should_cache(['status'=>404,'cache_404'=>1] + $ok) === true, '404 con flag => sí');

// matches_any (para url_excluded)
t_assert(CHC_Request_Rules::matches_any('/carrito/', ['/carrito', '/checkout']) === true, 'match substring');
t_assert(CHC_Request_Rules::matches_any('/blog/post/', ['/carrito']) === false, 'no match');
t_assert(CHC_Request_Rules::matches_any('/x/', []) === false, 'lista vacía');
```

- [ ] **Step 2: Deploy + correr (FALLA)**

Run: DEPLOY && UNIT
Expected: FAIL

- [ ] **Step 3: Implementar la clase**

`includes/class-request-rules.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Decide si una request es cacheable. `should_cache()` es pura; `is_cacheable()` es el glue WP. */
class CHC_Request_Rules
{
    public static function should_cache(array $c): bool
    {
        if (!empty($c['is_admin']))  { return false; }
        if (!empty($c['logged_in'])) { return false; }
        if (($c['method'] ?? 'GET') !== 'GET') { return false; }
        if (($c['query'] ?? '') !== '') { return false; }
        $status = (int) ($c['status'] ?? 200);
        if ($status !== 200 && !($status === 404 && !empty($c['cache_404']))) { return false; }
        if (stripos((string) ($c['content_type'] ?? 'text/html'), 'text/html') === false) { return false; }
        if (!empty($c['is_feed']))    { return false; }
        if (!empty($c['donotcache'])) { return false; }
        if (!empty($c['excluded_url'])) { return false; }
        if (!empty($c['woo_dynamic'])) { return false; }
        return true;
    }

    public static function matches_any(string $uri, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = trim((string) $p);
            if ($p !== '' && stripos($uri, $p) !== false) { return true; }
        }
        return false;
    }

    /** Glue WP: reúne el contexto y llama a should_cache(). */
    public static function is_cacheable(): bool
    {
        if (is_admin() || is_user_logged_in()) { return false; }

        $woo = false;
        if (function_exists('is_cart')) {
            $woo = is_cart() || is_checkout() || is_account_page();
            if (!$woo && function_exists('WC') && WC()->cart) {
                $woo = WC()->cart->get_cart_contents_count() > 0;
            }
        }
        $s = chc_settings();
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $status = is_404() ? 404 : (http_response_code() ?: 200);

        return self::should_cache([
            'is_admin'     => false,
            'logged_in'    => false,
            'method'       => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'query'        => $_SERVER['QUERY_STRING'] ?? '',
            'status'       => $status,
            'content_type' => 'text/html',
            'is_feed'      => is_feed(),
            'cache_404'    => (int) ($s['cache_404'] ?? 0),
            'donotcache'   => defined('DONOTCACHEPAGE') && DONOTCACHEPAGE,
            'excluded_url' => self::matches_any($uri, array_map('trim', explode("\n", (string) ($s['excluded_urls'] ?? '')))),
            'woo_dynamic'  => $woo,
        ]);
    }
}
```

- [ ] **Step 4: Deploy + correr (PASA)**

Run: DEPLOY && UNIT
Expected: todos pass

- [ ] **Step 5: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: CHC_Request_Rules (should_cache pura + is_cacheable glue)"
```

---

## Task 4: `CHC_Role_Gate` — decisión de bypass por rol (parte pura)

**Files:**
- Create: `includes/class-role-gate.php`, `tests/test-role-gate.php`

- [ ] **Step 1: Escribir el test**

`tests/test-role-gate.php`:
```php
<?php
t_assert(CHC_Role_Gate::should_bypass(['administrator'], ['administrator','editor']) === true, 'rol excluido => bypass');
t_assert(CHC_Role_Gate::should_bypass(['customer'], ['administrator','editor']) === false, 'rol permitido => no bypass');
t_assert(CHC_Role_Gate::should_bypass(['subscriber','customer'], ['customer']) === true, 'intersección => bypass');
t_assert(CHC_Role_Gate::should_bypass([], ['administrator']) === false, 'sin roles => no bypass');
t_assert(CHC_Role_Gate::should_bypass(['shop_manager'], []) === false, 'sin exclusiones => no bypass');
```

- [ ] **Step 2: Deploy + correr (FALLA)**

Run: DEPLOY && UNIT
Expected: FAIL

- [ ] **Step 3: Implementar la clase (pura + glue de cookie)**

`includes/class-role-gate.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Pone/quita la cookie `chc_nocache` según los roles excluidos del cache. */
class CHC_Role_Gate
{
    public const COOKIE = 'chc_nocache';

    /** Pura: ¿la sesión debe saltarse el cache por su rol? */
    public static function should_bypass(array $user_roles, array $excluded_roles): bool
    {
        return (bool) array_intersect($user_roles, $excluded_roles);
    }

    public function register(): void
    {
        add_action('set_logged_in_cookie', [$this, 'on_login'], 10, 4);
        add_action('wp_logout', [$this, 'on_logout']);
        add_action('init', [$this, 'on_init']);
    }

    public function on_login($cookie, $expire, $expiration, $user_id): void { $this->apply((int) $user_id); }
    public function on_init(): void { if (is_user_logged_in()) { $this->apply(get_current_user_id()); } }
    public function on_logout(): void { $this->set_cookie(false); }

    private function apply(int $user_id): void
    {
        $user = get_userdata($user_id);
        if (!$user) { return; }
        $bypass = self::should_bypass((array) $user->roles, (array) (chc_settings()['excluded_roles'] ?? []));
        $has    = isset($_COOKIE[self::COOKIE]);
        if ($bypass && !$has)      { $this->set_cookie(true); }
        elseif (!$bypass && $has)  { $this->set_cookie(false); }
    }

    private function set_cookie(bool $on): void
    {
        if (headers_sent()) { return; }
        setcookie(self::COOKIE, $on ? '1' : '', [
            'expires'  => $on ? time() + 2 * DAY_IN_SECONDS : time() - 3600,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($on) { $_COOKIE[self::COOKIE] = '1'; } else { unset($_COOKIE[self::COOKIE]); }
    }
}
```

- [ ] **Step 4: Deploy + correr (PASA)**

Run: DEPLOY && UNIT
Expected: todos pass

- [ ] **Step 5: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: CHC_Role_Gate (should_bypass pura + cookie chc_nocache)"
```

---

## Task 5: `CHC_Page_Generator` + `CHC_Purge`

**Files:**
- Create: `includes/class-page-generator.php`, `includes/class-purge.php`

*(Glue WP: se verifican por integración en Task 8. No hay test unitario nuevo.)*

- [ ] **Step 1: Implementar `class-page-generator.php`**
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Bufferiza la salida de páginas anónimas cacheables y las escribe al terminar. */
class CHC_Page_Generator
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_start'], 0);
    }

    public function maybe_start(): void
    {
        if (empty(chc_settings()['enabled'])) { return; }
        if (!CHC_Request_Rules::is_cacheable()) { return; }
        ob_start([$this, 'finish']);
    }

    public function finish(string $html): string
    {
        $code = http_response_code();
        if ($html !== '' && !is_user_logged_in() && ($code === 200 || $code === false)) {
            $s = chc_settings();
            $marked = $html . "\n<!-- corehost-cache " . gmdate('Y-m-d H:i:s') . " UTC -->";
            chc_store()->write(
                $_SERVER['HTTP_HOST'] ?? '',
                $_SERVER['REQUEST_URI'] ?? '/',
                $marked,
                !empty($s['gzip']),
                !empty($s['brotli'])
            );
        }
        return $html;
    }
}
```

- [ ] **Step 2: Implementar `class-purge.php`**
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Invalidación del cache por eventos de contenido + barrido TTL. */
class CHC_Purge
{
    public function register(): void
    {
        foreach (['save_post', 'deleted_post', 'trashed_post'] as $h) { add_action($h, [$this, 'on_post'], 10, 1); }
        foreach (['comment_post', 'edit_comment', 'wp_set_comment_status'] as $h) { add_action($h, [$this, 'on_comment'], 10, 1); }
        foreach (['switch_theme', 'customize_save_after', 'wp_update_nav_menu'] as $h) { add_action($h, [$this, 'all']); }
        add_action('chc_ttl_sweep', [$this, 'ttl_sweep']);
    }

    public function on_post($post_id): void
    {
        if (wp_is_post_revision($post_id)) { return; }
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'auto-draft') { return; }
        $this->url(get_permalink($post_id));
        $this->url(home_url('/'));
    }

    public function on_comment($comment_id): void
    {
        $c = get_comment($comment_id);
        if ($c) { $this->url(get_permalink($c->comment_post_ID)); }
    }

    public function url($permalink): void
    {
        if (!$permalink) { return; }
        $p = wp_parse_url($permalink);
        chc_store()->delete($p['host'] ?? ($_SERVER['HTTP_HOST'] ?? ''), $p['path'] ?? '/');
    }

    public function all(): void { chc_store()->purge_all(); }

    public function ttl_sweep(): void
    {
        chc_store()->sweep((int) (chc_settings()['ttl_hours'] ?? 10) * HOUR_IN_SECONDS);
    }
}
```

- [ ] **Step 3: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: CHC_Page_Generator (output buffering) + CHC_Purge (eventos + TTL)"
```

---

## Task 6: Bootstrap del plugin (`corehost-cache.php`) + `uninstall.php`

**Files:**
- Create: `corehost-cache.php`, `uninstall.php`

- [ ] **Step 1: Implementar el archivo principal**

`corehost-cache.php`:
```php
<?php
/**
 * Plugin Name: CoreHost Cache
 * Description: Page cache estático (HTML + gzip + brotli) servido por .htaccess sin PHP, con exclusión por rol e invalidación por evento+TTL. Seguro para WooCommerce.
 * Version: 1.0.0
 * Author: CoreHost
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) { exit; }

define('CHC_VERSION', '1.0.0');
define('CHC_DIR', plugin_dir_path(__FILE__));

require_once CHC_DIR . 'includes/class-cache-store.php';
require_once CHC_DIR . 'includes/class-htaccess.php';
require_once CHC_DIR . 'includes/class-request-rules.php';
require_once CHC_DIR . 'includes/class-role-gate.php';
require_once CHC_DIR . 'includes/class-page-generator.php';
require_once CHC_DIR . 'includes/class-purge.php';
require_once CHC_DIR . 'admin/class-admin-page.php';

/** Ajustes con defaults; excluded_roles = TODOS los roles si no se guardó nada. */
function chc_settings(): array
{
    $d = ['enabled' => 1, 'ttl_hours' => 10, 'cache_404' => 0, 'excluded_urls' => '', 'gzip' => 1, 'brotli' => 1];
    $s = array_merge($d, (array) get_option('chc_settings', []));
    if (!isset($s['excluded_roles'])) {
        $s['excluded_roles'] = function_exists('wp_roles') ? array_keys(wp_roles()->get_names()) : ['administrator'];
    }
    return $s;
}

function chc_store(): CHC_Cache_Store
{
    return new CHC_Cache_Store(WP_CONTENT_DIR . '/cache/corehost-cache');
}

/** Ruta URL (desde docroot) al dir de cache; maneja instalación en subdirectorio. */
function chc_cache_url_path(): string
{
    $docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $cachefs = rtrim(str_replace('\\', '/', WP_CONTENT_DIR . '/cache/corehost-cache'), '/');
    if ($docroot !== '' && str_starts_with($cachefs, $docroot)) {
        return substr($cachefs, strlen($docroot)); // ej. /key/wp-content/cache/corehost-cache
    }
    return '/wp-content/cache/corehost-cache';
}

function chc_root_htaccess(): string { return ABSPATH . '.htaccess'; }

function chc_refresh_htaccess(): void
{
    $ok = CHC_Htaccess::install(chc_root_htaccess(), CHC_Htaccess::rules(chc_cache_url_path()));
    update_option('chc_htaccess_writable', $ok ? 1 : 0, false);
}

register_activation_hook(__FILE__, function () {
    wp_mkdir_p(WP_CONTENT_DIR . '/cache/corehost-cache');
    chc_refresh_htaccess();
    if (!wp_next_scheduled('chc_ttl_sweep')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'chc_ttl_sweep');
    }
});

register_deactivation_hook(__FILE__, function () {
    CHC_Htaccess::remove(chc_root_htaccess());
    wp_clear_scheduled_hook('chc_ttl_sweep');
    chc_store()->purge_all();
});

// Regenerar reglas al guardar ajustes.
add_action('update_option_chc_settings', 'chc_refresh_htaccess');
add_action('add_option_chc_settings', 'chc_refresh_htaccess');

// Wiring de componentes.
(new CHC_Page_Generator())->register();
(new CHC_Purge())->register();
(new CHC_Role_Gate())->register();
if (is_admin()) { (new CHC_Admin_Page())->register(); }

if (defined('WP_CLI') && WP_CLI) {
    require_once CHC_DIR . 'includes/class-cli.php';
    WP_CLI::add_command('corehost-cache', 'CHC_CLI');
}
```

- [ ] **Step 2: Implementar `uninstall.php`**
```php
<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

require_once __DIR__ . '/includes/class-htaccess.php';
require_once __DIR__ . '/includes/class-cache-store.php';

CHC_Htaccess::remove(ABSPATH . '.htaccess');
(new CHC_Cache_Store(WP_CONTENT_DIR . '/cache/corehost-cache'))->purge_all();

delete_option('chc_settings');
delete_option('chc_htaccess_writable');
```

- [ ] **Step 3: Commit** (nota: aún falta admin y CLI; el plugin todavía no activa)
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: bootstrap del plugin + uninstall"
```

---

## Task 7: Admin (`class-admin-page.php` + `admin.js`) y CLI (`class-cli.php`)

**Files:**
- Create: `admin/class-admin-page.php`, `admin/admin.js`, `includes/class-cli.php`

- [ ] **Step 1: Implementar `admin/class-admin-page.php`**
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** Página de ajustes: on/off, TTL, roles, exclusiones, purga y stats. */
class CHC_Admin_Page
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_chc_purge_all', [$this, 'ajax_purge']);
    }

    public function menu(): void
    {
        add_options_page('CoreHost Cache', 'CoreHost Cache', 'manage_options', 'chc-settings', [$this, 'render']);
    }

    public function settings(): void
    {
        register_setting('chc', 'chc_settings', ['sanitize_callback' => [$this, 'sanitize']]);
    }

    public function sanitize($input): array
    {
        $input = (array) $input;
        $roles = function_exists('wp_roles') ? array_keys(wp_roles()->get_names()) : [];
        $excluded = array_values(array_intersect($roles, (array) ($input['excluded_roles'] ?? [])));
        return [
            'enabled'        => empty($input['enabled']) ? 0 : 1,
            'ttl_hours'      => max(0, (int) ($input['ttl_hours'] ?? 10)),
            'cache_404'      => empty($input['cache_404']) ? 0 : 1,
            'excluded_urls'  => sanitize_textarea_field($input['excluded_urls'] ?? ''),
            'gzip'           => empty($input['gzip']) ? 0 : 1,
            'brotli'         => empty($input['brotli']) ? 0 : 1,
            'excluded_roles' => $excluded,
        ];
    }

    public function enqueue($hook): void
    {
        if ($hook !== 'settings_page_chc-settings') { return; }
        wp_enqueue_script('chc-admin', plugins_url('admin.js', __FILE__), [], CHC_VERSION, true);
        wp_localize_script('chc-admin', 'chcAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('chc_purge'),
        ]);
    }

    public function ajax_purge(): void
    {
        check_ajax_referer('chc_purge');
        if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
        chc_store()->purge_all();
        update_option('chc_last_purge', time(), false);
        wp_send_json_success(['ok' => true]);
    }

    public function render(): void
    {
        $s     = chc_settings();
        $stats = chc_store()->stats();
        $roles = function_exists('wp_roles') ? wp_roles()->get_names() : [];
        ?>
        <div class="wrap">
            <h1>CoreHost Cache</h1>
            <?php if (!get_option('chc_htaccess_writable', 1)) : ?>
                <div class="notice notice-error"><p>No se pudo escribir <code><?php echo esc_html(chc_root_htaccess()); ?></code>. Pega este bloque arriba de <code># BEGIN WordPress</code>:</p>
                <textarea readonly rows="12" style="width:100%;font-family:monospace"><?php echo esc_textarea(CHC_Htaccess::rules(chc_cache_url_path())); ?></textarea></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('chc'); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row">Cache</th><td>
                        <label><input type="checkbox" name="chc_settings[enabled]" value="1" <?php checked($s['enabled']); ?>> Activar</label>
                    </td></tr>
                    <tr><th scope="row">TTL (horas)</th><td>
                        <input type="number" min="0" name="chc_settings[ttl_hours]" value="<?php echo esc_attr($s['ttl_hours']); ?>"> <span class="description">0 = sin expiración por tiempo</span>
                    </td></tr>
                    <tr><th scope="row">Compresión</th><td>
                        <label><input type="checkbox" name="chc_settings[gzip]" value="1" <?php checked($s['gzip']); ?>> gzip</label>&nbsp;
                        <label><input type="checkbox" name="chc_settings[brotli]" value="1" <?php checked($s['brotli']); ?>> Brotli</label>
                    </td></tr>
                    <tr><th scope="row">404</th><td>
                        <label><input type="checkbox" name="chc_settings[cache_404]" value="1" <?php checked($s['cache_404']); ?>> Cachear páginas 404</label>
                    </td></tr>
                    <tr><th scope="row">Roles excluidos del cache</th><td>
                        <p class="description">Los roles marcados <strong>siempre reciben la página fresca</strong> (bypass). Los desmarcados reciben la versión cacheada anónima.</p>
                        <?php foreach ($roles as $slug => $name) : ?>
                            <label style="display:inline-block;min-width:200px">
                                <input type="checkbox" name="chc_settings[excluded_roles][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, (array) $s['excluded_roles'], true)); ?>>
                                <?php echo esc_html($name); ?> <code><?php echo esc_html($slug); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td></tr>
                    <tr><th scope="row">URLs excluidas</th><td>
                        <textarea name="chc_settings[excluded_urls]" rows="4" style="width:100%" placeholder="/carrito&#10;/mi-cuenta"><?php echo esc_textarea($s['excluded_urls']); ?></textarea>
                        <p class="description">Una por línea; coincidencia por subcadena de la URL.</p>
                    </td></tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Estado</h2>
            <p><?php echo (int) $stats['pages']; ?> páginas cacheadas · <?php echo esc_html(size_format($stats['bytes'], 1)); ?> en disco.
            <?php if ($lp = (int) get_option('chc_last_purge', 0)) : ?> Última purga: <?php echo esc_html(date_i18n('Y-m-d H:i', $lp)); ?>.<?php endif; ?></p>
            <p><button type="button" class="button button-primary" id="chc-purge">Purgar todo</button> <span id="chc-purge-msg"></span></p>
        </div>
        <?php
    }
}
```

- [ ] **Step 2: Implementar `admin/admin.js`**
```javascript
document.getElementById('chc-purge')?.addEventListener('click', async function () {
    const msg = document.getElementById('chc-purge-msg');
    this.disabled = true; msg.textContent = 'Purgando…';
    try {
        const body = new URLSearchParams({ action: 'chc_purge_all', _wpnonce: chcAdmin.nonce });
        const r = await fetch(chcAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
        const j = await r.json();
        msg.textContent = j.success ? 'Cache purgada ✓' : 'Error';
    } catch (e) { msg.textContent = 'Error'; }
    this.disabled = false;
    setTimeout(() => { location.reload(); }, 800);
});
```

- [ ] **Step 3: Implementar `includes/class-cli.php`**
```php
<?php
if (!defined('ABSPATH')) { exit; }

/** WP-CLI: wp corehost-cache purge|status|warm */
class CHC_CLI
{
    /** Purga todo el cache. */
    public function purge($args, $assoc): void
    {
        chc_store()->purge_all();
        update_option('chc_last_purge', time(), false);
        WP_CLI::success('Cache purgada.');
    }

    /** Muestra estado. */
    public function status($args, $assoc): void
    {
        $s = chc_store()->stats();
        WP_CLI::log('Páginas: ' . $s['pages'] . ' · Disco: ' . size_format($s['bytes'], 1));
        WP_CLI::log('.htaccess escribible: ' . (get_option('chc_htaccess_writable', 1) ? 'sí' : 'NO'));
    }

    /** Precalienta el cache visitando las URLs del sitemap. */
    public function warm($args, $assoc): void
    {
        $sitemap = home_url('/wp-sitemap.xml');
        $body = wp_remote_retrieve_body(wp_remote_get($sitemap, ['timeout' => 20]));
        if (!$body) { WP_CLI::error('No se pudo leer el sitemap: ' . $sitemap); }
        preg_match_all('#<loc>([^<]+)</loc>#', $body, $m);
        $urls = array_slice(array_unique($m[1] ?? []), 0, 500);
        $n = 0;
        foreach ($urls as $u) {
            if (str_contains($u, 'sitemap')) { continue; }
            wp_remote_get($u, ['timeout' => 20, 'headers' => ['Accept-Encoding' => 'br,gzip']]);
            $n++;
        }
        WP_CLI::success("Precalentadas $n URLs.");
    }
}
```

- [ ] **Step 4: Deploy + correr UNIT (los tests puros deben seguir en verde)**

Run: DEPLOY && UNIT
Expected: todos pass (no rompimos las clases puras)

- [ ] **Step 5: Commit**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "feat: admin (ajustes + roles + purga + stats) y WP-CLI"
```

---

## Task 8: Verificación de integración end-to-end en Keypro

**Files:** (ninguno nuevo — verificación en el sitio real)

- [ ] **Step 1: Verificar Brotli en el server**

Run:
```bash
ssh aldeahostlatam 'php -r "echo function_exists(\"brotli_compress\")?\"brotli SI\n\":\"brotli NO\n\";"'
```
Expected: registra si hay Brotli. Si dice NO, en Ajustes se desmarca Brotli (el plugin degrada a gzip sin romper).

- [ ] **Step 2: Deploy + activar el plugin**

Run:
```bash
# DEPLOY (ver comando arriba), luego:
ssh aldeahostlatam 'wp --path=/home/corehost/public_html/key --allow-root plugin activate corehost-cache'
```
Expected: `Plugin 'corehost-cache' activated.` sin fatal.

- [ ] **Step 3: Verificar que el bloque quedó ARRIBA de WordPress en el .htaccess**

Run:
```bash
ssh aldeahostlatam 'grep -n "BEGIN CoreHost Cache\|BEGIN WordPress" /home/corehost/public_html/key/.htaccess'
```
Expected: la línea de `BEGIN CoreHost Cache` tiene número menor que `BEGIN WordPress`.

- [ ] **Step 4: Generar cache visitando una página anónima**

Run:
```bash
UA="Mozilla/5.0"; curl -s -o /dev/null -A "$UA" "https://aldeahostlatam.com/key/"
ssh aldeahostlatam 'find /home/corehost/public_html/key/wp-content/cache/corehost-cache -type f | head'
```
Expected: aparecen `index.html`, `index.html.gz` y (si hay ext) `index.html.br`.

- [ ] **Step 5: Confirmar HIT y Content-Encoding servidos por .htaccess**

Run:
```bash
U="https://aldeahostlatam.com/key/"
echo "--- br ---"; curl -s -o /dev/null -D - -H "Accept-Encoding: br,gzip" "$U" | grep -iE "^x-corehost-cache|^content-encoding|^vary|^content-type"
echo "--- sin encoding ---"; curl -s -o /dev/null -D - -H "Accept-Encoding: identity" "$U" | grep -iE "^x-corehost-cache|^content-type"
```
Expected: con `Accept-Encoding: br` → `X-CoreHost-Cache: HIT`, `Content-Encoding: br`, `Content-Type: text/html`. Sin encoding → `HIT` y HTML plano.
Si NO aparece HIT (posible subtlety mod_rewrite en subdirectorio): revisar `RewriteBase`/rutas y ajustar `chc_cache_url_path()`/`rules()`; re-`chc_refresh_htaccess`. Documentar el fix.

- [ ] **Step 6: Confirmar bypass de logueados y de query string**

Run:
```bash
U="https://aldeahostlatam.com/key/"
echo "--- con cookie logged_in (simulada) ---"; curl -s -o /dev/null -D - -H "Cookie: wordpress_logged_in_abc=1; chc_nocache=1" "$U" | grep -iE "^x-corehost-cache" || echo "(sin HIT = correcto, sirvió PHP)"
echo "--- con query string ---"; curl -s -o /dev/null -D - "$U?x=1" | grep -iE "^x-corehost-cache" || echo "(sin HIT = correcto)"
```
Expected: ninguno devuelve HIT (bypass correcto).

- [ ] **Step 7: Confirmar invalidación por evento**

Run:
```bash
P=/home/corehost/public_html/key
ssh aldeahostlatam "wp --path=$P --allow-root eval 'do_action(\"save_post\", 2, get_post(2), true);'"
ssh aldeahostlatam "find $P/wp-content/cache/corehost-cache -name index.html | wc -l"
```
Expected: la home fue purgada (menos archivos); luego se regenera en la próxima visita.

- [ ] **Step 8: Verificar WP-CLI**

Run:
```bash
ssh aldeahostlatam 'wp --path=/home/corehost/public_html/key --allow-root corehost-cache status'
ssh aldeahostlatam 'wp --path=/home/corehost/public_html/key --allow-root corehost-cache purge'
```
Expected: `status` muestra páginas/disco; `purge` → "Cache purgada."

- [ ] **Step 9: Commit final + tag**
```bash
cd "C:/Users/Luis Oses/Tools/corehost-cache" && git add -A && git commit -m "chore: verificación e2e en Keypro (v1.0.0)" --allow-empty && git tag v1.0.0
```

---

## Self-Review

**Cobertura del spec:**
- §4 servicio .htaccess → Task 2 (rules/install) + Task 8 (verificación serve/HIT/encoding/orden). ✓
- §5 generación + is_cacheable → Task 3 + Task 5 (Page_Generator) + Task 8 step 4. ✓
- §5.1 exclusión por rol → Task 4 (should_bypass + cookie) + Task 7 (UI de roles) + Task 8 step 6. ✓
- §6 invalidación evento+TTL → Task 5 (Purge) + Task 1 (sweep) + Task 8 step 7. ✓
- §7 admin → Task 7. ✓
- §8 almacenamiento → Task 1 (dir_for/write). ✓
- §9 activación/desactivación/uninstall → Task 6. ✓
- §10 riesgos → Task 8 (brotli step1, orden step3, serve/encoding step5, subdir en step5 nota). ✓
- §11 testing → Tasks 1–4 (unit) + Task 8 (integración). ✓

**Placeholders:** ninguno; todo el código está completo.

**Consistencia de tipos/nombres:** `chc_settings()`, `chc_store()`, `chc_cache_url_path()`, `chc_root_htaccess()`, `chc_refresh_htaccess()`, `CHC_Cache_Store::{dir_for,write,delete,purge_all,sweep,stats}`, `CHC_Htaccess::{rules,install,remove}`, `CHC_Request_Rules::{should_cache,matches_any,is_cacheable}`, `CHC_Role_Gate::{should_bypass,COOKIE}` — usados consistentemente entre tareas. ✓

**Nota de riesgo abierta (para el ejecutor):** la instalación en subdirectorio `/key/` es el caso más delicado del `.htaccess` (RewriteBase + rutas). Task 8 step 5 lo verifica explícitamente; si no da HIT, es ahí donde se ajusta antes de dar por terminado.
