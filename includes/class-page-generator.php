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
            $marked = $html . "\n<!-- corehost-cache " . gmdate('Y-m-d H:i:s') . " UTC -->";
            chc_store()->write(
                $_SERVER['HTTP_HOST'] ?? '',
                $_SERVER['REQUEST_URI'] ?? '/',
                $marked
            );
        }
        return $html;
    }
}
