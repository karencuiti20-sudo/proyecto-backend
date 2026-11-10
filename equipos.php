<?php
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
autorizar('equipos');

/* ============================================================
   FUNCIONES AUXILIARES
   ============================================================ */

// Envía una respuesta JSON con el código HTTP indicado y termina el script
function responder($codigo, $datos) {
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

// Comprueba que venga id_pc en la URL y que sea un entero positivo
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

// Normaliza el estado a uno de los valores permitidos por el CHECK de la tabla
function validarEstado($valor) {
    $validos = ['Disponible', 'Prestado', 'Mantenimiento'];
    if (is_string($valor)) {
        foreach ($validos as $v) {
            if (strcasecmp(trim($valor), $v) === 0) {
                return $v;
            }
        }
    }
    responder(400, ["error" => "El estado debe ser 'Disponible', 'Prestado' o 'Mantenimiento'"]);
}

// Valida los datos de un equipo. Lo que no venga en $datos se toma de $base
// (en POST son los valores por defecto, en PUT son los valores actuales).
function validarEquipo($datos, $base) {
    $r = [];

    foreach (['inventario', 'marca', 'modelo'] as $campo) {
        $valor = array_key_exists($campo, $datos) ? $datos[$campo] : ($base[$campo] ?? null);
        if (!is_string($valor) || trim($valor) === '') {
            responder(400, ["error" => "El campo $campo es obligatorio"]);
        }
        $valor = trim($valor);
        if (mb_strlen($valor) > 50) {
            responder(400, ["error" => "El campo $campo no puede superar los 50 caracteres"]);
        }
        $r[$campo] = $valor;
    }

    // Formato del inventario y de marca/modelo. Solo se exige si el valor es nuevo o cambió,
    // así un equipo viejo con otro formato se puede seguir editando.
    if (!isset($base['inventario']) || $r['inventario'] !== $base['inventario']) {
        $inv = $r['inventario'];
        if (mb_strlen($inv) < 3) {
            responder(400, ["error" => "El inventario tiene que tener al menos 3 caracteres (ej: PC-01)"]);
        }
        if (preg_match('/[^A-Za-z0-9-]/', $inv)) {
            responder(400, ["error" => "El inventario solo puede tener letras, números y guiones (sin espacios ni otros símbolos)"]);
        }
        if ($inv[0] === '-') {
            responder(400, ["error" => "El inventario no puede empezar con guion: agregue un prefijo (ej: PC-01)"]);
        }
    }
    foreach (['marca', 'modelo'] as $campo) {
        if (!isset($base[$campo]) || $r[$campo] !== $base[$campo]) {
            if (mb_strlen($r[$campo]) < 2 || !preg_match('/[\p{L}\p{N}]/u', $r[$campo])) {
                responder(400, ["error" => "El campo $campo debe tener al menos 2 caracteres y contener letras o números"]);
            }
        }
    }

    $estado = array_key_exists('estado', $datos) ? $datos['estado'] : ($base['estado'] ?? 'Disponible');
    $r['estado'] = validarEstado($estado);

    $obs = array_key_exists('observaciones', $datos) ? $datos['observaciones'] : ($base['observaciones'] ?? null);
    if ($obs !== null) {
        if (!is_string($obs)) {
            responder(400, ["error" => "Las observaciones deben ser texto"]);
        }
        $obs = trim($obs);
        if (mb_strlen($obs) > 100) {
            responder(400, ["error" => "Las observaciones no pueden superar los 100 caracteres"]);
        }
        if ($obs === '') {
            $obs = null;
        }
    }
    $r['observaciones'] = $obs;

    $rack = array_key_exists('presente_en_rack', $datos) ? $datos['presente_en_rack'] : ($base['presente_en_rack'] ?? 1);
    $rack = filter_var($rack, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($rack === null) {
        responder(400, ["error" => "presente_en_rack debe ser true/false (o 1/0)"]);
    }
    $r['presente_en_rack'] = $rack ? 1 : 0;

    return $r;
}

// Columnas que se devuelven. La imagen es binaria y rompería el JSON,
// por eso solo se informa si el equipo tiene una (se pide aparte en foto_equipo.php).
const COLUMNAS_EQUIPO = "id_pc, inventario, marca, modelo, estado, observaciones, presente_en_rack, (imagen IS NOT NULL) AS tiene_imagen";

// Busca un equipo por id. Devuelve la fila o null.
function buscarEquipo($conexion, $id) {
    $stmt = $conexion->prepare("SELECT " . COLUMNAS_EQUIPO . " FROM Equipos WHERE id_pc = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

/* ============================================================
   ENRUTADO POR MÉTODO HTTP
   ============================================================ */
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // ---------------------------------------------------
        // GET
        //   /equipos.php                        -> todos
        //   /equipos.php?id_pc=1                -> uno
        //   /equipos.php?estado=Disponible      -> filtrados por estado
        // ---------------------------------------------------
        case 'GET':
            if (isset($_GET['id_pc'])) {
                $equipo = buscarEquipo($conexion, validarId());
                if (!$equipo) {
                    responder(404, ["error" => "Equipo no encontrado"]);
                }
                responder(200, $equipo);
            }

            if (isset($_GET['estado'])) {
                $estado = validarEstado($_GET['estado']);
                $stmt = $conexion->prepare("SELECT " . COLUMNAS_EQUIPO . " FROM Equipos WHERE estado = ?");
                $stmt->execute([$estado]);
                responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            $stmt = $conexion->query("SELECT " . COLUMNAS_EQUIPO . " FROM Equipos");
            responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ---------------------------------------------------
        // POST - Registrar un equipo
        //   Obligatorios: inventario, marca, modelo
        //   Opcionales:   estado (Disponible), observaciones, presente_en_rack (true)
        // ---------------------------------------------------
        case 'POST':
            $datos = leerJson();
            $e = validarEquipo($datos, [
                'estado' => 'Disponible',
                'observaciones' => null,
                'presente_en_rack' => 1
            ]);

            // "Prestado" lo pone el sistema al registrar un préstamo, no se carga a mano
            if ($e['estado'] === 'Prestado') {
                responder(409, ["error" => "Un equipo nuevo no puede estar prestado: use 'Disponible' o 'Mantenimiento'"]);
            }

            $stmt = $conexion->prepare(
                "INSERT INTO Equipos (inventario, marca, modelo, estado, observaciones, presente_en_rack)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $e['inventario'], $e['marca'], $e['modelo'],
                $e['estado'], $e['observaciones'], $e['presente_en_rack']
            ]);

            responder(201, [
                "mensaje" => "Equipo creado correctamente",
                "id_pc" => (int) $conexion->lastInsertId()
            ]);
            break;

        // ---------------------------------------------------
        // PUT - Actualizar (se pueden mandar solo los campos a cambiar)
        // ---------------------------------------------------
        case 'PUT':
            $id = validarId();
            $actual = buscarEquipo($conexion, $id);
            if (!$actual) {
                responder(404, ["error" => "Equipo no encontrado"]);
            }

            $datos = leerJson();
            $e = validarEquipo($datos, $actual);

            // El estado Prestado/Disponible lo manejan los préstamos; acá no se puede forzar
            if ($actual['estado'] === 'Prestado' && $e['estado'] !== 'Prestado') {
                responder(409, ["error" => "El equipo está prestado: registre primero la devolución"]);
            }
            if ($actual['estado'] !== 'Prestado' && $e['estado'] === 'Prestado') {
                responder(409, ["error" => "El estado 'Prestado' lo pone el sistema al registrar un préstamo"]);
            }

            $stmt = $conexion->prepare(
                "UPDATE Equipos
                 SET inventario = ?, marca = ?, modelo = ?, estado = ?, observaciones = ?, presente_en_rack = ?
                 WHERE id_pc = ?"
            );
            $stmt->execute([
                $e['inventario'], $e['marca'], $e['modelo'],
                $e['estado'], $e['observaciones'], $e['presente_en_rack'], $id
            ]);

            responder(200, ["mensaje" => "Equipo actualizado correctamente"]);
            break;

        // ---------------------------------------------------
        // DELETE - Eliminar
        // ---------------------------------------------------
        case 'DELETE':
            $id = validarId();
            $actual = buscarEquipo($conexion, $id);
            if (!$actual) {
                responder(404, ["error" => "Equipo no encontrado"]);
            }
            if ($actual['estado'] === 'Prestado') {
                responder(409, ["error" => "No se puede eliminar un equipo que está prestado"]);
            }

            $stmt = $conexion->prepare("DELETE FROM Equipos WHERE id_pc = ?");
            $stmt->execute([$id]);

            responder(200, ["mensaje" => "Equipo eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    // 23000 = violación de integridad (UNIQUE o clave foránea)
    if ($ex->getCode() === '23000') {
        if ($metodo === 'DELETE') {
            responder(409, ["error" => "No se puede eliminar: el equipo tiene préstamos o alertas asociados"]);
        }
        responder(409, ["error" => "Ya existe un equipo con ese número de inventario"]);
    }
    responder(500, ["error" => "Error en la base de datos"]);
}