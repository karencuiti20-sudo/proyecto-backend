<?php
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";

/* ============================================================
   FUNCIONES AUXILIARES
   ============================================================ */
// Envía una respuesta JSON con el código HTTP indicado y termina el script.
// Si había una transacción abierta, la deshace antes de responder.
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

// Texto obligatorio, sin espacios sobrantes y con largo máximo
function textoObligatorio($valor, $campo, $max) {
    if (!is_string($valor) || trim($valor) === '') {
        responder(400, ["error" => "El campo $campo es obligatorio"]);
    }
    $valor = trim($valor);
    if (mb_strlen($valor) > $max) {
        responder(400, ["error" => "El campo $campo no puede superar los $max caracteres"]);
    }
    return $valor;
}

// Texto opcional: devuelve null si viene null o vacío
function textoOpcional($valor, $campo, $max) {
    if ($valor === null) {
        return null;
    }
    if (!is_string($valor)) {
        responder(400, ["error" => "El campo $campo debe ser texto"]);
    }
    $valor = trim($valor);
    if (mb_strlen($valor) > $max) {
        responder(400, ["error" => "El campo $campo no puede superar los $max caracteres"]);
    }
    return $valor === '' ? null : $valor;
}

// Comprueba si existe una fila. $tabla y $columna salen siempre del código, nunca del usuario.
function existe($conexion, $tabla, $columna, $valor) {
    $stmt = $conexion->prepare("SELECT 1 FROM $tabla WHERE $columna = ?");
    $stmt->execute([$valor]);
    return (bool) $stmt->fetchColumn();
}


/* ============================================================
   AUTENTICACIÓN Y PERMISOS
   Token Bearer + roles definidos en auth.php (PERMISOS['cursos']).
   ============================================================ */
autorizar('cursos');


function buscarCurso($conexion, $id) {
    $stmt = $conexion->prepare("SELECT * FROM Cursos WHERE id_cursos = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

function validarId() {
    if (!isset($_GET['id_cursos'])) {
        responder(400, ["error" => "Debe indicar el ID del curso"]);
    }
    return entero($_GET['id_cursos'], "id_cursos");
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /cursos.php                       -> todos
        // GET /cursos.php?id_cursos=1           -> uno
        // GET /cursos.php?tipo_cursos=Taller    -> filtrados por tipo
        case 'GET':
            if (isset($_GET['id_cursos'])) {
                $curso = buscarCurso($conexion, validarId());
                if (!$curso) {
                    responder(404, ["error" => "Curso no encontrado"]);
                }
                responder(200, $curso);
            }
            if (isset($_GET['tipo_cursos'])) {
                $stmt = $conexion->prepare("SELECT * FROM Cursos WHERE tipo_cursos = ?");
                $stmt->execute([$_GET['tipo_cursos']]);
                responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
            $stmt = $conexion->query("SELECT * FROM Cursos");
            responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // POST  Obligatorios: nombre, tipo_cursos   Opcional: descripcion
        case 'POST':
            $datos = leerJson();
            $nombre = textoObligatorio($datos['nombre'] ?? null, "nombre", 50);
            $tipo = textoObligatorio($datos['tipo_cursos'] ?? null, "tipo_cursos", 50);
            $descripcion = textoOpcional($datos['descripcion'] ?? null, "descripcion", 1000);

            $stmt = $conexion->prepare("INSERT INTO Cursos (nombre, descripcion, tipo_cursos) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $descripcion, $tipo]);

            responder(201, [
                "mensaje" => "Curso creado correctamente",
                "id_cursos" => (int) $conexion->lastInsertId()
            ]);
            break;

        // PUT /cursos.php?id_cursos=1  (solo los campos que se envíen)
        case 'PUT':
            $id = validarId();
            if (!buscarCurso($conexion, $id)) {
                responder(404, ["error" => "Curso no encontrado"]);
            }
            $datos = leerJson();
            $sets = [];
            $params = [];

            if (array_key_exists('nombre', $datos)) {
                $sets[] = "nombre = ?";
                $params[] = textoObligatorio($datos['nombre'], "nombre", 50);
            }
            if (array_key_exists('tipo_cursos', $datos)) {
                $sets[] = "tipo_cursos = ?";
                $params[] = textoObligatorio($datos['tipo_cursos'], "tipo_cursos", 50);
            }
            if (array_key_exists('descripcion', $datos)) {
                $sets[] = "descripcion = ?";
                $params[] = textoOpcional($datos['descripcion'], "descripcion", 1000);
            }
            if (count($sets) === 0) {
                responder(400, ["error" => "Debe enviar al menos un campo: nombre, tipo_cursos o descripcion"]);
            }

            $params[] = $id;
            $stmt = $conexion->prepare("UPDATE Cursos SET " . implode(", ", $sets) . " WHERE id_cursos = ?");
            $stmt->execute($params);
            responder(200, ["mensaje" => "Curso actualizado correctamente"]);
            break;

        case 'DELETE':
            $id = validarId();
            if (!buscarCurso($conexion, $id)) {
                responder(404, ["error" => "Curso no encontrado"]);
            }
            $stmt = $conexion->prepare("DELETE FROM Cursos WHERE id_cursos = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Curso eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        responder(409, ["error" => "No se puede eliminar: el curso tiene estudiantes, docentes o préstamos asociados"]);
    }
    responder(500, ["error" => "Error en la base de datos"]);
}