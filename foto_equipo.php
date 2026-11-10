<?php
// Imagen de un equipo. Se guarda en Equipos.imagen.
//   GET    /foto_equipo.php?id_pc=1  -> { "imagen": "data:image/jpeg;base64,..." }  (o null)   todos los roles
//   PUT    /foto_equipo.php?id_pc=1  Body: { "imagen": "data:image/jpeg;base64,..." }          administrador y encargado
//   DELETE /foto_equipo.php?id_pc=1  -> quita la imagen                                       administrador y encargado
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
require_once "seguridad.php";   // requerirLogin(), requerirRol()
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

function validarId() {
    if (!isset($_GET['id_pc'])) {
        responder(400, ["error" => "Debe indicar el ID del equipo"]);
    }
    $id = filter_var($_GET['id_pc'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($id === false) {
        responder(400, ["error" => "El id debe ser un número entero positivo"]);
    }
    return $id;
}

// Devuelve la fila [imagen] del equipo, o null si el equipo no existe
function buscarEquipo($conexion, $id) {
    $stmt = $conexion->prepare("SELECT imagen FROM Equipos WHERE id_pc = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        case 'GET':
            requerirLogin($conexion);
            $equipo = buscarEquipo($conexion, validarId());
            if (!$equipo) {
                responder(404, ["error" => "Equipo no encontrado"]);
            }
            responder(200, ["imagen" => aDataUrl($equipo['imagen'])]);
            break;

        case 'PUT':
            requerirRol($conexion, ['administrador', 'encargado']);
            $id = validarId();
            if (!buscarEquipo($conexion, $id)) {
                responder(404, ["error" => "Equipo no encontrado"]);
            }
            $datos = leerJson();
            $bin = decodificarImagen($datos['imagen'] ?? null);
            $stmt = $conexion->prepare("UPDATE Equipos SET imagen = ? WHERE id_pc = ?");
            $stmt->bindValue(1, $bin, PDO::PARAM_LOB);
            $stmt->bindValue(2, $id, PDO::PARAM_INT);
            $stmt->execute();
            responder(200, ["mensaje" => "Imagen guardada correctamente"]);
            break;

        case 'DELETE':
            requerirRol($conexion, ['administrador', 'encargado']);
            $id = validarId();
            if (!buscarEquipo($conexion, $id)) {
                responder(404, ["error" => "Equipo no encontrado"]);
            }
            $stmt = $conexion->prepare("UPDATE Equipos SET imagen = NULL WHERE id_pc = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Imagen eliminada correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    responder(500, ["error" => "Error en la base de datos"]);
}