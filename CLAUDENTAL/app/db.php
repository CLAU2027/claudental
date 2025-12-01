<?php
// app/db.php — conexión central
function db() {
  static $conn = null;
  if ($conn) return $conn;

  $host = "localhost";
  $usuario = "root";
  $contraseña = "12345";
  $bd = "dental22";

  $conn = @new mysqli($host, $usuario, $contraseña, $bd);
  if ($conn->connect_error) {
    die("Error de conexión: " . htmlspecialchars($conn->connect_error));
  }
  $conn->set_charset("utf8");
  return $conn;
}
