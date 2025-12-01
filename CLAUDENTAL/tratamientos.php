<?php
// tratamientos.php — BETANDENT (Episodios y cargos de tratamiento)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // paciente no entra

$conn = db();

// ========= Helpers =========
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($key, $val=null){
  if ($val===null) { $v = $_SESSION[$key] ?? ''; unset($_SESSION[$key]); return $v; }
  $_SESSION[$key] = $val;
}
// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
function check_csrf(){
  if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400); die("<h2>Solicitud inválida</h2><p>Token CSRF incorrecto.</p>");
  }
}

// ========= Catálogos mínimos =========
// Pacientes locales
$pacientes = [];
if ($r = $conn->query("SELECT id, nombre FROM pacientes ORDER BY nombre")) {
  while($row = $r->fetch_assoc()) $pacientes[] = $row; $r->free();
}
// Solo servicios tipo 'tratamiento'
$trat_catalogo = [];
if ($r = $conn->query("SELECT id, nombre FROM servicios WHERE tipo='tratamiento' AND activo=1 ORDER BY nombre")) {
  while($row = $r->fetch_assoc()) $trat_catalogo[] = $row; $r->free();
}

// ========= Acciones =========
$act = $_POST['act'] ?? $_GET['act'] ?? '';
$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// Crear episodio de tratamiento
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='crear_trat') {
  check_csrf();
  $paciente_id = (int)($_POST['paciente_id'] ?? 0);
  $servicio_id = (int)($_POST['servicio_id'] ?? 0);
  $abono_inicial = (float)($_POST['abono_inicial'] ?? 0);
  $mensualidad = (float)($_POST['mensualidad'] ?? 0);
  $obs = trim($_POST['observaciones'] ?? '');

  if ($paciente_id<=0 || $servicio_id<=0) {
    flash('err','Selecciona paciente y tratamiento.');
  } else {
    $st = $conn->prepare("INSERT INTO tratamientos(paciente_id,servicio_id,estado,abono_inicial,mensualidad,observaciones) VALUES (?,?, 'abierto', ?, ?, ?");
    $st->bind_param("iidds", $paciente_id, $servicio_id, $abono_inicial, $mensualidad, $obs);
    if ($st->execute()) {
      $newId = $st->insert_id;
      $st->close();
      // Crear cargo de Abono inicial (pendiente) si > 0 para cobrarlo luego en “Cobros”
      if ($abono_inicial > 0) {
        $desc = 'Abono inicial';
        $cant = 1;
        $pu = $abono_inicial;
        $tot = $abono_inicial;
        $pagado = 0;
        $st = $conn->prepare("INSERT INTO tratamiento_movs(tratamiento_id, concepto, cantidad, precio_unit, total, pagado) VALUES (?,?,?,?,?,?)");
        $st->bind_param("isiddi", $newId, $desc, $cant, $pu, $tot, $pagado);
        $st->execute();
        $st->close();
      }
      flash('ok','Tratamiento creado.');
      header("Location: tratamientos.php?act=ver&id=".$newId);
      exit;
    } else {
      flash('err','No se pudo crear: '.e($conn->error));
      $st->close();
    }
  }
  header("Location: tratamientos.php");
  exit;
}

// Agregar movimiento (cargo)
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='agregar_mov' && $id>0) {
  check_csrf();
  $concepto = trim($_POST['concepto'] ?? '');
  $cantidad = (int)($_POST['cantidad'] ?? 1);
  $precio   = (float)($_POST['precio_unit'] ?? 0);
  if ($concepto==='' || $cantidad<=0 || $precio<0) {
    flash('err','Datos del cargo inválidos.');
  } else {
    $total = $cantidad * $precio;
    $pagado = 0; // se liquida en Cobros
    $st = $conn->prepare("INSERT INTO tratamiento_movs(tratamiento_id, concepto, cantidad, precio_unit, total, pagado) VALUES (?,?,?,?,?,?)");
    $st->bind_param("isiddi", $id, $concepto, $cantidad, $precio, $total, $pagado);
    if ($st->execute()) flash('ok','Movimiento agregado.');
    else flash('err','No se pudo agregar: '.e($conn->error));
    $st->close();
  }
  header("Location: tratamientos.php?act=ver&id=".$id);
  exit;
}

// Eliminar movimiento (solo si está pendiente para no romper caja)
if ($_SERVER['REQUEST_METHOD']==='POST' && $act==='eliminar_mov' && $id>0) {
  check_csrf();
  $mov_id = (int)($_POST['mov_id'] ?? 0);
  if ($mov_id>0) {
    // verifica estado
    $puede = false;
    if ($r = $conn->query("SELECT pagado FROM tratamiento_movs WHERE id=".$mov_id." AND tratamiento_id=".$id." LIMIT 1")) {
      if ($row = $r->fetch_assoc()) $puede = ((int)$row['pagado']===0);
      $r->free();
    }
    if ($puede) {
      $st = $conn->prepare("DELETE FROM tratamiento_movs WHERE id=? AND tratamiento_id=?");
      $st->bind_param("ii", $mov_id, $id);
      if ($st->execute()) flash('ok','Movimiento eliminado.');
      else flash('err','No se pudo eliminar: '.e($conn->error));
      $st->close();
    } else {
      flash('err','No es posible eliminar un movimiento pagado.');
    }
  }
  header("Location: tratamientos.php?act=ver&id=".$id);
  exit;
}

// Marcar pagado / pendiente
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($act,['marcar_pagado','marcar_pendiente'],true) && $id>0) {
  check_csrf();
  $mov_id = (int)($_POST['mov_id'] ?? 0);
  if ($mov_id>0) {
    $val = $act==='marcar_pagado' ? 1 : 0;
    $st = $conn->prepare("UPDATE tratamiento_movs SET pagado=? WHERE id=? AND tratamiento_id=?");
    $st->bind_param("iii", $val, $mov_id, $id);
    if ($st->execute()) flash('ok', $val? 'Marcado como pagado.':'Marcado como pendiente.');
    else flash('err','No se pudo actualizar: '.e($conn->error));
    $st->close();
  }
  header("Location: tratamientos.php?act=ver&id=".$id);
  exit;
}

// Cerrar/Reabrir tratamiento
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($act,['cerrar_trat','reabrir_trat'],true) && $id>0) {
  check_csrf();
  $nuevo = $act==='cerrar_trat' ? 'cerrado' : 'abierto';
  $st = $conn->prepare("UPDATE tratamientos SET estado=? WHERE id=?");
  $st->bind_param("si", $nuevo, $id);
  if ($st->execute()) flash('ok', $nuevo==='cerrado'?'Tratamiento cerrado.':'Tratamiento reabierto.');
  else flash('err','No se pudo actualizar: '.e($conn->error));
  $st->close();
  header("Location: tratamientos.php?act=ver&id=".$id);
  exit;
}

// ========= Consultas para listados/detalle =========
$ok  = flash('ok'); $err = flash('err');

// Detalle de un tratamiento
$view = null;
$movs = [];
$sum_emitido = 0; $sum_pagado = 0;
if ($act==='ver' && $id>0) {
  $sql = "SELECT t.id, t.estado, t.abono_inicial, t.mensualidad, t.observaciones, 
                 p.nombre AS paciente, p.id AS paciente_id,
                 s.nombre AS servicio, s.id AS servicio_id
          FROM tratamientos t
          INNER JOIN pacientes p ON p.id=t.paciente_id
          INNER JOIN servicios s ON s.id=t.servicio_id
          WHERE t.id=".$id." LIMIT 1";
  if ($r = $conn->query($sql)) { $view = $r->fetch_assoc(); $r->free(); }

  if ($view) {
    if ($r = $conn->query("SELECT id, concepto, cantidad, precio_unit, total, pagado, DATE_FORMAT(fecha,'%Y-%m-%d %H:%i') AS fecha 
                           FROM tratamiento_movs WHERE tratamiento_id=".$id." ORDER BY fecha DESC, id DESC")) {
      while($row = $r->fetch_assoc()){
        $movs[] = $row;
        $sum_emitido += (float)$row['total'];
        if ((int)$row['pagado']===1) $sum_pagado += (float)$row['total'];
      }
      $r->free();
    }
  }
}

// Listado de tratamientos con filtros
$rows = [];
if (!$view) {
  $q = trim($_GET['q'] ?? '');       // por paciente
  $f_estado = $_GET['estado'] ?? 'abierto'; // abierto|cerrado|todos

  $where = [];
  if ($q!=='') {
    $q_esc = $conn->real_escape_string($q);
    $where[] = "p.nombre LIKE '%$q_esc%'";
  }
  if (in_array($f_estado, ['abierto','cerrado'], true)) {
    $where[] = "t.estado='$f_estado'";
  }
  $sql = "SELECT t.id, t.estado, t.abono_inicial, t.mensualidad, 
                 p.nombre AS paciente, s.nombre AS servicio,
                 (SELECT IFNULL(SUM(total),0) FROM tratamiento_movs m WHERE m.tratamiento_id=t.id) AS emitido,
                 (SELECT IFNULL(SUM(total),0) FROM tratamiento_movs m WHERE m.tratamiento_id=t.id AND m.pagado=1) AS pagado
          FROM tratamientos t
          INNER JOIN pacientes p ON p.id=t.paciente_id
          INNER JOIN servicios s ON s.id=t.servicio_id
          ".(count($where)?'WHERE '.implode(' AND ',$where):'')."
          ORDER BY t.id DESC
          LIMIT 200";
  if ($r = $conn->query($sql)) {
    while($row = $r->fetch_assoc()) {
      $row['pendiente'] = (float)$row['emitido'] - (float)$row['pagado'];
      $rows[] = $row;
    }
    $r->free();
  }
}

?>
<!doctype html>
<meta charset="utf-8">
<title>Tratamientos | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --brand:#38bdf8;
    --brand-dark:#0284c7;
    --accent:#a855f7;
    --accent-soft:#f5f3ff;
    --danger:#ef4444;
    --danger-soft:#fef2f2;
    --ok:#22c55e;
    --ink:#020617;
    --muted:#9ca3af;
    --bg:#020617;
    --card:#020617;
    --border-soft:rgba(148,163,184,.35);
    --shadow:0 22px 60px rgba(15,23,42,.9);
    --radius-xl:22px;
  }

  *{box-sizing:border-box;margin:0;padding:0}
  body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    min-height:100vh;
    color:#e5e7eb;
    background:
      radial-gradient(circle at 0% 0%,rgba(56,189,248,.25) 0,transparent 55%),
      radial-gradient(circle at 100% 0%,rgba(168,85,247,.28) 0,transparent 50%),
      radial-gradient(circle at 0% 100%,rgba(34,197,94,.2) 0,transparent 45%),
      #020617;
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
    background:rgba(15,23,42,.95);
    backdrop-filter:blur(22px);
    border:1px solid rgba(148,163,184,.55);
    box-shadow:0 18px 50px rgba(15,23,42,.9);
    position:sticky;
    top:10px;
    z-index:30;
  }
  .topbrand{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .top-logo{
    width:32px;
    height:32px;
    border-radius:12px;
    background:
      radial-gradient(circle at 25% 25%,#f9fafb 0,#e0f2fe 28%,transparent 52%),
      radial-gradient(circle at 80% 80%,#67e8f9 0,#22c55e 40%,transparent 70%);
    box-shadow:0 0 0 3px rgba(8,47,73,.7);
    display:flex;align-items:center;justify-content:center;
    font-size:1.1rem;
  }
  .top-text-main{
    font-size:.9rem;
    font-weight:700;
    letter-spacing:.12em;
    text-transform:uppercase;
  }
  .top-text-sub{
    font-size:.78rem;
    color:#9ca3af;
  }

  .top-actions{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:.8rem;
  }
  .badge-chip{
    padding:4px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.6);
    background:rgba(15,23,42,.9);
    color:#cbd5f5;
    font-size:.75rem;
    display:inline-flex;
    align-items:center;
    gap:6px;
  }
  .badge-chip span:first-child{
    width:6px;height:6px;border-radius:999px;background:#22c55e;
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
    background:linear-gradient(135deg,#0ea5e9,#6366f1);
    color:white;
    box-shadow:0 12px 30px rgba(56,189,248,.6);
  }
  .btn-primary:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 40px rgba(59,130,246,.8);
  }
  .btn-soft{
    background:rgba(15,23,42,.96);
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-soft:hover{
    border-color:#e5e7eb;
  }
  .btn-ghost{
    background:transparent;
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-ghost:hover{
    background:rgba(15,23,42,.96);
    border-color:#e5e7eb;
  }
  .btn-danger{
    background:linear-gradient(135deg,#ef4444,#b91c1c);
    color:#fee2e2;
    border:none;
    box-shadow:0 12px 26px rgba(248,113,113,.75);
  }
  .btn-danger:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 36px rgba(248,113,113,.95);
  }
  .btn-secondary{
    background:rgba(55,65,81,.95);
    color:#f9fafb;
    border:1px solid rgba(156,163,175,.9);
  }

  .page-head{
    margin-top:22px;
    margin-bottom:16px;
    display:flex;
    justify-content:space-between;
    gap:16px;
    align-items:flex-end;
  }
  .page-title{
    font-size:1.5rem;
    font-weight:700;
    letter-spacing:.04em;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .page-pill{
    padding:4px 10px;
    border-radius:999px;
    background:rgba(15,23,42,.9);
    border:1px solid rgba(148,163,184,.55);
    font-size:.75rem;
    color:#cbd5f5;
  }
  .page-sub{
    font-size:.8rem;
    color:#9ca3af;
    margin-top:4px;
  }

  .alerts{margin-bottom:10px;}
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
    background:rgba(22,163,74,.18);
    border-color:rgba(34,197,94,.8);
    color:#bbf7d0;
  }
  .alert.ok .alert-icon{background:rgba(34,197,94,.35);}
  .alert.err{
    background:rgba(248,113,113,.16);
    border-color:rgba(248,113,113,.85);
    color:#fee2e2;
  }
  .alert.err .alert-icon{background:rgba(239,68,68,.4);}

  .layout{
    display:grid;
    grid-template-columns:0.95fr 1.55fr;
    gap:16px;
  }
  @media (max-width:1040px){
    .layout{grid-template-columns:1fr;}
  }

  .card{
    background:radial-gradient(circle at top left,rgba(56,189,248,.2) 0,rgba(15,23,42,.98) 55%);
    border-radius:var(--radius-xl);
    padding:16px 16px 14px;
    border:1px solid var(--border-soft);
    box-shadow:var(--shadow);
  }
  .card-header{
    display:flex;
    justify-content:space-between;
    gap:10px;
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

  form label{
    display:block;
    margin-top:10px;
    margin-bottom:2px;
    font-size:.78rem;
    color:#e5e7eb;
    font-weight:500;
  }
  input[type="text"],
  input[type="number"],
  textarea,
  select{
    width:100%;
    padding:9px 11px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.7);
    background:rgba(15,23,42,.95);
    color:#e5e7eb;
    font-size:.85rem;
  }
  input:focus,
  textarea:focus,
  select:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 0 1px rgba(56,189,248,.8);
  }
  textarea{
    resize:vertical;
    min-height:70px;
  }
  .checkbox-inline{
    display:flex;
    align-items:center;
    gap:8px;
    margin-top:10px;
    font-size:.8rem;
  }
  .checkbox-inline input{width:auto;accent-color:#22c55e;}

  .form-footer{
    margin-top:12px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }
  .muted{
    color:#9ca3af;
    font-size:.78rem;
  }

  .filters{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    justify-content:flex-end;
  }
  .filters input[type="text"]{max-width:220px;}

  table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    font-size:.8rem;
  }
  thead{
    background:rgba(15,23,42,.96);
  }
  th,td{
    padding:8px 9px;
    text-align:left;
    border-bottom:1px solid rgba(30,64,175,.9);
    vertical-align:top;
  }
  th{
    font-size:.75rem;
    font-weight:600;
    color:#9ca3af;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  tbody tr:nth-child(even){
    background:rgba(15,23,42,.88);
  }
  tbody tr:nth-child(odd){
    background:rgba(15,23,42,.82);
  }
  tbody tr:hover{
    background:rgba(56,189,248,.22);
  }
  .money{
    font-variant-numeric:tabular-nums;
    font-weight:700;
  }
  .status-open{
    color:#bbf7d0;
    font-weight:600;
  }
  .status-closed{
    color:#e5e7eb;
    opacity:.8;
  }

  .pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:3px 9px;
    border-radius:999px;
    font-size:.75rem;
    border:1px solid rgba(148,163,184,.7);
    color:#cbd5f5;
    background:rgba(15,23,42,.9);
  }

  .badge-stat{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.75);
    background:rgba(15,23,42,.95);
    font-size:.78rem;
  }
</style>

<div class="shell">
  <header class="topbar">
    <div class="topbrand">
      <div class="top-logo">📈</div>
      <div>
        <div class="top-text-main">BETANDENT</div>
        <div class="top-text-sub">Control de tratamientos</div>
      </div>
    </div>
    <div class="top-actions">
      <div class="badge-chip">
        <span></span>
        <span>Admin · Empleado</span>
      </div>
      <a href="panel.php" class="btn btn-ghost">Volver al panel</a>
    </div>
  </header>

  <div class="page-head">
    <div>
      <div class="page-title">
        Tratamientos
        <span class="page-pill"><?= $view ? 'Detalle de episodio' : 'Listado y alta de episodios' ?></span>
      </div>
      <p class="page-sub">
        Registra episodios ortodónticos, cargos por piezas, mensualidades y controla lo emitido, pagado y pendiente.
      </p>
    </div>
  </div>

  <div class="alerts">
    <?php if ($ok): ?>
      <div class="alert ok">
        <div class="alert-icon">✓</div>
        <div><?= e($ok) ?></div>
      </div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert err">
        <div class="alert-icon">!</div>
        <div><?= e($err) ?></div>
      </div>
    <?php endif; ?>
  </div>

<?php if ($view): ?>
  <!-- VISTA DETALLE DE UN TRATAMIENTO -->
  <section class="layout">
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Datos del tratamiento</h2>
          <p class="card-sub">
            Episodio asociado a un paciente y a un tratamiento configurado en el catálogo.
          </p>
        </div>
        <span class="pill">
          ID #<?= (int)$view['id'] ?>
        </span>
      </div>

      <p><strong>Paciente:</strong> <?= e($view['paciente']) ?> <span class="muted">(ID <?= (int)$view['paciente_id'] ?>)</span></p>
      <p><strong>Tratamiento:</strong> <?= e($view['servicio']) ?></p>
      <p>
        <strong>Estado:</strong>
        <?php if ($view['estado']==='abierto'): ?>
          <span class="status-open">Abierto</span>
        <?php else: ?>
          <span class="status-closed">Cerrado</span>
        <?php endif; ?>
      </p>
      <p><strong>Abono inicial:</strong> $<?= number_format((float)$view['abono_inicial'],2) ?></p>
      <p><strong>Mensualidad sugerida:</strong> $<?= number_format((float)$view['mensualidad'],2) ?></p>
      <?php if($view['observaciones']): ?>
        <p><strong>Notas:</strong><br><?= nl2br(e($view['observaciones'])) ?></p>
      <?php endif; ?>

      <div class="form-footer" style="margin-top:12px">
        <?php if ($view['estado']==='abierto'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="act" value="cerrar_trat">
            <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
            <button class="btn btn-secondary" onclick="return confirm('¿Cerrar este tratamiento?');">
              Cerrar tratamiento
            </button>
          </form>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="act" value="reabrir_trat">
            <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
            <button class="btn btn-primary">Reabrir</button>
          </form>
        <?php endif; ?>
        <a class="btn btn-ghost" href="tratamientos.php">Volver al listado</a>
      </div>

      <hr style="border:none;border-top:1px solid rgba(148,163,184,.5);margin:14px 0 10px">

      <div>
        <h3 class="card-title" style="margin-bottom:4px;">Agregar cargo</h3>
        <p class="card-sub">Registra mensualidades, tubos, brackets u otros conceptos ligados al episodio.</p>

        <form method="post" class="form-footer" style="margin-top:10px;flex-wrap:wrap" oninput="calcTotal()">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="agregar_mov">
          <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">

          <div style="flex:2;min-width:230px">
            <label for="concepto">Concepto *</label>
            <input name="concepto" id="concepto" placeholder="Ej. Mensualidad, Tubo c/u, Bracket c/u" required>
          </div>
          <div style="flex:0.6;min-width:110px">
            <label for="cantidad">Cantidad *</label>
            <input name="cantidad" id="cantidad" type="number" min="1" value="1" required>
          </div>
          <div style="flex:0.9;min-width:140px">
            <label for="precio">Precio unit. *</label>
            <input name="precio_unit" id="precio" type="number" min="0" step="0.01"
                   value="<?= e(number_format((float)$view['mensualidad'],2,'.','')) ?>">
          </div>
          <div style="flex:0.9;min-width:140px">
            <label for="total">Total</label>
            <input id="total" type="text" readonly placeholder="$0.00">
          </div>
          <div style="flex:0.5;min-width:120px;align-self:flex-end">
            <button class="btn btn-primary" style="width:100%">Agregar</button>
          </div>
        </form>

        <div class="form-footer" style="margin-top:8px">
          <button class="btn btn-soft" type="button" onclick="presetMensualidad()">Mensualidad</button>
          <button class="btn btn-soft" type="button" onclick="presetTubo()">Tubo c/u ($100)</button>
          <button class="btn btn-soft" type="button" onclick="presetBracket()">Bracket c/u ($50)</button>
        </div>
      </div>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h3 class="card-title">Movimientos del tratamiento</h3>
          <p class="card-sub">
            Cada cargo puede marcarse como pagado o pendiente. Más adelante se puede enlazar a “Cobros”.
          </p>
        </div>
        <div>
          <div class="badge-stat">
            Emitido: <span class="money">$<?= number_format($sum_emitido,2) ?></span>
          </div>
          <div class="badge-stat" style="margin-top:4px">
            Pagado: <span class="money">$<?= number_format($sum_pagado,2) ?></span>
          </div>
          <div class="badge-stat" style="margin-top:4px">
            Pendiente: <span class="money">$<?= number_format($sum_emitido-$sum_pagado,2) ?></span>
          </div>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Fecha</th>
            <th>Concepto</th>
            <th>Cant</th>
            <th>PU</th>
            <th>Total</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($movs)): ?>
          <tr><td colspan="8" class="muted">Sin movimientos registrados.</td></tr>
        <?php else: foreach($movs as $m): ?>
          <tr>
            <td><?= (int)$m['id'] ?></td>
            <td><?= e($m['fecha']) ?></td>
            <td><?= e($m['concepto']) ?></td>
            <td><?= (int)$m['cantidad'] ?></td>
            <td class="money">$<?= number_format((float)$m['precio_unit'],2) ?></td>
            <td class="money">$<?= number_format((float)$m['total'],2) ?></td>
            <td>
              <?php if ((int)$m['pagado']===1): ?>
                <span class="status-open">Pagado</span>
              <?php else: ?>
                <span class="status-closed">Pendiente</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="form-footer" style="margin-top:0">
                <?php if ((int)$m['pagado']===0): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="marcar_pagado">
                    <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
                    <input type="hidden" name="mov_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-soft">Marcar pagado</button>
                  </form>
                  <form method="post" onsubmit="return confirm('¿Eliminar movimiento?');">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="eliminar_mov">
                    <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
                    <input type="hidden" name="mov_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-danger">Eliminar</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="marcar_pendiente">
                    <input type="hidden" name="id" value="<?= (int)$view['id'] ?>">
                    <input type="hidden" name="mov_id" value="<?= (int)$m['id'] ?>">
                    <button class="btn btn-secondary">Marcar pendiente</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </article>
  </section>

  <script>
    function calcTotal(){
      var c = parseFloat(document.getElementById('cantidad').value||'1');
      var p = parseFloat(document.getElementById('precio').value||'0');
      if (isNaN(c)) c = 1;
      if (isNaN(p)) p = 0;
      var t = (c*p).toFixed(2);
      document.getElementById('total').value = '$'+t;
    }
    function presetMensualidad(){
      document.getElementById('concepto').value = 'Mensualidad';
      document.getElementById('cantidad').value = 1;
      calcTotal();
    }
    function presetTubo(){
      document.getElementById('concepto').value = 'Tubo c/u';
      document.getElementById('cantidad').value = 1;
      document.getElementById('precio').value = 100;
      calcTotal();
    }
    function presetBracket(){
      document.getElementById('concepto').value = 'Bracket c/u';
      document.getElementById('cantidad').value = 1;
      document.getElementById('precio').value = 50;
      calcTotal();
    }
    calcTotal();
  </script>

<?php else: ?>
  <!-- LISTADO + CREAR EPISODIO -->
  <section class="layout">
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Nuevo tratamiento</h2>
          <p class="card-sub">
            Crea un nuevo episodio asociando un paciente y un tratamiento del catálogo.
          </p>
        </div>
        <span class="pill">Alta rápida</span>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="crear_trat">

        <label for="paciente_id">Paciente *</label>
        <select id="paciente_id" name="paciente_id" required>
          <option value="">— seleccionar —</option>
          <?php foreach($pacientes as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['nombre']) ?> (<?= (int)$p['id'] ?>)</option>
          <?php endforeach; ?>
        </select>

        <label for="servicio_id">Tratamiento *</label>
        <select id="servicio_id" name="servicio_id" required>
          <option value="">— seleccionar —</option>
          <?php foreach($trat_catalogo as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>

        <label for="abono_inicial">Abono inicial (MXN)</label>
        <input id="abono_inicial" type="number" name="abono_inicial" min="0" step="0.01" value="2500.00">

        <label for="mensualidad">Mensualidad (MXN)</label>
        <input id="mensualidad" type="number" name="mensualidad" min="0" step="0.01" value="250.00">

        <label for="observaciones">Observaciones</label>
        <textarea id="observaciones" name="observaciones" rows="2" placeholder="Notas del caso, duración estimada, etc."></textarea>

        <div class="form-footer">
          <button class="btn btn-primary">Crear episodio</button>
        </div>

        <p class="muted" style="margin-top:8px;">
          El abono inicial se registra como cargo pendiente para poder liquidarse en el módulo de cobros.
        </p>
      </form>
    </article>

    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Tratamientos registrados</h2>
          <p class="card-sub">
            Filtra por paciente o estado y entra al detalle para revisar cargos pieza por pieza.
          </p>
        </div>
        <form class="filters" method="get" action="tratamientos.php">
          <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Buscar por paciente">
          <select name="estado">
            <option value="abierto" <?= (($_GET['estado'] ?? 'abierto')==='abierto'?'selected':'') ?>>Abiertos</option>
            <option value="cerrado" <?= (($_GET['estado'] ?? '')==='cerrado'?'selected':'') ?>>Cerrados</option>
            <option value="todos" <?= (($_GET['estado'] ?? '')==='todos'?'selected':'') ?>>Todos</option>
          </select>
          <button class="btn btn-soft" type="submit">Filtrar</button>
          <?php if (!empty($_GET['q']) || (($_GET['estado'] ?? 'abierto')!=='abierto')): ?>
            <a class="btn btn-ghost" href="tratamientos.php">Limpiar</a>
          <?php endif; ?>
        </form>
      </div>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Paciente</th>
            <th>Tratamiento</th>
            <th>Estado</th>
            <th>Emitido</th>
            <th>Pagado</th>
            <th>Pendiente</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="8" class="muted">Sin episodios registrados con los filtros actuales.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['paciente']) ?></td>
            <td><?= e($r['servicio']) ?></td>
            <td>
              <?php if ($r['estado']==='abierto'): ?>
                <span class="status-open">Abierto</span>
              <?php else: ?>
                <span class="status-closed">Cerrado</span>
              <?php endif; ?>
            </td>
            <td class="money">$<?= number_format((float)$r['emitido'],2) ?></td>
            <td class="money">$<?= number_format((float)$r['pagado'],2) ?></td>
            <td class="money">$<?= number_format((float)$r['pendiente'],2) ?></td>
            <td>
              <a class="btn btn-soft" href="tratamientos.php?act=ver&id=<?= (int)$r['id'] ?>">Abrir</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <p class="muted" style="margin-top:8px">
        Máximo 200 episodios. Usa el buscador si la lista se hace muy larga.
      </p>
    </article>
  </section>
<?php endif; ?>

</div>
