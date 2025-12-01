<?php
// configuracion.php — Preferencias del sistema (solo admin) · BETANDENT
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
   Helpers config
   =========================== */
function get_cfg(mysqli $conn, $clave, $def=''){
  $st = $conn->prepare("SELECT valor FROM configuracion WHERE clave=? LIMIT 1");
  $st->bind_param("s",$clave);
  $st->execute(); $r=$st->get_result();
  $row = $r->fetch_assoc(); $st->close();
  return $row ? $row['valor'] : $def;
}
function set_cfg(mysqli $conn, $clave, $valor){
  $st = $conn->prepare("INSERT INTO configuracion(clave,valor) VALUES(?,?)
                        ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
  $st->bind_param("ss",$clave,$valor);
  $ok = $st->execute(); $err = $st->error ?: $conn->error;
  $st->close();
  if(!$ok) throw new Exception($err);
}

/* ===========================
   POST
   =========================== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }

  try {
    // 1/0 desde checkbox
    $reg_admin     = isset($_POST['registro_admin_bloqueado']) ? '1' : '0';
    $reg_empleado  = isset($_POST['registro_empleado_bloqueado']) ? '1' : '0';
    $reg_paciente  = isset($_POST['registro_paciente_bloqueado']) ? '1' : '0';

    // Horario y cuenta
    $horario = trim($_POST['horario_trabajo'] ?? 'L-S 08:00-18:00');
    $cuenta  = trim($_POST['cuenta_transferencia'] ?? 'Banco X · 0123456789 · CLABE 000000000000000000');

    // Datos de clínica opcionales (por si quieres mostrarlos en index)
    $clinica_nombre    = trim($_POST['clinica_nombre'] ?? 'BETANDENT');
    $clinica_direccion = trim($_POST['clinica_direccion'] ?? 'Calle Ejemplo #123, Col. Centro, CP 00000');
    $maps_q            = trim($_POST['maps_embed_q'] ?? 'BETANDENT, Calle Ejemplo 123, Centro');

    set_cfg($conn,'registro_admin_bloqueado',$reg_admin);
    set_cfg($conn,'registro_empleado_bloqueado',$reg_empleado);
    set_cfg($conn,'registro_paciente_bloqueado',$reg_paciente);
    set_cfg($conn,'horario_trabajo',$horario);
    set_cfg($conn,'cuenta_transferencia',$cuenta);

    // Estas tres no estaban en el esquema original, pero usamos la misma tabla:
    set_cfg($conn,'clinica_nombre',$clinica_nombre);
    set_cfg($conn,'clinica_direccion',$clinica_direccion);
    set_cfg($conn,'maps_embed_q',$maps_q);

    flash('ok','Configuración guardada.');
  } catch (Exception $ex) {
    flash('err','No se pudo guardar: '.e($ex->getMessage()));
  }
  header("Location: configuracion.php"); exit;
}

/* ===========================
   Cargar valores actuales
   =========================== */
$reg_admin     = get_cfg($conn,'registro_admin_bloqueado','0');
$reg_empleado  = get_cfg($conn,'registro_empleado_bloqueado','0');
$reg_paciente  = get_cfg($conn,'registro_paciente_bloqueado','0');
$horario       = get_cfg($conn,'horario_trabajo','L-S 08:00-18:00');
$cuenta        = get_cfg($conn,'cuenta_transferencia','Banco X · 0123456789 · CLABE 000000000000000000');

$clinica_nombre    = get_cfg($conn,'clinica_nombre','BETANDENT');
$clinica_direccion = get_cfg($conn,'clinica_direccion','Calle Ejemplo #123, Col. Centro, CP 00000');
$maps_q            = get_cfg($conn,'maps_embed_q','BETANDENT, Calle Ejemplo 123, Centro');

$ok=flash('ok'); $err=flash('err');
?>
<!doctype html>
<meta charset="utf-8">
<title>Configuración | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--brand:#0b5ed7;--ink:#111827;--muted:#6b7280;--bg:#f7f8fc;--card:#fff;--shadow:0 8px 30px rgba(0,0,0,.06);--radius:16px}
  *{box-sizing:border-box} html,body{margin:0} body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  a{text-decoration:none;color:inherit}
  .top{background:var(--brand);color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}
  .wrap{max-width:1100px;margin:0 auto;padding:20px 16px}
  .grid{display:grid;gap:16px}
  .cols{grid-template-columns:1fr 1fr}
  @media (max-width:1000px){.cols{grid-template-columns:1fr}}
  .card{background:var(--card);border-radius:16px;box-shadow:var(--shadow);padding:16px}
  label{display:block;margin-top:8px;font-weight:600}
  input,textarea{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-top:4px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .pill{background:#eef;border-radius:999px;padding:4px 10px;font-size:.9rem}
  .btn{display:inline-block;background:var(--brand);color:#fff;border:none;border-radius:10px;padding:9px 14px;cursor:pointer}
  .alert{padding:10px;border-radius:10px;margin:10px 0}
  .ok{background:#e8f7ed;color:#117a2b}
  .err{background:#fde8ec;color:#b00020}
  .muted{color:var(--muted)}
  .switch{display:flex;align-items:center;gap:8px;margin:8px 0}
</style>

<div class="top">
  <div><a href="panel.php" style="color:#fff;font-weight:800">BETANDENT</a> · Configuración</div>
  <div class="row">
    <a class="btn" style="background:#fff;color:var(--brand)" href="panel.php">Volver</a>
  </div>
</div>

<div class="wrap">
  <?php if($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="grid cols">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <!-- Registro y accesos -->
    <div class="card">
      <h2 style="margin:0 0 10px">Registros permitidos</h2>
      <p class="muted">Activa o bloquea quién puede crear cuenta nueva desde la web o la clínica.</p>

      <label class="switch">
        <input type="checkbox" name="registro_admin_bloqueado" <?= $reg_admin==='1'?'checked':'' ?>>
        <span>Bloquear registro de administrador</span>
      </label>
      <p class="muted">Recomendado: activado una vez creado el dueño. Evita que cualquiera se haga “admin”.</p>

      <label class="switch">
        <input type="checkbox" name="registro_empleado_bloqueado" <?= $reg_empleado==='1'?'checked':'' ?>>
        <span>Bloquear registro de empleado</span>
      </label>
      <p class="muted">Si está bloqueado, solo el admin puede dar de alta empleados en <strong>Empleados</strong>.</p>

      <label class="switch">
        <input type="checkbox" name="registro_paciente_bloqueado" <?= $reg_paciente==='1'?'checked':'' ?>>
        <span>Bloquear registro de paciente</span>
      </label>
      <p class="muted">Si activas esto, los pacientes no podrán registrarse por sí mismos.</p>
    </div>

    <!-- Horario y transferencias -->
    <div class="card">
      <h2 style="margin:0 0 10px">Clínica · Horario y pagos</h2>

      <label>Horario de trabajo (texto)</label>
      <input name="horario_trabajo" value="<?= e($horario) ?>" placeholder="L-S 08:00-18:00">

      <label>Cuenta para transferencias (se muestra al pagar)</label>
      <textarea name="cuenta_transferencia" rows="2" placeholder="Banco X · 0123456789 · CLABE ..."><?= e($cuenta) ?></textarea>

      <h2 style="margin:16px 0 8px">Datos visibles en el sitio</h2>
      <label>Nombre de la clínica</label>
      <input name="clinica_nombre" value="<?= e($clinica_nombre) ?>">

      <label>Dirección</label>
      <input name="clinica_direccion" value="<?= e($clinica_direccion) ?>">

      <label>Google Maps (consulta “q” del embed)</label>
      <input name="maps_embed_q" value="<?= e($maps_q) ?>" placeholder="BETANDENT, Calle ...">
    </div>

    <div class="card" style="grid-column:1/-1">
      <div class="row" style="justify-content:space-between">
        <div class="muted">Se guardará todo lo anterior en <code>configuracion</code>.</div>
        <div class="row">
          <button class="btn">Guardar cambios</button>
          <a class="btn" style="background:#6b7280" href="panel.php">Cancelar</a>
        </div>
      </div>
    </div>
  </form>

  <div class="card" style="margin-top:16px">
    <h2 style="margin:0 0 8px">Cómo se aplican estos switches</h2>
    <p class="muted">Si aún no lo hiciste, agrega estos checks en tus formularios para obedecer la configuración:</p>

    <pre style="white-space:pre-wrap;background:#f8fafc;padding:10px;border-radius:10px;border:1px solid #eef">
<?php
highlight_string('
/* En registro_admin.php */
$bloq = get_cfg($conn, "registro_admin_bloqueado", "0");
if ($bloq==="1") { die("Registro de administrador bloqueado por configuración."); }

/* En registrar_empleado.php (si expones uno público) */
$bloq = get_cfg($conn, "registro_empleado_bloqueado", "0");
if ($bloq==="1") { die("Registro de empleado bloqueado por configuración."); }

/* En registro.php (pacientes) */
$bloq = get_cfg($conn, "registro_paciente_bloqueado", "0");
if ($bloq==="1") { die("Registro de paciente bloqueado por configuración."); }

/* En index.php para mostrar nombre/dirección/mapa dinámicos */
$cfgClinica = [
  "nombre"    => get_cfg($conn,"clinica_nombre","BETANDENT"),
  "direccion" => get_cfg($conn,"clinica_direccion","Calle Ejemplo"),
  "maps_q"    => urlencode(get_cfg($conn,"maps_embed_q","BETANDENT, Calle Ejemplo"))
];
');
?>
    </pre>
  </div>
</div>
