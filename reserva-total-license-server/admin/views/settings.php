<?php defined('ABSPATH') || exit; ?>
<div class="wrap rtls-wrap">
  <h1 class="rtls-title"><span class="rtls-logo">⚙️</span> Configuración</h1>
  <div class="rtls-form-wrap" style="max-width:600px">
    <form method="post" class="rtls-form">
      <?php wp_nonce_field('rtls_settings', 'rtls_settings_nonce'); ?>
      <div class="rtls-field">
        <label class="rtls-label">Endpoint de verificación (solo lectura)</label>
        <input type="text" class="rtls-input" value="<?= esc_url(rest_url('reserva-total-licenses/v1/verify')) ?>" readonly onclick="this.select()">
        <span class="rtls-hint">Este es el URL que debe configurarse en el plugin Reserva Total del cliente (RT_LICENSE_SERVER).</span>
      </div>
      <div class="rtls-field">
        <label class="rtls-label">Clave Privada RSA <span style="color:#c62828">&#9888;&#65039; Solo en este servidor — nunca compartir</span></label>
        <textarea class="rtls-input rtls-mono" name="rtls_private_key" rows="10"
                  style="font-size:11px;resize:vertical"
                  placeholder="-----BEGIN PRIVATE KEY-----"><?= esc_textarea(get_option('rtls_private_key','')) ?></textarea>
        <span class="rtls-hint">
          <?php if ($private_key_set): ?>
            <span style="color:#2e7d32">&#10003; Clave privada configurada.</span>
          <?php else: ?>
            <span style="color:#c62828">Sin clave privada — las licencias saldrán sin firmar con RSA.</span>
          <?php endif; ?>
          Pegá acá la clave privada RSA generada. La clave pública va en el plugin cliente (class-rt-license.php).
        </span>
      </div>
      <div class="rtls-field">
        <label class="rtls-label">CallMeBot — API Key (notificación WhatsApp de solicitudes)</label>
        <input type="text" class="rtls-input" name="callmebot_apikey" value="<?= esc_attr($callmebot_apikey) ?>" placeholder="Ej: 6291539">
      </div>
      <div class="rtls-field">
        <label class="rtls-label">CallMeBot — Teléfono destino</label>
        <input type="text" class="rtls-input" name="callmebot_phone" value="<?= esc_attr($callmebot_phone) ?>" placeholder="Ej: 5491153283558">
      </div>
      <div class="rtls-form-footer">
        <button type="submit" class="rtls-btn rtls-btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>
