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
