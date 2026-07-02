<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Purga del edge de Cloudflare al invalidar el cache local.
 * No-fatal: cualquier error de red/API se guarda en `chc_cf_last_error` y nunca
 * interrumpe el flujo que la llamó (p.ej. guardar un post). El token NUNCA se loguea.
 */
class CHC_Cloudflare
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
    private const BATCH    = 30;

    /**
     * Construye la petición para `wp_remote_post` (pura: sin dependencias de WP,
     * testeable con `php tests/run.php` sin cargar WordPress).
     */
    public static function build_request(string $zone, string $token, array $body): array
    {
        return [
            'url'  => sprintf(self::ENDPOINT, $zone),
            'args' => [
                'method'  => 'POST',
                'timeout' => 5,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES),
            ],
        ];
    }

    /** Purga por URL (lotes de 30). No hace nada si CF no está listo (gating). */
    public function purge_urls(array $urls): void
    {
        $cfg = $this->config();
        if (!$cfg) { return; }
        $urls = array_values(array_filter($urls));
        if (!$urls) { return; }
        foreach (array_chunk($urls, self::BATCH) as $chunk) {
            $this->send($cfg['zone'], $cfg['token'], ['files' => $chunk]);
        }
    }

    /** Purga total del edge. No hace nada si CF no está listo (gating). */
    public function purge_all(): void
    {
        $cfg = $this->config();
        if (!$cfg) { return; }
        $this->send($cfg['zone'], $cfg['token'], ['purge_everything' => true]);
    }

    /** Zone + token listos, y la integración está activada; null si falta algo. */
    private function config(): ?array
    {
        $s     = function_exists('chc_settings') ? chc_settings() : [];
        $on    = !empty($s['cf_enabled']);
        $zone  = (string) ($s['cf_zone'] ?? '');
        $token = defined('CHC_CF_TOKEN') ? (string) CHC_CF_TOKEN : (string) ($s['cf_token'] ?? '');
        if (!$on || $zone === '' || $token === '') { return null; }
        return ['zone' => $zone, 'token' => $token];
    }

    /** Llamada bloqueante no-fatal a la API de purga. Nunca lanza ni imprime el token. */
    private function send(string $zone, string $token, array $body): void
    {
        try {
            $req  = self::build_request($zone, $token, $body);
            $resp = wp_remote_post($req['url'], $req['args']);
            if (is_wp_error($resp)) {
                $this->log_error($resp->get_error_message());
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code < 200 || $code >= 300) {
                $this->log_error('HTTP ' . $code);
                return;
            }
            update_option('chc_cf_last_error', '', false);
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage());
        }
    }

    private function log_error(string $msg): void
    {
        update_option('chc_cf_last_error', substr($msg, 0, 300), false);
    }
}
