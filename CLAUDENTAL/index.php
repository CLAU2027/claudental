<?php
// ==========================
// BETANDENT · index.php (todo en uno)
// ==========================
if (session_status() === PHP_SESSION_NONE) session_start();

// ---------- Conexión MySQLi (Railway + local)

// 1) Intentar con MYSQL_URL (Railway)
$url = getenv('MYSQL_URL');

if ($url) {
    $parts = parse_url($url);
    $host       = $parts['host'] ?? '127.0.0.1';
    $usuario    = $parts['user'] ?? 'root';
    $contraseña = $parts['pass'] ?? '';
    $bd         = ltrim($parts['path'] ?? '/railway', '/');
    $port       = $parts['port'] ?? 3306;
} else {
    // 2) Fallback a local
    $host       = getenv('MYSQLHOST') ?: '127.0.0.1';
    $usuario    = getenv('MYSQLUSER') ?: 'root';
    $contraseña = getenv('MYSQLPASSWORD') ?: '12345';
    $bd         = getenv('MYSQLDATABASE') ?: 'dental22';
    $port       = getenv('MYSQLPORT') ?: 3306;
}

$conn = @new mysqli($host, $usuario, $contraseña, $bd, (int)$port);
if ($conn->connect_error) {
  die("Error de conexión a la base de datos: " . htmlspecialchars($conn->connect_error));
}
$conn->set_charset("utf8mb4");



// ---------- Helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// ---------- Config rápida del sitio
$clinica_nombre = "BETANDENT";
$clinica_direccion = "AVENIDA HIDALGO NÚMERO #213 calle amaxac huitzizilingo Hgo";
$maps_q = urlencode("$clinica_nombre, $clinica_direccion");

// ---------- Cargar servicios activos
// ---------- Cargar servicios activos
$servs = [];
$tipos = [];
$sql = "SELECT id AS id_servicio, nombre, descripcion, precio, tipo
        FROM servicios
        WHERE activo=1
        ORDER BY tipo, nombre";
if ($res = $conn->query($sql)) {
  while ($row = $res->fetch_assoc()) $servs[] = $row;
  $res->free();
  $tipos = array_values(array_unique(array_map(fn($s)=>$s['tipo'], $servs)));
}

// ---------- URL para "Apartar cita" según rol
$rol = $_SESSION['rol'] ?? null;
$hrefCita = 'login.php'; // si no está logueado

if ($rol === 'paciente') {
  $hrefCita = 'citas_cliente.php';
} elseif ($rol === 'admin' || $rol === 'empleado') {
  $hrefCita = 'citas.php';
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>BETANDENT | Inicio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b5ed7">
  <style>
    *{box-sizing:border-box}
    :root{
      --brand:#0b5ed7; --ink:#111827; --muted:#6b7280; --bg:#f7f8fc; --card:#fff;
      --ghost:#e9eefc; --ok:#14a44d; --shadow:0 8px 30px rgba(0,0,0,.06); --radius:16px
    }
    html,body{margin:0;padding:0}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);line-height:1.45}
    a{text-decoration:none}
    .container{max-width:1150px;margin:0 auto;padding:24px 16px}

    /* Topbar */
    .topbar{position:sticky;top:0;z-index:20;background:var(--brand);color:#fff;box-shadow:var(--shadow);display:flex;justify-content:space-between;align-items:center;padding:10px 16px}
    .brand a{color:#fff;font-weight:800;letter-spacing:.2px}
    .nav{display:flex;gap:12px;align-items:center}
    .nav a{color:#fff;opacity:.95}
    .btn{display:inline-block;border-radius:12px;padding:9px 14px;font-weight:700}
    .btn.primary{background:#fff;color:var(--brand)}
    .btn.ghost{background:var(--ghost);color:var(--brand)}
    .btn.tiny{padding:6px 10px;border-radius:10px}

    /* Hero */
    .hero{position:relative;border-radius:var(--radius);overflow:hidden;background:linear-gradient(135deg,#e9eefc,#f6f7fb);box-shadow:var(--shadow);margin-top:16px}
    .hero__overlay{position:absolute;inset:0;background:radial-gradient(80% 55% at 80% 20%, rgba(11,94,215,.20), transparent)}
    .hero__content{position:relative;padding:30px}
    .hero h1{margin:0 0 10px 0;font-size:34px}
    .muted{color:var(--muted)}
    .row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}

    /* KPI */
    .strip.kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:18px 0}
    .kpi{background:#fff;border-radius:12px;box-shadow:var(--shadow);padding:16px;text-align:center}
    .kpi h3{margin:0 0 4px 0}

    /* Secciones */
    .section{margin:34px 0}
    .section__head{margin-bottom:10px}

    /* Tabs */
    .tabs{display:flex;gap:8px;margin:6px 0 12px}
    .tab{border:1px solid #dbe3ff;background:#fff;border-radius:999px;padding:6px 10px;cursor:pointer}
    .tab.is-active{background:var(--brand);color:#fff;border-color:var(--brand)}

    /* Grid cards */
    .grid{display:grid;gap:16px}
    .grid.cards{grid-template-columns:repeat(auto-fit,minmax(250px,1fr))}
    .card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:0;overflow:hidden;opacity:.0;transform:translateY(8px);transition:all .25s ease}
    .card.in{opacity:1;transform:none}
    .pro-card__media{position:relative;height:150px;background:#eef}
    .pro-card__media img{width:100%;height:100%;object-fit:cover;display:block}
    .badge{position:absolute;top:10px;left:10px;background:var(--ghost);color:var(--brand);padding:4px 8px;border-radius:999px;font-size:.8rem;border:1px solid #cfe0ff}
    .pro-card__body{padding:14px}
    .pro-card__body h3{margin:.2rem 0 .3rem 0}
    .price{font-weight:900}
    .pro-card__footer{display:flex;justify-content:space-between;align-items:center;margin-top:8px}

    /* Dos columnas */
    .two-cols{display:grid;gap:18px;grid-template-columns:1.2fr .8fr}
    @media (max-width:900px){.two-cols{grid-template-columns:1fr}}
    .checklist{padding-left:18px}
    .checklist li{margin:6px 0}
    .quote{padding:14px}
    .quote p{margin:.2rem 0}
    .quote span{font-size:.9rem;color:var(--muted)}

    /* Mapa */
    .map-wrap{height:320px;border-radius:var(--radius);overflow:hidden;box-shadow:var(--shadow);background:#fff}

    /* Footer */
    .footer{margin:28px 0 8px;text-align:center;color:var(--muted);font-size:.9rem}
    .pill{background:#e9f8ee;color:#0c6b2f;border:1px solid #c8efd7;padding:3px 8px;border-radius:999px;font-size:.8rem}
  </style>
</head>
<body>

<header class="topbar">
  <div class="brand"><a href="index.php">BETANDENT</a></div>
  <nav class="nav">
    <a href="#servicios">Servicios</a>
    <a href="#ubicacion">Ubicación</a>
    <?php if (empty($_SESSION['uid'])): ?>
      <a class="btn primary" href="login.php">Iniciar sesión</a>
      <a class="btn ghost" href="registro.php">Registrarme</a>
    <?php else: ?>
      <a class="btn primary" href="panel.php">Panel</a>
      <a class="btn ghost" href="cerrar_sesion.php">Cerrar sesión</a>
    <?php endif; ?>
  </nav>
</header>

<main class="container">
  <!-- HERO -->
  <section class="hero">
    <div class="hero__overlay"></div>
    <div class="hero__content">
      <h1>Tu sonrisa, en manos expertas</h1>
      <p class="muted">Precios claros, materiales certificados y atención honesta. Agenda en minutos.</p>
      <div class="row" style="margin-top:10px">
        <span class="pill">Horario: Lunes a Sabados 08:00 am a 5:00pm</span>
        <a class="btn primary" href="<?= e($hrefCita) ?>">Apartar cita</a>
        <a class="btn ghost" href="#servicios">Ver servicios</a>
      </div>
    </div>
  </section>

  <!-- KPIs -->
  <section class="strip kpis">
    <div class="kpi"><h3>+10</h3><div class="muted">Años de experiencia</div></div>
    <div class="kpi"><h3>+1,500</h3><div class="muted">Pacientes atendidos</div></div>
    <div class="kpi"><h3>4.9/5</h3><div class="muted">Satisfacción</div></div>
  </section>

  <!-- SERVICIOS -->
  <section class="section" id="servicios">
    <div class="section__head">
      <h2>Servicios y tratamientos</h2>
      <p class="muted">Precios visibles para decidir sin vueltas.</p>
    </div>

    <?php if (count($tipos) > 1): ?>
      <div class="tabs" id="tabs-serv">
        <button class="tab is-active" data-filter="all">Todos</button>
        <?php foreach($tipos as $t): ?>
          <button class="tab" data-filter="<?= e($t) ?>"><?= e(ucfirst($t)) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="grid cards" id="grid-servicios">
      <?php foreach($servs as $s): ?>
        <article class="card pro-card" data-tipo="<?= e($s['tipo']) ?>">
          <div class="pro-card__media">
            <img src="public/img/servicios/<?= (int)$s['id_servicio'] ?>.jpg"
                 onerror="this.src='public/img/servicios/logo.jpg'"
                 alt="<?= e($s['nombre']) ?>">
            <span class="badge"><?= e($s['tipo']==='servicio'?'Servicio':'Tratamiento') ?></span>
          </div>
          <div class="pro-card__body">
            <h3><?= e($s['nombre']) ?></h3>
            <p class="muted"><?= e($s['descripcion'] ?: 'Disponible en clínica') ?></p>
            <div class="pro-card__footer">
              <span class="price">$<?= number_format((float)$s['precio'], 2) ?></span>
              <a class="btn tiny primary" href="<?= e($hrefCita) ?>">Agendar</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (empty($servs)): ?>
        <article class="card pro-card in"><div class="pro-card__body">
          <h3>Sin servicios activos</h3><p class="muted">Agrega servicios desde el panel del administrador.</p>
        </div></article>
      <?php endif; ?>
    </div>
  </section>

  <!-- POR QUÉ ELEGIRNOS -->
  <section class="section two-cols">
    <div>
      <h2>¿Por qué elegirnos?</h2>
      <ul class="checklist">
        <li>Diagnóstico claro y sin letras chiquitas.</li>
        <li>Materiales y equipo con certificación.</li>
        <li>Recordatorios y horarios flexibles.</li>
        <li>Pagos transparentes, sin sorpresas.</li>
      </ul>
      <div class="row" style="margin-top:10px">
        <a class="btn primary" href="<?= e($hrefCita) ?>">Apartar cita</a>
        <a class="btn ghost" href="#servicios">Ver servicios</a>
      </div>
    </div>
    <div>
      <div class="quote card">
        <p>“Me explicaron costos y opciones desde el inicio. Cero sorpresas.”</p>
        <span>— Paciente verificado</span>
      </div>
      <div class="quote card">
        <p>“Tenía miedo al dentista y salí sonriendo. Trato impecable.”</p>
        <span>— Paciente verificado</span>
      </div>
    </div>
  </section>

  <!-- UBICACIÓN -->
  <section class="section" id="ubicacion">
    <div class="section__head">
      <h2>Ubicación</h2>
      <p class="muted"><?= e($clinica_nombre) ?> · <?= e($clinica_direccion) ?></p>
    </div>
    <div class="map-wrap">
      <iframe
        src="https://www.google.com/maps?q=<?= $maps_q ?>&output=embed"
        width="100%" height="100%" style="border:0" loading="lazy"></iframe>
    </div>
  </section>

  <!-- CTA -->
  <section class="section" style="text-align:center">
    <h2>¿Listo para tu cita?</h2>
    <p class="muted">Reservar te toma menos de 2 minutos.</p>
    <div class="row" style="justify-content:center">
      <a class="btn primary" href="<?= e($hrefCita) ?>">Apartar cita</a>
      <a class="btn ghost" href="login.php">Iniciar sesión</a>
    </div>
  </section>

  <div class="footer">© <?= date('Y') ?> BETANDENT</div>
</main>

<script>
  // Tabs de filtro
  const tabs = document.querySelectorAll('#tabs-serv .tab');
  const cards = document.querySelectorAll('#grid-servicios .pro-card');
  tabs.forEach(t => t.addEventListener('click', () => {
    tabs.forEach(x => x.classList.remove('is-active'));
    t.classList.add('is-active');
    const f = t.dataset.filter;
    cards.forEach(c => c.style.display = (f === 'all' || c.dataset.tipo === f) ? '' : 'none');
  }));

  // Fade-in elegante
  const io = new IntersectionObserver(entries => {
    entries.forEach(x => { if (x.isIntersecting) x.target.classList.add('in'); });
  }, { threshold: .1 });
  document.querySelectorAll('.card').forEach(el => io.observe(el));
</script>
</body>
</html>





