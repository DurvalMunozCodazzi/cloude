<?php defined('ABSPATH') || exit;
$is_edit = !empty($editing);
$action  = $is_edit ? 'rtls_update' : 'rtls_create';
$nonce   = $is_edit ? 'rtls_update' : 'rtls_create';
$plan    = $is_edit ? $editing['plan'] : 'starter';
?>
<div class="wrap rtls-wrap">
  <h1 class="rtls-title">
    <span class="rtls-logo">🔑</span>
    <?= $is_edit ? 'Editar Licencia' : 'Nueva Licencia' ?>
    <a href="<?= admin_url('admin.php?page=reserva-total-licenses') ?>" class="rtls-btn rtls-btn-ghost" style="float:right">← Volver</a>
  </h1>

  <?php if ($is_edit): ?>
    <div class="rtls-notice rtls-notice-info">
      Clave: <strong><?= esc_html($editing['license_key']) ?></strong>
      &nbsp;
      <button onclick="navigator.clipboard.writeText('<?= esc_attr($editing['license_key']) ?>');this.textContent='✓ Copiada!';setTimeout(()=>this.textContent='Copiar clave',1500)" class="rtls-btn rtls-btn-xs rtls-btn-secondary">Copiar clave</button>
    </div>
  <?php endif; ?>

  <div class="rtls-form-wrap">
    <form method="post" action="<?= admin_url('admin-post.php') ?>" class="rtls-form">
      <?php wp_nonce_field($nonce); ?>
      <input type="hidden" name="action" value="<?= esc_attr($action) ?>">
      <?php if ($is_edit): ?>
        <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>

      <div class="rtls-form-grid">

        <div class="rtls-field">
          <label class="rtls-label">Nombre del cliente <span class="rtls-req">*</span></label>
          <input type="text" name="customer_name" value="<?= esc_attr($editing['customer_name'] ?? '') ?>" class="rtls-input" required placeholder="Ej: Juan García">
        </div>

        <div class="rtls-field">
          <label class="rtls-label">Email del cliente</label>
          <input type="email" name="customer_email" value="<?= esc_attr($editing['customer_email'] ?? '') ?>" class="rtls-input" placeholder="cliente@email.com">
        </div>

        <div class="rtls-field rtls-field-full">
          <label class="rtls-label">Dominio autorizado <span class="rtls-req">*</span></label>
          <input type="text" name="domain" value="<?= esc_attr($editing['domain'] ?? '') ?>" class="rtls-input" required
                 placeholder="ej: miempresa.com (sin https:// ni www)">
          <span class="rtls-hint">El plugin verificará que se instale <strong>solo</strong> en este dominio. Ingresá solo el dominio raíz.</span>
        </div>

        <div class="rtls-field">
          <label class="rtls-label">Plan</label>
          <select name="plan" class="rtls-input rtls-sel" id="rtls-plan-sel" onchange="rtlsUpdatePlan(this.value)">
            <?php foreach (RTLS_License::PLANS as $pk => $pv): ?>
              <option value="<?= esc_attr($pk) ?>" <?= selected($plan, $pk, false) ?>>
                <?= esc_html($pv['label']) ?> — <?= $pv['max_resources'] == 999 ? 'Ilimitados' : $pv['max_resources'] ?> recurso<?= $pv['max_resources']!=1?'s':'' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($is_edit): ?>
        <div class="rtls-field">
          <label class="rtls-label">Estado</label>
          <select name="status" class="rtls-input rtls-sel">
            <option value="active"    <?= selected($editing['status'],'active',false) ?>>Activa</option>
            <option value="inactive"  <?= selected($editing['status'],'inactive',false) ?>>Inactiva</option>
            <option value="suspended" <?= selected($editing['status'],'suspended',false) ?>>Suspendida</option>
          </select>
        </div>
        <?php endif; ?>

        <div class="rtls-field">
          <label class="rtls-label">Fecha de vencimiento</label>
          <input type="date" name="expires_at" value="<?= esc_attr($editing['expires_at'] ?? '') ?>" class="rtls-input">
          <span class="rtls-hint">Dejá vacío para licencia sin vencimiento.</span>
        </div>

        <div class="rtls-field rtls-field-full">
          <label class="rtls-label">Notas internas</label>
          <textarea name="notes" class="rtls-input" rows="3" placeholder="Notas sobre esta licencia, pedido, condiciones, etc."><?= esc_textarea($editing['notes'] ?? '') ?></textarea>
        </div>

      </div>

      <!-- Plan summary card -->
      <div class="rtls-plan-card" id="rtls-plan-card">
        <?php $caps = RTLS_License::PLANS[$plan]; ?>
        <div class="rtls-plan-card-title" id="rtls-pc-title"><?= esc_html(RTLS_License::plan_label($plan)) ?></div>
        <div class="rtls-plan-card-row"><span>Recursos (habitaciones, vehículos, etc.)</span><strong id="rtls-pc-res"><?= $caps['max_resources'] == 999 ? 'Ilimitados' : $caps['max_resources'] ?></strong></div>
      </div>

      <div class="rtls-form-footer">
        <button type="submit" class="rtls-btn rtls-btn-primary rtls-btn-lg">
          <?= $is_edit ? '💾 Guardar cambios' : '🔑 Crear licencia' ?>
        </button>
        <a href="<?= admin_url('admin.php?page=reserva-total-licenses') ?>" class="rtls-btn rtls-btn-ghost">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<script>
const rtlsPlans = <?= json_encode(RTLS_License::PLANS) ?>;
function rtlsUpdatePlan(plan) {
  const p = rtlsPlans[plan] || rtlsPlans['starter'];
  document.getElementById('rtls-pc-title').textContent = p.label;
  document.getElementById('rtls-pc-res').textContent   = p.max_resources >= 999 ? 'Ilimitados' : p.max_resources;
}
</script>
