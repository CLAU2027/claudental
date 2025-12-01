<?php
// pacientes.php — BETANDENT (CRUD local)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // Paciente no entra aquí

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
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    if ($nombre === '') {
      flash('err', 'El nombre es obligatorio.');
    } else {
      $st = $conn->prepare("INSERT INTO pacientes(nombre, telefono, direccion) VALUES (?,?,?)");
      $st->bind_param("sss", $nombre, $telefono, $direccion);
      if ($st->execute()) {
        flash('ok','Paciente agregado correctamente.');
      } else {
        flash('err','No se pudo agregar: '.e($conn->error));
      }
      $st->close();
    }
    header("Location: pacientes.php");
    exit;
  }

  if ($act === 'actualizar' && $id>0) {
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    if ($nombre === '') {
      flash('err', 'El nombre es obligatorio.');
    } else {
      $st = $conn->prepare("UPDATE pacientes SET nombre=?, telefono=?, direccion=? WHERE id=?");
      $st->bind_param("sssi", $nombre, $telefono, $direccion, $id);
      if ($st->execute()) {
        flash('ok','Paciente actualizado.');
      } else {
        flash('err','No se pudo actualizar: '.e($conn->error));
      }
      $st->close();
    }
    header("Location: pacientes.php");
    exit;
  }

  if ($act === 'eliminar' && $id>0) {
    $st = $conn->prepare("DELETE FROM pacientes WHERE id=?");
    $st->bind_param("i", $id);
    if ($st->execute()) {
      flash('ok','Paciente eliminado.');
    } else {
      flash('err','No se pudo eliminar: '.e($conn->error));
    }
    $st->close();
    header("Location: pacientes.php");
    exit;
  }
}

// ========= Carga de datos =========
$editRow = null;
if ($act==='editar' && $id>0) {
  $st = $conn->prepare("SELECT id, nombre, telefono, direccion, creado_en FROM pacientes WHERE id=?");
  $st->bind_param("i", $id);
  $st->execute();
  $res = $st->get_result();
  $editRow = $res->fetch_assoc() ?: null;
  $st->close();
  if (!$editRow) {
    flash('err','Paciente no encontrado.');
    header("Location: pacientes.php");
    exit;
  }
}

// Listado con búsqueda simple
$q = trim($_GET['q'] ?? '');
$where = '';
$params = [];
$types = '';
if ($q !== '') {
  $where = "WHERE nombre LIKE CONCAT('%',?,'%') OR telefono LIKE CONCAT('%',?,'%') OR direccion LIKE CONCAT('%',?,'%')";
  $params = [$q,$q,$q];
  $types = "sss";
}

$sqlList = "SELECT id, nombre, telefono, direccion, DATE_FORMAT(creado_en, '%Y-%m-%d %H:%i') AS creado 
            FROM pacientes
            ".($where ? $where.' ' : '')."
            ORDER BY id DESC
            LIMIT 200";

$rows = [];
if ($where) {
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

// Flashes
$ok  = flash('ok');
$err = flash('err');

?>
<!doctype html>
<meta charset="utf-8">
<title>Pacientes (local) | BETANDENT</title>
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
    background:rgba(15,23,42,.85);
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

  .top-actions{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:.8rem;
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
  .btn-danger{
    background:linear-gradient(135deg,#ef4444,#b91c1c);
    color:#fee2e2;
    border:none;
    box-shadow:0 12px 25px rgba(239,68,68,.55);
  }
  .btn-danger:hover{
    transform:translateY(-1px);
    box-shadow:0 16px 36px rgba(248,113,113,.8);
  }
  .btn-soft{
    background:rgba(15,23,42,.9);
    color:#e5e7eb;
    border:1px solid rgba(148,163,184,.7);
  }
  .btn-soft:hover{
    border-color:#e5e7eb;
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
  }
  .page-sub{
    font-size:.8rem;
    color:#9ca3af;
    margin-top:4px;
  }

  .pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.7);
    font-size:.75rem;
    color:#cbd5f5;
    background:rgba(15,23,42,.8);
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

  .layout{
    display:grid;
    grid-template-columns:0.9fr 1.6fr;
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
  textarea{
    width:100%;
    padding:9px 11px;
    border-radius:12px;
    border:1px solid rgba(148,163,184,.7);
    background:rgba(15,23,42,.9);
    color:#e5e7eb;
    font-size:.85rem;
  }
  input[type="text"]:focus,
  textarea:focus{
    outline:none;
    border-color:#60a5fa;
    box-shadow:0 0 0 1px rgba(96,165,250,.7);
  }
  textarea{
    resize:vertical;
    min-height:70px;
  }

  .form-footer{
    margin-top:12px;
    display:flex;
    gap:8px;
    flex-wrap:wrap;
  }

  .muted{
    color:#9ca3af;
    font-size:.8rem;
  }
  .muted-center{
    text-align:center;
  }

  .search-bar{
    display:flex;
    gap:8px;
    flex:1;
    justify-content:flex-end;
    flex-wrap:wrap;
  }
  .search-bar input[type="text"]{
    max-width:260px;
  }

  table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    font-size:.8rem;
  }
  thead{
    background:rgba(15,23,42,.9);
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
    background:rgba(15,23,42,.8);
  }
  tbody tr:nth-child(odd){
    background:rgba(15,23,42,.7);
  }
  tbody tr:hover{
    background:rgba(59,130,246,.25);
  }
  td:nth-child(1){
    font-variant-numeric:tabular-nums;
    color:#e5e7eb;
  }
  td:nth-child(3),td:nth-child(5){
    font-variant-numeric:tabular-nums;
  }
  td .tag-empty{
    color:#6b7280;
    font-style:italic;
  }

  .actions{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
  }
  .actions form{display:inline-block;margin:0;}
</style>

<div class="shell">
  <header class="topbar">
    <div class="topbrand">
      <div class="topbrand-badge">BD</div>
      <div>
        <div>BETANDENT</div>
        <div class="top-title">Gestión de pacientes (local)</div>
      </div>
    </div>
    <div class="top-actions">
      <a href="panel.php" class="btn btn-ghost">Volver al panel</a>
    </div>
  </header>

  <div class="page-head">
    <div>
      <div class="page-title">Pacientes</div>
      <p class="page-sub">
        Alta, edición y búsqueda de pacientes registrados en el sistema local de BETANDENT.
      </p>
    </div>
    <div>
      <span class="pill">
        <span>Máx. 200 registros</span>
      </span>
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
            <?= $editRow ? 'Editar paciente' : 'Nuevo paciente' ?>
          </h2>
          <p class="card-sub">
            <?= $editRow ? 'Actualiza los datos del paciente seleccionado.' : 'Captura un nuevo paciente para el registro local.' ?>
          </p>
        </div>
        <span class="pill">
          <?= $editRow ? 'Modo edición' : 'Modo alta' ?>
        </span>
      </div>

      <?php if ($editRow): ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="actualizar">
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">

          <label for="nombre">Nombre *</label>
          <input id="nombre" name="nombre" value="<?= e($editRow['nombre']) ?>" required>

          <label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono" value="<?= e($editRow['telefono']) ?>">

          <label for="direccion">Dirección</label>
          <textarea id="direccion" name="direccion" rows="2"><?= e($editRow['direccion']) ?></textarea>

          <div class="form-footer">
            <button class="btn btn-primary">Guardar cambios</button>
            <a class="btn btn-soft" href="pacientes.php">Cancelar</a>
          </div>
        </form>
      <?php else: ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="crear">

          <label for="nombre">Nombre *</label>
          <input id="nombre" name="nombre" required>

          <label for="telefono">Teléfono</label>
          <input id="telefono" name="telefono">

          <label for="direccion">Dirección</label>
          <textarea id="direccion" name="direccion" rows="2"></textarea>

          <div class="form-footer">
            <button class="btn btn-primary">Agregar</button>
          </div>
        </form>
      <?php endif; ?>

      <p class="muted" style="margin-top:10px;">
        Los pacientes se guardan solo en este sistema local. Para sincronizar con otro entorno,
        usa los reportes o exportaciones que hayas definido.
      </p>
    </article>

    <!-- Columna derecha: búsqueda + tabla -->
    <article class="card">
      <div class="card-header">
        <div>
          <h2 class="card-title">Pacientes registrados</h2>
          <p class="card-sub">
            Busca por nombre, teléfono o dirección. Haz clic en editar para actualizar datos.
          </p>
        </div>
        <form class="search-bar" method="get" action="pacientes.php">
          <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Buscar paciente..."
          >
          <button class="btn btn-soft" type="submit">Buscar</button>
          <?php if ($q!==''): ?>
            <a class="btn btn-ghost" href="pacientes.php">Limpiar</a>
          <?php endif; ?>
        </form>
      </div>

      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre</th>
            <th>Teléfono</th>
            <th>Dirección</th>
            <th>Creado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="6" class="muted muted-center">Sin registros.</td>
          </tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><?= e($r['nombre']) ?></td>
            <td>
              <?php if($r['telefono']): ?>
                <?= e($r['telefono']) ?>
              <?php else: ?>
                <span class="tag-empty">Sin teléfono</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if($r['direccion']): ?>
                <?= e($r['direccion']) ?>
              <?php else: ?>
                <span class="tag-empty">Sin dirección</span>
              <?php endif; ?>
            </td>
            <td><?= e($r['creado']) ?></td>
            <td>
              <div class="actions">
                <a class="btn btn-soft" href="pacientes.php?act=editar&id=<?= (int)$r['id'] ?>">Editar</a>
                <form method="post" onsubmit="return confirm('¿Eliminar a <?= e($r['nombre']) ?>?');">
                  <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                  <input type="hidden" name="act" value="eliminar">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger">Eliminar</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <p class="muted" style="margin-top:8px">
        Mostrando máximo 200 registros. Si necesitas afinar, usa el buscador de la parte superior.
      </p>
    </article>
  </section>
</div>
