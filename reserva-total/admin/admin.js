// Reserva Total — WP Admin panel JS
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var saveBtn  = document.getElementById('rt-license-save');
        var checkBtn = document.getElementById('rt-license-check');
        var input    = document.getElementById('rt-license-key');
        var status   = document.getElementById('rt-license-status');
        if (!saveBtn || !checkBtn || !input || !status || typeof RT_ADMIN === 'undefined') return;

        function showStatus(result) {
            status.classList.remove('rt-status-ok', 'rt-status-err');
            if (result && result.valid) {
                status.classList.add('rt-status-ok');
                status.textContent = '✓ Licencia válida — Plan ' + (result.plan || '') +
                    (result.expires_at ? ' · vence ' + result.expires_at : ' · sin vencimiento');
            } else {
                status.classList.add('rt-status-err');
                status.textContent = '✗ ' + (result && result.message ? result.message : 'Licencia inválida');
            }
        }

        function post(action, extra) {
            var body = new URLSearchParams(Object.assign({ action: action, nonce: RT_ADMIN.nonce }, extra || {}));
            return fetch(RT_ADMIN.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            }).then(function(r) { return r.json(); });
        }

        saveBtn.addEventListener('click', function() {
            status.textContent = 'Guardando…';
            status.className = 'rt-license-status';
            post('rt_save_license', { license_key: input.value.trim() }).then(function(res) {
                status.textContent = res && res.success ? '✓ Clave guardada' : '✗ Error al guardar';
                status.className = 'rt-license-status ' + (res && res.success ? 'rt-status-ok' : 'rt-status-err');
            });
        });

        checkBtn.addEventListener('click', function() {
            status.textContent = 'Verificando…';
            status.className = 'rt-license-status';
            post('rt_check_license_status').then(showStatus);
        });
    });
})();
