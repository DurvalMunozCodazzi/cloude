<?php
defined('ABSPATH') || exit;

class RTLS_Api {

    public function register_routes(): void {
        register_rest_route('reserva-total-licenses/v1', '/verify', [
            'methods'             => 'POST',
            'callback'            => [$this, 'verify'],
            'permission_callback' => '__return_true',
            'args'                => [
                'license_key' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'domain'      => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route('reserva-total-licenses/v1', '/solicitar', [
            'methods'             => 'POST',
            'callback'            => [$this, 'solicitar'],
            'permission_callback' => '__return_true',
            'args'                => [
                'nombre'   => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'email'    => ['required' => true, 'sanitize_callback' => 'sanitize_email'],
                'telefono' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'dominio'  => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function verify(WP_REST_Request $request): WP_REST_Response {
        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rate_key = 'rtls_rate_' . md5($ip);
        $hits     = (int) get_transient($rate_key);
        if ($hits >= 10) {
            return new WP_REST_Response(['valid' => false, 'reason' => 'rate_limit', 'message' => 'Demasiadas verificaciones. Esperá 1 minuto.'], 429);
        }
        set_transient($rate_key, $hits + 1, MINUTE_IN_SECONDS);

        $key    = $request->get_param('license_key');
        $domain = $request->get_param('domain');

        if (empty($key) || empty($domain)) {
            return new WP_REST_Response(['valid' => false, 'reason' => 'missing_params', 'message' => 'Faltan parámetros requeridos.'], 400);
        }

        $result = RTLS_License::verify($key, $domain);
        // Siempre 200 — los llamadores chequean el campo 'valid' en el JSON.
        // Un 4xx dispara capas de seguridad (Wordfence, mod_security) que
        // reemplazan nuestra respuesta JSON por su propia página de error.
        return new WP_REST_Response($result, 200);
    }

    public function solicitar(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;

        $nombre   = $request->get_param('nombre');
        $email    = $request->get_param('email');
        $telefono = $request->get_param('telefono');
        $dominio  = RTLS_License::normalize_domain($request->get_param('dominio'));

        if (!$nombre || !is_email($email) || !$telefono || !$dominio) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Datos incompletos o inválidos.'], 200);
        }

        $ip       = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = 'rtls_sol_rate_' . md5($ip);
        $hits     = (int) get_transient($rate_key);
        if ($hits >= 3) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Demasiadas solicitudes. Intentá en una hora.'], 200);
        }
        set_transient($rate_key, $hits + 1, HOUR_IN_SECONDS);

        $t = $wpdb->prefix . 'rtls_requests';
        $wpdb->insert($t, compact('nombre', 'email', 'telefono', 'dominio'));

        $apikey = get_option('rtls_callmebot_apikey', '');
        $phone  = get_option('rtls_callmebot_phone',  '');
        if ($apikey && $phone) {
            $texto = "🗓 Nueva solicitud Reserva Total:\n👤 {$nombre}\n📧 {$email}\n📱 {$telefono}\n🌐 {$dominio}";
            wp_remote_get(add_query_arg([
                'phone'  => $phone,
                'text'   => urlencode($texto),
                'apikey' => $apikey,
            ], 'https://api.callmebot.com/whatsapp.php'), ['timeout' => 10, 'blocking' => false]);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
