<?php
// reportes.php — BETANDENT (ventas y citas por rango, con export CSV/Excel/PDF y gráficas)
require __DIR__.'/app/db.php';
require __DIR__.'/app/session.php';
require_login();
require_rol(['admin','empleado']); // paciente no entra

$conn = db();

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function flash($k,$v=null){
  if($v===null){
    $t=$_SESSION[$k]??'';
    unset($_SESSION[$k]);
    return $t;
  }
  $_SESSION[$k]=$v;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16));
$csrf=$_SESSION['csrf'];

// Rango por defecto: hoy
$hoy = date('Y-m-d');
$ini = $_GET['ini'] ?? $hoy;
$fin = $_GET['fin'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$ini)) $ini = $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fin)) $fin = $hoy;

// Normalizamos límites [ini 00:00:00, fin 23:59:59) usando < fin+1
$ini_dt = $ini.' 00:00:00';
$fin_dt = date('Y-m-d', strtotime($fin.' +1 day')).' 00:00:00';

// =======================
// Exportadores CSV / EXCEL / PDF
// =======================
$export = $_GET['export'] ?? '';

// ---- CSV VENTAS ----
if ($export === 'ventas') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ventas_'.$ini.'_a_'.$fin.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Fecha','Hora','Código','Paciente (local)','Método','Estado','Total']);
  $st = $conn->prepare("
    SELECT v.id, DATE(v.creado_en) f, DATE_FORMAT(v.creado_en,'%H:%i') h, v.codigo,
           p.nombre AS paciente, v.metodo_pago, v.estado, v.total
    FROM ventas v
    LEFT JOIN pacientes p ON p.id=v.paciente_id
    WHERE v.creado_en >= ? AND v.creado_en < ?
    ORDER BY v.creado_en ASC, v.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    fputcsv($out, [
      $row['id'], $row['f'], $row['h'], $row['codigo'],
      $row['paciente'] ?? '', $row['metodo_pago'] ?? '',
      $row['estado'], number_format((float)$row['total'],2,'.','')
    ]);
  }
  fclose($out); exit;
}

// ---- CSV CITAS ----
if ($export === 'citas') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="citas_'.$ini.'_a_'.$fin.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Fecha','Hora','Paciente (web)','Servicio','Estado','Notas','Creado en']);
  $st = $conn->prepare("
    SELECT c.id, c.fecha, c.hora, u.nombre AS paciente, s.nombre AS servicio,
           c.estado, c.notas, c.creado_en
    FROM citas c
    INNER JOIN usuarios u ON u.id=c.paciente_id
    LEFT JOIN servicios s ON s.id=c.servicio_id
    WHERE c.creado_en >= ? AND c.creado_en < ?
    ORDER BY c.fecha ASC, c.hora ASC, c.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    fputcsv($out, [
      $row['id'], $row['fecha'], substr($row['hora'],0,5),
      $row['paciente'] ?? '', $row['servicio'] ?? '',
      $row['estado'], $row['notas'] ?? '',
      $row['creado_en']
    ]);
  }
  fclose($out); exit;
}

// ---- EXCEL VENTAS ----
if ($export === 'ventas_excel') {
  header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ventas_'.$ini.'_a_'.$fin.'.xls"' );

  echo "<table border='1'>";
  echo "<tr>
          <th>ID</th><th>Fecha</th><th>Hora</th><th>Código</th>
          <th>Paciente (local)</th><th>Método</th><th>Estado</th><th>Total</th>
        </tr>";

  $st = $conn->prepare("
    SELECT v.id, DATE(v.creado_en) f, DATE_FORMAT(v.creado_en,'%H:%i') h, v.codigo,
           p.nombre AS paciente, v.metodo_pago, v.estado, v.total
    FROM ventas v
    LEFT JOIN pacientes p ON p.id=v.paciente_id
    WHERE v.creado_en >= ? AND v.creado_en < ?
    ORDER BY v.creado_en ASC, v.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    echo "<tr>
            <td>".(int)$row['id']."</td>
            <td>".htmlspecialchars($row['f'])."</td>
            <td>".htmlspecialchars($row['h'])."</td>
            <td>".htmlspecialchars($row['codigo'])."</td>
            <td>".htmlspecialchars($row['paciente'] ?? '')."</td>
            <td>".htmlspecialchars($row['metodo_pago'] ?? '')."</td>
            <td>".htmlspecialchars($row['estado'])."</td>
            <td>".number_format((float)$row['total'],2)."</td>
          </tr>";
  }
  echo "</table>";
  exit;
}

// ---- EXCEL CITAS ----
if ($export === 'citas_excel') {
  header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
  header('Content-Disposition: attachment; filename="citas_'.$ini.'_a_'.$fin.'.xls"' );

  echo "<table border='1'>";
  echo "<tr>
          <th>ID</th><th>Fecha</th><th>Hora</th><th>Paciente (web)</th>
          <th>Servicio</th><th>Estado</th><th>Notas</th><th>Creado en</th>
        </tr>";

  $st = $conn->prepare("
    SELECT c.id, c.fecha, c.hora, u.nombre AS paciente, s.nombre AS servicio,
           c.estado, c.notas, c.creado_en
    FROM citas c
    INNER JOIN usuarios u ON u.id=c.paciente_id
    LEFT JOIN servicios s ON s.id=c.servicio_id
    WHERE c.creado_en >= ? AND c.creado_en < ?
    ORDER BY c.fecha ASC, c.hora ASC, c.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    echo "<tr>
            <td>".(int)$row['id']."</td>
            <td>".htmlspecialchars($row['fecha'])."</td>
            <td>".htmlspecialchars(substr($row['hora'],0,5))."</td>
            <td>".htmlspecialchars($row['paciente'] ?? '')."</td>
            <td>".htmlspecialchars($row['servicio'] ?? '')."</td>
            <td>".htmlspecialchars($row['estado'])."</td>
            <td>".htmlspecialchars($row['notas'] ?? '')."</td>
            <td>".htmlspecialchars($row['creado_en'])."</td>
          </tr>";
  }
  echo "</table>";
  exit;
}

// ---- PDF VENTAS ----
if ($export === 'ventas_pdf') {
  require __DIR__.'/fpdf/fpdf.php'; // <- usa la carpeta correcta
  $pdf = new FPDF('L','mm','A4');
  $pdf->AddPage();
  $pdf->SetFont('Arial','B',14);
  $pdf->Cell(0,10,utf8_decode('Reporte de ventas '.$ini.' a '.$fin),0,1,'C');
  $pdf->Ln(5);
  $pdf->SetFont('Arial','B',9);

  $headers = ['ID','Fecha','Hora','Código','Paciente','Método','Estado','Total'];
  foreach($headers as $h){
    $pdf->Cell(35,7,utf8_decode($h),1,0,'C');
  }
  $pdf->Ln();

  $pdf->SetFont('Arial','',8);
  $st = $conn->prepare("
    SELECT v.id, DATE(v.creado_en) f, DATE_FORMAT(v.creado_en,'%H:%i') h, v.codigo,
           p.nombre AS paciente, v.metodo_pago, v.estado, v.total
    FROM ventas v
    LEFT JOIN pacientes p ON p.id=v.paciente_id
    WHERE v.creado_en >= ? AND v.creado_en < ?
    ORDER BY v.creado_en ASC, v.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    $pdf->Cell(35,6,$row['id'],1);
    $pdf->Cell(35,6,$row['f'],1);
    $pdf->Cell(35,6,$row['h'],1);
    $pdf->Cell(35,6,utf8_decode($row['codigo']),1);
    $pdf->Cell(35,6,utf8_decode($row['paciente'] ?? ''),1);
    $pdf->Cell(35,6,utf8_decode($row['metodo_pago'] ?? ''),1);
    $pdf->Cell(35,6,utf8_decode($row['estado']),1);
    $pdf->Cell(35,6,number_format((float)$row['total'],2),1);
    $pdf->Ln();
  }

  $pdf->Output('D','ventas_'.$ini.'_a_'.$fin.'.pdf');
  exit;
}

// ---- PDF CITAS ----
if ($export === 'citas_pdf') {
  require __DIR__.'/fpdf/fpdf.php'; // <- usa la carpeta correcta
  $pdf = new FPDF('L','mm','A4');
  $pdf->AddPage();
  $pdf->SetFont('Arial','B',14);
  $pdf->Cell(0,10,utf8_decode('Reporte de citas '.$ini.' a '.$fin),0,1,'C');
  $pdf->Ln(5);
  $pdf->SetFont('Arial','B',9);

  $headers = ['ID','Fecha','Hora','Paciente','Servicio','Estado','Notas'];
  foreach($headers as $h){
    $pdf->Cell(40,7,utf8_decode($h),1,0,'C');
  }
  $pdf->Ln();

  $pdf->SetFont('Arial','',8);
  $st = $conn->prepare("
    SELECT c.id, c.fecha, c.hora, u.nombre AS paciente, s.nombre AS servicio,
           c.estado, c.notas
    FROM citas c
    INNER JOIN usuarios u ON u.id=c.paciente_id
    LEFT JOIN servicios s ON s.id=c.servicio_id
    WHERE c.creado_en >= ? AND c.creado_en < ?
    ORDER BY c.fecha ASC, c.hora ASC, c.id ASC
  ");
  $st->bind_param("ss",$ini_dt,$fin_dt);
  $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){
    $pdf->Cell(20,6,$row['id'],1);
    $pdf->Cell(25,6,$row['fecha'],1);
    $pdf->Cell(20,6,substr($row['hora'],0,5),1);
    $pdf->Cell(40,6,utf8_decode($row['paciente'] ?? ''),1);
    $pdf->Cell(40,6,utf8_decode($row['servicio'] ?? ''),1);
    $pdf->Cell(30,6,utf8_decode($row['estado']),1);
    $pdf->Cell(80,6,utf8_decode($row['notas'] ?? ''),1);
    $pdf->Ln();
  }

  $pdf->Output('D','citas_'.$ini.'_a_'.$fin.'.pdf');
  exit;
}

// =======================
// Dashboard del rango
// =======================

// Ventas: totales y desglose
$tot_pagado = $tot_pend = $tot_efectivo = $tot_trans_pag = 0.0;
$cnt_pagadas = $cnt_pend = 0;

// pagadas
$st = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND creado_en>=? AND creado_en<?");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
if($row=$r->fetch_assoc()){ $cnt_pagadas=(int)$row['c']; $tot_pagado=(float)$row['s']; }
$st->close();

// pendientes
$st = $conn->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM ventas WHERE estado='pendiente' AND creado_en>=? AND creado_en<?");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
if($row=$r->fetch_assoc()){ $cnt_pend=(int)$row['c']; $tot_pend=(float)$row['s']; }
$st->close();

// efectivo
$st = $conn->prepare("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND metodo_pago='efectivo' AND creado_en>=? AND creado_en<?");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
if($row=$r->fetch_assoc()){ $tot_efectivo=(float)$row['s']; }
$st->close();

// transferencia pagada
$st = $conn->prepare("SELECT COALESCE(SUM(total),0) s FROM ventas WHERE estado='pagado' AND metodo_pago='transferencia' AND creado_en>=? AND creado_en<?");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
if($row=$r->fetch_assoc()){ $tot_trans_pag=(float)$row['s']; }
$st->close();

// Ventas listado
$ventas = [];
$st = $conn->prepare("
  SELECT v.id, DATE(v.creado_en) f, DATE_FORMAT(v.creado_en,'%H:%i') h, v.codigo,
         p.nombre AS paciente, v.metodo_pago, v.estado, v.total
  FROM ventas v
  LEFT JOIN pacientes p ON p.id=v.paciente_id
  WHERE v.creado_en >= ? AND v.creado_en < ?
  ORDER BY v.creado_en ASC, v.id ASC
");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){ $ventas[]=$row; }
$st->close();

// Ventas agregadas por método de pago (para la gráfica)
$ventas_por_metodo = [];
foreach($ventas as $v){
  $m = $v['metodo_pago'] ?: 'Sin método';
  if (!isset($ventas_por_metodo[$m])) $ventas_por_metodo[$m] = 0;
  $ventas_por_metodo[$m] += (float)$v['total'];
}

// Citas: conteos por estado
$citas_stats = ['pendiente'=>0,'confirmada'=>0,'cancelada'=>0,'atendida'=>0];
$st = $conn->prepare("
  SELECT estado, COUNT(*) c
  FROM citas
  WHERE creado_en >= ? AND creado_en < ?
  GROUP BY estado
");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){
  if(isset($citas_stats[$row['estado']])){
    $citas_stats[$row['estado']] = (int)$row['c'];
  }
}
$st->close();

// Citas listado
$citas = [];
$st = $conn->prepare("
  SELECT c.id, c.fecha, c.hora, u.nombre AS paciente, s.nombre AS servicio, c.estado, c.notas
  FROM citas c
  INNER JOIN usuarios u ON u.id=c.paciente_id
  LEFT JOIN servicios s ON s.id=c.servicio_id
  WHERE c.creado_en >= ? AND c.creado_en < ?
  ORDER BY c.fecha ASC, c.hora ASC
");
$st->bind_param("ss",$ini_dt,$fin_dt);
$st->execute(); $r=$st->get_result();
while($row=$r->fetch_assoc()){ $citas[]=$row; }
$st->close();

$ok=flash('ok'); $err=flash('err');
?>
<!doctype html>
<meta charset="utf-8">
<title>Reportes | BETANDENT</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--brand:#0b5ed7;--ink:#111827;--muted:#6b7280;--bg:#f7f8fc;--card:#fff;--shadow:0 8px 30px rgba(0,0,0,.06);--radius:16px}
  *{box-sizing:border-box} html,body{margin:0} body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
  a{text-decoration:none;color:inherit}
  .top{background:var(--brand);color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center}
  .wrap{max-width:1200px;margin:0 auto;padding:20px 16px}
  .grid{display:grid;gap:16px}
  .cols{grid-template-columns:1fr 1fr}
  @media (max-width:1100px){.cols{grid-template-columns:1fr}}
  .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
  .kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
  @media (max-width:1100px){.kpis{grid-template-columns:repeat(2,1fr)}}
  .kpi{background:#fff;border-radius:12px;box-shadow:var(--shadow);padding:14px;text-align:center}
  .muted{color:var(--muted)}
  label{display:block;margin-top:8px;font-weight:600}
  input,select{width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:10px;margin-top:4px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #eef;text-align:left;vertical-align:top}
  .btn{display:inline-block;background:var(--brand);color:#fff;border:none;border-radius:10px;padding:9px 14px;cursor:pointer}
  .btn.secondary{background:#6b7280}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .pill{background:#eef;border-radius:999px;padding:4px 10px;font-size:.9rem}
</style>

<div class="top">
  <div><a href="panel.php" style="color:#fff;font-weight:800">BETANDENT</a> · Reportes</div>
  <form method="get" class="row" style="gap:8px">
    <input type="date" name="ini" value="<?= e($ini) ?>">
    <input type="date" name="fin" value="<?= e($fin) ?>">
    <button class="btn" style="background:#fff;color:var(--brand)">Ver</button>

    <!-- Exportes VENTAS -->
    <a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=ventas" style="background:#fff;color:var(--brand)">Excel ventas</a>
    <!--<a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=ventas_excel" style="background:#fff;color:var(--brand)">Excel ventas</a>-->
    <a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=ventas_pdf" style="background:#fff;color:var(--brand)">PDF ventas</a>

    <!-- Exportes CITAS -->
    <a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=citas" style="background:#fff;color:var(--brand)">Excel citas</a>
    <!--<a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=citas_excel" style="background:#fff;color:var(--brand)">Excel citas</a>-->
    <a class="btn" href="reportes.php?ini=<?= e($ini) ?>&fin=<?= e($fin) ?>&export=citas_pdf" style="background:#fff;color:var(--brand)">PDF citas</a>

    <!-- Volver al menú -->
    <a class="btn" href="panel.php" style="background:#fff;color:var(--brand)">Volver al menú</a>
  </form>
</div>

<div class="wrap">
  <?php if($ok): ?><div class="card" style="background:#e8f7ed;color:#117a2b"><?= e($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="card" style="background:#fde8ec;color:#b00020"><?= e($err) ?></div><?php endif; ?>

  <div class="grid kpis">
    <div class="kpi"><div class="muted">Ventas pagadas</div><h2 style="margin:.2rem 0"><?= (int)$cnt_pagadas ?></h2></div>
    <div class="kpi"><div class="muted">Total pagado</div><h2 style="margin:.2rem 0">$<?= number_format($tot_pagado,2) ?></h2></div>
    <div class="kpi"><div class="muted">Pendiente</div><h2 style="margin:.2rem 0">$<?= number_format($tot_pend,2) ?> <span class="muted" style="font-size:.9rem">(<?= (int)$cnt_pend ?>)</span></h2></div>
    <div class="kpi"><div class="muted">Efectivo</div><h2 style="margin:.2rem 0">$<?= number_format($tot_efectivo,2) ?></h2></div>
    <div class="kpi"><div class="muted">Trans. pagadas</div><h2 style="margin:.2rem 0">$<?= number_format($tot_trans_pag,2) ?></h2></div>
  </div>

  <div class="grid cols" style="margin-top:16px">
    <div class="card">
      <h2 style="margin:0 0 10px">Ventas (<?= e($ini) ?> a <?= e($fin) ?>)</h2>
      <?php if(empty($ventas)): ?>
        <p class="muted">Sin ventas en el rango seleccionado.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Fecha</th><th>Hora</th><th>Código</th><th>Paciente</th><th>Método</th><th>Estado</th><th>Total</th></tr></thead>
          <tbody>
            <?php foreach($ventas as $v): ?>
              <tr>
                <td><?= e($v['f']) ?></td>
                <td><?= e($v['h']) ?></td>
                <td><?= e($v['codigo']) ?></td>
                <td><?= e($v['paciente'] ?? '') ?></td>
                <td><span class="pill"><?= e($v['metodo_pago']) ?></span></td>
                <td><span class="pill"><?= e($v['estado']) ?></span></td>
                <td>$<?= number_format((float)$v['total'],2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px">Citas creadas (<?= e($ini) ?> a <?= e($fin) ?>)</h2>
      <p class="muted">
        Pendientes: <strong><?= (int)$citas_stats['pendiente'] ?></strong> ·
        Confirmadas: <strong><?= (int)$citas_stats['confirmada'] ?></strong> ·
        Atendidas: <strong><?= (int)$citas_stats['atendida'] ?></strong> ·
        Canceladas: <strong><?= (int)$citas_stats['cancelada'] ?></strong>
      </p>
      <?php if(empty($citas)): ?>
        <p class="muted">Sin citas registradas en este rango.</p>
      <?php else: ?>
        <table>
          <thead><tr><th>Fecha</th><th>Hora</th><th>Paciente (web)</th><th>Servicio</th><th>Estado</th><th>Notas</th></tr></thead>
          <tbody>
            <?php foreach($citas as $c): ?>
              <tr>
                <td><?= e($c['fecha']) ?></td>
                <td><?= e(substr($c['hora'],0,5)) ?></td>
                <td><?= e($c['paciente'] ?? '') ?></td>
                <td><?= e($c['servicio'] ?? '') ?></td>
                <td><span class="pill"><?= e($c['estado']) ?></span></td>
                <td><?= e($c['notas'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- SECCIÓN DE GRÁFICAS -->
  <div class="grid cols" style="margin-top:16px">
    <div class="card">
      <h2 style="margin:0 0 10px">Gráfica de ventas por método de pago</h2>
      <canvas id="chartVentasMetodo" style="max-height:320px"></canvas>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px">Distribución de citas por estado</h2>
      <canvas id="chartCitasEstado" style="max-height:320px"></canvas>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const ventasMetodoLabels = <?= json_encode(array_keys($ventas_por_metodo), JSON_UNESCAPED_UNICODE) ?>;
  const ventasMetodoData   = <?= json_encode(array_values($ventas_por_metodo), JSON_UNESCAPED_UNICODE) ?>;

  const citasEstadoLabels  = <?= json_encode(array_keys($citas_stats), JSON_UNESCAPED_UNICODE) ?>;
  const citasEstadoData    = <?= json_encode(array_values($citas_stats), JSON_UNESCAPED_UNICODE) ?>;

  // Gráfica de barras: ventas por método de pago
  const ctxVentas = document.getElementById('chartVentasMetodo');
  if (ctxVentas && ventasMetodoLabels.length > 0) {
    new Chart(ctxVentas, {
      type: 'bar',
      data: {
        labels: ventasMetodoLabels,
        datasets: [{
          label: 'Total vendido (MXN)',
          data: ventasMetodoData
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: { beginAtZero: true }
        }
      }
    });
  }

  // Gráfica de pastel: citas por estado
  const ctxCitas = document.getElementById('chartCitasEstado');
  if (ctxCitas) {
    new Chart(ctxCitas, {
      type: 'pie',
      data: {
        labels: citasEstadoLabels,
        datasets: [{
          data: citasEstadoData
        }]
      },
      options: {
        responsive: true
      }
    });
  }
</script>
