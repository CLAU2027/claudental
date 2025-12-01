<?php
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
$conn = db();

if (session_status() === PHP_SESSION_NONE) session_start();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// Bloqueo de registro de pacientes (configuración)
$bloq = '0';
if ($r = $conn->query("SELECT valor FROM configuracion WHERE clave='registro_paciente_bloqueado'")) {
  if ($row = $r->fetch_assoc()) $bloq = $row['valor'];
  $r->free();
}
if ($bloq === '1') {
  http_response_code(403);
  die("<h2>Registro cerrado temporalmente</h2><p>Comunícate con la clínica.</p><p><a href='login.php'>Iniciar sesión</a></p>");
}

$msg = $err = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF opcional
  if (empty($_POST['csrf']) || $_POST['csrf'] !== $csrf) {
    http_response_code(400);
    die('CSRF inválido');
  }

  $nombre = trim($_POST['nombre'] ?? "");
  $correo = strtolower(trim($_POST['correo'] ?? ""));
  $pass   = $_POST['pass'] ?? "";
  $tel    = trim($_POST['telefono'] ?? "");
  $dir    = trim($_POST['direccion'] ?? "");

  // límites suaves para evitar chatarra
  if (strlen($nombre) > 100)   $nombre = substr($nombre, 0, 100);
  if (strlen($correo) > 120)   $correo = substr($correo, 0, 120);
  if (strlen($tel) > 30)       $tel    = substr($tel, 0, 30);
  if (strlen($dir) > 200)      $dir    = substr($dir, 0, 200);

  if ($nombre && filter_var($correo, FILTER_VALIDATE_EMAIL) && $pass) {
    // ¿correo ya existe?
    $st = $conn->prepare("SELECT id FROM usuarios WHERE correo=? LIMIT 1");
    $st->bind_param("s", $correo);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) {
      $err = "Ese correo ya está registrado.";
    } else {
      $st->close();

      $hash = password_hash($pass, PASSWORD_BCRYPT);

      // nombre, correo, pass_hash, rol fijo 'paciente', telefono, direccion, activo=1
      $sql = "INSERT INTO usuarios(nombre, correo, pass_hash, rol, telefono, direccion, activo)
              VALUES (?,?,?,'paciente',?,?,1)";
      $st = $conn->prepare($sql);
      $st->bind_param("sssss", $nombre, $correo, $hash, $tel, $dir);

      if ($st->execute()) {
        // OPCIÓN A: login automático + mandar a agenda del paciente
        $_SESSION['uid']   = $st->insert_id;
        $_SESSION['nombre']= $nombre;
        $_SESSION['rol']   = 'paciente';
        header("Location: citas_cliente.php");
        exit;

        // OPCIÓN B (si prefieres no auto-login):
        // $msg = "Cuenta creada. Ya puedes iniciar sesión.";
      } else {
        $err = "No se pudo registrar: " . e($conn->error ?: $st->error);
      }
    }
    if (isset($st) && $st) $st->close();
  } else {
    $err = "Completa los campos obligatorios con un correo válido.";
  }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Registrarme | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --primary:#0b5ed7;
    --primary-soft:#e7f0ff;
    --accent:#16a085;
    --bg:#f3f4f8;
    --text:#111827;
    --muted:#6b7280;
    --danger-bg:#fde8ec;
    --danger:#b00020;
    --success-bg:#e8f7ed;
    --success:#117a2b;
    --radius-card:18px;
  }

  *{box-sizing:border-box;}

  body{
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    margin:0;
    min-height:100vh;
    color:var(--text);
    background:
      radial-gradient(circle at top left, rgba(11,94,215,.20), transparent 55%),
      radial-gradient(circle at bottom right, rgba(22,160,133,.18), transparent 55%),
      var(--bg);
    display:flex;
    flex-direction:column;
  }

  .top{
    background:rgba(255,255,255,.9);
    backdrop-filter:blur(14px);
    border-bottom:1px solid rgba(15,23,42,.06);
    padding:10px 18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    position:sticky;
    top:0;
    z-index:10;
  }

  .brand{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .brand-badge{
    width:32px;
    height:32px;
    border-radius:12px;
    background:linear-gradient(135deg,var(--primary),var(--accent));
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-weight:800;
    font-size:16px;
    box-shadow:0 6px 18px rgba(15,23,42,.25);
  }
  .top a{
    color:var(--primary);
    text-decoration:none;
    font-weight:800;
    letter-spacing:.05em;
    font-size:14px;
  }

  .top-cta{
    font-size:13px;
    color:var(--muted);
  }

  .wrap{
    max-width:1000px;
    margin:32px auto 40px auto;
    padding:0 16px;
  }

  h1{
    margin:0 0 6px 0;
    font-size:26px;
    letter-spacing:.02em;
  }

  .subtitle{
    margin:0 0 20px 0;
    color:var(--muted);
    font-size:14px;
  }

  .layout{
    display:grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1.3fr);
    gap:22px;
    align-items:flex-start;
  }

  .card{
    background:#fff;
    border-radius:var(--radius-card);
    box-shadow:0 14px 40px rgba(15,23,42,.12);
    padding:22px 20px 20px 20px;
    border:1px solid rgba(148,163,184,.25);
  }

  .side-card{
    background:linear-gradient(135deg,var(--primary),#1c7ed6);
    color:#f9fafb;
    border-radius:var(--radius-card);
    padding:22px 20px 20px 20px;
    box-shadow:0 18px 40px rgba(15,23,42,.35);
    position:relative;
    overflow:hidden;
  }

  .side-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
      radial-gradient(circle at 0% 0%, rgba(255,255,255,.18), transparent 55%),
      radial-gradient(circle at 100% 100%, rgba(22,160,133,.18), transparent 50%);
    opacity:.9;
    pointer-events:none;
  }

  .side-inner{
    position:relative;
    z-index:1;
  }

  .side-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(15,23,42,.25);
    padding:4px 10px;
    border-radius:999px;
    font-size:11px;
  }
  .side-dot{
    width:8px;height:8px;border-radius:999px;
    background:#22c55e;
    box-shadow:0 0 0 5px rgba(34,197,94,.35);
  }

  .side-title{
    margin:10px 0 6px 0;
    font-size:18px;
    font-weight:600;
  }

  .side-text{
    font-size:13px;
    line-height:1.4;
    margin:0 0 10px 0;
  }

  .side-list{
    font-size:13px;
    padding-left:18px;
    margin:0 0 10px 0;
  }

  .side-footnote{
    font-size:11px;
    opacity:.9;
  }

  label{
    display:block;
    margin-top:10px;
    font-size:13px;
    font-weight:600;
    color:#111827;
  }

  input, textarea{
    width:100%;
    padding:10px 11px;
    border:1px solid #d1d5db;
    border-radius:11px;
    margin-top:4px;
    font-size:14px;
    background:#f9fafb;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease, transform .09s ease;
  }

  input:focus, textarea:focus{
    outline:none;
    border-color:var(--primary);
    background:#ffffff;
    box-shadow:0 0 0 1px rgba(11,94,215,.20),0 0 0 6px rgba(59,130,246,.18);
    transform:translateY(-1px);
  }

  input::placeholder, textarea::placeholder{
    color:#9ca3af;
  }

  textarea{
    resize:vertical;
    min-height:60px;
    max-height:180px;
  }

  .btn{
    background:linear-gradient(135deg,var(--primary),#1c7ed6);
    color:#fff;
    border:none;
    border-radius:999px;
    padding:11px 18px;
    cursor:pointer;
    margin-top:18px;
    font-size:14px;
    font-weight:600;
    display:inline-flex;
    align-items:center;
    gap:8px;
    letter-spacing:.02em;
    box-shadow:0 12px 30px rgba(15,23,42,.25);
    transition:transform .12s ease, box-shadow .12s ease, filter .12s ease;
  }

  .btn::after{
    content:"→";
    font-size:14px;
  }

  .btn:hover{
    filter:brightness(1.03);
    transform:translateY(-1px);
    box-shadow:0 14px 38px rgba(15,23,42,.30);
  }

  .btn:active{
    transform:translateY(0);
    box-shadow:0 6px 16px rgba(15,23,42,.28);
  }

  .muted{
    color:var(--muted);
    font-size:13px;
    margin-top:14px;
  }
  .muted a{
    color:var(--primary);
    text-decoration:none;
    font-weight:600;
  }
  .muted a:hover{
    text-decoration:underline;
  }

  .alert{
    margin:10px 0 14px 0;
    border-radius:10px;
    padding:9px 11px;
    font-size:13px;
    display:flex;
    align-items:flex-start;
    gap:8px;
  }

  .alert-icon{
    font-size:15px;
    line-height:1;
    margin-top:1px;
  }

  .ok{
    background:var(--success-bg);
    color:var(--success);
    border:1px solid rgba(16,185,129,.40);
  }

  .err{
    background:var(--danger-bg);
    color:var(--danger);
    border:1px solid rgba(248,113,113,.65);
  }

  .required-hint{
    font-size:11px;
    color:var(--muted);
    margin-top:3px;
  }

  @media (max-width: 800px){
    .layout{
      grid-template-columns: minmax(0, 1fr);
    }
    .side-card{
      order:-1;
    }
    .wrap{
      margin-top:24px;
    }
  }

  @media (prefers-reduced-motion: reduce){
    *, *::before, *::after{
      scroll-behavior:auto !important;
      animation-duration:.01ms !important;
      animation-iteration-count:1 !important;
      transition-duration:.01ms !important;
    }
  }
</style>

<header class="top">
  <div class="brand">
    <div class="brand-badge">B</div>
    <a href="index.php">BETANDENT</a>
  </div>
  <div class="top-cta">Cuidado dental con agenda en línea</div>
</header>

<div class="wrap">
  <h1>Crear cuenta (Paciente)</h1>
  <p class="subtitle">Crea tu acceso para agendar, consultar y gestionar tus citas en la clínica.</p>

  <?php if($msg): ?>
    <div class="alert ok">
      <div class="alert-icon">✔</div>
      <div><?= e($msg) ?></div>
    </div>
  <?php endif; ?>

  <?php if($err): ?>
    <div class="alert err">
      <div class="alert-icon">!</div>
      <div><?= e($err) ?></div>
    </div>
  <?php endif; ?>

  <div class="layout">
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <label>Nombre completo *</label>
      <input name="nombre" required placeholder="Ej. Ana López Martínez">

      <label>Correo *</label>
      <input type="email" name="correo" required placeholder="tucorreo@ejemplo.com">

      <label>Contraseña *</label>
      <input type="password" name="pass" required placeholder="Mínimo 8 caracteres">

      <label>Teléfono</label>
      <input name="telefono" placeholder="Ej. 483 000 0000">

      <label>Dirección</label>
      <textarea name="direccion" rows="2" placeholder="Calle, número, colonia y ciudad"></textarea>

      <div class="required-hint">* Campos obligatorios</div>

      <button class="btn">Registrarme</button>
    </form>

    <aside class="side-card">
      <div class="side-inner">
        <div class="side-pill">
          <span class="side-dot"></span>
          <span>Portal de pacientes BETANDENT</span>
        </div>
        <p class="side-title">¿Qué puedes hacer con tu cuenta?</p>
        <p class="side-text">
          Al registrarte podrás gestionar tus citas sin llamar por teléfono, mantener tus datos actualizados y facilitar tu atención en recepción.
        </p>
        <ul class="side-list">
          <li>Agendar y revisar próximas citas.</li>
          <li>Mantener tu información de contacto al día.</li>
          <li>Reducir tiempos de espera en recepción.</li>
        </ul>
        <p class="side-footnote">
          Si ya te registraste anteriormente, entra desde <strong>“Iniciar sesión”</strong> para evitar duplicar tu cuenta.
        </p>
      </div>
    </aside>
  </div>

  <p class="muted">¿Ya tienes cuenta? <a href="login.php">Iniciar sesión</a></p>
</div>
