<?php
// CHC_Html_Integrity — extracción de CSS locales de uploads y vetos de integridad.

$up_url = 'https://example.com/key/wp-content/uploads';
$up_dir = '/home/x/public_html/key/wp-content/uploads';

// --- local_upload_styles: extracción y mapeo ---
$html = <<<HTML
<link rel='stylesheet' id='base-desktop-css' href='https://example.com/key/wp-content/uploads/elementor/css/base-desktop.css?ver=6a74e' media='all' />
<link rel="stylesheet" href="https://example.com/key/wp-content/uploads/elementor/css/post-20.css">
<link rel='stylesheet' href='https://example.com/key/wp-content/plugins/elementor/assets/css/frontend.min.css?ver=4.2.0' />
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans">
<link href='https://example.com/key/wp-content/uploads/elementor/css/base-mobile.css?ver=1' rel='stylesheet' media='(max-width:767px)' />
<link rel="preload" href="https://example.com/key/wp-content/uploads/elementor/css/no-es-stylesheet.css">
HTML;
$got = CHC_Html_Integrity::local_upload_styles($html, $up_url, $up_dir);
t_eq($got, [
    $up_dir . '/elementor/css/base-desktop.css',
    $up_dir . '/elementor/css/post-20.css',
    $up_dir . '/elementor/css/base-mobile.css',
], 'extrae solo stylesheets bajo uploads, sin query, href en cualquier orden de atributos');

t_eq(CHC_Html_Integrity::local_upload_styles('<p>sin links</p>', $up_url, $up_dir), [], 'HTML sin links => vacío');

// barra final en uploads_url/uploads_dir no debe duplicarse
$got = CHC_Html_Integrity::local_upload_styles(
    '<link rel="stylesheet" href="https://example.com/key/wp-content/uploads/a.css">',
    $up_url . '/', $up_dir . '/'
);
t_eq($got, [$up_dir . '/a.css'], 'tolera barras finales en url/dir');

// --- assets_veto ---
$html_ok = '<link rel="stylesheet" href="https://example.com/key/wp-content/uploads/elementor/css/base-desktop.css?ver=1">';
t_eq(CHC_Html_Integrity::assets_veto($html_ok, $up_url, $up_dir, fn($p) => true), null, 'todos los CSS existen => sin veto');
t_eq(
    CHC_Html_Integrity::assets_veto($html_ok, $up_url, $up_dir, fn($p) => false),
    'assets:' . $up_dir . '/elementor/css/base-desktop.css',
    'CSS referenciado inexistente => veto con la ruta'
);
t_eq(CHC_Html_Integrity::assets_veto('<p>nada</p>', $up_url, $up_dir, fn($p) => false), null, 'sin CSS de uploads => sin veto');

// --- elementor_veto ---
$wrapped = '<div data-elementor-type="wp-page" data-elementor-id="20" class="elementor elementor-20">';
t_eq(CHC_Html_Integrity::elementor_veto($wrapped, 20, 60000, true, 'builder'), null, 'builder con wrapper => sin veto');
t_eq(CHC_Html_Integrity::elementor_veto('<p>plano</p>', 20, 60000, true, 'builder'), 'elementor:sin-wrapper', 'builder sin wrapper => veto');
t_eq(CHC_Html_Integrity::elementor_veto('<p>plano</p>', 20, 60000, false, ''), 'elementor:meta-perdida', 'meta perdida + data => veto (caso Keypro)');
t_eq(CHC_Html_Integrity::elementor_veto($wrapped, 20, 60000, false, ''), null, 'meta perdida pero wrapper presente => sin veto');
t_eq(CHC_Html_Integrity::elementor_veto('<p>plano</p>', 20, 50, false, ''), null, 'data trivial => sin veto');
t_eq(CHC_Html_Integrity::elementor_veto('<p>plano</p>', 20, 60000, true, ''), null, 'meta existe vacía (volvió a Gutenberg a propósito) => sin veto');
t_eq(CHC_Html_Integrity::elementor_veto('<p>plano</p>', 0, 60000, false, ''), null, 'sin post id => sin veto');
// id que es prefijo de otro no debe dar falso positivo de wrapper
t_eq(
    CHC_Html_Integrity::elementor_veto('<div data-elementor-id="201">', 20, 60000, true, 'builder'),
    'elementor:sin-wrapper',
    'wrapper de otro id (201) no cuenta como el del 20'
);
