<?php
// Foto de perfil del usuario que inició sesión (cualquier rol). Se guarda en Usuarios.foto_perfil.
//   GET    /foto_perfil.php  -> { "foto": "data:image/jpeg;base64,..." }  (o null si no tiene)
//   PUT    /foto_perfil.php  Body: { "foto": "data:image/jpeg;base64,..." }
//   DELETE /foto_perfil.php  -> quita la foto
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
require_once "seguridad.php";   // requerirLogin()
require_once "util_imagen.php";

function responder($codigo, $datos) {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function leerJson() {
    $datos = json_decode(file_get_contents("php://input"), true);
    if (!is_array($datos)) {
        responder(400, ["error" => "El cuerpo de la petición debe ser un JSON válido"]);
    }
    return $datos;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        case 'GET':
            $id = requerirLogin($conexion)['id'];   // 401 si no hay token válido
            $stmt = $conexion->prepare("SELECT foto_perfil FROM Usuarios WHERE id_usuario = ?");
            $stmt->execute([$id]);
            responder(200, ["foto" => aDataUrl($stmt->fetchColumn())]);
            break;

        case 'PUT':
            $id = requerirLogin($conexion)['id'];
            $datos = leerJson();
            $bin = decodificarImagen($datos['foto'] ?? null);
            $stmt = $conexion->prepare("UPDATE Usuarios SET foto_perfil = ? WHERE id_usuario = ?");
            $stmt->bindValue(1, $bin, PDO::PARAM_LOB);
            $stmt->bindValue(2, $id, PDO::PARAM_INT);
            $stmt->execute();
            responder(200, ["mensaje" => "Foto actualizada correctamente"]);
            break;

        case 'DELETE':
            $id = requerirLogin($conexion)['id'];
            $stmt = $conexion->prepare("UPDATE Usuarios SET foto_perfil = NULL WHERE id_usuario = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Foto eliminada correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    responder(500, ["error" => "Error en la base de datos"]);
}