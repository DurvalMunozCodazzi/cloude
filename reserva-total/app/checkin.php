<?php
// Checklist de check-in digital — el huésped completa sus datos, sube una
// foto de su DNI y acepta los términos antes de llegar. Sin login, protegido
// por el checkin_token de la reserva.
require_once 'config.php';
$db = rtDB();

$token = $_GET['token'] ?? '';
$st = $db->prepare("SELECT r.*, res.name as resource_name FROM reservations r
                     JOIN resources res ON res.id = r.resource_id WHERE r.checkin_token=?");
$st->execute([$token]);
$rv = $st->fetch();

header('Content-Type: text/html; charset=utf-8');

if (!$rv) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Enlace inválido</title></head>
    <body style="font-family:Arial,sans-serif;text-align:center;padding:60px;color:#555">
    <h2>Enlace no válido o vencido</h2><p>Pedí un nuevo enlace al establecimiento.</p></body></html>';
    exit;
}

$subSt = $db->prepare("SELECT * FROM rt_checkin_submissions WHERE reservation_id=?");
$subSt->execute([$rv['id']]);
$submission = $subSt->fetch();

$ci = new DateTime($rv['check_in']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Check-in — <?= htmlspecialchars($rv['resource_name']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;background:#f4f5f9;color:#14161f;padding:20px;margin:0}
  .wrap{max-width:460px;margin:0 auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 20px rgba(0,0,0,.08)}
  h1{font-size:18px;margin:0 0 2px}
  .sub{font-size:12px;color:#767b90;margin-bottom:20px}
  label{display:block;font-size:11px;font-weight:700;color:#767b90;text-transform:uppercase;letter-spacing:.4px;margin:14px 0 5px}
  input[type=text],input[type=email],input[type=tel]{width:100%;padding:10px 12px;border:1px solid #e1e4ec;border-radius:8px;font-size:14px}
  input[type=file]{width:100%;font-size:13px;margin-top:2px}
  .chk{display:flex;align-items:flex-start;gap:8px;margin-top:16px;font-size:12px;color:#4a4f63;line-height:1.5}
  .chk input{margin-top:2px}
  button{width:100%;margin-top:20px;background:#0d9488;color:#fff;border:none;padding:12px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
  button:disabled{opacity:.6;cursor:not-allowed}
  .msg{margin-top:14px;font-size:13px;padding:10px 12px;border-radius:8px;display:none}
  .msg.err{background:#ef444422;color:#ef4444;display:block}
  .msg.ok{background:#22c55e22;color:#16a34a;display:block}
  .done{text-align:center;padding:20px 0}
  .done i{font-size:40px;color:#16a34a}
</style>
</head>
<body>
<div class="wrap">
  <h1><?= htmlspecialchars($rv['resource_name']) ?></h1>
  <div class="sub">Check-in: <?= $ci->format('d/m/Y H:i') ?> hs</div>

  <?php if ($submission && $submission['accepted_terms']): ?>
    <div class="done">
      <div style="font-size:40px;color:#16a34a">✓</div>
      <p style="margin-top:10px;font-size:14px">¡Listo, <?= htmlspecialchars($rv['guest_name']) ?>! Ya completaste el check-in digital.</p>
      <p style="font-size:12px;color:#767b90">Enviado el <?= (new DateTime($submission['submitted_at']))->format('d/m/Y H:i') ?> hs</p>
    </div>
  <?php else: ?>
    <form id="ckForm">
      <label>Nombre completo *</label>
      <input type="text" name="guest_name" value="<?= htmlspecialchars($rv['guest_name'] ?? '') ?>" required>
      <label>Email</label>
      <input type="email" name="guest_email" value="<?= htmlspecialchars($rv['guest_email'] ?? '') ?>">
      <label>Teléfono</label>
      <input type="tel" name="guest_phone" value="<?= htmlspecialchars($rv['guest_phone'] ?? '') ?>">
      <label>DNI / Pasaporte</label>
      <input type="text" name="guest_doc" value="<?= htmlspecialchars($rv['guest_doc'] ?? '') ?>">
      <label>Dirección</label>
      <input type="text" name="guest_address" value="<?= htmlspecialchars($rv['guest_address'] ?? '') ?>">
      <label>Foto del DNI (opcional)</label>
      <input type="file" name="dni_photo" accept="image/jpeg,image/png,image/webp">
      <label>Firma — escribí tu nombre completo *</label>
      <input type="text" name="signature_name" required placeholder="Tu nombre y apellido">
      <div class="chk">
        <input type="checkbox" id="acceptChk" name="accepted_terms" value="1" required>
        <label for="acceptChk" style="margin:0;text-transform:none;font-weight:400;font-size:12px">Acepto los términos y condiciones del establecimiento y confirmo que los datos cargados son correctos.</label>
      </div>
      <button type="submit" id="ckBtn">Enviar check-in</button>
      <div class="msg" id="ckMsg"></div>
    </form>
    <script>
      document.getElementById('ckForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = document.getElementById('ckBtn');
        const msg = document.getElementById('ckMsg');
        msg.className = 'msg'; msg.textContent = '';
        btn.disabled = true; btn.textContent = 'Enviando...';
        try {
          const fd = new FormData(this);
          const res = await fetch('api/checkin_public.php?action=submit&token=<?= urlencode($token) ?>', { method: 'POST', body: fd });
          const data = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(data.error || 'Error al enviar');
          location.reload();
        } catch (err) {
          msg.className = 'msg err'; msg.textContent = err.message;
          btn.disabled = false; btn.textContent = 'Enviar check-in';
        }
      });
    </script>
  <?php endif; ?>
</div>
</body>
</html>
