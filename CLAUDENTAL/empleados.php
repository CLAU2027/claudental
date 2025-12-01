<?php
// empleados.php — Gestión de empleados (solo admin) · BETANDENT
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin']); // solo admin

$conn = db();

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($k,$v=null){ if($v===null){$t=$_SESSION[$k]??'';unset($_SESSION[$k]);return $t;} $_SESSION[$k]=$v; }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ===========================
   Acciones (POST)
   =========================== */
$act = $_POST['act'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }

  // Crear empleado
  if ($act==='crear') {
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = strtolower(trim($_POST['correo'] ?? ''));
    $pass   = $_POST['pass'] ?? '';
    $tel    = trim($_POST['telefono'] ?? '');
    $dir    = trim($_POST['direccion'] ?? '');

    if (!$nombre || !filter_var($correo,FILTER_VALIDATE_EMAIL) || !$pass) {
      flash('err','Completa nombre, correo válido y contraseña.');
      header("Location: empleados.php"); exit;
    }

    // ¿correo existe?
    $st = $conn->prepare("SELECT id FROM usuarios WHERE correo=? LIMIT 1");
    $st->bind_param("s",$correo);
    $st->execute(); $existe = (bool)$st->get_result()->fetch_assoc(); $st->close();
    if ($existe) { flash('err','Ese correo ya está registrado.'); header("Location: empleados.php"); exit; }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $rol='empleado';
    $st = $conn->prepare("INSERT INTO usuarios(nombre,correo,pass_hash,rol,telefono,direccion,activo) VALUES (?,?,?,?,?,?,1)");
    $st->bind_param("ssssss",$nombre,$correo,$hash,$rol,$tel,$dir);
    if ($st->execute()) { flash('ok','Empleado creado.'); } else { flash('err','Error al crear: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: empleados.php"); exit;
  }

  // Actualizar datos básicos
  if ($act==='actualizar') {
    $id     = (int)($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $correo = strtolower(trim($_POST['correo'] ?? ''));
    $tel    = trim($_POST['telefono'] ?? '');
    $dir    = trim($_POST['direccion'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;

    if (!$id || !$nombre || !filter_var($correo,FILTER_VALIDATE_EMAIL)) {
      flash('err','Datos inválidos.'); header("Location: empleados.php"); exit;
    }

    // Asegura que es empleado
    $st = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol='empleado'");
    $st->bind_param("i",$id); $st->execute(); $okEmp = (bool)$st->get_result()->fetch_assoc(); $st->close();
    if (!$okEmp) { flash('err','El usuario no es empleado.'); header("Location: empleados.php"); exit; }

    // Correo único
    $st = $conn->prepare("SELECT id FROM usuarios WHERE correo=? AND id<>? LIMIT 1");
    $st->bind_param("si",$correo,$id); $st->execute(); $dupe=(bool)$st->get_result()->fetch_assoc(); $st->close();
    if ($dupe) { flash('err','Ese correo ya está en uso.'); header("Location: empleados.php"); exit; }

    $st = $conn->prepare("UPDATE usuarios SET nombre=?, correo=?, telefono=?, direccion=?, activo=? WHERE id=? AND rol='empleado'");
    $st->bind_param("ssssii",$nombre,$correo,$tel,$dir,$activo,$id);
    if ($st->execute()) { flash('ok','Empleado actualizado.'); } else { flash('err','No se pudo actualizar: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: empleados.php"); exit;
  }

  // Resetear contraseña
  if ($act==='password') {
    $id   = (int)($_POST['id'] ?? 0);
    $pass = $_POST['pass'] ?? '';
    if (!$id || !$pass) { flash('err','Contraseña inválida.'); header("Location: empleados.php"); exit; }

    $st = $conn->prepare("SELECT id FROM usuarios WHERE id=? AND rol='empleado'");
    $st->bind_param("i",$id); $st->execute(); $okEmp=(bool)$st->get_result()->fetch_assoc(); $st->close();
    if (!$okEmp) { flash('err','El usuario no es empleado.'); header("Location: empleados.php"); exit; }

    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $conn->prepare("UPDATE usuarios SET pass_hash=? WHERE id=?");
    $st->bind_param("si",$hash,$id);
    if ($st->execute()) { flash('ok','Contraseña actualizada.'); } else { flash('err','No se pudo actualizar: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: empleados.php"); exit;
  }

  // Activar/Desactivar rápido
  if ($act==='toggle') {
    $id = (int)($_POST['id'] ?? 0);
    $nuevo = (int)($_POST['nuevo'] ?? 0);
    $st = $conn->prepare("UPDATE usuarios SET activo=? WHERE id=? AND rol='empleado'");
    $st->bind_param("ii",$nuevo,$id);
    if ($st->execute()) { flash('ok', $nuevo? 'Empleado activado.':'Empleado desactivado.'); }
    else { flash('err','No se pudo cambiar el estado: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: empleados.php"); exit;
  }

  // Eliminar
  if ($act==='eliminar') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $conn->prepare("DELETE FROM usuarios WHERE id=? AND rol='empleado'");
    $st->bind_param("i",$id);
    if ($st->execute()) { flash('ok','Empleado eliminado.'); } else { flash('err','No se pudo eliminar: '.e($st->error ?: $conn->error)); }
    $st->close();
    header("Location: empleados.php"); exit;
  }
}

/* ===========================
   Listado y búsqueda
   =========================== */
$q = trim($_GET['q'] ?? '');
$where = "WHERE rol='empleado'";
$params = [];
$bind = '';
if ($q !== '') {
  $where .= " AND (nombre LIKE CONCAT('%',?,'%') OR correo LIKE CONCAT('%',?,'%') OR telefono LIKE CONCAT('%',?,'%'))";
  $bind = 'sss';
  $params = [$q,$q,$q];
}

$sql = "SELECT id,nombre,correo,telefono,direccion,activo,DATE_FORMAT(creado_en,'%Y-%m-%d %H:%i') creado
        FROM usuarios
        $where
        ORDER BY activo DESC, nombre ASC
        LIMIT 500";

$emps = [];
if ($bind) {
  $st = $conn->prepare($sql);
  $st->bind_param($bind, ...$params);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()) $emps[]=$row;
  $st->close();
} else {
  if ($res = $conn->query($sql)) {
    while($row=$res->fetch_assoc()) $emps[]=$row;
    $res->free();
  }
}

$ok = flash('ok'); $err = flash('err');
?>

<!doctype html>
<meta charset="utf-8">
<title>Empleados | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --brand:#0b5ed7;
    --brand-soft:#e3edff;
    --ink:#111827;
    --muted:#6b7280;
    --bg:#f3f4f9;
    --card:#ffffff;
    --shadow:0 10px 35px rgba(15,23,42,.08);
    --radius:18px;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    background:var(--bg);
    color:var(--ink);
  }
  a{text-decoration:none;color:inherit}

  /* TOPBAR */
  .top{
    position:sticky;
    top:0;
    z-index:20;
    background:var(--brand);
    color:#fff;
    padding:10px 18px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 8px 25px rgba(15,23,42,.35);
  }
  .top-left{
    font-weight:800;
    font-size:1rem;
    display:flex;
    flex-direction:column;
    gap:2px;
  }
  .top-left a{color:#fff}
  .top-left small{font-weight:400;opacity:.85;font-size:.8rem}
  .top form{gap:8px}
  @media (max-width:820px){
    .top{flex-direction:column;align-items:flex-start;gap:8px}
    .top form{width:100%}
    .top form input{flex:1}
  }

  /* LAYOUT */
  .wrap{
    max-width:1180px;
    margin:0 auto;
    padding:20px 16px 28px;
  }
  .grid{display:grid;gap:18px}
  .cols{grid-template-columns:2fr 1.1fr}
  @media (max-width:1024px){.cols{grid-template-columns:1fr}}

  .card{
    background:var(--card);
    border-radius:var(--radius);
    box-shadow:var(--shadow);
    padding:18px 18px 16px;
  }
  .card h2{
    margin:0 0 6px;
    font-size:1.05rem;
    display:flex;
    justify-content:space-between;
    align-items:center;
  }
  .card small.section-hint{
    font-size:.78rem;
    color:var(--muted);
    font-weight:400;
  }

  .row{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
  }

  /* FORM CONTROLS */
  label{
    display:block;
    margin-top:10px;
    font-weight:600;
    font-size:.88rem;
  }
  input,textarea{
    width:100%;
    padding:9px 10px;
    border:1px solid #e5e7eb;
    border-radius:10px;
    margin-top:4px;
    font-size:.88rem;
  }
  textarea{resize:vertical;min-height:60px}

  /* BUTTONS */
  .btn{
    display:inline-block;
    background:var(--brand);
    color:#fff;
    border:none;
    border-radius:999px;
    padding:8px 14px;
    cursor:pointer;
    font-size:.85rem;
    font-weight:600;
    transition:.15s transform,.15s box-shadow,.15s background;
    box-shadow:0 8px 18px rgba(37,99,235,.25);
  }
  .btn:hover{
    transform:translateY(-1px);
    box-shadow:0 10px 22px rgba(37,99,235,.32);
  }
  .btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    box-shadow:none;
    transform:none;
  }
  .btn.secondary{
    background:#6b7280;
    box-shadow:none;
  }
  .btn.ghost{
    background:#eef2ff;
    color:var(--brand);
    box-shadow:none;
  }
  .btn.danger{
    background:#b91c1c;
    box-shadow:none;
  }
  .btn.sm{
    padding:6px 10px;
    font-size:.78rem;
    box-shadow:none;
  }

  /* ALERTAS */
  .alert{
    padding:10px 12px;
    border-radius:12px;
    margin:0 0 14px;
    font-size:.86rem;
  }
  .ok{
    background:#e8f7ed;
    color:#117a2b;
    border:1px solid #c4f0d3;
  }
  .err{
    background:#fde8ec;
    color:#b00020;
    border:1px solid #f9c2cf;
  }

  .muted{color:var(--muted);font-size:.83rem}

  /* TABLA */
  table{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
    font-size:.86rem;
  }
  thead{
    background:#f3f4ff;
  }
  th,td{
    padding:9px 8px;
    border-bottom:1px solid #edf0f7;
    text-align:left;
    vertical-align:top;
  }
  th{
    color:#4b5563;
    font-weight:600;
    font-size:.8rem;
  }
  tr:nth-child(even) td{background:#fafbff}

  /* BADGES / PASTILLAS */
  .pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:var(--brand-soft);
    border-radius:999px;
    padding:3px 10px;
    font-size:.78rem;
    color:#1e3a8a;
    border:1px solid #d2ddff;
  }
  .pill-dot{
    width:7px;
    height:7px;
    border-radius:50%;
    background:#22c55e;
  }
  .pill-dot.off{background:#9ca3af}

  /* ACCIONES */
  .actions{
    display:flex;
    flex-direction:column;
    gap:6px;
    min-width:210px;
  }
  .actions form,
  .actions details{
    width:100%;
  }

  details summary{
    list-style:none;
    cursor:pointer;
  }
  details summary::-webkit-details-marker{display:none}

  .inline-edit{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:8px;
  }
  .inline-edit input{
    max-width:170px;
    flex:1 1 auto;
  }
  .inline-edit label{
    margin-top:0;
    font-weight:500;
    font-size:.78rem;
  }
  .inline-edit .chk-wrap{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:.8rem;
    margin-left:2px;
  }

  @media (max-width:880px){
    .actions{
      flex-direction:row;
      flex-wrap:wrap;
      gap:4px;
    }
    .inline-edit input{max-width:100%}
  }
</style>

<div class="top">
  <div class="top-left">
    <div><a href="panel.php">BETANDENT</a> · Empleados</div>
    <small>Administración de usuarios con rol <strong>empleado</strong></small>
  </div>
  <form method="get" class="row" style="align-items:stretch">
    <input name="q" placeholder="Buscar por nombre, correo o teléfono" value="<?= e($q) ?>">
    <button class="btn" style="background:#fff;color:var(--brand);box-shadow:none">Buscar</button>
    <a class="btn ghost" href="empleados.php">Limpiar</a>
  </form>
</div>

<div class="wrap">
  <?php if($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

  <div class="grid cols">
    <!-- LISTADO -->
    <div class="card">
      <h2>
        Empleados
        <small class="section-hint">Máx. 500 registros · ordenados por estado y nombre</small>
      </h2>
      <?php if(empty($emps)): ?>
        <p class="muted" style="margin-top:8px">Sin empleados registrados.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Empleado</th>
              <th>Contacto</th>
              <th>Estado</th>
              <th>Creado</th>
              <th style="min-width:220px">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($emps as $e1): ?>
              <tr>
                <td>
                  <strong><?= e($e1['nombre']) ?></strong><br>
                  <span class="muted"><?= e($e1['direccion'] ?: 'Sin dirección registrada') ?></span>
                </td>
                <td>
                  <?= e($e1['correo']) ?><br>
                  <span class="muted"><?= e($e1['telefono'] ?: 'Sin teléfono') ?></span>
                </td>
                <td>
                  <span class="pill">
                    <span class="pill-dot <?= $e1['activo'] ? '' : 'off' ?>"></span>
                    <?= $e1['activo']? 'Activo':'Inactivo' ?>
                  </span>
                </td>
                <td><span class="muted"><?= e($e1['creado']) ?></span></td>
                <td>
                  <div class="actions">
                    <!-- Editar formulario inline -->
                    <details>
                      <summary class="pill" style="justify-content:center">
                        Editar datos
                      </summary>
                      <form method="post" class="inline-edit">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="act" value="actualizar">
                        <input type="hidden" name="id" value="<?= (int)$e1['id'] ?>">

                        <input name="nombre" placeholder="Nombre" value="<?= e($e1['nombre']) ?>" required>
                        <input name="correo" type="email" placeholder="Correo" value="<?= e($e1['correo']) ?>" required>
                        <input name="telefono" placeholder="Teléfono" value="<?= e($e1['telefono']) ?>">
                        <input name="direccion" placeholder="Dirección" value="<?= e($e1['direccion']) ?>">

                        <div class="chk-wrap">
                          <input type="checkbox" name="activo" <?= $e1['activo']?'checked':''; ?>>
                          <span>Activo</span>
                        </div>

                        <button class="btn sm">Guardar</button>
                      </form>
                    </details>

                    <!-- Reset pass -->
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="password">
                      <input type="hidden" name="id" value="<?= (int)$e1['id'] ?>">
                      <input name="pass" type="password" placeholder="Nueva contraseña" required>
                      <button class="btn secondary sm">Reset pass</button>
                    </form>

                    <!-- Activar/Desactivar rápido -->
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="toggle">
                      <input type="hidden" name="id" value="<?= (int)$e1['id'] ?>">
                      <input type="hidden" name="nuevo" value="<?= $e1['activo']?0:1 ?>">
                      <button class="btn sm" style="background:<?= $e1['activo']?'#6b7280':'#16a34a'; ?>;box-shadow:none">
                        <?= $e1['activo']?'Desactivar':'Activar' ?>
                      </button>
                    </form>

                    <!-- Eliminar -->
                    <form method="post" onsubmit="return confirm('¿Eliminar empleado definitivamente?');">
                      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                      <input type="hidden" name="act" value="eliminar">
                      <input type="hidden" name="id" value="<?= (int)$e1['id'] ?>">
                      <button class="btn danger sm">Eliminar</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- NUEVO EMPLEADO -->
    <div class="card">
      <h2>
        Nuevo empleado
        <small class="section-hint">Solo se crean con rol <strong>empleado</strong></small>
      </h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="crear">

        <label>Nombre completo</label>
        <input name="nombre" required>

        <label>Correo</label>
        <input name="correo" type="email" required>

        <label>Contraseña</label>
        <input name="pass" type="password" required>

        <label>Teléfono (opcional)</label>
        <input name="telefono">

        <label>Dirección (opcional)</label>
        <textarea name="direccion" rows="2"></textarea>

        <div class="row" style="margin-top:12px">
          <button class="btn">Crear empleado</button>
          <a class="btn secondary" href="panel.php">Volver</a>
        </div>
      </form>

      <p class="muted" style="margin-top:14px">
        El correo debe ser único en el sistema. El usuario creado podrá iniciar sesión como <strong>empleado</strong>.
      </p>
    </div>
  </div>
</div>
