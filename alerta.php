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
   Token Bearer + roles definidos en auth.php (PERMISOS['alertas']).
   ============================================================ */
autorizar('alertas');


require_once "csv.php";   // rango de fechas y exportación a CSV

// Tipos permitidos por el CHECK de la tabla
function validarTipo($valor) {
    $validos = ['extraccion_no_autorizada', 'falta_devolucion'];
    if (is_string($valor) && in_array(trim($valor), $validos, true)) {
        return trim($valor);
    }
    responder(400, ["error" => "tipo debe ser 'extraccion_no_autorizada' o 'falta_devolucion'"]);
}

function validarBool($valor, $campo) {
    $b = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($b === null) {
        responder(400, ["error" => "$campo debe ser true/false (o 1/0)"]);
    }
    return $b ? 1 : 0;
}

function buscarAlerta($conexion, $id) {
    $stmt = $conexion->prepare("SELECT * FROM Alertas WHERE id_alerta = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

function validarId() {
    if (!isset($_GET['id_alerta'])) {
        responder(400, ["error" => "Debe indicar el ID de la alerta"]);
    }
    return entero($_GET['id_alerta'], "id_alerta");
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /alertas.php                       -> todas (las más nuevas primero)
        // GET /alertas.php?id_alerta=1           -> una
        // GET /alertas.php?id_pc=1               -> de un equipo
        // GET /alertas.php?atendida=0            -> pendientes
        // GET /alertas.php?tipo=falta_devolucion -> por tipo
        // GET /alertas.php?desde=2026-10-01&hasta=2026-10-28 -> por rango de fechas
        // GET /alertas.php?formato=csv           -> descarga CSV (acepta los mismos filtros)
        case 'GET':
            if (isset($_GET['id_alerta'])) {
                $alerta = buscarAlerta($conexion, validarId());
                if (!$alerta) {
                    responder(404, ["error" => "Alerta no encontrada"]);
                }
                responder(200, $alerta);
            }

            $where = [];
            $params = [];
            if (isset($_GET['id_pc'])) {
                $where[] = "fk_id_pc = ?";
                $params[] = entero($_GET['id_pc'], "id_pc");
            }
            if (isset($_GET['atendida'])) {
                $where[] = "atendida = ?";
                $params[] = validarBool($_GET['atendida'], "atendida");
            }
            if (isset($_GET['tipo'])) {
                $where[] = "tipo = ?";
                $params[] = validarTipo($_GET['tipo']);
            }
            // Rango de fechas (inclusive): ?desde=2026-10-01&hasta=2026-10-28
            agregarRango($where, $params, "fecha_alerta", leerRangoFechas());
            $csv = quiereCsv();

            $sql = "SELECT * FROM Alertas" . ($where ? " WHERE " . implode(" AND ", $where) : "")
                 . " ORDER BY fecha_alerta DESC";
            $stmt = $conexion->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($csv) {
                enviarCsv("alertas_" . date('Y-m-d') . ".csv",
                    ['id_alerta', 'fecha_alerta', 'tipo', 'atendida', 'fk_id_pc'], $filas);
            }
            responder(200, $filas);
            break;

        // POST  Body: { "id_pc": 1, "tipo": "extraccion_no_autorizada" }
        // (la fecha se pone sola y atendida arranca en false)
        case 'POST':
            $datos = leerJson();
            foreach (['id_pc', 'tipo'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }
            $idPc = entero($datos['id_pc'], "id_pc");
            $tipo = validarTipo($datos['tipo']);

            if (!existe($conexion, "Equipos", "id_pc", $idPc)) {
                responder(404, ["error" => "El equipo no existe"]);
            }

            $stmt = $conexion->prepare("INSERT INTO Alertas (fk_id_pc, tipo) VALUES (?, ?)");
            $stmt->execute([$idPc, $tipo]);
            responder(201, [
                "mensaje" => "Alerta creada correctamente",
                "id_alerta" => (int) $conexion->lastInsertId()
            ]);
            break;

        // PUT /alertas.php?id_alerta=1   Body: { "atendida": true }
        case 'PUT':
            $id = validarId();
            if (!buscarAlerta($conexion, $id)) {
                responder(404, ["error" => "Alerta no encontrada"]);
            }
            $datos = leerJson();
            if (!array_key_exists('atendida', $datos)) {
                responder(400, ["error" => "Debe enviar el campo atendida"]);
            }
            $stmt = $conexion->prepare("UPDATE Alertas SET atendida = ? WHERE id_alerta = ?");
            $stmt->execute([validarBool($datos['atendida'], "atendida"), $id]);
            responder(200, ["mensaje" => "Alerta actualizada correctamente"]);
            break;

        case 'DELETE':
            $id = validarId();
            if (!buscarAlerta($conexion, $id)) {
                responder(404, ["error" => "Alerta no encontrada"]);
            }
            $stmt = $conexion->prepare("DELETE FROM Alertas WHERE id_alerta = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Alerta eliminada correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    responder(500, ["error" => "Error en la base de datos"]);
}