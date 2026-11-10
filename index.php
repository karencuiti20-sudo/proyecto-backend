<?php
// Datos de conexión
$host = '127.0.0.1';
$user = 'root';
$pass = '1234';
$db = 'computa';

try {
    // Intentar conectar a la base de datos
    $conexion = new PDO("mysql:host=$host;dbname=$db;charset=utf8",$user, $pass);
    $conexion->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    echo "<h1>¡Servidor PHP y conexión a MySQL funcionando correctamente!</h1>";
} catch (PDOException $e) {
    echo "<h1>Servidor PHP activo, pero falló MySQL:</h1>";
    echo "<p>" . $e->getMessage() . "</p>";
}