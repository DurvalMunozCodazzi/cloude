<?php defined('ABSPATH') || exit; ?>
<div class="wrap rtls-wrap">
  <h1 class="rtls-title">
    <span class="rtls-logo">🔑</span> Reserva Total Licenses
    <a href="<?= admin_url('admin.php?page=reserva-total-licenses-new') ?>" class="rtls-btn rtls-btn-primary" style="float:right">+ Nueva Licencia</a>
  </h1>

  <?php if (isset($_GET['msg'])): ?>
    <?php $msgs = ['created'=>'Licencia creada correctamente.','updated'=>'Licencia actualizada.','deleted'=>'Licencia eliminada.','error'=>'Error al guardar la licencia.']; ?>
    <div class="rtls-notice <?= $_GET['msg']==='error'?'rtls-notice-error':'rtls-notice-success' ?>">
      <?= esc_html($msgs[$_GET['msg']] ?? 'Operación realizada.') ?>
      <?php if ($_GET['msg']==='error' && !empty($_GET['dberr'])): ?>
        <br><small><strong>Detalle:</strong> <?= esc_html(urldecode($_GET['dberr'])) ?></small>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Stats -->
  <?php
    global $wpdb; $t = $wpdb->prefix.'rtls_licenses';
    $stats = $wpdb->get_row("SELECT
      COUNT(*) total,
      SUM(status='active') active,
      SUM(status='inactive') inactive,
      SUM(status='suspended') suspended,
      SUM(expires_at IS NOT NULL AND expires_at < CURDATE()) expired
      FROM `{$t}`", ARRAY_A);
  ?>
  <div class="rtls-stats">
    <div class="rtls-stat"><span class="rtls-stat-n"><?= (int)$stats['total'] ?></span><span class="rtls-stat-l">Total</span></div>
    <div class="rtls-stat rtls-stat-ok"><span class="rtls-stat-n"><?= (int)$stats['active'] ?></span><span class="rtls-stat-l">Activas</span></div>
    <div class="rtls-stat rtls-stat-wa"><span class="rtls-stat-n"><?= (int)$stats['inactive'] ?></span><span class="rtls-stat-l">Inactivas</span></div>
    <div class="rtls-stat rtls-stat-er"><span class="rtls-stat-n"><?= (int)$stats['suspended'] ?></span><span class="rtls-stat-l">Suspendidas</span></div>
    <div class="rtls-stat rtls-stat-er"><span class="rtls-stat-n"><?= (int)$stats['expired'] ?></span><span class="rtls-stat-l">Expiradas</span></div>
  </div>

  <!-- Filters -->
  <form method="get" class="rtls-filters">
    <input type="hidden" name="page" value="reserva-total-licenses">
    <input type="text" name="s" value="<?= esc_attr($search) ?>" placeholder="Buscar por clave, nombre, email o dominio…" class="rtls-input rtls-search">
    <select name="status" class="rtls-input rtls-sel">
      <option value="">Todos los estados</option>
      <option value="active"    <?= selected($status,'active',false) ?>>Activas</option>
      <option value="inactive"  <?= selected($status,'inactive',false) ?>>Inactivas</option>
      <option value="suspended" <?= selected($status,'suspended',false) ?>>Suspendidas</option>
    </select>
    <button type="submit" class="rtls-btn rtls-btn-secondary">Filtrar</button>
    <?php if ($search || $status): ?>
      <a href="<?= admin_url('admin.php?page=reserva-total-licenses') ?>" class="rtls-btn rtls-btn-ghost">✕ Limpiar</a>
    <?php endif; ?>
  </form>

  <!-- Table -->
  <div class="rtls-table-wrap">
    <table class="rtls-table">
      <thead>
        <tr>
          <th>Clave</th>
          <th>Cliente</th>
          <th>Dominio</th>
          <th>Plan</th>
          <th>Estado</th>
          <th>Vencimiento</th>
          <th>Creada</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data['rows'])): ?>
          <tr><td colspan="8" class="rtls-empty">No se encontraron licencias.</td></tr>
        <?php else: foreach ($data['rows'] as $lic):
          $exp = $lic['expires_at'];
          $expired = $exp && $exp < date('Y-m-d');
          $status_label = ['active'=>'Activa','inactive'=>'Inactiva','suspended'=>'Suspendida'][$lic['status']] ?? $lic['status'];
          $status_cls   = ['active'=>'ok','inactive'=>'wa','suspended'=>'er'][$lic['status']] ?? '';
        ?>
          <tr>
            <td>
              <span class="rtls-key" onclick="navigator.clipboard.writeText('<?= esc_attr($lic['license_key']) ?>');this.textContent='✓ Copiada!';setTimeout(()=>this.textContent='<?= esc_attr($lic['license_key']) ?>',1500)" title="Clic para copiar">
                <?= esc_html($lic['license_key']) ?>
              </span>
            </td>
            <td>
              <strong><?= esc_html(($lic['customer_name'] ?? '') ?: '—') ?></strong>
              <?php if (!empty($lic['customer_email'])): ?>
                <br><span class="rtls-meta"><?= esc_html($lic['customer_email']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($lic['domain'])): ?>
                <span class="rtls-domain"><?= esc_html($lic['domain']) ?></span>
              <?php else: ?>
                <span class="rtls-meta">Sin dominio</span>
              <?php endif; ?>
            </td>
            <td><span class="rtls-plan rtls-plan-<?= esc_attr($lic['plan'] ?? '') ?>"><?= esc_html(RTLS_License::plan_label($lic['plan'] ?? '')) ?></span></td>
            <td>
              <span class="rtls-badge rtls-badge-<?= $status_cls ?>">
                <?= esc_html($status_label) ?><?= ($expired && $lic['status']==='active') ? ' · Expirada' : '' ?>
              </span>
            </td>
            <td><?= $exp ? esc_html($exp) : '<span class="rtls-meta">Sin límite</span>' ?></td>
            <td><span class="rtls-meta"><?= esc_html(substr($lic['created_at'] ?? '',0,10)) ?></span></td>
            <td class="rtls-actions">
              <a href="<?= admin_url('admin.php?page=reserva-total-licenses-new&edit='.urlencode($lic['license_key'])) ?>" class="rtls-btn rtls-btn-xs rtls-btn-secondary">Editar</a>
              <!-- Toggle active/inactive -->
              <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline">
                <?php wp_nonce_field('rtls_toggle'); ?>
                <input type="hidden" name="action" value="rtls_toggle">
                <input type="hidden" name="id"     value="<?= (int)$lic['id'] ?>">
                <input type="hidden" name="status"  value="<?= esc_attr($lic['status']) ?>">
                <button type="submit" class="rtls-btn rtls-btn-xs <?= $lic['status']==='active'?'rtls-btn-warning':'rtls-btn-ghost' ?>">
                  <?= $lic['status']==='active' ? 'Desactivar' : 'Activar' ?>
                </button>
              </form>
              <a href="<?= admin_url('admin.php?page=reserva-total-licenses-log&key='.urlencode($lic['license_key'])) ?>" class="rtls-btn rtls-btn-xs rtls-btn-ghost">Log</a>
              <!-- Delete -->
              <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline" onsubmit="return confirm('¿Eliminar esta licencia? Esta acción no se puede deshacer.')">
                <?php wp_nonce_field('rtls_delete'); ?>
                <input type="hidden" name="action" value="rtls_delete">
                <input type="hidden" name="id"     value="<?= (int)$lic['id'] ?>">
                <button type="submit" class="rtls-btn rtls-btn-xs rtls-btn-danger">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($data['total'] > 25):
    $total_pages = ceil($data['total'] / 25);
    $base_url = admin_url('admin.php?page=reserva-total-licenses' . ($search?"&s=".urlencode($search):'') . ($status?"&status=".urlencode($status):''));
  ?>
    <div class="rtls-pagination">
      <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <a href="<?= $base_url ?>&paged=<?= $p ?>" class="rtls-btn rtls-btn-xs <?= $p===$page?'rtls-btn-primary':'rtls-btn-ghost' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <span class="rtls-meta"><?= $data['total'] ?> licencias en total</span>
    </div>
  <?php endif; ?>
</div>
