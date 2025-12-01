<?php
// citas.php — Agenda semanal BETANDENT (admin/empleado)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // pacientes no entran aquí

$conn = db();

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($k,$v=null){ if($v===null){$t=$_SESSION[$k]??'';unset($_SESSION[$k]);return $t;} $_SESSION[$k]=$v; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ===========================
   Parámetros de agenda
   =========================== */
$slotMin   = 60;        // duración del slot en minutos
$hInicio   = 8;         // 08:00
$hFin      = 18;        // 18:00 (último inicio es 17:00 si slot=60)
$incluyeDomingo = false;

// Feriados editables (YYYY-MM-DD). Pon los tuyos reales.
$feriados = [
  date('Y').'-01-01', // Año Nuevo
  date('Y').'-02-05', // Const. MX (variable real, aquí fijo)
  date('Y').'-05-01', // Trabajo
  date('Y').'-09-16', // Independencia
  date('Y').'-11-20', // Revolución
  date('Y').'-12-25', // Navidad
];

// Día base (cualquier fecha de la semana a mostrar)
$hoy = date('Y-m-d');
$dia = $_GET['dia'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dia)) $dia = $hoy;

// Lunes de la semana (o domingo si decides)
$time = strtotime($dia);
$w = (int)date('N', $time); // 1=Lunes ... 7=Domingo
$offset = $w-1; // mover al lunes
$iniSemana = date('Y-m-d', strtotime("-$offset day", $time));
$finSemana = date('Y-m-d', strtotime("+6 day", strtotime($iniSemana))); // mostrable, aunque ocultamos domingo

// Rango para consultas [ini 00:00, fin+1 00:00)
$iniDT = $iniSemana.' 00:00:00';
$finDT = date('Y-m-d', strtotime($finSemana.' +1 day')).' 00:00:00';

/* ===========================
   Acciones (POST)
   =========================== */
$act = $_POST['act'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF inválido'); }

  // Alta manual de cita (para pacientes web)
  if ($act==='crear') {
    $paciente_id = (int)($_POST['paciente_id'] ?? 0);
    $servicio_id = (int)($_POST['servicio_id'] ?? 0);
    $fecha = $_POST['fecha'] ?? '';
    $hora  = $_POST['hora'] ?? '';
    $notas = trim($_POST['notas'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || !preg_match('/^\d{2}:\d{2}$/',$hora)) {
      flash('err','Fecha u hora inválidas.');
      header("Location: citas.php?dia=".urlencode($dia)); exit;
    }

    // Validaciones de calendario
    $dow = (int)date('N', strtotime($fecha)); // 1..7
    if ($dow===7 && !$incluyeDomingo) { flash('err','Domingo no laborable.'); header("Location: citas.php?dia=".urlencode($dia)); exit; }
    if ($dow===7 || $dow===0) { /* por si tu server es loco */ }
    if (in_array($fecha, $feriados, true)) { flash('err','Feriado no laborable.'); header("Location: citas.php?dia=".urlencode($dia)); exit; }

    [$hh,$mm] = explode(':',$hora);
    if ((int)$hh < $hInicio || (int)$hh >= $hFin) { flash('err','Fuera de horario (8:00 a 18:00).'); header("Location: citas.php?dia=".urlencode($dia)); exit; }
    if (!in_array($mm, ['00','30']) && $slotMin===30) { flash('err','Minutos deben ser 00 o 30.'); header("Location: citas.php?dia=".urlencode($dia)); exit; }

    // Verificar paciente web existe y es rol paciente
    $st = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol='paciente' AND activo=1");
    $st->bind_param("i",$paciente_id); $st->execute(); $r=$st->get_result();
    $okPac = (bool)$r->fetch_assoc(); $st->close();
    if (!$okPac) { flash('err','Paciente web inválido.'); header("Location: citas.php?dia=".urlencode($dia)); exit; }

    // Verificar servicio si viene
    if ($servicio_id>0) {
      $st = $conn->prepare("SELECT id FROM servicios WHERE id=? AND activo=1");
      $st->bind_param("i",$servicio_id); $st->execute(); $r=$st->get_result();
      $okServ = (bool)$r->fetch_assoc(); $st->close();
      if (!$okServ) { flash('err','Servicio inválido.'); header("Location: citas.php?dia=".urlencode($dia)); exit; }
    } else {
      $servicio_id = null;
    }

    // Intentar insertar
    $estado = 'confirmada'; // creado desde clínica
    $st = $conn->prepare("INSERT INTO citas(paciente_id, servicio_id, fecha, hora, estado, notas) VALUES (?,?,?,?,?,?)");
    if ($servicio_id===null) {
      $null = null;
      $st->bind_param("iissss", $paciente_id, $null, $fecha, $hora, $estado, $notas);
    } else {
      $st->bind_param("iissss", $paciente_id, $servicio_id, $fecha, $hora, $estado, $notas);
    }
    if ($st->execute()) {
      flash('ok','Cita creada y confirmada.');
    } else {
      $err = $st->error ?: $conn->error;
      if (stripos($err,'Duplicate')!==false) {
        flash('err','Ya hay una cita en ese horario.');
      } else {
        flash('err','No se pudo crear la cita: '.e($err));
      }
    }
    $st->close();
    header("Location: citas.php?dia=".urlencode($dia)); exit;
  }

  // Cambiar estado (confirmar, cancelar, atendida)
  if ($act==='estado') {
    $id = (int)($_POST['id'] ?? 0);
    $nuevo = $_POST['nuevo'] ?? '';
    if (!in_array($nuevo, ['pendiente','confirmada','cancelada','atendida'], true)) {
      flash('err','Estado inválido.'); header("Location: citas.php?dia=".urlencode($dia)); exit;
    }
    $st = $conn->prepare("UPDATE citas SET estado=? WHERE id=?");
    $st->bind_param("si",$nuevo,$id);
    if ($st->execute()) { flash('ok','Cita actualizada.'); } else { flash('err','No se pudo actualizar: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: citas.php?dia=".urlencode($dia)); exit;
  }

  // Eliminar cita
  if ($act==='eliminar') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $conn->prepare("DELETE FROM citas WHERE id=?");
    $st->bind_param("i",$id);
    if ($st->execute()) { flash('ok','Cita eliminada.'); } else { flash('err','No se pudo eliminar: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: citas.php?dia=".urlencode($dia)); exit;
  }
}

/* ===========================
   Datos para pintar la semana
   =========================== */

// Armamos slots
$slots = [];
for ($h=$hInicio; $h < $hFin; $h++) {
  $slots[] = sprintf('%02d:00', $h);
  if ($slotMin===30) $slots[] = sprintf('%02d:30', $h);
}

// Días de la semana L..S (+D si lo quisieras)
$dias = [];
for ($i=0; $i<7; $i++) {
  $d = date('Y-m-d', strtotime("+$i day", strtotime($iniSemana)));
  $dow = (int)date('N', strtotime($d));
  if ($dow===7 && !$incluyeDomingo) continue; // saltar domingo
  $dias[] = $d;
}

// Traer citas de la semana (filtrar por FECHA de la cita, no por creado_en)
$citas = []; // indexadas por "YYYY-MM-DD|HH:MM"
$finFechaPlus = date('Y-m-d', strtotime($finSemana.' +1 day'));

$sql = "SELECT c.id, c.fecha, c.hora, c.estado, c.notas,
               u.nombre AS paciente, s.nombre AS servicio
        FROM citas c
        INNER JOIN usuarios u ON u.id=c.paciente_id
        LEFT JOIN servicios s ON s.id=c.servicio_id
        WHERE c.fecha >= ? AND c.fecha < ?
        ORDER BY c.fecha, c.hora";

$st = $conn->prepare($sql);
$st->bind_param("ss", $iniSemana, $finFechaPlus);
$st->execute();
$r = $st->get_result();
while($row = $r->fetch_assoc()){
  $key = $row['fecha'].'|'.substr($row['hora'],0,5);
  $citas[$key] = $row;
}
$st->close();

// Para el formulario: pacientes web y servicios
$pacientes = [];
if ($res = $conn->query("SELECT id, nombre, correo FROM usuarios WHERE rol='paciente' AND activo=1 ORDER BY nombre LIMIT 1000")) {
  while($row=$res->fetch_assoc()) $pacientes[]=$row;
  $res->free();
}
$servicios = [];
if ($res = $conn->query("SELECT id, nombre, tipo FROM servicios WHERE activo=1 ORDER BY tipo, nombre")) {
  while($row=$res->fetch_assoc()) $servicios[]=$row;
  $res->free();
}

$ok=flash('ok'); $err=flash('err');

// Navegación de semanas
$prevDia = date('Y-m-d', strtotime($iniSemana.' -7 day'));
$nextDia = date('Y-m-d', strtotime($iniSemana.' +7 day'));
?>
<!doctype html>
<meta charset="utf-8">
<title>Citas | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --brand:#0b5ed7;
    --brand-soft:#e5efff;
    --accent:#16a085;
    --bg:#0f172a;
    --bg-soft:#f3f4f8;
    --card:#ffffff;
    --ink:#0f172a;
    --muted:#6b7280;
    --ok:#16a34a;
    --warn:#eab308;
    --bad:#dc2626;
    --shadow-strong:0 18px 45px rgba(15,23,42,.35);
    --shadow-soft:0 10px 30px rgba(15,23,42,.18);
    --radius-xl:22px;
    --radius-md:16px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    color:var(--ink);
    background:
      radial-gradient(circle at top left,rgba(37,99,235,.38),transparent 60%),
      radial-gradient(circle at bottom right,rgba(20,184,166,.35),transparent 60%),
      #020617;
    display:flex;
    flex-direction:column;
  }
  a{text-decoration:none;color:inherit}

  /* TOP BAR */
  .topbar{
    position:sticky;
    top:0;
    z-index:20;
    backdrop-filter:blur(14px);
    background:rgba(15,23,42,.96);
    color:#e5e7eb;
    padding:10px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid rgba(148,163,184,.5);
  }
  .top-left{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .brand-icon{
    width:30px;height:30px;
    border-radius:11px;
    background:linear-gradient(135deg,var(--brand),var(--accent));
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    font-size:16px;
    color:#fff;
    box-shadow:0 8px 22px rgba(15,23,42,.7);
  }
  .brand-title{
    font-weight:800;
    letter-spacing:.08em;
    font-size:13px;
  }
  .brand-sub{
    font-size:11px;
    color:#9ca3af;
  }
  .top-right{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12px;
  }
  .week-pill{
    padding:5px 10px;
    border-radius:999px;
    background:rgba(15,23,42,.65);
    border:1px solid rgba(148,163,184,.7);
    font-size:11px;
    color:#e5e7eb;
  }
  .nav-btn{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.7);
    font-size:11px;
    color:#e5e7eb;
    background:rgba(15,23,42,.5);
    cursor:pointer;
  }
  .nav-btn:hover{
    background:rgba(15,23,42,.9);
  }

  /* PAGE LAYOUT */
  .page{
    flex:1;
    padding:20px 16px 24px 16px;
  }
  .shell{
    max-width:1250px;
    margin:0 auto;
  }

  .page-header{
    color:#e5e7eb;
    margin-bottom:16px;
  }
  .page-title{
    font-size:24px;
    font-weight:600;
    letter-spacing:.02em;
  }
  .page-sub{
    font-size:13px;
    color:#9ca3af;
    margin-top:3px;
  }

  .layout{
    display:grid;
    grid-template-columns:2.2fr 1.1fr;
    gap:18px;
  }
  @media(max-width:1100px){
    .layout{
      grid-template-columns:1fr;
    }
  }

  .card-main{
    background:linear-gradient(145deg,rgba(248,250,252,1),rgba(226,232,240,1));
    border-radius:var(--radius-xl);
    padding:16px 16px 18px 16px;
    box-shadow:var(--shadow-strong);
    border:1px solid rgba(148,163,184,.55);
  }
  .card-side{
    background:#f9fafb;
    border-radius:var(--radius-xl);
    padding:16px 16px 18px 16px;
    box-shadow:var(--shadow-soft);
    border:1px solid rgba(226,232,240,1);
  }

  .section-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
  }
  .section-title{
    font-size:16px;
    font-weight:600;
    color:#111827;
  }
  .section-sub{
    font-size:12px;
    color:#6b7280;
  }

  /* ALERTS */
  .alerts{
    margin-bottom:10px;
  }
  .alert{
    padding:9px 11px;
    border-radius:12px;
    font-size:13px;
    margin-bottom:6px;
  }
  .alert-ok{
    background:#e8f7ed;
    color:#166534;
    border:1px solid #bbf7d0;
  }
  .alert-err{
    background:#fee2e2;
    color:#b91c1c;
    border:1px solid #fecaca;
  }

  /* AGENDA GRID */
  .agenda{
    display:grid;
    grid-template-columns:110px repeat(6,minmax(0,1fr));
    gap:8px;
  }
  @media(max-width:900px){
    .agenda{
      grid-template-columns:90px repeat(6,minmax(0,1fr));
    }
  }
  @media(max-width:700px){
    .agenda{
      overflow:auto;
      font-size:12px;
    }
  }

  .slot{
    background:#ffffff;
    border-radius:14px;
    padding:8px;
    min-height:82px;
    border:1px solid rgba(226,232,240,1);
    position:relative;
    box-shadow:0 2px 6px rgba(148,163,184,.20);
  }
  .slot.free{
    background:#f9fafb;
  }
  .slot-hour{
    background:#eef2ff;
    border-radius:14px;
    padding:8px;
    font-weight:600;
    color:#4b5563;
    text-align:center;
    border:1px solid rgba(199,210,254,1);
  }
  .slot-header{
    min-height:auto;
    padding:8px;
    font-weight:600;
    text-align:center;
    background:#f9fafb;
    border-radius:14px;
    border:1px solid rgba(226,232,240,1);
  }
  .slot-header.feriado{
    background:#fef2f2;
    border-color:#fecaca;
    color:#b91c1c;
  }
  .slot.feriado{
    background:#fef2f2;
    border-color:#fecaca;
    opacity:.9;
  }
  .hora{
    position:absolute;
    top:6px;
    right:8px;
    font-size:11px;
    color:#6b7280;
  }

  .tag{
    display:inline-block;
    border-radius:999px;
    padding:2px 8px;
    font-size:11px;
    font-weight:600;
    border:1px solid transparent;
  }
  .tag.pendiente{
    background:#fff7ed;
    color:#9a3412;
    border-color:#fed7aa;
  }
  .tag.confirmada{
    background:#ecfdf5;
    color:#047857;
    border-color:#bbf7d0;
  }
  .tag.cancelada{
    background:#fef2f2;
    color:#b91c1c;
    border-color:#fecaca;
  }
  .tag.atendida{
    background:#eef2ff;
    color:#3730a3;
    border-color:#c7d2fe;
  }

  .slot strong{
    font-size:13px;
  }
  .muted{
    font-size:12px;
    color:#6b7280;
  }

  .acciones{
    margin-top:6px;
  }
  .acciones form{display:inline}
  .acciones button{
    border:none;
    cursor:pointer;
    border-radius:999px;
    padding:3px 8px;
    margin:1px 2px 0 0;
    font-size:11px;
    font-weight:500;
  }
  .a-ok{background:#e8f7ed;color:#166534;border:1px solid #bbf7d0}
  .a-i{background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe}
  .a-w{background:#fffbeb;color:#92400e;border:1px solid #fed7aa}
  .a-b{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}

  /* FORM SIDE */
  label{
    display:block;
    font-size:13px;
    font-weight:600;
    margin-top:8px;
    color:#111827;
  }
  select,input[type="date"],input[name="notas"]{
    width:100%;
    padding:9px 10px;
    border-radius:11px;
    border:1px solid #d1d5db;
    font-size:13px;
    margin-top:4px;
    background:#f9fafb;
    transition:border-color .15s, box-shadow .15s, background .15s, transform .08s;
  }
  select:focus,input[type="date"]:focus,input[name="notas"]:focus{
    outline:none;
    border-color:var(--brand);
    background:#ffffff;
    box-shadow:0 0 0 1px rgba(59,130,246,.30),0 0 0 6px rgba(191,219,254,.65);
    transform:translateY(-1px);
  }

  .form-row{
    display:flex;
    gap:10px;
    margin-top:4px;
  }
  @media(max-width:600px){
    .form-row{
      flex-direction:column;
    }
  }

  .btn-main{
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:none;
    border-radius:999px;
    padding:8px 13px;
    margin-top:12px;
    font-size:12px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.08em;
    cursor:pointer;
    background:linear-gradient(135deg,var(--brand),#1d4ed8);
    color:#f9fafb;
    box-shadow:0 10px 26px rgba(15,23,42,.30);
  }
  .btn-main::after{
    content:"→";
    font-size:11px;
  }
  .btn-main:hover{
    filter:brightness(1.05);
  }

  .btn-secondary{
    display:inline-flex;
    align-items:center;
    border-radius:999px;
    padding:8px 11px;
    margin-top:12px;
    font-size:12px;
    font-weight:500;
    border:1px solid #d1d5db;
    background:#ffffff;
    color:#4b5563;
  }

  .mini-card{
    margin-top:12px;
    background:#f3f4f8;
    border-radius:16px;
    padding:10px 11px;
    border:1px solid rgba(226,232,240,1);
  }
  .mini-card strong{
    font-size:13px;
  }
  .mini-card p{
    margin:.25rem 0;
    font-size:12px;
    color:#6b7280;
  }
</style>

<header class="topbar">
  <div class="top-left">
    <div class="brand-icon">B</div>
    <div>
      <div class="brand-title"><a href="panel.php" style="color:inherit">BETANDENT</a> · Citas</div>
      <div class="brand-sub">Agenda semanal de la clínica</div>
    </div>
  </div>
  <div class="top-right">
    <a class="nav-btn" href="citas.php?dia=<?= e($prevDia) ?>">
      <span>&larr;</span><span>Semana anterior</span>
    </a>
    <span class="week-pill"><?= e($iniSemana) ?> a <?= e($finSemana) ?></span>
    <a class="nav-btn" href="citas.php?dia=<?= e($nextDia) ?>">
      <span>Semana siguiente</span><span>&rarr;</span>
    </a>
  </div>
</header>

<div class="page">
  <div class="shell">

    <div class="page-header">
      <div class="page-title">Agenda semanal</div>
      <div class="page-sub">
        Visualiza y administra las citas de pacientes por horario. Domingos y feriados aparecen bloqueados automáticamente.
      </div>
    </div>

    <div class="alerts">
      <?php if($ok): ?>
        <div class="alert alert-ok"><?= e($ok) ?></div>
      <?php endif; ?>
      <?php if($err): ?>
        <div class="alert alert-err"><?= e($err) ?></div>
      <?php endif; ?>
    </div>

    <div class="layout">
      <!-- Agenda semanal -->
      <section class="card-main">
        <div class="section-header">
          <div class="section-title">Calendario de citas</div>
          <div class="section-sub">Haz clic en los botones de cada cita para actualizar su estado.</div>
        </div>

        <div class="agenda">
          <!-- Encabezados -->
          <div></div>
          <?php foreach($dias as $d):
            $lbl = strftime('%a %d/%m', strtotime($d)); 
            $isFeriado = in_array($d,$feriados,true);
          ?>
            <div class="slot-header <?= $isFeriado? 'feriado':'' ?>">
              <?= e($lbl) ?><?= $isFeriado?' · Feriado':'' ?>
            </div>
          <?php endforeach; ?>

          <!-- Filas por hora -->
          <?php foreach($slots as $h): ?>
            <div class="slot-hour"><?= e($h) ?></div>
            <?php foreach($dias as $d):
              $key = $d.'|'.$h;
              $isFeriado = in_array($d,$feriados,true);

              if ($isFeriado) {
                echo '<div class="slot feriado"></div>';
                continue;
              }

              if (isset($citas[$key])) {
                $c = $citas[$key];
                ?>
                <div class="slot">
                  <div class="hora"><?= e($h) ?></div>
                  <div><span class="tag <?= e($c['estado']) ?>"><?= e($c['estado']) ?></span></div>
                  <div style="margin-top:4px"><strong><?= e($c['paciente']) ?></strong></div>
                  <div class="muted"><?= e($c['servicio'] ?? '—') ?></div>
                  <?php if(!empty($c['notas'])): ?>
                    <div class="muted" style="font-size:.9rem;margin-top:2px;">“<?= e($c['notas']) ?>”</div>
                  <?php endif; ?>
                  <div class="acciones">
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="estado">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="nuevo" value="confirmada">
                      <button class="a-ok" title="Confirmar">Confirmar</button>
                    </form>
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="estado">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="nuevo" value="atendida">
                      <button class="a-i" title="Marcar atendida">Atendida</button>
                    </form>
                    <form method="post" onsubmit="return confirm('¿Cancelar esta cita?')">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="estado">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <input type="hidden" name="nuevo" value="cancelada">
                      <button class="a-w" title="Cancelar">Cancelar</button>
                    </form>
                    <form method="post" onsubmit="return confirm('¿Eliminar definitivamente?')">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="eliminar">
                      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                      <button class="a-b" title="Eliminar">Eliminar</button>
                    </form>
                  </div>
                </div>
              <?php } else { ?>
                <div class="slot free">
                  <div class="hora"><?= e($h) ?></div>
                  <div class="muted">Disponible</div>
                </div>
              <?php } ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Alta manual -->
      <aside class="card-side">
        <div class="section-header">
          <div class="section-title">Crear cita (clínica)</div>
          <div class="section-sub">Asignar una cita directamente desde recepción.</div>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="crear">

          <label>Paciente (usuarios web)</label>
          <select name="paciente_id" required>
            <option value="">— seleccionar —</option>
            <?php foreach($pacientes as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?> · <?= e($p['correo']) ?></option>
            <?php endforeach; ?>
          </select>

          <label>Servicio (opcional)</label>
          <select name="servicio_id">
            <option value="0">— sin servicio —</option>
            <?php foreach($servicios as $s): ?>
              <option value="<?= (int)$s['id'] ?>">[<?= e($s['tipo']) ?>] <?= e($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="form-row">
            <div style="flex:1">
              <label>Fecha</label>
              <input type="date" name="fecha" value="<?= e($dia) ?>" required>
            </div>
            <div style="flex:1">
              <label>Hora</label>
              <select name="hora" required>
                <?php foreach($slots as $h): ?>
                  <option value="<?= e($h) ?>"><?= e($h) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <label>Notas (opcional)</label>
          <input name="notas" maxlength="200" placeholder="Ej. control de ortodoncia, traer radiografía">

          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn-main" type="submit"><span>Guardar cita</span></button>
            <a class="btn-secondary" href="panel.php">Volver al panel</a>
          </div>

          <p class="muted" style="margin-top:8px;">
            Horario: L a S de 08:00 a 18:00. Domingos y feriados se inhabilitan automáticamente.
          </p>
        </form>

        <div class="mini-card">
          <strong>Feriados configurados</strong>
          <p><?= e(implode(', ', $feriados)) ?></p>
          <p>Modifica el arreglo <code>$feriados</code> al inicio del archivo para ajustarlos a tu calendario real.</p>
        </div>
      </aside>
    </div>
  </div>
</div>
