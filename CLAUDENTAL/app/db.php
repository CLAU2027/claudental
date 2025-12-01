<?php
// app/db.php — conexión central para Railway + local

function db() {
  static $conn = null;
  if ($conn) return $conn;

  // Primero intentamos leer valores desde las variables de entorno de Railway
  $host = getenv('MYSQLHOST') ?: '127.0.0.1';
  $usuario = getenv('MYSQLUSER') ?: 'root';
  $contraseña = getenv('MYSQLPASSWORD') ?: '12345';
  $bd = getenv('MYSQLDATABASE') ?: 'dental22';
  $port = getenv('MYSQLPORT') ?: '3306';

  // Conexión MySQLi
  $conn = @new mysqli($host, $usuario, $contraseña, $bd, (int)$port);

  if ($conn->connect_error) {
    die("Error de conexión: " . htmlspecialchars($conn->connect_error));
  }

  $conn->set_charset("utf8mb4");
  return $conn;
}
