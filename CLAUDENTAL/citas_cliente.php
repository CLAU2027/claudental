<?php
// citas_cliente.php — Agenda del paciente (BETANDENT)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['paciente']); // solo pacientes

$conn = db();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); $csrf=$_SESSION['csrf'];
$uid = (int)$_SESSION['uid'];

// Parámetros de agenda
$slotMin=60; $hInicio=8; $hFin=18; $incluyeDomingo=false;
// Feriados básicos (ajusta si quieres)
$feriados = [
  date('Y').'-01-01', date('Y').'-05-01', date('Y').'-09-16', date('Y').'-12-25'
];

// Día a mostrar
$hoy = date('Y-m-d');
$dia = $_GET['dia'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dia)) $dia = $hoy;

// Generar slots
$slots = [];
for($h=$hInicio;$h<$hFin;$h++){
  $slots[] = sprintf('%02d:00',$h);
  if($slotMin===30) $slots[] = sprintf('%02d:30',$h);
}

// Servicios activos
$servicios=[];
if($res=$conn->query("SELECT id,nombre,tipo FROM servicios WHERE activo=1 ORDER BY tipo,nombre")){
  while($row=$res->fetch_assoc()) $servicios[]=$row; $res->free();
}

// Citas del día (para bloquear ocupados)
$ocupados=[];
$st=$conn->prepare("SELECT hora FROM citas WHERE fecha=? AND estado IN ('pendiente','confirmada','atendida')");
$st->bind_param("s",$dia); $st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){ $ocupados[substr($row['hora'],0,5)] = true; }
$st->close();

// Acciones
$ok=$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if (empty($_POST['csrf']) || $_POST['csrf']!==$csrf) { http_response_code(400); die('CSRF'); }
  $act = $_POST['act'] ?? '';

  if($act==='crear'){
    $servicio_id = (int)($_POST['servicio_id'] ?? 0);
    $fecha = $_POST['fecha'] ?? $hoy;
    $hora  = $_POST['hora'] ?? '';
    $notas = trim($_POST['notas'] ?? '');

    // Validaciones
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || !preg_match('/^\d{2}:\d{2}$/',$hora)){
      $err='Fecha u hora inválidas.';
    } else {
      $dow = (int)date('N', strtotime($fecha)); // 1..7
      if(($dow===7 && !$incluyeDomingo) || in_array($fecha,$feriados,true)){
        $err='Ese día no se agenda.'; 
      } else {
        [$hh,$mm] = explode(':',$hora);
        if((int)$hh < $hInicio || (int)$hh >= $hFin) $err='Fuera de horario (08:00 a 18:00).';
        elseif($slotMin===30 && !in_array($mm,['00','30'])) $err='Minutos deben ser 00 o 30.';
      }
    }
    if(!$err){
      // Verifica servicio
      $okServ=true; $sid=null;
      if($servicio_id>0){
        $st=$conn->prepare("SELECT id FROM servicios WHERE id=? AND activo=1");
        $st->bind_param("i",$servicio_id); $st->execute(); $okServ=(bool)$st->get_result()->fetch_assoc(); $st->close();
        $sid = $okServ? $servicio_id : null;
      }

      if(!$okServ){ $err='Servicio inválido.'; }
      else{
        // Crear cita pendiente
        $estado='pendiente';
        $st=$conn->prepare("INSERT INTO citas(paciente_id,servicio_id,fecha,hora,estado,notas) VALUES (?,?,?,?,?,?)");
        if($sid===null){
          $null=null; $st->bind_param("iissss",$uid,$null,$fecha,$hora,$estado,$notas);
        } else {
          $st->bind_param("iissss",$uid,$sid,$fecha,$hora,$estado,$notas);
        }
        if($st->execute()){ $ok='Cita creada. Te confirmaremos por el sistema.'; }
        else{
          $e=$st->error ?: $conn->error;
          if(stripos($e,'Duplicate')!==false) $err='Ese horario ya está ocupado.';
          else $err='No se pudo crear la cita: '.e($e);
        }
        $st->close();
        // refrescar ocupados del día
        header("Location: citas_cliente.php?dia=".urlencode($fecha)); exit;
      }
    }
  }

  if($act==='cancelar'){
    $id=(int)($_POST['id'] ?? 0);
    // Solo puede cancelar su propia cita y si no está atendida
    $st=$conn->prepare("UPDATE citas SET estado='cancelada' WHERE id=? AND paciente_id=? AND estado IN ('pendiente','confirmada')");
    $st->bind_param("ii",$id,$uid);
    if($st->execute() && $st->affected_rows>0){ $ok='Cita cancelada.'; } else { $err='No se pudo cancelar.'; }
    $st->close();
    header("Location: citas_cliente.php?dia=".urlencode($dia)); exit;
  }
}

// Citas futuras del paciente
$mis = [];
$st=$conn->prepare("SELECT c.id,c.fecha,c.hora,c.estado,c.notas,s.nombre AS servicio
                    FROM citas c
                    LEFT JOIN servicios s ON s.id=c.servicio_id
                    WHERE c.paciente_id=? AND CONCAT(c.fecha,' ',c.hora) >= NOW()
                    ORDER BY c.fecha ASC, c.hora ASC");
$st->bind_param("i",$uid); $st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()) $mis[]=$row;
$st->close();
?>
<!doctype html>
<meta charset="utf-8">
<title>Mis citas | BETANDENT</title>
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
  input,select{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-top:4px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .btn{display:inline-block;background:var(--brand);color:#fff;border:none;border-radius:10px;padding:8px 12px;cursor:pointer}
  table{width:100%;border-collapse:collapse} th,td{padding:10px;border-bottom:1px solid #eef;text-align:left}
  .muted{color:var(--muted)} .pill{background:#eef;border-radius:999px;padding:4px 10px;font-size:.9rem}
  .alert{padding:10px;border-radius:10px;margin:10px 0} .ok{background:#e8f7ed;color:#117a2b} .err{background:#fde8ec;color:#b00020}
</style>

<div class="top">
  <div>BETANDENT · Mis citas</div>
  <form method="get" class="row">
    <input type="date" name="dia" value="<?= e($dia) ?>">
    <button class="btn" style="background:#fff;color:var(--brand)">Ver día</button>
    <a class="btn" style="background:#fff;color:var(--brand)" href="perfil.php">Mi perfil</a>
    <a class="btn" style="background:#fff;color:var(--brand)" href="index.php">inicio</a>
  </form>
</div>

<div class="wrap">
  <?php if($ok): ?><div class="alert ok"><?= e($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

  <div class="grid cols">
    <div class="card">
      <h2 style="margin:0 0 10px">Agendar en <?= e($dia) ?></h2>
      <?php
        $dow=(int)date('N',strtotime($dia));
        if(($dow===7 && !$incluyeDomingo) || in_array($dia,$feriados,true)){
          echo '<p class="muted">No hay agenda este día. Elige otra fecha.</p>';
        } else {
      ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="act" value="crear">
        <label>Servicio (opcional)</label>
        <select name="servicio_id">
          <option value="0">— sin servicio —</option>
          <?php foreach($servicios as $s): ?>
            <option value="<?= (int)$s['id'] ?>">[<?= e($s['tipo']) ?>] <?= e($s['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="row">
          <div style="flex:1">
            <label>Fecha</label>
            <input type="date" name="fecha" value="<?= e($dia) ?>" required>
          </div>
          <div style="flex:1">
            <label>Hora</label>
            <select name="hora" required>
              <?php foreach($slots as $h): ?>
                <option value="<?= e($h) ?>" <?= isset($ocupados[$h])?'disabled':'' ?>>
                  <?= e($h) ?> <?= isset($ocupados[$h])?'(ocupado)':'' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <label>Notas (opcional)</label>
        <input name="notas" maxlength="200" placeholder="Ej. control de ortodoncia">
        <button class="btn" style="margin-top:10px">Apartar cita</button>
      </form>
      <?php } ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px">Mis próximas citas</h2>
      <?php if(empty($mis)): ?>
        <p class="muted">No tienes citas próximas.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Fecha</th><th>Hora</th><th>Servicio</th><th>Estado</th><th></th></tr></thead>
          <tbody>
          <?php foreach($mis as $c): ?>
            <tr>
              <td><?= e($c['fecha']) ?></td>
              <td><?= e(substr($c['hora'],0,5)) ?></td>
              <td><?= e($c['servicio'] ?? '—') ?></td>
              <td><span class="pill"><?= e($c['estado']) ?></span></td>
              <td>
                <?php if(in_array($c['estado'],['pendiente','confirmada'])): ?>
                  <form method="post" onsubmit="return confirm('¿Cancelar esta cita?')">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="act" value="cancelar">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button class="btn" style="background:#b00020">Cancelar</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
