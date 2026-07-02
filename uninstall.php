<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

require_once __DIR__ . '/includes/class-htaccess.php';
require_once __DIR__ . '/includes/class-cache-store.php';

CHC_Htaccess::remove(ABSPATH . '.htaccess');
(new CHC_Cache_Store(WP_CONTENT_DIR . '/cache/corehost-cache'))->purge_all();

delete_option('chc_settings');
delete_option('chc_htaccess_writable');
