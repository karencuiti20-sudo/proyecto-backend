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
   Token Bearer + roles definidos en auth.php (PERMISOS['reportes']).
   ============================================================ */
autorizar('reportes');


function buscarReporte($conexion, $id) {
    $stmt = $conexion->prepare("SELECT * FROM Reportes WHERE id_reporte = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

function validarId() {
    if (!isset($_GET['id_reporte'])) {
        responder(400, ["error" => "Debe indicar el ID del reporte"]);
    }
    return entero($_GET['id_reporte'], "id_reporte");
}

require_once "csv.php";   // rango de fechas y exportación a CSV

// Reporte junto con el préstamo al que está asociado (si tiene)
$sqlBase = "SELECT r.id_reporte, r.fecha_generacion, r.estado, r.descripcion, r.tipo_reportes,
                   pr.fk_id_prestamo AS id_prestamo
            FROM Reportes r
            LEFT JOIN Prestamos_reportes pr ON pr.fk_id_reporte = r.id_reporte";

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /reportes.php                          -> todos
        // GET /reportes.php?id_reporte=1             -> uno
        // GET /reportes.php?id_prestamo=1            -> los de un préstamo
        // GET /reportes.php?estado=Abierto           -> por estado
        // GET /reportes.php?tipo_reportes=Daño       -> por tipo
        // GET /reportes.php?desde=2026-10-01&hasta=2026-10-28  -> por rango de fechas
        // GET /reportes.php?formato=csv              -> descarga CSV (acepta los mismos filtros)
        case 'GET':
            if (isset($_GET['id_reporte'])) {
                $stmt = $conexion->prepare($sqlBase . " WHERE r.id_reporte = ?");
                $stmt->execute([validarId()]);
                $fila = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$fila) {
                    responder(404, ["error" => "Reporte no encontrado"]);
                }
                responder(200, $fila);
            }

            $where = [];
            $params = [];
            if (isset($_GET['id_prestamo'])) {
                $where[] = "pr.fk_id_prestamo = ?";
                $params[] = entero($_GET['id_prestamo'], "id_prestamo");
            }
            if (isset($_GET['estado'])) {
                $where[] = "r.estado = ?";
                $params[] = $_GET['estado'];
            }
            if (isset($_GET['tipo_reportes'])) {
                $where[] = "r.tipo_reportes = ?";
                $params[] = $_GET['tipo_reportes'];
            }
            // Rango de fechas (inclusive): ?desde=2026-10-01&hasta=2026-10-28
            agregarRango($where, $params, "r.fecha_generacion", leerRangoFechas());
            $csv = quiereCsv();

            $sql = $sqlBase . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY r.fecha_generacion DESC";
            $stmt = $conexion->prepare($sql);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ?formato=csv descarga un archivo que abre Excel / Google Sheets
            if ($csv) {
                enviarCsv("reportes_" . date('Y-m-d') . ".csv",
                    ['id_reporte', 'fecha_generacion', 'estado', 'tipo_reportes', 'descripcion', 'id_prestamo'],
                    $filas);
            }
            responder(200, $filas);
            break;

        // POST  Obligatorios: estado, descripcion, tipo_reportes
        //       Opcional: id_prestamo (lo asocia a un préstamo)
        case 'POST':
            $datos = leerJson();
            $estado = textoObligatorio($datos['estado'] ?? null, "estado", 50);
            $descripcion = textoObligatorio($datos['descripcion'] ?? null, "descripcion", 250);
            $tipo = textoObligatorio($datos['tipo_reportes'] ?? null, "tipo_reportes", 50);
            $idPrestamo = isset($datos['id_prestamo']) ? entero($datos['id_prestamo'], "id_prestamo") : null;

            if ($idPrestamo !== null && !existe($conexion, "Prestamos", "id_prestamo", $idPrestamo)) {
                responder(404, ["error" => "El préstamo no existe"]);
            }

            $conexion->beginTransaction();

            $stmt = $conexion->prepare(
                "INSERT INTO Reportes (estado, descripcion, tipo_reportes) VALUES (?, ?, ?)"
            );
            $stmt->execute([$estado, $descripcion, $tipo]);
            $idReporte = (int) $conexion->lastInsertId();

            if ($idPrestamo !== null) {
                $stmt = $conexion->prepare(
                    "INSERT INTO Prestamos_reportes (fk_id_prestamo, fk_id_reporte) VALUES (?, ?)"
                );
                $stmt->execute([$idPrestamo, $idReporte]);
            }

            $conexion->commit();

            responder(201, [
                "mensaje" => "Reporte creado correctamente",
                "id_reporte" => $idReporte
            ]);
            break;

        // PUT /reportes.php?id_reporte=1  (estado, descripcion, tipo_reportes)
        case 'PUT':
            $id = validarId();
            if (!buscarReporte($conexion, $id)) {
                responder(404, ["error" => "Reporte no encontrado"]);
            }
            $datos = leerJson();
            $sets = [];
            $params = [];

            if (array_key_exists('estado', $datos)) {
                $sets[] = "estado = ?";
                $params[] = textoObligatorio($datos['estado'], "estado", 50);
            }
            if (array_key_exists('descripcion', $datos)) {
                $sets[] = "descripcion = ?";
                $params[] = textoObligatorio($datos['descripcion'], "descripcion", 250);
            }
            if (array_key_exists('tipo_reportes', $datos)) {
                $sets[] = "tipo_reportes = ?";
                $params[] = textoObligatorio($datos['tipo_reportes'], "tipo_reportes", 50);
            }
            if (count($sets) === 0) {
                responder(400, ["error" => "Debe enviar al menos un campo: estado, descripcion o tipo_reportes"]);
            }

            $params[] = $id;
            $stmt = $conexion->prepare("UPDATE Reportes SET " . implode(", ", $sets) . " WHERE id_reporte = ?");
            $stmt->execute($params);
            responder(200, ["mensaje" => "Reporte actualizado correctamente"]);
            break;

        // DELETE: primero se borra la relación con el préstamo
        case 'DELETE':
            $id = validarId();
            if (!buscarReporte($conexion, $id)) {
                responder(404, ["error" => "Reporte no encontrado"]);
            }
            $conexion->beginTransaction();
            $stmt = $conexion->prepare("DELETE FROM Prestamos_reportes WHERE fk_id_reporte = ?");
            $stmt->execute([$id]);
            $stmt = $conexion->prepare("DELETE FROM Reportes WHERE id_reporte = ?");
            $stmt->execute([$id]);
            $conexion->commit();
            responder(200, ["mensaje" => "Reporte eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    responder(500, ["error" => "Error en la base de datos"]);
}