<?php
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
autorizar('telefonos');   // token Bearer + roles definidos en auth.php (PERMISOS['telefonos'])

/* ============================================================
   FUNCIONES AUXILIARES
   (rechazar() y autorizar() ya vienen de auth.php)
   ============================================================ */

// Envía una respuesta JSON con el código HTTP indicado y termina el script
function responder($codigo, $datos) {
    global $conexion;
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Lee el body de la petición y comprueba que sea un JSON válido
function leerJson() {
    $datos = json_decode(file_get_contents("php://input"), true);
    if (!is_array($datos)) {
        responder(400, ["error" => "El cuerpo de la petición debe ser un JSON válido"]);
    }
    return $datos;
}

// Comprueba que $valor sea un entero positivo y lo devuelve como int
function entero($valor, $campo) {
    $n = filter_var($valor, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($n === false) {
        responder(400, ["error" => "$campo debe ser un número entero positivo"]);
    }
    return $n;
}

// Comprueba si existe una fila. $tabla y $columna salen siempre del código, nunca del usuario.
function existe($conexion, $tabla, $columna, $valor) {
    $stmt = $conexion->prepare("SELECT 1 FROM $tabla WHERE $columna = ?");
    $stmt->execute([$valor]);
    return (bool) $stmt->fetchColumn();
}

// Teléfono: entre 8 y 9 dígitos (igual que el CHECK de la tabla)
function validarNumero($valor) {
    $s = is_int($valor) ? (string) $valor : $valor;
    if (!is_string($s) || !ctype_digit($s) || strlen($s) < 8 || strlen($s) > 9) {
        responder(400, ["error" => "El número debe tener entre 8 y 9 dígitos"]);
    }
    return (int) $s;
}

function buscarTelefono($conexion, $id) {
    $stmt = $conexion->prepare("SELECT * FROM Telefono WHERE id_telefono = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /telefono.php                    -> todos
        // GET /telefono.php?id_telefono=1      -> uno
        // GET /telefono.php?id_usuario=1       -> los teléfonos de un usuario
        case 'GET':
            if (isset($_GET['id_telefono'])) {
                $tel = buscarTelefono($conexion, entero($_GET['id_telefono'], "id_telefono"));
                if (!$tel) {
                    responder(404, ["error" => "Teléfono no encontrado"]);
                }
                responder(200, $tel);
            }
            if (isset($_GET['id_usuario'])) {
                $stmt = $conexion->prepare("SELECT * FROM Telefono WHERE fk_id_usuario = ?");
                $stmt->execute([entero($_GET['id_usuario'], "id_usuario")]);
                responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            $stmt = $conexion->query("SELECT * FROM Telefono");
            responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // POST  Body: { "id_usuario": 1, "numero": "099123456" }
        case 'POST':
            $datos = leerJson();
            foreach (['id_usuario', 'numero'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }
            $idUsuario = entero($datos['id_usuario'], "id_usuario");
            $numero = validarNumero($datos['numero']);

            if (!existe($conexion, "Usuarios", "id_usuario", $idUsuario)) {
                responder(404, ["error" => "El usuario no existe"]);
            }

            $stmt = $conexion->prepare("INSERT INTO Telefono (numero, fk_id_usuario) VALUES (?, ?)");
            $stmt->execute([$numero, $idUsuario]);

            responder(201, [
                "mensaje" => "Teléfono creado correctamente",
                "id_telefono" => (int) $conexion->lastInsertId()
            ]);
            break;

        // PUT /telefono.php?id_telefono=1   Body: { "numero": "098111222" }
        case 'PUT':
            if (!isset($_GET['id_telefono'])) {
                responder(400, ["error" => "Debe indicar el ID del teléfono"]);
            }
            $id = entero($_GET['id_telefono'], "id_telefono");
            if (!buscarTelefono($conexion, $id)) {
                responder(404, ["error" => "Teléfono no encontrado"]);
            }
            $datos = leerJson();
            if (!isset($datos['numero'])) {
                responder(400, ["error" => "Debe enviar el campo numero"]);
            }
            $stmt = $conexion->prepare("UPDATE Telefono SET numero = ? WHERE id_telefono = ?");
            $stmt->execute([validarNumero($datos['numero']), $id]);
            responder(200, ["mensaje" => "Teléfono actualizado correctamente"]);
            break;

        // DELETE /telefono.php?id_telefono=1
        case 'DELETE':
            if (!isset($_GET['id_telefono'])) {
                responder(400, ["error" => "Debe indicar el ID del teléfono"]);
            }
            $id = entero($_GET['id_telefono'], "id_telefono");
            if (!buscarTelefono($conexion, $id)) {
                responder(404, ["error" => "Teléfono no encontrado"]);
            }
            $stmt = $conexion->prepare("DELETE FROM Telefono WHERE id_telefono = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Teléfono eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    responder(500, ["error" => "Error en la base de datos"]);
}