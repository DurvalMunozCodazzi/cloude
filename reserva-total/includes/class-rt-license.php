<?php
defined('ABSPATH') || exit;

class RT_License {

    // Endpoint REST del plugin Reserva Total License Server instalado en reservatotal.ar
    const SERVER = 'https://reservatotal.ar/wp-json/reserva-total-licenses/v1/verify';
    // RSA public key — safe to embed in source; private key never leaves the license server
    const PUBLIC_KEY = <<<'RSA_PUB'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuFGICih1P2eIzFhk5Xd5
1yP/Cr6jJzmg6g5psza2/i84ydrQEuepzCHsVha+oNJ79xmAsQjuzAMW0O5se0eE
NUjdIQkt8S6ILbbafRnOsCEjTwuinxsi+JxnTaKCpvEJI06nDC8chlCEfWHs27OS
9I056B62cTR4IfjQQ5cKT83I+XDiycv2Uu4b88eADNKDNLfJGId2b+5G8PqlcGSD
WhZiqTH3m7zv99f8XtwzvUD7FYsMTg9YVaG2vop3KFVAIPPaAfTlq6GTXOsJ6GLo
CK9ZoPhX5CacXhCsA1lTVMPR5/cMCB8JXhUmRw2FSQioAjwTwkPR8wNu2/X/1kwk
OwIDAQAB
-----END PUBLIC KEY-----
RSA_PUB;

    const PLANS = [
        'free'         => ['label' => 'Gratis',       'max_resources' => 1],
        'starter'      => ['label' => 'Starter',      'max_resources' => 3],
        'professional' => ['label' => 'Professional', 'max_resources' => 15],
        'unlimited'    => ['label' => 'Unlimited',     'max_resources' => 999],
    ];

    public static function verify(string $key, string $domain): array {
        if (empty($key)) {
            return ['valid' => false, 'reason' => 'no_key', 'message' => 'No se ingresó una clave de licencia'];
        }

        $server    = get_option('rt_license_server_url', self::SERVER);
        $cache_key = 'rt_license_' . md5($key . $domain);
        $cached    = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $response = wp_remote_post($server, [
            'timeout'    => 15,
            'user-agent' => 'Reserva-Total/' . (defined('RT_VERSION') ? RT_VERSION : '1.0'),
            'headers'    => ['Content-Type' => 'application/json'],
            'body'       => wp_json_encode(['license_key' => $key, 'domain' => $domain]),
        ]);

        if (is_wp_error($response)) {
            // Modo offline: usar solo si existe cache previa válida para esta clave exacta
            $offline = get_option('rt_license_offline_cache_' . md5($key), null);
            if ($offline && is_array($offline) && !empty($offline['valid'])) {
                $offline['offline'] = true;
                return $offline;
            }
            return [
                'valid'   => false,
                'reason'  => 'server_unreachable',
                'message' => 'No se pudo contactar el servidor de licencias ('
                             . $response->get_error_message()
                             . '). Verificá tu conexión o contactá al vendedor.',
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 429) {
            return ['valid' => false, 'reason' => 'rate_limit',
                    'message' => 'Demasiadas verificaciones. Esperá 1 minuto.'];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return ['valid' => false, 'reason' => 'bad_response',
                    'message' => 'Respuesta inválida del servidor de licencias (HTTP ' . $code . ')'];
        }

        // Verificar firma RSA — la clave privada nunca sale del servidor; esta clave pública es segura en el código
        if (!empty($data['sig']) && !empty($data['issued_at'])) {
            $payload = implode('|', [
                $key,
                $data['domain']     ?? $domain,
                !empty($data['valid']) ? 'true' : 'false',
                $data['plan']       ?? '',
                $data['expires_at'] ?? '',
                $data['issued_at'],
            ]);
            $pkey   = openssl_pkey_get_public(self::PUBLIC_KEY);
            $result = $pkey ? openssl_verify($payload, base64_decode($data['sig']), $pkey, OPENSSL_ALGO_SHA256) : -1;
            if ($result !== 1) {
                return ['valid' => false, 'reason' => 'invalid_signature',
                        'message' => 'La firma de la respuesta del servidor es inválida.'];
            }
        }

        // Guardar cache persistente para modo offline (solo si la licencia es válida)
        if (!empty($data['valid'])) {
            update_option('rt_license_offline_cache_' . md5($key), $data, false);
        }

        $ttl = !empty($data['valid']) ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        set_transient($cache_key, $data, $ttl);
        return $data;
    }

    public static function clear_cache(string $key): void {
        $domain = parse_url(get_site_url(), PHP_URL_HOST) ?? '';
        delete_transient('rt_license_' . md5($key . $domain));
        delete_transient('rt_license_' . md5($key));
        delete_option('rt_license_offline_cache_' . md5($key));
    }

    public static function plan_label(string $plan): string {
        return self::PLANS[$plan]['label'] ?? ucfirst($plan);
    }
}
