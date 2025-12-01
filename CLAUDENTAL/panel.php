<?php
// panel.php — BETANDENT
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
$conn = db();

// Helpers rápidos
function q1($conn, $sql){
  $v = 0;
  if ($r = $conn->query($sql)) { $row = $r->fetch_row(); $v = (float)$row[0]; $r->free(); }
  return $v;
}
function q1i($conn, $sql){
  $v = 0;
  if ($r = $conn->query($sql)) { $row = $r->fetch_row(); $v = (int)$row[0]; $r->free(); }
  return $v;
}

// ROL
$rol = usuario_rol();
$nombre = usuario_nombre();

// Stats comunes
$pacientes_locales    = q1i($conn, "SELECT COUNT(*) FROM pacientes");
$servicios_activos    = q1i($conn, "SELECT COUNT(*) FROM servicios WHERE activo=1");
$citas_pendientes     = q1i($conn, "SELECT COUNT(*) FROM citas WHERE estado='pendiente'");
$ventas_hoy           = q1($conn,  "SELECT IFNULL(SUM(total),0) FROM ventas WHERE estado='pagado' AND DATE(creado_en)=CURDATE()");
$ventas_pendientes    = q1i($conn, "SELECT COUNT(*) FROM ventas WHERE estado='pendiente'");
$tratamientos_abiertos= q1i($conn, "SELECT COUNT(*) FROM tratamientos WHERE estado='abierto'");

// Menús por rol
$menu_admin = [
  ['Pacientes', 'pacientes.php', 'P'],
  ['Servicios', 'servicios.php', 'S'],
  ['Tratamientos', 'tratamientos.php', 'T'],
  ['Cobros', 'cobros.php', 'C'],
  ['Caja', 'caja.php', 'X'],
  ['Reportes', 'reportes.php', 'R'],
  ['Citas', 'citas.php', 'C'],
  ['Empleados', 'empleados.php', 'E'],
  ['Configuración', 'configuracion.php', '⚙'],
];

$menu_empleado = [
  ['Pacientes', 'pacientes.php', 'P'],
  ['Tratamientos', 'tratamientos.php', 'T'],
  ['Cobros', 'cobros.php', 'C'],
  ['Citas', 'citas.php', 'C'],
  ['Reportes', 'reportes.php', 'R'],
];

$menu_paciente = [
  ['Mis citas', 'citas_cliente.php', 'C'],
  ['Subir comprobante', 'transferir.php', '↑'],
  ['Precios', 'index.php#servicios', '$'],
];

$menu = es_admin() ? $menu_admin : (es_empleado() ? $menu_empleado : $menu_paciente);

// Últimas 5 citas pendientes (solo admin/empleado)
$ultimas_citas = [];
if (!es_paciente()) {
  $sql = "SELECT c.id, u.nombre AS paciente, s.nombre AS servicio, c.fecha, c.hora, c.estado
          FROM citas c
          LEFT JOIN usuarios u ON u.id=c.paciente_id
          LEFT JOIN servicios s ON s.id=c.servicio_id
          WHERE c.estado IN ('pendiente','confirmada')
          ORDER BY c.fecha, c.hora
          LIMIT 5";
  if ($r = $conn->query($sql)) { while ($row = $r->fetch_assoc()) $ultimas_citas[] = $row; $r->free(); }
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Panel | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --brand:#0b5ed7;
    --brand-soft:#e5efff;
    --accent:#16a085;
    --bg:#0f172a;
    --bg-soft:#f3f4f8;
    --card:#ffffff;
    --ink:#0f172a;
    --muted:#6b7280;
    --ok:#16a34a;
    --warn:#eab308;
    --danger:#dc2626;
    --chip:#e5e7eb;
    --shadow-strong:0 18px 45px rgba(15,23,42,.35);
    --shadow-soft:0 10px 30px rgba(15,23,42,.18);
    --radius-xl:22px;
    --radius-md:14px;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
    color:var(--ink);
    background:
      radial-gradient(circle at top left,rgba(37,99,235,.38),transparent 60%),
      radial-gradient(circle at bottom right,rgba(20,184,166,.35),transparent 60%),
      #020617;
    display:flex;
    flex-direction:column;
  }
  a{text-decoration:none;color:inherit}

  /* TOPBAR */
  .topbar{
    position:sticky;
    top:0;
    z-index:30;
    backdrop-filter:blur(16px);
    background:rgba(15,23,42,.92);
    color:#e5e7eb;
    padding:10px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid rgba(148,163,184,.35);
  }
  .top-left{
    display:flex;
    align-items:center;
    gap:10px;
  }
  .brand-icon{
    width:34px;height:34px;
    border-radius:13px;
    background:linear-gradient(135deg,var(--brand),var(--accent));
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:800;
    font-size:18px;
    color:#fff;
    box-shadow:0 10px 26px rgba(15,23,42,.7);
  }
  .brand-text{
    display:flex;
    flex-direction:column;
  }
  .brand-name{
    font-weight:800;
    letter-spacing:.08em;
    font-size:13px;
  }
  .brand-sub{
    font-size:11px;
    color:#9ca3af;
  }
  .top-right{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:13px;
  }
  .role-pill{
    border-radius:999px;
    padding:3px 9px;
    background:rgba(15,23,42,.6);
    border:1px solid rgba(148,163,184,.6);
    color:#e5e7eb;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.08em;
  }
  .logout-link{
    color:#e5e7eb;
    font-weight:500;
    padding:5px 8px;
    border-radius:999px;
    border:1px solid rgba(248,250,252,.18);
    transition:background .15s,transform .1s,box-shadow .1s;
  }
  .logout-link:hover{
    background:rgba(248,250,252,.06);
    transform:translateY(-1px);
    box-shadow:0 4px 14px rgba(15,23,42,.6);
  }

  /* LAYOUT */
  .page{
    flex:1;
    padding:20px 16px 26px 16px;
  }
  .shell{
    max-width:1200px;
    margin:0 auto;
  }

  .page-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    flex-wrap:wrap;
    gap:12px;
    color:#e5e7eb;
    margin-bottom:18px;
  }
  .page-title{
    font-size:26px;
    font-weight:600;
    letter-spacing:.02em;
  }
  .page-sub{
    font-size:13px;
    color:#9ca3af;
    margin-top:4px;
  }
  .tagline{
    font-size:12px;
    color:#cbd5f5;
    padding:4px 9px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.5);
    background:rgba(15,23,42,.65);
  }

  .content-grid{
    display:grid;
    grid-template-columns:3.2fr 2.2fr;
    gap:18px;
    align-items:flex-start;
  }
  @media (max-width:960px){
    .content-grid{
      grid-template-columns:1fr;
    }
  }

  .panel-main{
    background:linear-gradient(145deg,rgba(248,250,252,1),rgba(226,232,240,1));
    border-radius:var(--radius-xl);
    padding:18px 18px 20px 18px;
    box-shadow:var(--shadow-strong);
    border:1px solid rgba(148,163,184,.45);
  }
  .panel-side{
    display:flex;
    flex-direction:column;
    gap:14px;
  }

  /* STATS */
  .stats-grid{
    display:grid;
    gap:12px;
  }
  .stats-grid-main{
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
  .stats-grid-sub{
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
  @media(max-width:900px){
    .stats-grid-main,.stats-grid-sub{
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
  }
  @media(max-width:640px){
    .stats-grid-main,.stats-grid-sub{
      grid-template-columns:minmax(0,1fr);
    }
  }

  .stat-card{
    background:#fff;
    border-radius:18px;
    padding:10px 12px 12px 12px;
    box-shadow:var(--shadow-soft);
    border:1px solid rgba(226,232,240,1);
    position:relative;
    overflow:hidden;
  }
  .stat-label{
    font-size:12px;
    color:var(--muted);
    display:flex;
    justify-content:space-between;
    align-items:center;
  }
  .stat-chip{
    font-size:10px;
    padding:2px 7px;
    border-radius:999px;
    background:var(--brand-soft);
    color:#1d4ed8;
  }
  .stat-value{
    margin-top:6px;
    font-size:20px;
    font-weight:800;
    letter-spacing:.03em;
    color:var(--ink);
  }
  .stat-foot{
    margin-top:4px;
    font-size:11px;
    color:var(--muted);
  }

  .stat-card.accent{
    background:linear-gradient(135deg,var(--brand),#1d4ed8);
    color:#e5e7eb;
    border-color:rgba(59,130,246,.7);
  }
  .stat-card.accent .stat-label{color:#dbeafe;}
  .stat-card.accent .stat-value{color:#f9fafb;}
  .stat-card.accent .stat-foot{color:#e5e7eb;}
  .stat-card.accent .stat-chip{
    background:rgba(15,23,42,.35);
    color:#e5e7eb;
    border:1px solid rgba(191,219,254,.65);
  }

  /* MODULES */
  .section-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin:14px 0 8px 0;
  }
  .section-title{
    font-size:14px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.11em;
    color:#4b5563;
  }
  .section-sub{
    font-size:12px;
    color:#9ca3af;
  }

  .modules-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
  }
  @media(max-width:960px){
    .modules-grid{
      grid-template-columns:repeat(3,minmax(0,1fr));
    }
  }
  @media(max-width:820px){
    .modules-grid{
      grid-template-columns:repeat(2,minmax(0,1fr));
    }
  }
  @media(max-width:640px){
    .modules-grid{
      grid-template-columns:minmax(0,1fr);
    }
  }

  .module-tile{
    background:#f9fafb;
    border-radius:16px;
    padding:10px 11px;
    border:1px solid rgba(226,232,240,1);
    display:flex;
    align-items:center;
    gap:10px;
    transition:transform .12s, box-shadow .12s, border-color .12s, background .12s;
  }
  .module-icon{
    width:34px;height:34px;
    border-radius:12px;
    background:var(--brand-soft);
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:17px;
    color:#1d4ed8;
  }
  .module-body{
    display:flex;
    flex-direction:column;
  }
  .module-title{
    font-size:13px;
    font-weight:600;
  }
  .module-sub{
    font-size:11px;
    color:#9ca3af;
  }
  .module-tile:hover{
    transform:translateY(-2px);
    box-shadow:var(--shadow-soft);
    background:#ffffff;
    border-color:rgba(129,140,248,.8);
  }

  /* BUTTONS */
  .btn{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:linear-gradient(135deg,var(--brand),#1d4ed8);
    color:#f9fafb;
    border:none;
    border-radius:999px;
    padding:8px 13px;
    font-size:12px;
    font-weight:600;
    letter-spacing:.06em;
    text-transform:uppercase;
    cursor:pointer;
    box-shadow:0 8px 24px rgba(15,23,42,.35);
    transition:transform .1s, box-shadow .1s, filter .12s;
  }
  .btn span{
    font-size:12px;
  }
  .btn::after{
    content:"→";
    font-size:12px;
  }
  .btn:hover{
    filter:brightness(1.05);
    transform:translateY(-1px);
    box-shadow:0 10px 28px rgba(15,23,42,.4);
  }
  .btn:active{
    transform:translateY(0);
    box-shadow:0 5px 16px rgba(15,23,42,.45);
  }

  .btn-ghost{
    background:transparent;
    border-radius:999px;
    padding:4px 9px;
    font-size:11px;
    color:#6b7280;
    border:1px dashed rgba(148,163,184,.7);
    box-shadow:none;
  }
  .btn-ghost::after{content:"";}

  /* CARDS LATERALES */
  .side-card{
    background:rgba(15,23,42,.85);
    border-radius:var(--radius-xl);
    padding:14px 14px 15px 14px;
    border:1px solid rgba(148,163,184,.7);
    box-shadow:var(--shadow-strong);
    color:#e5e7eb;
    position:relative;
    overflow:hidden;
  }
  .side-card::before{
    content:"";
    position:absolute;
    inset:0;
    background:
      radial-gradient(circle at 0 0,rgba(59,130,246,.45),transparent 55%),
      radial-gradient(circle at 100% 100%,rgba(20,184,166,.45),transparent 55%);
    opacity:.85;
    pointer-events:none;
  }
  .side-inner{
    position:relative;
    z-index:1;
  }
  .side-section-title{
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.14em;
    color:#bfdbfe;
    margin-bottom:4px;
  }
  .side-main{
    font-size:14px;
    font-weight:600;
    margin-bottom:4px;
  }
  .side-text{
    font-size:12px;
    color:#e5e7eb;
    opacity:.96;
    margin-bottom:6px;
  }
  .side-tag{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-size:11px;
    margin-top:6px;
  }
  .side-dot{
    width:8px;height:8px;border-radius:999px;
    background:#22c55e;
    box-shadow:0 0 0 4px rgba(34,197,94,.4);
  }

  .side-mini{
    background:#f9fafb;
    border-radius:var(--radius-md);
    padding:10px 11px;
    border:1px solid rgba(226,232,240,1);
    box-shadow:var(--shadow-soft);
  }
  .side-mini-title{
    font-size:13px;
    font-weight:600;
    margin-bottom:3px;
    color:#111827;
  }
  .side-mini-text{
    font-size:11px;
    color:#6b7280;
    margin-bottom:6px;
  }

  /* TABLE Citas */
  .table-card{
    background:#f9fafb;
    border-radius:var(--radius-xl);
    padding:14px 14px 12px 14px;
    border:1px solid rgba(226,232,240,1);
    box-shadow:var(--shadow-soft);
  }
  table{
    width:100%;
    border-collapse:collapse;
    font-size:12px;
  }
  th,td{
    padding:8px 6px;
    border-bottom:1px solid #e5e7eb;
    text-align:left;
  }
  th{
    color:#6b7280;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.09em;
  }
  tbody tr:last-child td{
    border-bottom:none;
  }
  .muted{
    color:#6b7280;
    font-size:12px;
  }

  .status{
    font-size:11px;
    font-weight:600;
    padding:2px 7px;
    border-radius:999px;
    border:1px solid transparent;
    display:inline-block;
  }
  .status-ok{
    color:var(--ok);
    background:#dcfce7;
    border-color:#bbf7d0;
  }
  .status-pend{
    color:var(--warn);
    background:#fef9c3;
    border-color:#fef08a;
  }
</style>

<header class="topbar">
  <div class="top-left">
    <div class="brand-icon">B</div>
    <div class="brand-text">
      <div class="brand-name"><a href="index.php" style="color:inherit">BETANDENT</a></div>
      <div class="brand-sub">Panel central de la clínica</div>
    </div>
  </div>
  <div class="top-right">
    <span style="font-size:13px;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
      <?= htmlspecialchars($nombre,ENT_QUOTES,'UTF-8') ?>
    </span>
    <span class="role-pill"><?= htmlspecialchars($rol,ENT_QUOTES,'UTF-8') ?></span>
    <a class="logout-link" href="cerrar_sesion.php">Cerrar sesión</a>
  </div>
</header>

<div class="page">
  <div class="shell">
    <?php if (es_paciente()): ?>
      <div class="page-header">
        <div>
          <div class="page-title">Mi panel</div>
          <div class="page-sub">Consulta tus citas, servicios y pagos relacionados con tu atención dental.</div>
        </div>
        <div class="tagline">Acceso exclusivo para pacientes BETANDENT</div>
      </div>

      <div class="content-grid" style="grid-template-columns:2.4fr 2.1fr;">
        <div class="panel-main">
          <div class="section-header" style="margin-top:0">
            <div class="section-title">Resumen</div>
            <div class="section-sub">Estado general de tus próximas atenciones</div>
          </div>

          <div class="stats-grid stats-grid-main" style="margin-bottom:10px;">
            <div class="stat-card accent">
              <div class="stat-label">
                <span>Citas pendientes</span>
                <span class="stat-chip">Próximas</span>
              </div>
              <div class="stat-value"><?= (int)$citas_pendientes ?></div>
              <div class="stat-foot">Citas agendadas que aún no se han atendido.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Servicios activos</span>
                <span class="stat-chip">Catálogo</span>
              </div>
              <div class="stat-value"><?= (int)$servicios_activos ?></div>
              <div class="stat-foot">Tratamientos y servicios disponibles en la clínica.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Pagos por confirmar</span>
                <span class="stat-chip">Referencia</span>
              </div>
              <div class="stat-value"><?= (int)$ventas_pendientes ?></div>
              <div class="stat-foot">Movimientos aún en proceso de validación.</div>
            </div>
          </div>

          <div class="section-header">
            <div class="section-title">Accesos rápidos</div>
            <div class="section-sub">Las opciones que más vas a usar</div>
          </div>

          <div class="modules-grid">
            <?php foreach($menu as $m): ?>
              <a class="module-tile" href="<?= htmlspecialchars($m[1],ENT_QUOTES,'UTF-8') ?>">
                <div class="module-icon"><?= htmlspecialchars($m[2],ENT_QUOTES,'UTF-8') ?></div>
                <div class="module-body">
                  <div class="module-title"><?= htmlspecialchars($m[0],ENT_QUOTES,'UTF-8') ?></div>
                  <div class="module-sub"><?= htmlspecialchars($m[1],ENT_QUOTES,'UTF-8') ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel-side">
          <div class="side-card">
            <div class="side-inner">
              <div class="side-section-title">Consejo rápido</div>
              <div class="side-main">Mantén tus datos siempre actualizados.</div>
              <div class="side-text">
                Verifica que tu correo y teléfono sean correctos para recibir recordatorios
                de citas y confirmaciones de pago sin problemas.
              </div>
              <div class="side-tag">
                <span class="side-dot"></span>
                <span>Mejor comunicación con la clínica</span>
              </div>
            </div>
          </div>

          <div class="side-mini">
            <div class="side-mini-title">Pagos por transferencia</div>
            <div class="side-mini-text">
              Si ya realizaste una transferencia, entra a <strong>“Subir comprobante”</strong>
              para que el equipo de recepción pueda validar tu pago.
            </div>
            <a href="transferir.php" class="btn btn-ghost"><span>Abrir subir comprobante</span></a>
          </div>
        </div>
      </div>

    <?php else: ?>
      <div class="page-header">
        <div>
          <div class="page-title">Panel de control</div>
          <div class="page-sub">Visión general de pacientes, citas, caja y tratamientos activos en BETANDENT.</div>
        </div>
        <div class="tagline">Operación diaria · Vista rápida para administración</div>
      </div>

      <div class="content-grid">
        <div class="panel-main">
          <div class="section-header" style="margin-top:0">
            <div class="section-title">Indicadores del día</div>
            <div class="section-sub">Cifras clave de pacientes, agenda y caja</div>
          </div>

          <div class="stats-grid stats-grid-main" style="margin-bottom:10px;">
            <div class="stat-card accent">
              <div class="stat-label">
                <span>Ventas de hoy</span>
                <span class="stat-chip">Caja</span>
              </div>
              <div class="stat-value">$<?= number_format($ventas_hoy,2) ?></div>
              <div class="stat-foot">Total cobrado con estado <strong>pagado</strong> en la fecha actual.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Pacientes registrados</span>
              </div>
              <div class="stat-value"><?= (int)$pacientes_locales ?></div>
              <div class="stat-foot">Registros en el padrón de pacientes.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Servicios activos</span>
              </div>
              <div class="stat-value"><?= (int)$servicios_activos ?></div>
              <div class="stat-foot">Servicios disponibles en el catálogo actual.</div>
            </div>
          </div>

          <div class="stats-grid stats-grid-sub">
            <div class="stat-card">
              <div class="stat-label">
                <span>Citas pendientes</span>
              </div>
              <div class="stat-value"><?= (int)$citas_pendientes ?></div>
              <div class="stat-foot">Pacientes en espera de atención.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Tratamientos abiertos</span>
              </div>
              <div class="stat-value"><?= (int)$tratamientos_abiertos ?></div>
              <div class="stat-foot">Casos en seguimiento clínico.</div>
            </div>
            <div class="stat-card">
              <div class="stat-label">
                <span>Ventas pendientes</span>
              </div>
              <div class="stat-value"><?= (int)$ventas_pendientes ?></div>
              <div class="stat-foot">Movimientos con cobro aún no confirmado.</div>
            </div>
          </div>

          <div class="section-header" style="margin-top:18px">
            <div>
              <div class="section-title">Módulos</div>
              <div class="section-sub">Navegación rápida entre las áreas principales</div>
            </div>
          </div>

          <div class="modules-grid">
            <?php foreach($menu as $m): ?>
              <a class="module-tile" href="<?= htmlspecialchars($m[1],ENT_QUOTES,'UTF-8') ?>">
                <div class="module-icon"><?= htmlspecialchars($m[2],ENT_QUOTES,'UTF-8') ?></div>
                <div class="module-body">
                  <div class="module-title"><?= htmlspecialchars($m[0],ENT_QUOTES,'UTF-8') ?></div>
                  <div class="module-sub"><?= htmlspecialchars($m[1],ENT_QUOTES,'UTF-8') ?></div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="panel-side">
          <div class="side-card">
            <div class="side-inner">
              <div class="side-section-title">Agenda</div>
              <div class="side-main">Próximas citas registradas.</div>
              <div class="side-text">
                Revisa rápidamente las próximas atenciones para organizar mejor los tiempos de sillón
                y recursos de la clínica.
              </div>
              <div style="margin-top:8px;">
                <a href="citas.php" class="btn"><span>Ver agenda completa</span></a>
              </div>
              <div class="side-tag">
                <span class="side-dot"></span>
                <span>Información actualizada en tiempo real</span>
              </div>
            </div>
          </div>

          <div class="table-card">
            <?php if (empty($ultimas_citas)): ?>
              <div class="muted">Sin citas próximas registradas.</div>
            <?php else: ?>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Paciente</th>
                    <th>Servicio</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                    <th>Estado</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($ultimas_citas as $c): ?>
                    <tr>
                      <td><?= (int)$c['id'] ?></td>
                      <td><?= htmlspecialchars($c['paciente'] ?? '—',ENT_QUOTES,'UTF-8') ?></td>
                      <td><?= htmlspecialchars($c['servicio'] ?? '—',ENT_QUOTES,'UTF-8') ?></td>
                      <td><?= htmlspecialchars($c['fecha'],ENT_QUOTES,'UTF-8') ?></td>
                      <td><?= htmlspecialchars(substr($c['hora'],0,5),ENT_QUOTES,'UTF-8') ?></td>
                      <td>
                        <?php
                          $estado = $c['estado'];
                          $cls = $estado==='confirmada' ? 'status-ok' : ($estado==='pendiente' ? 'status-pend' : '');
                        ?>
                        <span class="status <?= $cls ?>"><?= htmlspecialchars($estado,ENT_QUOTES,'UTF-8') ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <div class="side-mini">
            <div class="side-mini-title">Recordatorio operativo</div>
            <div class="side-mini-text">
              Mantén los estados de cita y tratamiento actualizados para que los reportes reflejen
              la realidad de la clínica y la caja cuadre correctamente.
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
