<?php
// servicios.php — BETANDENT (Catálogo de servicios/tratamientos)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin']); // Solo admin administra catálogo

$conn = db();

// ========= Helpers =========
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($key, $val=null){
  if ($val===null) { $v = $_SESSION[$key] ?? ''; unset($_SESSION[$key]); return $v; }
  $_SESSION[$key] = $val;
}

// CSRF mínimo
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

function check_csrf(){
  if (empty($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    http_response_code(400);
    die("<h2>Solicitud inválida</h2><p>Token CSRF incorrecto.</p>");
  }
}

// ========= Acciones =========
$act = $_POST['act'] ?? $_GET['act'] ?? '';
$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD']==='POST') {
  check_csrf();

  if ($act === 'crear') {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio = trim($_POST['precio'] ?? '0');
    $tipo = $_POST['tipo'] ?? 'servicio';
    $activo = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '') {
      flash('err', 'El nombre es obligatorio.');
    } elseif (!in_array($tipo, ['servicio','tratamiento'], true)) {
      flash('err', 'Tipo inválido.');
    } elseif (!is_numeric($precio) || (float)$precio < 0) {
      flash('err', 'Precio inválido.');
    } else {
      $precio = number_format((float)$precio, 2, '.', '');
      $st = $conn->prepare("INSERT INTO servicios(nombre, descripcion, precio, tipo, activo) VALUES (?,?,?,?,?)");
      $st->bind_param("ssdsi", $nombre, $descripcion, $precio, $tipo, $activo);
      if ($st->execute()) { flash('ok','Servicio agregado.'); }
      else {
        // Puede chocar con UNIQUE(nombre)
        flash('err','No se pudo agregar: '.e($conn->error));
      }
      $st->close();
    }
    header("Location: servicios.php");
    exit;
  }

  if ($act === 'actualizar' && $id>0) {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio = trim($_POST['precio'] ?? '0');
    $tipo = $_POST['tipo'] ?? 'servicio';
    $activo = isset($_POST['activo']) ? 1 : 0;

    if ($nombre === '') {
      flash('err', 'El nombre es obligatorio.');
    } elseif (!in_array($tipo, ['servicio','tratamiento'], true)) {
      flash('err', 'Tipo inválido.');
    } elseif (!is_numeric($precio) || (float)$precio < 0) {
      flash('err', 'Precio inválido.');
    } else {
      $precio = number_format((float)$precio, 2, '.', '');
      $st = $conn->prepare("UPDATE servicios SET nombre=?, descripcion=?, precio=?, tipo=?, activo=? WHERE id=?");
      $st->bind_param("ssdsii", $nombre, $descripcion, $precio, $tipo, $activo, $id);
      if ($st->execute()) { flash('ok','Servicio actualizado.'); }
      else { flash('err','No se pudo actualizar: '.e($conn->error)); }
      $st->close();
    }
    header("Location: servicios.php");
    exit;
  }

  // En lugar de borrar duro, permitimos activar/desactivar para evitar choques con llaves foráneas
  if ($act === 'desactivar' && $id>0) {
    $st = $conn->prepare("UPDATE servicios SET activo=0 WHERE id=?");
    $st->bind_param("i",$id);
    if ($st->execute()) flash('ok','Servicio desactivado.');
    else flash('err','No se pudo desactivar: '.e($conn->error));
    $st->close();
    header("Location: servicios.php");
    exit;
  }

  if ($act === 'activar' && $id>0) {
    $st = $conn->prepare("UPDATE servicios SET activo=1 WHERE id=?");
    $st->bind_param("i",$id);
    if ($st->execute()) flash('ok','Servicio activado.');
    else flash('err','No se pudo activar: '.e($conn->error));
    $st->close();
    header("Location: servicios.php");
    exit;
  }

  // Borrado definitivo opcional (puede fallar si hay tratamientos ligados por RESTRICT)
  if ($act === 'eliminar' && $id>0) {
    $st = $conn->prepare("DELETE FROM servicios WHERE id=?");
    $st->bind_param("i",$id);
    if ($st->execute()) {
      flash('ok','Servicio eliminado.');
    } else {
      // Si falla por FK, avisamos y sugerimos desactivar
      flash('err','No se pudo eliminar (posibles referencias). Puedes desactivarlo. Detalle: '.e($conn->error));
    }
    $st->close();
    header("Location: servicios.php");
    exit;
  }
}

// ========= Carga de datos =========
// Edición
$editRow = null;
if ($act==='editar' && $id>0) {
  $st = $conn->prepare("SELECT id, nombre, descripcion, precio, tipo, activo FROM servicios WHERE id=?");
  $st->bind_param("i",$id);
  $st->execute();
  $res = $st->get_result();
  $editRow = $res->fetch_assoc() ?: null;
  $st->close();
  if (!$editRow) {
    flash('err','Servicio no encontrado.');
    header("Location: servicios.php");
    exit;
  }
}

// Filtros/búsqueda
$q = trim($_GET['q'] ?? '');
$f_tipo = $_GET['tipo'] ?? 'todos';       // todos | servicio | tratamiento
$f_activo = $_GET['estado'] ?? 'todos';   // todos | activos | inactivos

$where = [];
$params = [];
$types = '';

if ($q !== '') {
  $where[] = "(nombre LIKE CONCAT('%',?,'%') OR descripcion LIKE CONCAT('%',?,'%'))";
  $params[] = $q; $params[] = $q; $types .= "ss";
}
if (in_array($f_tipo, ['servicio','tratamiento'], true)) {
  $where[] = "tipo=?";
  $params[] = $f_tipo; $types .= "s";
}
if ($f_activo === 'activos') {
  $where[] = "activo=1";
} elseif ($f_activo === 'inactivos') {
  $where[] = "activo=0";
}

$sqlList = "SELECT id, nombre, descripcion, precio, tipo, activo
            FROM servicios
            ".(count($where)?'WHERE '.implode(' AND ', $where):'')."
            ORDER BY tipo, nombre
            LIMIT 300";

$rows = [];
if ($types !== '') {
  $st = $conn->prepare($sqlList);
  $st->bind_param($types, ...$params);
  $st->execute();
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) $rows[] = $r;
  $st->close();
} else {
  if ($res = $conn->query($sqlList)) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
  }
}

$ok  = flash('ok');
$err = flash('err');
?>
<!doctype html>
<meta charset="utf-8">
<title>Servicios | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --brand:#0ea5e9;
    --brand-dark:#0284c7;
    --accent:#a855f7;
    --accent-soft:#f5f3ff;
    --danger:#ef4444;
    --danger-soft:#fef2f2;
    --ink:#020617;
    --muted:#6b7280;
    --bg:#0b1120;
    --bg-soft:#020617;
    --card:#020617;
    --card-soft:#0f172a;
    --shadow:0 22px 60px rgba(15,23,42,.85);
    --radius-xl:22px;
    --radius:14px;
    --border-soft:rgba(148,163,184,.35);
  }

  *{box-sizing:border-box;margin:0;padding:0}
  body{
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
    min-height:100vh;
    color:#e5e7eb;
    background:
      radial-gradient(circle at 0% 0%,rgba(56,189,248,.3) 0,transparent 55%),
      radial-gradient(circle at 100% 0%,rgba(168,85,247,.35) 0,transparent 50%),
      radial-gradient(circle at 0% 100%,rgba(34,197,94,.22) 0,transparent 45%),
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
    background:rgba(15,23,42,.9);
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
    gap:12px;
  }
  .top-logo{
    width:32px;
    height:32px;
    border-radius:13px;
    background:
      radial-gradient(circle at 30% 30%,#f9fafb 0, #e0f2fe 28%, transparent 55%),
      radial-gradient(circle at 80% 80%,#67e8f9 0, #22c55e 40%, transparent 70%);
    box-shadow:0 0 0 3px rgba(8,47,73,.7);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.1rem;
  }
  .top-logo span{
    filter:drop-shadow(0 2px 4px rgba(15,23,42,.5));
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
    background:linear-gradient(135deg,var(--brand),var(--accent));
    color:white;
    box-shadow:0 12px 30px rgba(14,165,233,.6);
  }
  .btn-primary:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 40px rgba(59,130,246,.75);
  }
  .btn-ghost{
    background:transparent;
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-ghost:hover{
    background:rgba(15,23,42,.95);
    border-color:#e5e7eb;
  }
  .btn-soft{
    background:rgba(15,23,42,.95);
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-soft:hover{
    border-color:#e5e7eb;
  }
  .btn-danger{
    background:linear-gradient(135deg,#ef4444,#b91c1c);
    color:#fee2e2;
    border:none;
    box-shadow:0 12px 26px rgba(248,113,113,.7);
  }
  .btn-danger:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 36px rgba(248,113,113,.9);
  }

  .page-head{
    margin-top:22px;
    margin-bottom:18px;
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

  .alerts{
    margin-bottom:10px;
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
    background:rgba(22,163,74,.16);
    border-color:rgba(34,197,94,.7);
    color:#bbf7d0;
  }
  .alert.ok .alert-icon{
    background:rgba(34,197,94,.3);
  }
  .alert.err{
    background:rgba(248,113,113,.16);
    border-color:rgba(248,113,113,.85);
    color:#fee2e2;
  }
  .alert.err .alert-icon{
    background:rgba(239,68,68,.4);
  }

  .layout{
    display:grid;
    grid-template-columns:0.9fr 1.6fr;
    gap:16px;
  }
  @media (max-width:1040px){
    .layout{grid-template-columns:1fr;}
  }

  .card{
    background:radial-gradient(circle at top left,rgba(56,189,248,.22) 0,rgba(15,23,42,.98) 55%);
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
    margin-bottom:12px;
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
    background:rgba(15,23,42,.92);
    color:#e5e7eb;
    font-size:.85rem;
  }
  input:focus,
  textarea:focus,
  select:focus{
    outline:none;
    border-color:#38bdf8;
    box-shadow:0 0 0 1px rgba(56,189,248,.75);
  }
  textarea{
    resize:vertical;
    min-height:80px;
  }
  .checkbox-inline{
    display:flex;
    align-items:center;
    gap:8px;
    margin-top:10px;
    font-size:.8rem;
  }
  .checkbox-inline input{
    width:auto;
    accent-color:#22c55e;
  }
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
  .filters input[type="text"]{
    max-width:220px;
  }

  .services-list{
    margin-top:6px;
  }
  .service-row{
    display:grid;
    grid-template-columns:0.6fr 0.5fr 0.45fr 0.6fr;
    gap:10px;
    padding:10px 10px;
    border-radius:16px;
    background:rgba(15,23,42,.84);
    border:1px solid rgba(30,64,175,.9);
    margin-bottom:8px;
    position:relative;
    overflow:hidden;
  }
  @media (max-width:1024px){
    .service-row{
      grid-template-columns:1fr;
    }
  }
  .service-row::before{
    content:'';
    position:absolute;
    inset:auto -40px 0 auto;
    width:120px;
    height:120px;
    background:radial-gradient(circle at 30% 30%,rgba(56,189,248,.4) 0,transparent 60%);
    opacity:.4;
    pointer-events:none;
  }
  .service-main{
    position:relative;
    z-index:1;
  }
  .service-name{
    font-weight:600;
    font-size:.95rem;
  }
  .service-desc{
    font-size:.78rem;
    color:#9ca3af;
    margin-top:3px;
    max-height:4.2em;
    overflow:hidden;
  }
  .service-meta{
    position:relative;
    z-index:1;
    display:flex;
    flex-direction:column;
    gap:6px;
    font-size:.8rem;
  }
  .service-meta-line{
    display:flex;
    align-items:center;
    gap:6px;
  }
  .meta-label{
    color:#9ca3af;
  }
  .price-tag{
    font-size:1rem;
    font-weight:700;
  }
  .status-chip{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    font-size:.75rem;
    font-weight:500;
  }
  .status-chip span:first-child{
    width:7px;height:7px;border-radius:999px;
  }
  .status-active{
    background:rgba(22,163,74,.16);
    border:1px solid rgba(34,197,94,.8);
    color:#bbf7d0;
  }
  .status-active span:first-child{background:#22c55e;}
  .status-inactive{
    background:rgba(148,163,184,.16);
    border:1px solid rgba(148,163,184,.8);
    color:#e5e7eb;
  }
  .status-inactive span:first-child{background:#9ca3af;}

  .type-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:3px 9px;
    border-radius:999px;
    font-size:.75rem;
    font-weight:500;
  }
  .type-servicio{
    background:rgba(56,189,248,.16);
    border:1px solid rgba(56,189,248,.9);
    color:#e0f2fe;
  }
  .type-tratamiento{
    background:rgba(168,85,247,.16);
    border:1px solid rgba(168,85,247,.9);
    color:#f3e8ff;
  }

  .service-actions{
    position:relative;
    z-index:1;
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    justify-content:flex-end;
    align-items:flex-start;
  }
  .service-actions form{
    margin:0;
  }

  .empty-list{
    margin-top:10px;
    padding:18px 14px;
    border-radius:16px;
    border:1px dashed rgba(148,163,184,.7);
    background:rgba(15,23,42,.86);
    font-size:.8rem;
    color:#9ca3af;
    text-align:center;
  }
</style>

<div class="shell">
  <header class="topbar">
    <div class="topbrand">
      <div class="top-logo"><span>🦷</span></div>
      <div>
        <div class="top-text-main">BETANDENT</div>
        <div class="top-text-sub">Catálogo de servicios y tratamientos</div>
      </div>
    </div>
    <div class="top-actions">
      <div class="badge-chip">
        <span></span>
        <span>Solo administración</span>
      </div>
      <a href="panel.php" class="btn btn-ghost">Volver al panel</a>
    </div>
  </header>

  <div class="page-head">
    <div>
      <div class="page-title">
        Servicios
        <span class="page-pill">Máx. 300 registros</span>
      </div>
      <p class="page-sub">
        Administra los servicios y tratamientos disponibles para agendar y facturar en BETANDENT.
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

  <section class="layout">
    <!-- Columna izquierda: crear/editar -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">
            <?= $editRow ? 'Editar servicio' : 'Nuevo servicio' ?>
          </h2>
          <p class="card-sub">
            <?= $editRow
              ? 'Ajusta la información de un servicio ya existente.'
              : 'Da de alta un servicio o tratamiento disponible en la clínica.' ?>
          </p>
        </div>
        <span class="badge-chip">
          <span></span>
          <span><?= $editRow ? 'Modo edición' : 'Modo alta' ?></span>
        </span>
      </div>

      <?php if ($editRow): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="actualizar">
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">

          <label for="nombre">Nombre *</label>
          <input id="nombre" name="nombre" value="<?= e($editRow['nombre']) ?>" required>

          <label for="descripcion">Descripción</label>
          <textarea id="descripcion" name="descripcion" rows="3"><?= e($editRow['descripcion']) ?></textarea>

          <label for="precio">Precio (MXN) *</label>
          <input id="precio" name="precio" type="number" min="0" step="0.01"
                 value="<?= e(number_format((float)$editRow['precio'],2,'.','')) ?>" required>

          <label for="tipo">Tipo *</label>
          <select id="tipo" name="tipo" required>
            <option value="servicio" <?= $editRow['tipo']==='servicio'?'selected':'' ?>>Servicio</option>
            <option value="tratamiento" <?= $editRow['tipo']==='tratamiento'?'selected':'' ?>>Tratamiento</option>
          </select>

          <label class="checkbox-inline">
            <input type="checkbox" name="activo" <?= $editRow['activo'] ? 'checked' : '' ?>>
            <span>Activo en el catálogo</span>
          </label>

          <div class="form-footer">
            <button class="btn btn-primary">Guardar cambios</button>
            <a class="btn btn-soft" href="servicios.php">Cancelar</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="crear">

          <label for="nombre">Nombre *</label>
          <input id="nombre" name="nombre" required>

          <label for="descripcion">Descripción</label>
          <textarea id="descripcion" name="descripcion" rows="3"></textarea>

          <label for="precio">Precio (MXN) *</label>
          <input id="precio" name="precio" type="number" min="0" step="0.01" value="0.00" required>

          <label for="tipo">Tipo *</label>
          <select id="tipo" name="tipo" required>
            <option value="servicio">Servicio</option>
            <option value="tratamiento">Tratamiento</option>
          </select>

          <label class="checkbox-inline">
            <input type="checkbox" name="activo" checked>
            <span>Activo en el catálogo</span>
          </label>

          <div class="form-footer">
            <button class="btn btn-primary">Agregar</button>
          </div>
        </form>
      <?php endif; ?>

      <p class="muted" style="margin-top:10px;">
        Puedes desactivar servicios con historial para evitar problemas con llaves foráneas,
        sin perder su referencia en tratamientos antiguos.
      </p>
    </article>

    <!-- Columna derecha: filtros + lista -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Catálogo de servicios</h2>
          <p class="card-sub">
            Filtra por tipo y estado, y edita o desactiva lo que ya no se use en la clínica.
          </p>
        </div>
        <form class="filters" method="get" action="servicios.php">
          <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Buscar por nombre o descripción"
          >
          <select name="tipo">
            <option value="todos" <?= $f_tipo==='todos'?'selected':'' ?>>Todos los tipos</option>
            <option value="servicio" <?= $f_tipo==='servicio'?'selected':'' ?>>Servicio</option>
            <option value="tratamiento" <?= $f_tipo==='tratamiento'?'selected':'' ?>>Tratamiento</option>
          </select>
          <select name="estado">
            <option value="todos" <?= $f_activo==='todos'?'selected':'' ?>>Todos</option>
            <option value="activos" <?= $f_activo==='activos'?'selected':'' ?>>Activos</option>
            <option value="inactivos" <?= $f_activo==='inactivos'?'selected':'' ?>>Inactivos</option>
          </select>
          <button class="btn btn-soft" type="submit">Filtrar</button>
          <?php if ($q!=='' || $f_tipo!=='todos' || $f_activo!=='todos'): ?>
            <a class="btn btn-ghost" href="servicios.php">Limpiar</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="services-list">
        <?php if (empty($rows)): ?>
          <div class="empty-list">
            Sin servicios con los filtros actuales. Ajusta la búsqueda o agrega uno nuevo desde la columna izquierda.
          </div>
        <?php else: ?>
          <?php foreach($rows as $r): ?>
            <div class="service-row">
              <div class="service-main">
                <div class="service-name">
                  #<?= (int)$r['id'] ?> · <?= e($r['nombre']) ?>
                </div>
                <div class="service-desc">
                  <?= nl2br(e($r['descripcion'] ?: 'Sin descripción registrada.')) ?>
                </div>
              </div>

              <div class="service-meta">
                <div class="service-meta-line">
                  <span class="meta-label">Tipo</span>
                  <?php if ($r['tipo']==='servicio'): ?>
                    <span class="type-pill type-servicio">Servicio</span>
                  <?php else: ?>
                    <span class="type-pill type-tratamiento">Tratamiento</span>
                  <?php endif; ?>
                </div>

                <div class="service-meta-line">
                  <span class="meta-label">Precio</span>
                  <span class="price-tag">$<?= number_format((float)$r['precio'], 2) ?></span>
                </div>
              </div>

              <div class="service-meta">
                <div class="service-meta-line">
                  <span class="meta-label">Estado</span>
                  <?php if($r['activo']): ?>
                    <span class="status-chip status-active">
                      <span></span>
                      <span>Activo</span>
                    </span>
                  <?php else: ?>
                    <span class="status-chip status-inactive">
                      <span></span>
                      <span>Inactivo</span>
                    </span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="service-actions">
                <a class="btn btn-soft" href="servicios.php?act=editar&id=<?= (int)$r['id'] ?>">Editar</a>

                <?php if ($r['activo']): ?>
                  <form method="post" onsubmit="return confirm('¿Desactivar «<?= e($r['nombre']) ?>»?');">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="desactivar">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-ghost">Desactivar</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="activar">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-primary">Activar</button>
                  </form>
                <?php endif; ?>

                <form method="post" onsubmit="return confirm('Eliminar «<?= e($r['nombre']) ?>» definitivamente?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="act" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger">Eliminar</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <p class="muted" style="margin-top:8px">
        Recuerda: los servicios inactivos no deberían aparecer en agendas ni cotizaciones,
        pero siguen visibles aquí para mantener el historial limpio.
      </p>
    </article>
  </section>
</div>
