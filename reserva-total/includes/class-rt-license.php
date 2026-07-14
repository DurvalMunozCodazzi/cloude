<?php
class RT_License {
    public static function check() {
        $key = defined('RT_LICENSE_KEY') ? RT_LICENSE_KEY : '';
        if (!$key) return ['valid' => true, 'plan' => 'trial', 'max_resources' => 3];

        $cache = RT_APP_DIR . 'rt-license-cache.json';
        if (file_exists($cache)) {
            $c = json_decode(file_get_contents($cache), true);
            if ($c && isset($c['expires']) && $c['expires'] > time()) return $c;
        }
        // Remote verify (future)
        return ['valid' => true, 'plan' => 'trial', 'max_resources' => 3];
    }
}
