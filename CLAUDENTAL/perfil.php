<?php
// perfil.php — Datos del usuario + avatar (BETANDENT)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();

$conn = db();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); 
$csrf = $_SESSION['csrf'];
$uid  = (int)$_SESSION['uid'];

$ok = $err = '';

// Cambios de datos / password / avatar
if($_SERVER['REQUEST_METHOD']==='POST'){
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }
  $act = $_POST['act'] ?? '';

  if($act==='datos'){
    $nombre = trim($_POST['nombre'] ?? '');
    $tel    = trim($_POST['telefono'] ?? '');
    $dir    = trim($_POST['direccion'] ?? '');
    if(!$nombre){
      $err='Nombre requerido.';
    }else{
      $st=$conn->prepare("UPDATE usuarios SET nombre=?,telefono=?,direccion=? WHERE id=?");
      $st->bind_param("sssi",$nombre,$tel,$dir,$uid);
      if($st->execute()){
        $ok='Datos actualizados.';
        $_SESSION['nombre']=$nombre;
      } else {
        $err='No se pudo guardar.';
      }
      $st->close();
    }
  }

  if($act==='password'){
    $p1=$_POST['p1']??''; 
    $p2=$_POST['p2']??'';
    if(!$p1 || $p1!==$p2){
      $err='Las contraseñas no coinciden.';
    }else{
      $hash=password_hash($p1,PASSWORD_BCRYPT);
      $st=$conn->prepare("UPDATE usuarios SET pass_hash=? WHERE id=?");
      $st->bind_param("si",$hash,$uid);
      if($st->execute()){
        $ok='Contraseña actualizada.';
      } else {
        $err='No se pudo actualizar.';
      }
      $st->close();
    }
  }

  if($act==='avatar' && isset($_FILES['avatar']) && $_FILES['avatar']['error']===UPLOAD_ERR_OK){
    $tmp=$_FILES['avatar']['tmp_name'];
    $info=@getimagesize($tmp);
    if(!$info){
      $err='Archivo no es imagen.';
    }else{
      $mime=$info['mime']; 
      $ext = $mime==='image/png' ? 'png' : 'jpg';
      if(!in_array($mime,['image/jpeg','image/png'])){
        $err='Solo JPG o PNG.';
      }else{
        // crea carpeta si no existe
        $dir=__DIR__.'/public/avatars';
        if(!is_dir($dir)) @mkdir($dir, 0775, true);
        $dest = $dir.'/'.$uid.'.'.$ext;

        if(!move_uploaded_file($tmp,$dest)){
          $err='No se pudo guardar la imagen.';
        }else{
          // ruta relativa para BD
          $ruta='public/avatars/'.$uid.'.'.$ext;
          $st=$conn->prepare("UPDATE usuarios SET avatar=? WHERE id=?");
          $st->bind_param("si",$ruta,$uid);
          if($st->execute()){
            $ok='Avatar actualizado.';
          } else {
            $err='No se pudo actualizar avatar.';
          }
          $st->close();
        }
      }
    }
  }
}

// Cargar datos
$st=$conn->prepare("SELECT nombre,correo,telefono,direccion,avatar,rol FROM usuarios WHERE id=? LIMIT 1");
$st->bind_param("i",$uid);
$st->execute();
$r  =$st->get_result();
$me =$r->fetch_assoc();
$st->close();

$avatar = $me['avatar'] ?: 'public/img/servicios/logo.jpg';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Mi perfil | BETANDENT</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
    :root{
  --brand:#5a2de2;
  --brand2:#2b70ff;
  --accent:#37e5d8;
  --bg:#0f0f17;
  --card:#1b1b27;
  --ink:#f5f5f7;
  --muted:#9ba0b7;
  --radius:18px;

  --shadow:0 18px 45px rgba(0,0,0,.55);
}

/* GENERAL */
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
  background:
    radial-gradient(circle at top left,#1d2234 0,#10121c 50%,#0b0c14 100%);
  min-height:100vh;
  color:var(--ink);
}
a{text-decoration:none;color:inherit}

/* TOP BAR */
.top{
  background:linear-gradient(130deg,var(--brand) 0%,var(--brand2) 45%,#1829a0 100%);
  color:#fff;
  padding:14px 24px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  box-shadow:0 10px 35px rgba(0,0,0,.6);
  position:sticky;
  top:0;
  z-index:10;
}
.top-title{
  font-weight:600;
  display:flex;
  align-items:center;
  gap:10px;
  letter-spacing:.5px;
}
.logo-circle{
  width:30px;
  height:30px;
  border-radius:999px;
  background:#ffffff25;
  display:flex;
  justify-content:center;
  align-items:center;
  font-weight:700;
}

/* WRAP */
.wrap{
  max-width:1000px;
  margin:0 auto;
  padding:28px 16px 40px;
}

/* HEADINGS */
.page-heading h1{
  font-size:1.5rem;
  margin:0;
  background:linear-gradient(90deg,var(--brand2),var(--accent));
  -webkit-background-clip:text;
  color:transparent;
}
.page-heading p{margin:6px 0 0;color:var(--muted);font-size:.95rem}

.chip{
  font-size:.8rem;
  padding:4px 10px;
  border-radius:999px;
  background:#ffffff10;
  color:var(--accent);
  border:1px solid #ffffff15;
  backdrop-filter:blur(5px);
}

/* GRID */
.grid{display:grid;gap:22px}
.cols{grid-template-columns:1fr 1fr}
@media(max-width:960px){.cols{grid-template-columns:1fr}}

/* CARD */
.card{
  background:var(--card);
  border-radius:var(--radius);
  padding:22px 20px 26px;
  box-shadow:var(--shadow);
  position:relative;
  overflow:hidden;
  border:1px solid #ffffff08;
}
.card::before{
  content:"";
  position:absolute;
  inset:-40%;
  background:
    radial-gradient(circle at top right,rgba(91,131,255,.25),transparent 60%),
    radial-gradient(circle at bottom left,rgba(97,36,255,.25),transparent 60%);
  pointer-events:none;
  opacity:.6;
}
.card-title{margin:0;font-size:1.1rem;font-weight:600}
.card-sub{margin:2px 0 0;color:var(--muted);font-size:.85rem}

/* INPUTS */
label{
  margin-top:14px;
  font-weight:600;
  font-size:.9rem;
  display:block;
}
input,textarea{
  width:100%;
  padding:12px;
  border-radius:12px;
  margin-top:6px;
  font-size:.93rem;
  border:1px solid #ffffff18;
  background:#11111a;
  color:var(--ink);
  transition:all .18s ease;
}
input:focus,textarea:focus{
  border-color:var(--accent);
  box-shadow:0 0 10px rgba(55,229,216,.25);
  background:#161622;
}

/* BUTTONS */
.btn{
  background:linear-gradient(130deg,var(--brand) 0%,var(--brand2) 50%,var(--accent) 100%);
  padding:10px 16px;
  border:none;
  border-radius:999px;
  cursor:pointer;
  color:#fff;
  font-weight:600;
  font-size:.9rem;
  box-shadow:0 15px 28px rgba(0,0,0,.45);
  transition:all .15s ease;
}
.btn:hover{
  transform:translateY(-2px);
  box-shadow:0 24px 38px rgba(0,0,0,.55);
}
.btn:active{
  transform:translateY(0);
  box-shadow:0 12px 24px rgba(0,0,0,.5);
}

.btn-ghost{
  background:#ffffff15;
  color:var(--accent);
  border:1px solid #ffffff25;
  box-shadow:0 10px 25px rgba(0,0,0,.35);
}
.btn-ghost:hover{background:#ffffff25}

/* ALERTAS */
.alert{
  padding:12px 14px;
  border-radius:12px;
  margin:18px 0;
  font-size:.9rem;
}
.ok{
  background:#0f5132;
  color:#d1fae5;
}
.err{
  background:#5b2b30;
  color:#fbcbd0;
}

/* AVATAR */
.avatar-wrap{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.avatar{
  width:120px;
  height:120px;
  border-radius:999px;
  object-fit:cover;
  border:3px solid #ffffff30;
  box-shadow:0 20px 40px rgba(0,0,0,.6);
}
.role-pill{
  background:#ffffff08;
  padding:4px 12px;
  border-radius:999px;
  color:var(--accent);
  font-size:.8rem;
  border:1px solid #ffffff15;
}

/* PASSWORD GRID */
.password-row{
  display:grid;
  gap:12px;
  grid-template-columns:1fr 1fr auto;
}
@media(max-width:720px){
  .password-row{grid-template-columns:1fr}
}

  </style>
</head>
<body>

  <header class="top">
    <div class="top-title">
      <span class="logo-circle">B</span>
      <span>BETANDENT · Mi perfil</span>
    </div>
    <div class="row">
      <?php if($me['rol']==='paciente'): ?>
        <a class="btn btn-ghost" href="citas_cliente.php">
          Mis citas
        </a>
      <?php else: ?>
        <a class="btn btn-ghost" href="panel.php">
          Panel
        </a>
      <?php endif; ?>
    </div>
  </header>

  <main class="wrap">
    <div class="page-heading">
      <div>
        <h1>Configuración de perfil</h1>
        <p>Actualiza tus datos, tu fotografía y mantén tu cuenta protegida.</p>
      </div>
      <div class="chip">
        Perfil activo
        <span style="width:8px;height:8px;border-radius:999px;background:#22c55e;box-shadow:0 0 0 5px rgba(34,197,94,.25);"></span>
      </div>
    </div>

    <?php if($ok): ?>
      <div class="alert ok">
        ✅ <?= e($ok) ?>
      </div>
    <?php endif; ?>

    <?php if($err): ?>
      <div class="alert err">
        ⚠️ <?= e($err) ?>
      </div>
    <?php endif; ?>

    <div class="grid cols">
      <!-- Perfil + avatar -->
      <section class="card">
        <div class="card-header">
          <div>
            <h2 class="card-title">Datos del perfil</h2>
            <p class="card-sub">Nombre, correo, rol y fotografía de tu cuenta.</p>
          </div>
        </div>

        <div class="avatar-wrap">
          <img class="avatar" src="<?= e($avatar) ?>" alt="avatar"
               onerror="this.src='public/img/servicios/logo.jpg'">
          <div>
            <div style="font-weight:600;font-size:1.05rem"><?= e($me['nombre']) ?></div>
            <div class="muted"><?= e($me['correo']) ?></div>
            <div class="role-pill">
              <span style="width:7px;height:7px;border-radius:999px;background:#4f46e5;"></span>
              Rol: <?= e($me['rol']) ?>
            </div>
          </div>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:16px">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="avatar">

          <label>Actualizar foto de perfil</label>
          <div class="section-note">
            JPG o PNG, tamaño recomendado 400×400 px, peso máximo ~2MB.
          </div>
          <input class="file-input" type="file" name="avatar" accept="image/jpeg,image/png" required>

          <button class="btn" style="margin-top:10px;">
            Subir nueva foto
          </button>
        </form>
      </section>

      <!-- Datos básicos -->
      <section class="card">
        <div class="card-header">
          <div>
            <h2 class="card-title">Editar datos</h2>
            <p class="card-sub">Información de contacto que usará la clínica.</p>
          </div>
        </div>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="datos">

          <label>Nombre completo</label>
          <input name="nombre" value="<?= e($me['nombre']) ?>" required>

          <label>Teléfono</label>
          <input name="telefono" value="<?= e($me['telefono'] ?? '') ?>" placeholder="Ej. 483 000 0000">

          <label>Dirección</label>
          <textarea name="direccion" rows="3" placeholder="Calle, número, colonia, ciudad"><?= e($me['direccion'] ?? '') ?></textarea>

          <button class="btn" style="margin-top:12px;">
            Guardar cambios
          </button>
        </form>
      </section>

      <!-- Password -->
      <section class="card" style="grid-column:1/-1">
        <div class="card-header">
          <div>
            <h2 class="card-title">Cambiar contraseña</h2>
            <p class="card-sub">Te recomendamos una contraseña larga y única para este sistema.</p>
          </div>
        </div>

        <form method="post" class="password-row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="act" value="password">

          <input type="password" name="p1" placeholder="Nueva contraseña" required>
          <input type="password" name="p2" placeholder="Repite la contraseña" required>

          <button class="btn">
            Actualizar contraseña
          </button>
        </form>

        <div class="section-note" style="margin-top:10px;">
          Tip: combina mayúsculas, minúsculas, números y símbolos, y evita usar la misma contraseña en otros sitios.
        </div>
      </section>
    </div>
  </main>

</body>
</html>
