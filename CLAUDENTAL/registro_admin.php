<?php
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
$conn = db();

// Si ya hay admin y tú no eres admin, al login
$hayAdmin = 0;
if ($r = $conn->query("SELECT COUNT(*) c FROM usuarios WHERE rol='admin'")) {
  $hayAdmin = (int)$r->fetch_assoc()['c'];
  $r->free();
}
if ($hayAdmin > 0 && !es_admin()) {
  header("Location: login.php");
  exit;
}

// Si el dentista bloqueó nuevos admins (después del primero), respétalo
$bloq = '0';
if ($r = $conn->query("SELECT valor FROM configuracion WHERE clave='registro_admin_bloqueado'")) {
  if ($row = $r->fetch_assoc()) $bloq = $row['valor'];
  $r->free();
}
if ($hayAdmin > 0 && $bloq === '1' && !es_admin()) {
  http_response_code(403);
  die("<h2>Registro de administradores bloqueado</h2><p>Inicia sesión o contacta al administrador.</p>");
}

$msg=$err="";
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $nombre = trim($_POST['nombre'] ?? "");
  $correo = strtolower(trim($_POST['correo'] ?? ""));
  $pass   = $_POST['pass'] ?? "";

  if ($nombre && $correo && $pass) {
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $st = $conn->prepare("INSERT INTO usuarios(nombre,correo,pass_hash,rol,activo) VALUES (?,?,?,'admin',1)");
    if (!$st) { $err = "Error preparando: ".e($conn->error); }
    else {
      $st->bind_param("sss",$nombre,$correo,$hash);
      if ($st->execute()) { $msg = "Administrador creado. Ya puedes iniciar sesión."; }
      else { $err = "Error al guardar: ".e($conn->error); }
      $st->close();
    }
  } else { $err = "Completa todos los campos."; }
}
?>
<!doctype html><meta charset="utf-8">
<title>Registrar administrador | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f7f8fc;color:#111;margin:0}
  .top{background:#0b5ed7;color:#fff;padding:12px 16px}
  .top a{color:#fff;text-decoration:none;font-weight:800}
  .wrap{max-width:720px;margin:28px auto;padding:0 16px}
  .card{background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.06);padding:16px}
  label{display:block;margin-top:8px}
  input{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-top:4px}
  .btn{background:#0b5ed7;color:#fff;border:none;border-radius:10px;padding:10px 14px;cursor:pointer;margin-top:12px}
  .alert{margin:10px 0;border-radius:10px;padding:10px}
  .ok{background:#e8f7ed;color:#117a2b}
  .err{background:#fde8ec;color:#b00020}
</style>
<header class="top"><a href="index.php">BETANDENT</a></header>
<div class="wrap">
  <h1>Registrar administrador</h1>
  <?php if($msg): ?><div class="alert ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="card">
    <label>Nombre</label><input name="nombre" required>
    <label>Correo</label><input name="correo" type="email" required>
    <label>Contraseña</label><input name="pass" type="password" required>
    <button class="btn">Guardar</button>
  </form>
  <p><a href="login.php">Volver a iniciar sesión</a></p>
</div>
