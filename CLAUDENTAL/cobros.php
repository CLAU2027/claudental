<?php
// cobros.php — BETANDENT (POS mostrador: servicios y movimientos de tratamiento)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // paciente no entra

$conn = db();

// ===== Helpers =====
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($key, $val=null){
  if ($val===null) { $v = $_SESSION[$key] ?? ''; unset($_SESSION[$key]); return $v; }
  $_SESSION[$key] = $val;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function check_csrf(){
  if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    die("<h2>Solicitud inválida</h2><p>Token CSRF incorrecto.</p>");
  }
}
function money($n){ return number_format((float)$n, 2, '.', ''); }
function codigo_venta(){ return 'BET-'.date('Ymd-His').'-'.substr(bin2hex(random_bytes(2)),0,4); }

// ===== Estado de UI en sesión (POS) =====
if (!isset($_SESSION['pos'])) {
  $_SESSION['pos'] = [
    'paciente_id'     => null,
    'paciente_nombre' => null,
    'carrito'         => [], // ['tipo'=>'serv'|'mov','servicio_id'=>?, 'mov_id'=>?, 'desc'=>?, 'cantidad'=>int, 'precio'=>float, 'total'=>float]
    'tratamiento_id'  => null,
  ];
}
$POS =& $_SESSION['pos'];

// ===== Acciones (POST) =====
$act = $_POST['act'] ?? $_GET['act'] ?? '';
$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();

  // 1) Seleccionar paciente
  if ($act==='select_paciente') {
    $pid = (int)($_POST['paciente_id'] ?? 0);
    if ($pid>0) {
      $st = $conn->prepare("SELECT id, nombre FROM pacientes WHERE id=?");
      $st->bind_param("i",$pid);
      $st->execute();
      $res = $st->get_result();
      if ($u = $res->fetch_assoc()) {
        $POS['paciente_id']     = (int)$u['id'];
        $POS['paciente_nombre'] = $u['nombre'];
        $POS['carrito']         = [];
        $POS['tratamiento_id']  = null;
        flash('ok','Paciente seleccionado: '.$u['nombre']);
      } else {
        flash('err','Paciente no encontrado.');
      }
      $st->close();
    } else {
      flash('err','Selecciona un paciente.');
    }
    header("Location: cobros.php");
    exit;
  }

  // 2) Agregar servicio directo al carrito
  if ($act==='add_servicio') {
    $sid  = (int)($_POST['servicio_id'] ?? 0);
    $cant = max(1, (int)($_POST['cantidad'] ?? 1));

    if ($sid>0) {
      $st = $conn->prepare("SELECT id, nombre, precio, tipo FROM servicios WHERE id=? AND activo=1 LIMIT 1");
      $st->bind_param("i",$sid);
      $st->execute();
      $res = $st->get_result();
      if ($s = $res->fetch_assoc()) {
        $precio = (float)$s['precio'];
        $POS['carrito'][] = [
          'tipo'        => 'serv',
          'servicio_id' => (int)$s['id'],
          'mov_id'      => null,
          'desc'        => $s['nombre'],
          'cantidad'    => $cant,
          'precio'      => $precio,
          'total'       => $cant * $precio,
        ];
        flash('ok','Agregado al carrito: '.$s['nombre']);
      } else {
        flash('err','Servicio no válido o inactivo.');
      }
      $st->close();
    }
    header("Location: cobros.php");
    exit;
  }

  // 3) Seleccionar tratamiento abierto del paciente
  if ($act==='select_tratamiento') {
    $tid = (int)($_POST['tratamiento_id'] ?? 0);
    if ($tid>0 && $POS['paciente_id']) {
      $st = $conn->prepare("SELECT t.id FROM tratamientos t WHERE t.id=? AND t.paciente_id=? AND t.estado='abierto' LIMIT 1");
      $st->bind_param("ii",$tid,$POS['paciente_id']);
      $st->execute();
      $res = $st->get_result();
      if ($res->fetch_assoc()) {
        $POS['tratamiento_id'] = $tid;
        flash('ok', 'Tratamiento #'.$tid.' seleccionado.');
      } else {
        flash('err','Tratamiento inválido para el paciente seleccionado.');
      }
      $st->close();
    }
    header("Location: cobros.php");
    exit;
  }

  // 4) Agregar movimientos pendientes de tratamiento al carrito
  if ($act==='add_movs' && $POS['tratamiento_id']) {
    $movs = $_POST['mov_ids'] ?? [];
    if (is_array($movs) && count($movs)) {
      $in = implode(',', array_map('intval', $movs));
      $sql = "SELECT id, concepto, cantidad, precio_unit, total
              FROM tratamiento_movs
              WHERE id IN ($in)
                AND tratamiento_id=".$POS['tratamiento_id']."
                AND pagado=0";
      if ($r = $conn->query($sql)) {
        $added = 0;
        while($m = $r->fetch_assoc()){
          $POS['carrito'][] = [
            'tipo'        => 'mov',
            'servicio_id' => null,
            'mov_id'      => (int)$m['id'],
            'desc'        => $m['concepto'],
            'cantidad'    => (int)$m['cantidad'],
            'precio'      => (float)$m['precio_unit'],
            'total'       => (float)$m['total'],
          ];
          $added++;
        }
        $r->free();
        if ($added>0) flash('ok', "Se agregaron $added cargos del tratamiento al carrito.");
        else flash('err','No había cargos pendientes válidos para agregar.');
      }
    }
    header("Location: cobros.php");
    exit;
  }

  // 5) Quitar una línea del carrito
  if ($act==='remove_item') {
    $idx = (int)($_POST['idx'] ?? -1);
    if (isset($POS['carrito'][$idx])) {
      array_splice($POS['carrito'], $idx, 1);
    }
    header("Location: cobros.php");
    exit;
  }

  // 6) Vaciar carrito
  if ($act==='vaciar') {
    $POS['carrito']        = [];
    $POS['tratamiento_id'] = null;
    header("Location: cobros.php");
    exit;
  }

  // 7) Cobrar / registrar venta
  if ($act==='cobrar') {
    if (!$POS['paciente_id']) {
      flash('err','Selecciona un paciente antes de cobrar.');
      header("Location: cobros.php"); exit;
    }
    if (empty($POS['carrito'])) {
      flash('err','El carrito está vacío.');
      header("Location: cobros.php"); exit;
    }

    // Verificar paciente sigue existiendo
    $chk = $conn->prepare("SELECT id FROM pacientes WHERE id=?");
    $chk->bind_param("i", $POS['paciente_id']);
    $chk->execute(); $rs = $chk->get_result(); $pacOK = (bool)$rs->fetch_assoc(); $chk->close();
    if (!$pacOK) {
      flash('err','El paciente ya no existe. Vuelve a seleccionarlo.');
      header("Location: cobros.php"); exit;
    }

    // Verificar empleado
    $empleado_id = (int)usuario_id();
    $chk = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol IN ('admin','empleado')");
    $chk->bind_param("i", $empleado_id);
    $chk->execute(); $rs = $chk->get_result(); $empOK = (bool)$rs->fetch_assoc(); $chk->close();
    if (!$empOK) {
      flash('err','Tu sesión no corresponde a un usuario válido (admin/empleado). Vuelve a iniciar sesión.');
      header("Location: cobros.php"); exit;
    }

    $metodo = $_POST['metodo'] ?? 'efectivo';
    if (!in_array($metodo, ['efectivo','transferencia'], true)) $metodo = 'efectivo';

    // Calcular total carrito
    $total = 0.0;
    foreach($POS['carrito'] as $li) $total += (float)$li['total'];
    $total = (float)money($total);

    $pagado_con = (float)($_POST['pagado_con'] ?? 0);
    $cambio     = 0.0;
    $estado     = 'pagado';

    if ($metodo==='efectivo') {
      if ($pagado_con < $total) {
        flash('err','El efectivo no alcanza para el total.');
        header("Location: cobros.php"); exit;
      }
      $cambio = $pagado_con - $total;
    } else {
      $estado     = 'pendiente';
      $pagado_con = 0;
      $cambio     = 0;
    }

    // Insertar venta
    $codigo = codigo_venta();
    $st = $conn->prepare("INSERT INTO ventas(codigo, paciente_id, empleado_id, total, pagado_con, cambio, metodo_pago, estado)
                          VALUES (?,?,?,?,?,?,?,?)");
    if (!$st) {
      flash('err','No se pudo preparar la venta: '.e($conn->error));
      header("Location: cobros.php"); exit;
    }
    $st->bind_param("siiiddss", $codigo, $POS['paciente_id'], $empleado_id, $total, $pagado_con, $cambio, $metodo, $estado);
    if (!$st->execute()) {
      $err = $st->error ?: $conn->error;
      $st->close();
      flash('err','No se pudo registrar la venta: '.e($err));
      header("Location: cobros.php"); exit;
    }
    $venta_id = $st->insert_id;
    $st->close();

    // Insertar detalle
    foreach($POS['carrito'] as $li){
      $serv_id = ($li['tipo']==='serv') ? (int)$li['servicio_id'] : null;
      $desc    = $li['desc'];
      $cant    = (int)$li['cantidad'];
      $precio  = (float)$li['precio'];
      $tot     = (float)$li['total'];

      $ins = $conn->prepare("INSERT INTO venta_detalle(venta_id, servicio_id, descripcion, cantidad, precio_unit, total)
                             VALUES (?,?,?,?,?,?)");
      if (!$ins) {
        $err = $conn->error;
        flash('err','No se pudo preparar el detalle de venta: '.e($err));
        header("Location: cobros.php"); exit;
      }

      if ($serv_id === null) {
        $null = null;
        $ins->bind_param("iisidd", $venta_id, $null, $desc, $cant, $precio, $tot);
      } else {
        $ins->bind_param("iisidd", $venta_id, $serv_id, $desc, $cant, $precio, $tot);
      }

      if (!$ins->execute()) {
        $err = $ins->error ?: $conn->error;
        $ins->close();
        flash('err','No se pudo insertar detalle: '.e($err));
        header("Location: cobros.php"); exit;
      }
      $ins->close();
    }

    // Marcar movimientos de tratamiento como pagados
    $mov_ids = array_values(
      array_filter(
        array_map(fn($li)=> $li['tipo']==='mov' ? (int)$li['mov_id'] : 0, $POS['carrito']),
        fn($x)=>$x>0
      )
    );
    if (count($mov_ids)) {
      $in = implode(',', array_map('intval',$mov_ids));
      $conn->query("UPDATE tratamiento_movs SET pagado=1 WHERE id IN ($in)");
    }

    // Guardar ticket en sesión
    $_SESSION['last_ticket'] = [
      'venta_id'   => $venta_id,
      'codigo'     => $codigo,
      'paciente'   => $POS['paciente_nombre'],
      'metodo'     => $metodo,
      'estado'     => $estado,
      'total'      => $total,
      'pagado_con' => $pagado_con,
      'cambio'     => $cambio,
      'lineas'     => $POS['carrito'],
    ];

    // Limpiar POS parcial
    $POS['carrito']        = [];
    $POS['tratamiento_id'] = null;

    flash('ok', $estado==='pagado'
      ? 'Venta registrada y pagada.'
      : 'Venta registrada como pendiente (transferencia).');
    header("Location: cobros.php?ticket=1");
    exit;
  }
}

// ===== Datos para UI (GET) =====

// Pacientes para combo
$pacientes = [];
if ($r = $conn->query("SELECT id, nombre, telefono FROM pacientes ORDER BY nombre LIMIT 500")) {
  while($row = $r->fetch_assoc()) $pacientes[] = $row;
  $r->free();
}

// Servicios activos para combo
$servicios = [];
if ($r = $conn->query("SELECT id, nombre, precio, tipo FROM servicios WHERE activo=1 ORDER BY tipo, nombre")) {
  while($row = $r->fetch_assoc()) $servicios[] = $row;
  $r->free();
}

// Tratamientos abiertos del paciente + movimientos pendientes
$tratamientos = [];
$mov_pend = [];
if ($POS['paciente_id']) {
  $st = $conn->prepare("SELECT t.id, s.nombre AS tratamiento, t.mensualidad
                        FROM tratamientos t
                        INNER JOIN servicios s ON s.id=t.servicio_id
                        WHERE t.paciente_id=? AND t.estado='abierto'
                        ORDER BY t.id DESC");
  $st->bind_param("i",$POS['paciente_id']);
  $st->execute();
  $res = $st->get_result();
  while($row = $res->fetch_assoc()) $tratamientos[] = $row;
  $st->close();

  if ($POS['tratamiento_id']) {
    $tid = (int)$POS['tratamiento_id'];
    if ($r = $conn->query("SELECT id, concepto, cantidad, precio_unit, total, DATE_FORMAT(fecha,'%Y-%m-%d') AS fecha
                           FROM tratamiento_movs
                           WHERE tratamiento_id=$tid AND pagado=0
                           ORDER BY fecha DESC, id DESC")) {
      while($m = $r->fetch_assoc()) $mov_pend[] = $m;
      $r->free();
    }
  }
}

// Subtotal carrito
$subtotal = 0.0;
foreach($POS['carrito'] as $li) $subtotal += (float)$li['total'];

// Flashes y ticket
$ok     = flash('ok');
$err    = flash('err');
$ticket = null;
if (isset($_GET['ticket']) && !empty($_SESSION['last_ticket'])) {
  $ticket = $_SESSION['last_ticket'];
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Cobros | BETANDENT</title>
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
    --ink:#e5e7eb;
    --muted:#9ca3af;
    --bg:#020617;
    --card:#020617;
    --border-soft:rgba(148,163,184,.35);
    --shadow:0 22px 60px rgba(15,23,42,.9);
    --radius-xl:22px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{
    font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;
    min-height:100vh;
    color:var(--ink);
    background:
      radial-gradient(circle at 0% 0%,rgba(56,189,248,.25) 0,transparent 55%),
      radial-gradient(circle at 100% 0%,rgba(168,85,247,.28) 0,transparent 50%),
      radial-gradient(circle at 0% 100%,rgba(34,197,94,.2) 0,transparent 45%),
      #020617;
  }
  a{text-decoration:none;color:inherit}

  .shell{
    max-width:1250px;
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
    background:rgba(15,23,42,.96);
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
    width:32px;height:32px;
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
    color:var(--muted);
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
    background:rgba(15,23,42,.96);
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
    padding:7px 14px;
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
    color:var(--ink);
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-soft:hover{
    border-color:#e5e7eb;
  }
  .btn-ghost{
    background:transparent;
    color:var(--ink);
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
    box-shadow:0 10px 24px rgba(248,113,113,.75);
  }
  .btn-danger:hover{ transform:translateY(-1px); }
  .btn-secondary{
    background:rgba(55,65,81,.95);
    color:#f9fafb;
    border:1px solid rgba(156,163,175,.9);
  }

  .page-head{
    margin-top:20px;
    margin-bottom:14px;
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
    background:rgba(15,23,42,.96);
    border:1px solid rgba(148,163,184,.55);
    font-size:.75rem;
    color:#cbd5f5;
  }
  .page-sub{
    font-size:.8rem;
    color:var(--muted);
    margin-top:4px;
  }

  .alerts{margin-bottom:12px;}
  .alert{
    padding:9px 13px;
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
    grid-template-columns:1.2fr 1.1fr 0.9fr;
    gap:16px;
  }
  @media (max-width:1120px){
    .layout{grid-template-columns:1fr;}
  }

  .card{
    background:radial-gradient(circle at top left,rgba(56,189,248,.18) 0,rgba(15,23,42,.98) 55%);
    border-radius:var(--radius-xl);
    padding:14px 14px 12px;
    border:1px solid var(--border-soft);
    box-shadow:var(--shadow);
  }
  .card-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:10px;
    margin-bottom:8px;
  }
  .card-title{
    font-size:1rem;
    font-weight:600;
  }
  .card-sub{
    font-size:.78rem;
    color:var(--muted);
    margin-top:2px;
  }

  form label{
    display:block;
    margin-top:9px;
    margin-bottom:2px;
    font-size:.78rem;
    color:var(--ink);
    font-weight:500;
  }
  input[type="text"],
  input[type="number"],
  select{
    width:100%;
    padding:9px 10px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.7);
    background:rgba(15,23,42,.96);
    color:var(--ink);
    font-size:.85rem;
  }
  input:focus,select:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 0 1px rgba(56,189,248,.8);
  }

  .row{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
  }
  .muted{color:var(--muted);font-size:.78rem;}

  table{
    width:100%;
    border-collapse:collapse;
    margin-top:8px;
    font-size:.78rem;
  }
  thead{
    background:rgba(15,23,42,.98);
  }
  th,td{
    padding:7px 8px;
    text-align:left;
    border-bottom:1px solid rgba(30,64,175,.9);
    vertical-align:top;
  }
  th{
    font-size:.72rem;
    font-weight:600;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  tbody tr:nth-child(even){background:rgba(15,23,42,.9);}
  tbody tr:nth-child(odd){background:rgba(15,23,42,.84);}
  tbody tr:hover{background:rgba(56,189,248,.22);}

  .money{
    font-variant-numeric:tabular-nums;
    font-weight:700;
  }

  .ticket-box{
    margin-top:14px;
  }
  .ticket{
    font-family:ui-monospace,monospace;
    background:rgba(15,23,42,1);
    border-radius:18px;
    padding:12px 14px;
    border:1px dashed rgba(148,163,184,.8);
  }
  .ticket hr{
    border:none;
    border-top:1px dashed rgba(75,85,99,.9);
    margin:6px 0;
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
    background:rgba(15,23,42,.96);
  }

  .kbd{
    font-family:ui-monospace,monospace;
    background:rgba(15,23,42,.96);
    border:1px solid rgba(148,163,184,.7);
    border-radius:999px;
    padding:2px 8px;
    font-size:.75rem;
    color:#e5e7eb;
  }
</style>

<div class="shell">
  <header class="topbar">
    <div class="topbrand">
      <div class="top-logo">💳</div>
      <div>
        <div class="top-text-main">BETANDENT</div>
        <div class="top-text-sub">Cobros y mostrador</div>
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
        Punto de cobro
        <span class="page-pill">Servicios · Tratamientos · Ticket</span>
      </div>
      <p class="page-sub">
        Selecciona paciente, agrega servicios o cargos de tratamiento y registra el pago en un solo flujo.
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

  <?php if($ticket): ?>
    <section class="ticket-box card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Ticket reciente</h2>
          <p class="card-sub">Resumen de la última venta registrada en este equipo.</p>
        </div>
        <span class="pill">Código <?= e($ticket['codigo']) ?></span>
      </div>
      <div class="ticket">
        <div><strong>Estado:</strong> <?= e($ticket['estado']) ?> · <strong>Método:</strong> <?= e($ticket['metodo']) ?></div>
        <div><strong>Paciente:</strong> <?= e($ticket['paciente']) ?></div>
        <hr>
        <?php foreach($ticket['lineas'] as $li): ?>
          <div>
            <?= e($li['desc']) ?> × <?= (int)$li['cantidad'] ?>
            @ $<?= number_format((float)$li['precio'],2) ?>
            = <span class="money">$<?= number_format((float)$li['total'],2) ?></span>
          </div>
        <?php endforeach; ?>
        <hr>
        <div><strong>Total:</strong> $<?= number_format((float)$ticket['total'],2) ?></div>
        <?php if($ticket['metodo']==='efectivo'): ?>
          <div><strong>Efectivo:</strong> $<?= number_format((float)$ticket['pagado_con'],2) ?></div>
          <div><strong>Cambio:</strong> $<?= number_format((float)$ticket['cambio'],2) ?></div>
        <?php else: ?>
          <div class="muted">Venta registrada como pendiente de comprobante de transferencia.</div>
        <?php endif; ?>
        <div class="muted" style="margin-top:4px;">Fecha impresión: <?= date('Y-m-d H:i') ?></div>
      </div>
      <div class="row" style="margin-top:10px">
        <button class="btn btn-soft" onclick="window.print()">Imprimir</button>
        <a class="btn btn-primary" href="cobros.php">Nueva venta</a>
      </div>
    </section>
  <?php endif; ?>

  <section class="layout">
    <!-- Columna 1: Paciente + tratamientos -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">1 · Paciente y tratamientos</h2>
          <p class="card-sub">Selecciona al paciente y, si aplica, un tratamiento ortodóntico abierto.</p>
        </div>
        <?php if($POS['paciente_id']): ?>
          <span class="pill">
            Actual: <?= e($POS['paciente_nombre']) ?>
          </span>
        <?php endif; ?>
      </div>

      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="select_paciente">
        <select name="paciente_id" required style="min-width:260px">
          <option value="">— seleccionar paciente —</option>
          <?php foreach($pacientes as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= ($POS['paciente_id']==(int)$p['id']?'selected':'') ?>>
              <?= e($p['nombre']) ?> (ID <?= (int)$p['id'] ?>)<?= $p['telefono'] ? ' · '.e($p['telefono']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-soft">Usar</button>
      </form>

      <hr style="border:none;border-top:1px solid rgba(51,65,85,.9);margin:10px 0 8px">

      <h3 class="card-title" style="font-size:.9rem;">Tratamientos abiertos</h3>
      <?php if(!$POS['paciente_id']): ?>
        <p class="muted">Selecciona un paciente para ver sus tratamientos.</p>
      <?php elseif(empty($tratamientos)): ?>
        <p class="muted">Este paciente no tiene tratamientos abiertos registrados.</p>
      <?php else: ?>
        <form method="post" class="row" style="margin-top:6px">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="select_tratamiento">
          <select name="tratamiento_id" style="min-width:260px">
            <option value="">— seleccionar tratamiento —</option>
            <?php foreach($tratamientos as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= ($POS['tratamiento_id']==(int)$t['id']?'selected':'') ?>>
                #<?= (int)$t['id'] ?> · <?= e($t['tratamiento']) ?> (Mensualidad: $<?= number_format((float)$t['mensualidad'],2) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-soft">Ver cargos pendientes</button>
        </form>

        <?php if($POS['tratamiento_id']): ?>
          <form method="post" style="margin-top:8px">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="act" value="add_movs">
            <?php if(empty($mov_pend)): ?>
              <p class="muted">No hay cargos pendientes en este tratamiento.</p>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th></th><th>Fecha</th><th>Concepto</th><th>Cant</th><th>PU</th><th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($mov_pend as $m): ?>
                    <tr>
                      <td><input type="checkbox" name="mov_ids[]" value="<?= (int)$m['id'] ?>"></td>
                      <td><?= e($m['fecha']) ?></td>
                      <td><?= e($m['concepto']) ?></td>
                      <td><?= (int)$m['cantidad'] ?></td>
                      <td class="money">$<?= number_format((float)$m['precio_unit'],2) ?></td>
                      <td class="money">$<?= number_format((float)$m['total'],2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <button class="btn btn-primary" style="margin-top:8px">Agregar seleccionados al carrito</button>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </article>

    <!-- Columna 2: Catálogo de servicios -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">2 · Servicios rápidos</h2>
          <p class="card-sub">Agrega consultas, limpiezas, placas u otros servicios configurados en el catálogo.</p>
        </div>
      </div>

      <form method="post" class="row" style="margin-top:4px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="add_servicio">
        <select name="servicio_id" required style="min-width:280px">
          <option value="">— seleccionar servicio —</option>
          <?php foreach($servicios as $s): ?>
            <option value="<?= (int)$s['id'] ?>">
              [<?= e(ucfirst($s['tipo'])) ?>] <?= e($s['nombre']) ?> — $<?= number_format((float)$s['precio'],2) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="cantidad" min="1" value="1" style="max-width:110px">
        <button class="btn btn-soft">Agregar</button>
      </form>
      <p class="muted" style="margin-top:6px">
        Para mensualidades, tubos y brackets usa la sección de <strong>Tratamientos abiertos</strong>.
      </p>
    </article>

    <!-- Columna 3: Carrito + pago -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">3 · Carrito y pago</h2>
          <p class="card-sub">Revisa los conceptos, confirma el total y registra el cobro.</p>
        </div>
        <span class="pill">Total actual: $<?= number_format($subtotal,2) ?></span>
      </div>

      <h3 class="card-title" style="font-size:.9rem;margin-bottom:4px;">Carrito</h3>
      <?php if(empty($POS['carrito'])): ?>
        <p class="muted">Todavía no hay servicios ni cargos agregados.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Concepto</th>
              <th>Cant</th>
              <th>PU</th>
              <th>Total</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($POS['carrito'] as $i=>$li): ?>
              <tr>
                <td>
                  <?= e($li['desc']) ?>
                  <?php if($li['tipo']==='mov'): ?>
                    <span class="muted">(tratamiento)</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$li['cantidad'] ?></td>
                <td class="money">$<?= number_format((float)$li['precio'],2) ?></td>
                <td class="money">$<?= number_format((float)$li['total'],2) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('¿Quitar este concepto del carrito?');">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="remove_item">
                    <input type="hidden" name="idx" value="<?= (int)$i ?>">
                    <button class="btn btn-danger">✕</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="row" style="justify-content:space-between;margin-top:8px">
          <strong class="money">Total: $<?= number_format($subtotal,2) ?></strong>
          <form method="post" onsubmit="return confirm('¿Vaciar todo el carrito?');">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="act" value="vaciar">
            <button class="btn btn-secondary">Vaciar</button>
          </form>
        </div>
      <?php endif; ?>

      <hr style="border:none;border-top:1px solid rgba(51,65,85,.9);margin:12px 0 10px">

      <h3 class="card-title" style="font-size:.9rem;margin-bottom:4px;">Pago</h3>
      <form method="post" oninput="calcCambio()">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="cobrar">

        <label for="metodo">Método de pago</label>
        <select name="metodo" id="metodo" onchange="toggleEfectivo()">
          <option value="efectivo">Efectivo</option>
          <option value="transferencia">Transferencia</option>
        </select>

        <div id="efectivo_wrap">
          <label for="pagado_con">Monto recibido</label>
          <input type="number" name="pagado_con" id="pagado_con" min="0" step="0.01" value="0.00">
          <div class="muted" style="margin-top:3px;">
            Cambio estimado: <span id="cambio_show">$0.00</span>
          </div>
        </div>

        <div class="row" style="margin-top:10px">
          <button class="btn btn-primary" <?= empty($POS['carrito'])?'disabled':'' ?>>
            Finalizar cobro
          </button>
        </div>
        <p class="muted" style="margin-top:6px">
          Si eliges <strong>transferencia</strong>, la venta queda en estado <em>pendiente</em> hasta confirmar el comprobante.
        </p>
      </form>
    </article>
  </section>
</div>

<script>
  const total = <?= json_encode((float)$subtotal) ?>;
  function calcCambio(){
    const metodo = document.getElementById('metodo').value;
    if(metodo==='efectivo'){
      const pc = parseFloat(document.getElementById('pagado_con').value || '0');
      const cambio = Math.max(0, pc - total).toFixed(2);
      document.getElementById('cambio_show').innerText = '$'+cambio;
    }
  }
  function toggleEfectivo(){
    const metodo = document.getElementById('metodo').value;
    const wrap = document.getElementById('efectivo_wrap');
    wrap.style.display = (metodo==='efectivo') ? '' : 'none';
  }
  toggleEfectivo();
  calcCambio();
</script>
