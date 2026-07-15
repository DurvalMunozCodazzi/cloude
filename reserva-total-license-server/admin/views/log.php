<?php defined('ABSPATH') || exit; ?>
<div class="wrap rtls-wrap">
  <h1 class="rtls-title">
    <span class="rtls-logo">📋</span>
    Log de Verificaciones
    <?php if ($key): ?>
      <span style="font-size:14px;font-weight:400;margin-left:10px">— <?= esc_html($key) ?></span>
    <?php endif; ?>
    <a href="<?= admin_url('admin.php?page=reserva-total-licenses') ?>" class="rtls-btn rtls-btn-ghost" style="float:right">← Licencias</a>
  </h1>

  <div class="rtls-table-wrap">
    <table class="rtls-table">
      <thead>
        <tr>
          <th>Clave</th>
          <th>Dominio</th>
          <th>Resultado</th>
          <th>IP</th>
          <th>Fecha</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($logs)): ?>
          <tr><td colspan="5" class="rtls-empty">Sin registros.</td></tr>
        <?php else: foreach ($logs as $log):
          $cls = ['valid'=>'ok','invalid'=>'er','not_found'=>'er','expired'=>'wa','suspended'=>'er'][$log['result']] ?? '';
        ?>
          <tr>
            <td><span class="rtls-key"><?= esc_html($log['license_key']) ?></span></td>
            <td><?= esc_html($log['domain'] ?: '—') ?></td>
            <td><span class="rtls-badge rtls-badge-<?= $cls ?>"><?= esc_html($log['result']) ?></span></td>
            <td><span class="rtls-meta"><?= esc_html($log['ip']) ?></span></td>
            <td><span class="rtls-meta"><?= esc_html($log['verified_at']) ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
