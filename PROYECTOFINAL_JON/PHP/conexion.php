<?php
$host     = "localhost";
$user     = "root"; // Usuario por defecto en XAMPP
$password = "";
$dbname   = "u171751115_RunaWorld";

// El orden correcto es: host, usuario, password, base de datos
$conexion = new mysqli($host, $user, $password, $dbname);

if ($conexion->connect_error) { 
    die("Error de conexión: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");

// Si llegas aquí, la conexión fue exitosa
?>