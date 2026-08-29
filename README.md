# CoreHost Cache

Page cache para WordPress que **sirve el HTML desde `.htaccess`, sin levantar PHP**. Con exclusión por rol, invalidación por evento y TTL, y las guardas que hacen falta para no romper WooCommerce.

La diferencia con un cache normal de WordPress es dónde se corta la petición. Casi todos los plugins de cache siguen arrancando PHP para decidir si sirven la copia. Aquí la reescritura vive en `.htaccess`: si hay copia válida, Apache o LiteSpeed la entregan directamente y WordPress ni se entera. LiteSpeed además la comprime al vuelo.

- **PHP 8.1+**
- **121 tests, 0 fallos** (`php tests/run.php`)
- GPL-2.0-or-later

## Qué hace

**Cache servido por el servidor.** `class-htaccess.php` genera las reglas de reescritura; `class-page-generator.php` escribe las copias. La petición cacheada no toca el intérprete.

**Exclusión por rol.** `class-role-gate.php` decide quién ve copia y quién ve la página generada. Por defecto **ningún rol** recibe cache hasta que se configura: es preferible cachear de menos que servirle a un cliente el carrito de otro.

**Seguro para WooCommerce.** `class-request-rules.php` deja fuera carrito, checkout, mi cuenta y cualquier petición con sesión o cookies de comercio.

**Invalidación por evento y por TTL.** `class-purge.php` limpia lo que corresponde al publicar, editar o borrar, sin tirar el cache entero.

**Purga en Cloudflare.** `class-cloudflare.php` propaga la invalidación al CDN. El token nunca se registra en los logs, y un fallo de la API no interrumpe el guardado del post que la disparó.

**Guarda de integridad del HTML.** `class-html-integrity.php` verifica que la copia esté completa antes de guardarla: una respuesta truncada cacheada es peor que no cachear.

**Drop-in y WP-CLI.** `advanced-cache.php` para el arranque temprano y `class-cli.php` para operar desde consola.

## Instalación

Copiar la carpeta en `wp-content/plugins/` y activar. El plugin instala su propio drop-in y escribe las reglas en `.htaccess`.

Al desinstalar, `uninstall.php` retira reglas, drop-in y opciones. No deja restos.

## Tests

```bash
php tests/run.php
```

Sin dependencias ni framework: `tests/bootstrap.php` simula lo justo de WordPress para poder probar la lógica pura (reglas de petición, roles, `.htaccess`, integridad del HTML, cliente de Cloudflare, almacén) sin levantar una instalación.

## Estructura

```
corehost-cache.php        arranque y ajustes
includes/                 logica: store, htaccess, reglas, roles, purga, cloudflare, drop-in
admin/                    pantalla de ajustes, barra de admin, meta por entrada
dropin/advanced-cache.php drop-in de arranque temprano
tests/                    121 aserciones
docs/ y plans/            decisiones de diseño e iteraciones
```

## Limitaciones conocidas

- Pensado para **Apache y LiteSpeed**. La variante para nginx está planteada en `plans/005-nginx-dropin.md` pero no implementada: nginx no lee `.htaccess`, así que necesita otro mecanismo.
- El cache es por URL. No segmenta por idioma, moneda ni geolocalización.

## Licencia

GPL-2.0-or-later
