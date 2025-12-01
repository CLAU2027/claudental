<?php
require __DIR__.'/app/session.php';
$_SESSION = [];
session_destroy();
header("Location: index.php");
exit;
