<?php
// caja.php — Corte de caja diario (BETANDENT)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // paciente no entra

$conn = db();

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($k,$v=null){ if($v===null){$t=$_SESSION[$k]??'';unset($_SESSION[$k]);return $t;} $_SESSION[$k]=$v; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf=$_SESSION['csrf'];

// Fecha seleccionada (YYYY-MM-DD)
$hoy = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha)) $fecha = $hoy;

$act = $_POST['act'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }

  if ($act==='guardar_corte') {
    // Solo admin y empleado pueden guardar, ya validado arriba
    $fecha_post = $_POST['fecha'] ?? $hoy;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha_post)) $fecha_post = $hoy;

    $cambio_inicial = (float)($_POST['cambio_inicial'] ?? 0);
    $obs = trim($_POST['observaciones'] ?? '');

    // Total pagado del día
    $st = $conn->prepare("SELECT COALESCE(SUM(total),0) AS tot FROM ventas WHERE estado='pagado' AND DATE(creado_en)=?");
    $st->bind_param("s",$fecha_post);
    $st->execute(); $r=$st->get_result(); $row=$r->fetch_assoc();
    $total_ingresos = (float)($row['tot'] ?? 0); $st->close();

    // ¿Ya existe corte para esa fecha?
    $st = $conn->prepare("SELECT id FROM caja_cortes WHERE fecha=? LIMIT 1");
    $st->bind_param("s",$fecha_post);
    $st->execute(); $r=$st->get_result(); $existe = (bool)$r->fetch_assoc(); $st->close();

    if ($existe) {
      // Si existe, actualiza
      $st = $conn->prepare("UPDATE caja_cortes SET cambio_inicial=?, total_ingresos=?, observaciones=? WHERE fecha=?");
      $st->bind_param("ddss",$cambio_inicial,$total_ingresos,$obs,$fecha_post);
      if ($st->execute()) { flash('ok','Corte actualizado.'); } else { flash('err','No se pudo actualizar: '.e($st->error ?: $conn->error)); }
      $st->close();
    } else {
      // Inserta
      $st = $conn->prepare("INSERT INTO caja_cortes(fecha,cambio_inicial,total_ingresos,observaciones) VALUES (?,?,?,?)");
      $st->bind_param("sdds",$fecha_post,$cambio_inicial,$total_ingresos,$obs);
      if ($st->execute()) { flash('ok','Corte guardado.'); } else { flash('err','No se pudo guardar: '.e($st->error ?: $conn->error)); }
      $st->close();
    }
    header("Location: caja.php?fecha=".$fecha_post); exit;
  }
}

// Totales del día seleccionado
$tot_pagado = $tot_efectivo = $tot_trans_pag = 0.0; $cnt_pagadas = 0;
$st = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND DATE(creado_en)=?");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result(); if($row=$r->fetch_assoc()){ $cnt_pagadas=(int)$row['c']; $tot_pagado=(float)$row['s']; } $st->close();

$st = $conn->prepare("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND metodo_pago='efectivo' AND DATE(creado_en)=?");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result(); if($row=$r->fetch_assoc()){ $tot_efectivo=(float)$row['s']; } $st->close();

$st = $conn->prepare("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND metodo_pago='transferencia' AND DATE(creado_en)=?");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result(); if($row=$r->fetch_assoc()){ $tot_trans_pag=(float)$row['s']; } $st->close();

// Pendientes (transfer) del día
$cnt_pend = 0; $pendientes = [];
$st = $conn->prepare("SELECT id,codigo,total,metodo_pago,estado,DATE_FORMAT(creado_en,'%H:%i') h FROM ventas WHERE estado='pendiente' AND DATE(creado_en)=? ORDER BY creado_en DESC");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){ $pendientes[]=$row; }
$cnt_pend = count($pendientes);
$st->close();

// Ventas pagadas lista
$ventas = [];
$st = $conn->prepare("SELECT id,codigo,total,metodo_pago,DATE_FORMAT(creado_en,'%H:%i') h FROM ventas WHERE estado='pagado' AND DATE(creado_en)=? ORDER BY creado_en ASC");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){ $ventas[]=$row; }
$st->close();

// Corte guardado (si existe)
$corte = null;
$st = $conn->prepare("SELECT cambio_inicial,total_ingresos,observaciones,DATE_FORMAT(creado_en,'%Y-%m-%d %H:%i') creado FROM caja_cortes WHERE fecha=? LIMIT 1");
$st->bind_param("s",$fecha);
$st->execute(); $r=$st->get_result(); $corte=$r->fetch_assoc(); $st->close();

$ok=flash('ok'); $err=flash('err');
?>
<!doctype html>
<meta charset="utf-8">
<title>Caja | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
  :root{
    --brand:#2563eb;
    --brand-soft:#e0ecff;
    --accent:#22c55e;
    --danger:#ef4444;
    --ink:#0f172a;
    --muted:#6b7280;
    --bg:#0f172a;
    --bg-soft:#020617;
    --card:#020617;
    --shadow:0 18px 45px rgba(15,23,42,.45);
    --radius-xl:22px;
    --radius:14px;
    --border-soft:rgba(148,163,184,.28);
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    background: radial-gradient(circle at top left,#1d4ed8 0, #020617 55%);
    color:#e5e7eb;
    min-height:100vh;
  }
  a{text-decoration:none;color:inherit}

  .shell{
    max-width:1200px;
    margin:0 auto;
    padding:18px 16px 40px;
  }

  .topbar{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:12px 18px;
    border-radius:999px;
    background:rgba(15,23,42,.8);
    backdrop-filter:blur(18px);
    border:1px solid rgba(148,163,184,.5);
    box-shadow:0 14px 40px rgba(15,23,42,.75);
    position:sticky;
    top:10px;
    z-index:20;
  }
  .topbrand{
    display:flex;
    align-items:center;
    gap:10px;
    font-weight:700;
    letter-spacing:.06em;
    text-transform:uppercase;
    font-size:.82rem;
  }
  .topbrand-badge{
    width:28px;height:28px;
    border-radius:999px;
    background:linear-gradient(135deg,#22c55e,#a3e635);
    display:flex;align-items:center;justify-content:center;
    color:#022c22;font-weight:900;font-size:.7rem;
    box-shadow:0 0 0 3px rgba(34,197,94,.18);
  }
  .top-title{
    font-size:.9rem;
    color:#cbd5f5;
  }

  .top-form{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .top-form label{
    font-size:.75rem;
    color:#9ca3af;
  }
  .top-form input[type="date"]{
    padding:6px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.6);
    background:rgba(15,23,42,.85);
    color:#e5e7eb;
    font-size:.8rem;
  }
  .top-form input[type="date"]:focus{
    outline:none;
    border-color:#60a5fa;
    box-shadow:0 0 0 1px rgba(96,165,250,.6);
  }

  .btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    border:none;
    cursor:pointer;
    border-radius:999px;
    padding:7px 16px;
    font-size:.8rem;
    font-weight:600;
    letter-spacing:.03em;
    text-transform:uppercase;
    transition:.18s ease-in-out;
    white-space:nowrap;
  }
  .btn-primary{
    background:linear-gradient(135deg,#3b82f6,#6366f1);
    color:white;
    box-shadow:0 12px 30px rgba(59,130,246,.45);
  }
  .btn-primary:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 40px rgba(59,130,246,.6);
  }
  .btn-ghost{
    background:transparent;
    color:#cbd5f5;
    border:1px solid rgba(148,163,184,.65);
  }
  .btn-ghost:hover{
    border-color:#e5e7eb;
    background:rgba(15,23,42,.9);
  }

  .page-head{
    margin-top:22px;
    margin-bottom:16px;
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-end;
  }
  .page-title{
    font-size:1.4rem;
    font-weight:700;
    letter-spacing:.03em;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .pill-date{
    padding:4px 12px;
    border-radius:999px;
    background:rgba(15,23,42,.8);
    border:1px solid rgba(148,163,184,.6);
    font-size:.78rem;
    color:#cbd5f5;
  }
  .page-sub{
    font-size:.8rem;
    color:#9ca3af;
    margin-top:4px;
  }

  .alerts{
    margin:4px 0 10px;
  }
  .alert{
    padding:10px 14px;
    border-radius:999px;
    font-size:.78rem;
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:6px;
    border:1px solid transparent;
  }
  .alert-icon{
    width:18px;height:18px;border-radius:999px;
    display:flex;align-items:center;justify-content:center;
    font-size:.7rem;
  }
  .alert.ok{
    background:rgba(22,163,74,.12);
    border-color:rgba(34,197,94,.6);
    color:#bbf7d0;
  }
  .alert.ok .alert-icon{
    background:rgba(34,197,94,.3);
  }
  .alert.err{
    background:rgba(220,38,38,.12);
    border-color:rgba(248,113,113,.7);
    color:#fecaca;
  }
  .alert.err .alert-icon{
    background:rgba(239,68,68,.4);
  }

  .grid-kpis{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    margin-top:10px;
  }
  @media (max-width:960px){
    .grid-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}
  }
  @media (max-width:640px){
    .grid-kpis{grid-template-columns:1fr;}
  }

  .kpi{
    position:relative;
    padding:14px 14px 12px;
    border-radius:18px;
    background:radial-gradient(circle at top left,rgba(56,189,248,.18) 0,rgba(15,23,42,.96) 52%);
    border:1px solid rgba(148,163,184,.55);
    box-shadow:0 14px 40px rgba(15,23,42,.9);
    overflow:hidden;
  }
  .kpi-label{
    font-size:.78rem;
    color:#9ca3af;
    letter-spacing:.05em;
    text-transform:uppercase;
    margin-bottom:4px;
  }
  .kpi-value{
    font-size:1.35rem;
    font-weight:700;
  }
  .kpi-tag{
    position:absolute;
    right:12px;
    top:10px;
    font-size:.7rem;
    padding:3px 9px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.7);
    color:#cbd5f5;
    background:rgba(15,23,42,.9);
  }

  .layout{
    margin-top:18px;
    display:grid;
    grid-template-columns:1.4fr .9fr;
    gap:16px;
  }
  @media (max-width:1040px){
    .layout{grid-template-columns:1fr;}
  }

  .card{
    background:radial-gradient(circle at top left,rgba(59,130,246,.18) 0,rgba(15,23,42,.98) 55%);
    border-radius:var(--radius-xl);
    padding:16px 16px 14px;
    border:1px solid var(--border-soft);
    box-shadow:var(--shadow);
  }
  .card-header{
    display:flex;
    justify-content:space-between;
    gap:6px;
    align-items:flex-start;
    margin-bottom:10px;
  }
  .card-title{
    font-size:1rem;
    font-weight:600;
  }
  .card-sub{
    font-size:.78rem;
    color:#9ca3af;
    margin-top:2px;
  }

  .badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:3px 8px;
    border-radius:999px;
    font-size:.72rem;
    font-weight:500;
    text-transform:uppercase;
    letter-spacing:.05em;
  }
  .badge-soft{
    background:rgba(15,23,42,.9);
    border:1px solid rgba(148,163,184,.65);
    color:#e5e7eb;
  }
  .badge-efectivo{
    background:rgba(34,197,94,.12);
    border:1px solid rgba(34,197,94,.7);
    color:#bbf7d0;
  }
  .badge-trans{
    background:rgba(59,130,246,.12);
    border:1px solid rgba(59,130,246,.7);
    color:#bfdbfe;
  }
  .badge-pend{
    background:rgba(234,179,8,.12);
    border:1px solid rgba(234,179,8,.7);
    color:#fef3c7;
  }

  table{
    width:100%;
    border-collapse:collapse;
    margin-top:8px;
    font-size:.8rem;
  }
  thead{
    background:rgba(15,23,42,.85);
  }
  th,td{
    padding:8px 10px;
    text-align:left;
    border-bottom:1px solid rgba(31,41,55,.9);
  }
  th{
    font-size:.75rem;
    font-weight:600;
    color:#9ca3af;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  tbody tr:nth-child(even){
    background:rgba(15,23,42,.75);
  }
  tbody tr:nth-child(odd){
    background:rgba(15,23,42,.6);
  }
  tbody tr:hover{
    background:rgba(59,130,246,.25);
  }
  td:last-child{
    font-variant-numeric:tabular-nums;
  }

  .muted{
    color:#9ca3af;
    font-size:.8rem;
  }
  .muted-center{
    text-align:center;
    margin:10px 0;
  }

  form label{
    display:block;
    margin-top:10px;
    margin-bottom:2px;
    font-size:.78rem;
    color:#e5e7eb;
    font-weight:500;
  }
  input[type="number"],
  input[readonly],
  textarea{
    width:100%;
    padding:9px 11px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.7);
    background:rgba(15,23,42,.9);
    color:#e5e7eb;
    font-size:.85rem;
  }
  input[type="number"]:focus,
  textarea:focus{
    outline:none;
    border-color:#60a5fa;
    box-shadow:0 0 0 1px rgba(96,165,250,.7);
  }
  textarea{
    resize:vertical;
    min-height:80px;
  }

  .form-footer{
    margin-top:12px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  .small-meta{
    font-size:.72rem;
    color:#9ca3af;
    margin-top:6px;
  }
</style>

<div class="shell">

  <header class="topbar">
    <div class="topbrand">
      <div class="topbrand-badge">BD</div>
      <div>
        <div>BETANDENT</div>
        <div class="top-title">Panel de caja</div>
      </div>
    </div>

    <form method="get" class="top-form">
      <label for="fecha">Fecha</label>
      <input type="date" id="fecha" name="fecha" value="<?= e($fecha) ?>">
      <button class="btn btn-primary" type="submit">
        <span>Ver corte</span>
      </button>
    </form>
  </header>

  <div class="page-head">
    <div>
      <div class="page-title">
        Corte de caja
        <span class="pill-date"><?= e($fecha) ?></span>
      </div>
      <p class="page-sub">
        Resumen diario de ventas, métodos de pago y control de efectivo en BETANDENT.
      </p>
    </div>
  </div>

  <div class="alerts">
    <?php if($ok): ?>
      <div class="alert ok">
        <div class="alert-icon">✓</div>
        <div><?= e($ok) ?></div>
      </div>
    <?php endif; ?>
    <?php if($err): ?>
      <div class="alert err">
        <div class="alert-icon">!</div>
        <div><?= e($err) ?></div>
      </div>
    <?php endif; ?>
  </div>

  <section class="grid-kpis">
    <article class="kpi">
      <div class="kpi-label">Ventas pagadas</div>
      <div class="kpi-value"><?= (int)$cnt_pagadas ?></div>
      <div class="kpi-tag">Tickets cerrados</div>
    </article>
    <article class="kpi">
      <div class="kpi-label">Total pagado</div>
      <div class="kpi-value">$<?= number_format($tot_pagado,2) ?></div>
      <div class="kpi-tag">Ingresos del día</div>
    </article>
    <article class="kpi">
      <div class="kpi-label">Efectivo</div>
      <div class="kpi-value">$<?= number_format($tot_efectivo,2) ?></div>
      <div class="kpi-tag">En caja</div>
    </article>
    <article class="kpi">
      <div class="kpi-label">Transferencias pagadas</div>
      <div class="kpi-value">$<?= number_format($tot_trans_pag,2) ?></div>
      <div class="kpi-tag">Bancos</div>
    </article>
  </section>

  <section class="layout">
    <!-- Izquierda: detalle de ventas -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Detalle de ventas</h2>
          <p class="card-sub">Listado de tickets pagados y transferencias pendientes.</p>
        </div>
        <span class="badge badge-soft">
          <?= (int)$cnt_pagadas ?> pagadas
          <?php if($cnt_pend): ?> · <?= (int)$cnt_pend ?> pendientes<?php endif; ?>
        </span>
      </div>

      <h3 class="card-sub" style="text-transform:uppercase;letter-spacing:.15em;margin-top:0;">Pagadas</h3>

      <?php if(empty($ventas)): ?>
        <p class="muted muted-center">Sin ventas pagadas en esta fecha.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Hora</th>
              <th>Código</th>
              <th>Método</th>
              <th style="text-align:right;">Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($ventas as $v): ?>
            <tr>
              <td><?= e($v['h']) ?></td>
              <td><?= e($v['codigo']) ?></td>
              <td>
                <?php
                  $mp = $v['metodo_pago'];
                  $clase = $mp === 'efectivo' ? 'badge-efectivo' : 'badge-trans';
                ?>
                <span class="badge <?= $clase ?>">
                  <?= e($mp) ?>
                </span>
              </td>
              <td style="text-align:right;">$<?= number_format((float)$v['total'],2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <h3 class="card-sub" style="text-transform:uppercase;letter-spacing:.15em;margin-top:14px;">Pendientes de transferencia</h3>

      <?php if(empty($pendientes)): ?>
        <p class="muted muted-center">Sin pendientes para esta fecha.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Hora</th>
              <th>Código</th>
              <th style="text-align:right;">Total</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($pendientes as $p): ?>
            <tr>
              <td><?= e($p['h']) ?></td>
              <td><?= e($p['codigo']) ?></td>
              <td style="text-align:right;">$<?= number_format((float)$p['total'],2) ?></td>
              <td>
                <span class="badge badge-pend">
                  <?= e($p['estado']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </article>

    <!-- Derecha: corte de caja -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Corte de caja</h2>
          <p class="card-sub">Registra el cambio inicial y notas del día.</p>
        </div>
        <span class="badge badge-soft">
          <?= $corte ? 'Corte registrado' : 'Sin corte' ?>
        </span>
      </div>

      <?php if($corte): ?>
        <p class="small-meta">
          Corte guardado para <strong><?= e($fecha) ?></strong><br>
          Última actualización: <?= e($corte['creado']) ?>
        </p>
      <?php else: ?>
        <p class="small-meta">
          No hay corte guardado para esta fecha. Puedes capturarlo ahora.
        </p>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="guardar_corte">
        <input type="hidden" name="fecha" value="<?= e($fecha) ?>">

        <label for="cambio_inicial">Cambio inicial</label>
        <input
          id="cambio_inicial"
          type="number"
          name="cambio_inicial"
          step="0.01"
          min="0"
          value="<?= $corte? e($corte['cambio_inicial']) : '0.00' ?>"
        >

        <label for="total_dia">Total ingresos del día</label>
        <input
          id="total_dia"
          type="text"
          value="$<?= number_format($tot_pagado,2) ?>"
          readonly
        >

        <label for="obs">Observaciones</label>
        <textarea id="obs" name="observaciones" rows="3"><?= $corte? e($corte['observaciones']) : '' ?></textarea>

        <div class="form-footer">
          <button class="btn btn-primary" type="submit">Guardar corte</button>
          <a href="panel.php" class="btn btn-ghost">Volver al panel</a>
        </div>
      </form>
    </article>
  </section>
</div>
