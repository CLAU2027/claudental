<?php
// login.php — BETANDENT
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';

if (session_status()===PHP_SESSION_NONE) session_start();
$conn = db();

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$msg=$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }

  $correo = strtolower(trim($_POST['correo'] ?? ''));
  $pass   = $_POST['pass'] ?? '';

  if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !$pass) {
    $err = 'Correo o contraseña inválidos.';
  } else {
    $st = $conn->prepare("SELECT id,nombre,pass_hash,rol,activo FROM usuarios WHERE correo=? LIMIT 1");
    $st->bind_param("s",$correo);
    $st->execute(); $r=$st->get_result();
    if ($u = $r->fetch_assoc()) {
      if (!$u['activo']) {
        $err = 'Tu cuenta está inactiva. Contacta a la clínica.';
      } elseif (!password_verify($pass, $u['pass_hash'])) {
        $err = 'Credenciales incorrectas.';
      } else {
        $_SESSION['uid']    = (int)$u['id'];
        $_SESSION['nombre'] = $u['nombre'];
        $_SESSION['rol']    = $u['rol'];

        if ($u['rol']==='admin' || $u['rol']==='empleado') {
          header("Location: panel.php"); exit;
        } else {
          header("Location: citas_cliente.php"); exit;
        }
      }
    } else {
      $err = 'No existe una cuenta con ese correo.';
    }
    $st->close();
  }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Iniciar sesión | BETANDENT</title>
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
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1.4fr);
    gap:22px;
    align-items:stretch;
  }

  .card{
    background:#fff;
    border-radius:var(--radius-card);
    box-shadow:0 14px 40px rgba(15,23,42,.12);
    padding:22px 20px 20px 20px;
    border:1px solid rgba(148,163,184,.25);
    display:flex;
    flex-direction:column;
    justify-content:center;
  }

  .hero-card{
    background:linear-gradient(135deg,var(--primary),#1c7ed6);
    color:#f9fafb;
    border-radius:var(--radius-card);
    padding:22px 20px 20px 20px;
    box-shadow:0 18px 40px rgba(15,23,42,.35);
    position:relative;
    overflow:hidden;
  }
  .hero-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
      radial-gradient(circle at 0% 0%, rgba(255,255,255,.18), transparent 55%),
      radial-gradient(circle at 100% 100%, rgba(22,160,133,.18), transparent 50%);
    opacity:.9;
    pointer-events:none;
  }
  .hero-inner{
    position:relative;
    z-index:1;
  }
  .hero-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:rgba(15,23,42,.25);
    padding:4px 10px;
    border-radius:999px;
    font-size:11px;
  }
  .hero-dot{
    width:8px;height:8px;border-radius:999px;
    background:#22c55e;
    box-shadow:0 0 0 5px rgba(34,197,94,.35);
  }
  .hero-title{
    margin:12px 0 6px 0;
    font-size:20px;
    font-weight:600;
  }
  .hero-text{
    font-size:13px;
    line-height:1.5;
    margin:0 0 12px 0;
  }
  .hero-list{
    font-size:13px;
    padding-left:18px;
    margin:0 0 10px 0;
  }
  .hero-foot{
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

  input{
    width:100%;
    padding:10px 11px;
    border:1px solid #d1d5db;
    border-radius:11px;
    margin-top:4px;
    font-size:14px;
    background:#f9fafb;
    transition:border-color .18s ease, box-shadow .18s ease, background .18s ease, transform .09s ease;
  }

  input:focus{
    outline:none;
    border-color:var(--primary);
    background:#ffffff;
    box-shadow:0 0 0 1px rgba(11,94,215,.20),0 0 0 6px rgba(59,130,246,.18);
    transform:translateY(-1px);
  }

  input::placeholder{
    color:#9ca3af;
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

  .alert{
    padding:9px 11px;
    border-radius:10px;
    margin:10px 0 14px 0;
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
  .err{
    background:var(--danger-bg);
    color:var(--danger);
    border:1px solid rgba(248,113,113,.65);
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

  @media (max-width: 860px){
    .layout{
      grid-template-columns:minmax(0,1fr);
    }
    .hero-card{
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
  <div class="top-cta">Acceso seguro al panel de la clínica</div>
</header>

<div class="wrap">
  <h1>Iniciar sesión</h1>
  <p class="subtitle">Ingresa con tu correo y contraseña para gestionar citas y pacientes.</p>

  <div class="layout">
    <div class="card">
      <?php if($err): ?>
        <div class="alert err">
          <div class="alert-icon">!</div>
          <div><?= e($err) ?></div>
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

        <label>Correo</label>
        <input type="email" name="correo" required placeholder="tucorreo@ejemplo.com">

        <label>Contraseña</label>
        <input type="password" name="pass" required placeholder="Tu contraseña de acceso">

        <button class="btn">Entrar</button>
      </form>

      <p class="muted">
        ¿No tienes cuenta?
        <a href="registro.php">Crear cuenta como paciente</a>
      </p>
    </div>

    <aside class="hero-card">
      <div class="hero-inner">
        <div class="hero-pill">
          <span class="hero-dot"></span>
          <span>Portal BETANDENT</span>
        </div>
        <p class="hero-title">Citas bajo control, pacientes tranquilos.</p>
        <p class="hero-text">
          Este acceso es exclusivo para pacientes registrados y personal de la clínica.
          Mantén tus credenciales seguras y evita iniciar sesión en dispositivos públicos.
        </p>
        <ul class="hero-list">
          <li>Acceso al panel administrativo (admin/empleado).</li>
          <li>Agenda de citas para pacientes.</li>
          <li>Información centralizada en un solo lugar.</li>
        </ul>
        <p class="hero-foot">
          Si olvidaste tu contraseña, comunícate directamente con la recepción para recuperar el acceso.
        </p>
      </div>
    </aside>
  </div>
</div>
