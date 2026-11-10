<?php
require_once "comun.php";
require_once "conexion.php";
require_once "seguridad.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
responder(405, ["error" => "Método no permitido"]);
}
try {
requerirLogin($conexion); // si no hay sesión válida responde 401 borramos la sesión: ese token deja de servir aunque alguien lo conserve
$stmt = $conexion->prepare("DELETE FROM sesiones WHERE token_hash = ?");
$stmt->execute([hashToken(obtenerToken())]);
responder(200, ["mensaje" => "Sesión cerrada"]);
} catch (PDOException $e) {
responder(500, ["error" => "Error interno del servidor"]);
}