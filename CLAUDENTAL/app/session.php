<?php
// app/session.php — helpers de sesión
if (session_status() === PHP_SESSION_NONE) session_start();

function usuario_id(){ return $_SESSION['uid'] ?? null; }
function usuario_nombre(){ return $_SESSION['nombre'] ?? null; }
function usuario_rol(){ return $_SESSION['rol'] ?? null; }

function es_admin(){ return usuario_rol()==='admin'; }
function es_empleado(){ return usuario_rol()==='empleado'; }
function es_paciente(){ return usuario_rol()==='paciente'; }

function require_login(){
  if (!usuario_id()) { header("Location: login.php"); exit; }
}
function require_rol($roles){
  if (!usuario_id() || !in_array(usuario_rol(), (array)$roles, true)) {
    http_response_code(403);
    echo "<h2>Acceso denegado</h2>";
    exit;
  }
}

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
