<?php
$host     = "localhost";
$dbname   = "u171751115_RunaWorld";
$user     = "u171751115_jondrar";
$password = "@@@TCP54732UDP638932@@@Diablo1234";
$conexion = new mysqli($host, $user, $password, $dbname);
if ($conexion->connect_error) { die("Error de conexión: " . $conexion->connect_error);}
$conexion->set_charset("utf8");