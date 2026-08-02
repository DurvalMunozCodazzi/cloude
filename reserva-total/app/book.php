<?php
// ── Motor de reserva online ──────────────────────────────────────────────────
// Página pública: el huésped elige alojamiento y fechas, ve el precio real
// (tarifas de temporada + descuentos), deja sus datos y paga la seña con
// Mercado Pago. La reserva entra sola al calendario.
$status = $_GET['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reservá online</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:Arial,Helvetica,sans-serif;background:#f4f5f9;color:#14161f;padding:20px}
  .wrap{max-width:520px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 20px rgba(0,0,0,.08);margin-bottom:16px}
  h1{font-size:20px;margin-bottom:2px}
  .sub{font-size:12px;color:#767b90;margin-bottom:20px}
  label{display:block;font-size:11px;font-weight:700;color:#767b90;text-transform:uppercase;letter-spacing:.4px;margin:14px 0 5px}
  input,select{width:100%;padding:10px 12px;border:1px solid #e1e4ec;border-radius:8px;font-size:14px;background:#fff}
  .row{display:flex;gap:10px}
  .row > div{flex:1}
  .res-card{border:2px solid #e1e4ec;border-radius:10px;padding:12px;margin-bottom:8px;cursor:pointer;transition:.15s}
  .res-card:hover{border-color:#0d948880}
  .res-card.sel{border-color:#0d9488;background:#0d948810}
  .res-head{display:flex;align-items:center;gap:12px}
  .res-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0}
  .res-nm{font-weight:700;font-size:14px}
  .res-pr{font-size:12px;color:#767b90}
  .res-desc{font-size:12px;color:#4a4f63;margin-top:8px;line-height:1.5}
  .res-photos{display:flex;gap:6px;overflow-x:auto;margin-top:10px;padding-bottom:4px}
  .res-photos img{width:120px;height:85px;object-fit:cover;border-radius:8px;flex-shrink:0;border:1px solid #e1e4ec}
  .lightbox{position:fixed;inset:0;background:rgba(0,0,0,.85);display:none;align-items:center;justify-content:center;z-index:99;flex-direction:column;padding:20px}
  .lightbox.on{display:flex}
  .lightbox img{max-width:95vw;max-height:80vh;border-radius:10px}
  .lightbox .cap{color:#fff;font-size:13px;margin-top:10px}
  .lightbox .close{position:absolute;top:14px;right:18px;color:#fff;font-size:28px;cursor:pointer;background:none;border:none;width:auto;margin:0;padding:4px 10px}
  .quote{background:#f8fffe;border:1px solid #0d948840;border-radius:10px;padding:14px;margin-top:16px;display:none}
  .quote .line{display:flex;justify-content:space-between;font-size:13px;color:#4a4f63;margin-bottom:6px}
  .quote .total{display:flex;justify-content:space-between;font-size:16px;font-weight:800;color:#0d9488;border-top:1px solid #0d948840;padding-top:8px;margin-top:4px}
  .quote .dep{display:flex;justify-content:space-between;font-size:13px;font-weight:700;color:#14161f;margin-top:6px}
  .noavail{background:#ef444422;color:#ef4444;border-radius:10px;padding:12px;margin-top:16px;font-size:13px;font-weight:700;text-align:center;display:none}
  button{width:100%;margin-top:20px;background:#0d9488;color:#fff;border:none;padding:13px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer}
  button:disabled{opacity:.5;cursor:not-allowed}
  .msg{margin-top:14px;font-size:13px;padding:12px;border-radius:8px;display:none}
  .msg.err{background:#ef444422;color:#ef4444;display:block}
  .banner{border-radius:10px;padding:16px;margin-bottom:16px;font-size:14px;font-weight:600;text-align:center}
  .banner.ok{background:#22c55e22;color:#16a34a}
  .banner.warn{background:#f59e0b22;color:#b45309}
  .banner.bad{background:#ef444422;color:#ef4444}
  .foot{text-align:center;font-size:11px;color:#999;margin-top:8px}
</style>
</head>
<body>
<div class="wrap">
  <?php if ($status === 'success'): ?>
    <div class="banner ok">✅ ¡Pago recibido! Tu reserva quedó confirmada. Te enviamos los detalles por email.</div>
  <?php elseif ($status === 'pending'): ?>
    <div class="banner warn">⏳ Tu pago está en proceso. Apenas se acredite, la reserva queda confirmada — te avisamos por email.</div>
  <?php elseif ($status === 'failure'): ?>
    <div class="banner bad">❌ El pago no se completó. Podés intentar de nuevo eligiendo tus fechas.</div>
  <?php elseif ($status === 'requested'): ?>
    <div class="banner ok">✅ ¡Recibimos tu solicitud de reserva! Te contactaremos a la brevedad para coordinar el pago de la seña.</div>
  <?php endif; ?>

  <div class="card">
    <h1>Reservá online</h1>
    <div class="sub">Elegí tu alojamiento, tus fechas y confirmá con la seña</div>

    <label>Alojamiento</label>
    <div id="resList"><div style="font-size:12px;color:#767b90;padding:8px 0">Cargando...</div></div>

    <div class="row">
      <div><label>Entrada</label><input type="date" id="bkIn"></div>
      <div><label>Salida</label><input type="date" id="bkOut"></div>
    </div>
    <div class="row">
      <div><label>Huéspedes</label><input type="number" id="bkAdults" min="1" value="2"></div>
      <div></div>
    </div>

    <div class="noavail" id="bkNoAvail">😔 No hay disponibilidad para esas fechas — probá con otras</div>

    <div class="quote" id="bkQuote">
      <div class="line"><span id="qNights"></span><span id="qBase"></span></div>
      <div class="line" id="qDiscRow" style="display:none;color:#16a34a"><span id="qDiscLbl"></span><span id="qDiscAmt"></span></div>
      <div class="total"><span>Total estadía</span><span id="qTotal"></span></div>
      <div class="dep" id="qDepRow"><span id="qDepLbl"></span><span id="qDep"></span></div>
    </div>

    <label>Nombre completo *</label>
    <input type="text" id="bkName" placeholder="Tu nombre y apellido">
    <div class="row">
      <div><label>Email</label><input type="email" id="bkEmail" placeholder="tu@email.com"></div>
      <div><label>Teléfono / WhatsApp</label><input type="tel" id="bkPhone" placeholder="11 5555 1234"></div>
    </div>
    <label>DNI / Pasaporte *</label>
    <input type="text" id="bkDoc" placeholder="Requerido por ley para el registro de huéspedes">

    <button id="bkBtn" onclick="submitBooking()" disabled>Elegí alojamiento y fechas</button>
    <div class="msg" id="bkMsg"></div>
  </div>
  <div class="foot">Reserva Total · reservas seguras con Mercado Pago</div>
</div>

<div class="lightbox" id="lightbox" onclick="closeLightbox(event)">
  <button class="close" onclick="closeLightbox()">&times;</button>
  <img id="lbImg" src="" alt="">
  <div class="cap" id="lbCap"></div>
</div>

<script>
const API = 'api/booking_public.php';
let RESOURCES = [], SELECTED = null, QUOTE = null, MP_ENABLED = false, DEPOSIT_PCT = 30;

async function jget(url) {
  const r = await fetch(url);
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.error || 'Error ' + r.status);
  return d;
}

(async function init() {
  try {
    const d = await jget(API + '?action=resources');
    RESOURCES = d.resources || [];
    MP_ENABLED = !!d.mp_enabled;
    DEPOSIT_PCT = d.deposit_pct || 30;
    const el = document.getElementById('resList');
    el.innerHTML = RESOURCES.length ? '' : '<div style="font-size:12px;color:#767b90">No hay alojamientos disponibles</div>';
    RESOURCES.forEach(r => {
      const div = document.createElement('div');
      div.className = 'res-card';
      div.id = 'res' + r.id;
      const photos = r.photos || [];
      div.innerHTML = `
        <div class="res-head">
          <div class="res-dot" style="background:${r.color||'#0d9488'}"></div>
          <div style="flex:1">
            <div class="res-nm">${esc(r.name)}</div>
            <div class="res-pr">Hasta ${r.capacity} persona(s) · desde $${fmt(r.price_per_day)}/noche</div>
          </div>
        </div>
        ${r.description ? `<div class="res-desc">${esc(r.description)}</div>` : ''}
        ${photos.length ? `<div class="res-photos">` + photos.map(p =>
          `<img src="uploads/${encodeURIComponent(p.filename)}" alt="${esc(p.caption||r.name)}" loading="lazy"
                onclick="openLightbox(event, 'uploads/${encodeURIComponent(p.filename)}', '${esc(p.caption||'')}')">`
        ).join('') + `</div>` : ''}`;
      div.onclick = () => { SELECTED = r.id;
        document.querySelectorAll('.res-card').forEach(c => c.classList.remove('sel'));
        div.classList.add('sel'); refreshQuote(); };
      el.appendChild(div);
    });
  } catch(e) {
    document.getElementById('resList').innerHTML = '<div style="color:#ef4444;font-size:12px">' + esc(e.message) + '</div>';
  }
  const today = new Date().toISOString().slice(0,10);
  document.getElementById('bkIn').min = today;
  document.getElementById('bkOut').min = today;
  document.getElementById('bkIn').onchange = refreshQuote;
  document.getElementById('bkOut').onchange = refreshQuote;
})();

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

function openLightbox(ev, src, caption) {
  ev.stopPropagation(); // no seleccionar el alojamiento al ampliar una foto
  document.getElementById('lbImg').src = src;
  document.getElementById('lbCap').textContent = caption || '';
  document.getElementById('lightbox').classList.add('on');
}
function closeLightbox(ev) {
  if (ev && ev.target && ev.target.id === 'lbImg') return; // clic sobre la foto no cierra
  document.getElementById('lightbox').classList.remove('on');
}
function fmt(n){ n = parseFloat(n||0); return n.toLocaleString('es-AR', {minimumFractionDigits:0, maximumFractionDigits:2}); }
function dates() {
  const i = document.getElementById('bkIn').value, o = document.getElementById('bkOut').value;
  if (!i || !o || o <= i) return null;
  return { ci: i + ' 14:00:00', co: o + ' 12:00:00' };
}

async function refreshQuote() {
  QUOTE = null;
  document.getElementById('bkQuote').style.display = 'none';
  document.getElementById('bkNoAvail').style.display = 'none';
  const btn = document.getElementById('bkBtn');
  btn.disabled = true; btn.textContent = 'Elegí alojamiento y fechas';
  const d = dates();
  if (!SELECTED || !d) return;
  try {
    const q = await jget(`${API}?action=quote&resource_id=${SELECTED}&check_in=${encodeURIComponent(d.ci)}&check_out=${encodeURIComponent(d.co)}`);
    if (!q.available) { document.getElementById('bkNoAvail').style.display = ''; return; }
    QUOTE = q;
    document.getElementById('qNights').textContent = q.nights + ' noche(s)';
    document.getElementById('qBase').textContent = '$' + fmt(q.base_total);
    const dr = document.getElementById('qDiscRow');
    if (q.discount_pct > 0) {
      dr.style.display = 'flex';
      document.getElementById('qDiscLbl').textContent = 'Descuento estadía larga (' + q.discount_pct + '%)';
      document.getElementById('qDiscAmt').textContent = '−$' + fmt(q.base_total - q.total);
    } else dr.style.display = 'none';
    document.getElementById('qTotal').textContent = '$' + fmt(q.total);
    if (q.mp_enabled) {
      document.getElementById('qDepRow').style.display = 'flex';
      document.getElementById('qDepLbl').textContent = 'Seña para confirmar (' + q.deposit_pct + '%)';
      document.getElementById('qDep').textContent = '$' + fmt(q.deposit);
      btn.textContent = 'Reservar y pagar seña de $' + fmt(q.deposit);
    } else {
      document.getElementById('qDepRow').style.display = 'none';
      btn.textContent = 'Solicitar reserva';
    }
    document.getElementById('bkQuote').style.display = '';
    btn.disabled = false;
  } catch(e) {
    document.getElementById('bkMsg').className = 'msg err';
    document.getElementById('bkMsg').textContent = e.message;
  }
}

async function submitBooking() {
  const msg = document.getElementById('bkMsg');
  msg.className = 'msg'; msg.textContent = '';
  const d = dates();
  if (!SELECTED || !d || !QUOTE) return;
  const name = document.getElementById('bkName').value.trim();
  const email = document.getElementById('bkEmail').value.trim();
  const phone = document.getElementById('bkPhone').value.trim();
  if (!name) { msg.className = 'msg err'; msg.textContent = 'Ingresá tu nombre completo'; return; }
  if (!document.getElementById('bkDoc').value.trim()) { msg.className = 'msg err'; msg.textContent = 'Ingresá tu DNI o pasaporte — es obligatorio para el registro de huéspedes'; return; }
  if (!email && !phone) { msg.className = 'msg err'; msg.textContent = 'Dejanos un email o teléfono para contactarte'; return; }

  const btn = document.getElementById('bkBtn');
  btn.disabled = true; btn.textContent = 'Procesando...';
  try {
    const r = await fetch(API + '?action=create', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        resource_id: SELECTED, check_in: d.ci, check_out: d.co,
        guest_name: name, guest_email: email, guest_phone: phone,
        guest_doc: document.getElementById('bkDoc').value.trim(),
        adults: parseInt(document.getElementById('bkAdults').value) || 1,
      }),
    });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || 'Error ' + r.status);
    if (data.payment === 'mp' && data.init_point) {
      window.location.href = data.init_point; // → Checkout de Mercado Pago
    } else {
      window.location.href = 'book.php?status=requested';
    }
  } catch(e) {
    msg.className = 'msg err'; msg.textContent = e.message;
    btn.disabled = false; refreshQuote();
  }
}
</script>
</body>
</html>
