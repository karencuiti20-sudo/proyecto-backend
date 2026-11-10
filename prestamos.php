<?php
header("Content-Type: application/json");

require_once "conexion.php";
require_once "auth.php";
require_once "seguridad.php";
require_once "csv.php";   // rango de fechas y exportación a CSV

// Días de préstamo por defecto cuando no se indica fecha_prevista
// (el estudiante siempre usa este valor: la fecha de devolución se calcula sola)
const DIAS_PRESTAMO = 7;
// Máximo de días de préstamo que puede pedir un administrador/encargado
const DIAS_MAXIMOS = 60;
// Cantidad máxima de préstamos activos por usuario (0 = sin límite; poné 1 para "un equipo a la vez")
const MAX_ACTIVOS_USUARIO = 0;

autorizar('prestamos');   // vale para administrador, encargado y usuario

// Después de autorizar() siempre hay un usuario válido: [id, usuario, rol]
$actual = usuarioActual($conexion);
$esUsuarioComun = ($actual['rol'] === 'usuario');

/* ============================================================
   FUNCIONES AUXILIARES
   ============================================================ */

function responder($codigo, $datos) {
    global $conexion;
    if (isset($conexion) && $conexion->inTransaction()) {
        $conexion->rollBack();
    }
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

function entero($valor, $campo) {
    $n = filter_var($valor, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($n === false) {
        responder(400, ["error" => "$campo debe ser un número entero positivo"]);
    }
    return $n;
}

function validarId() {
    if (!isset($_GET['id_prestamo'])) {
        responder(400, ["error" => "Debe indicar el ID del préstamo"]);
    }
    return entero($_GET['id_prestamo'], "id_prestamo");
}

function validarFecha($valor, $campo) {
    if (is_string($valor)) {
        foreach (['Y-m-d H:i:s', 'Y-m-d'] as $formato) {
            $d = DateTime::createFromFormat($formato, $valor);
            if ($d && $d->format($formato) === $valor) {
                return $d->format('Y-m-d H:i:s');
            }
        }
    }
    responder(400, ["error" => "$campo debe tener formato YYYY-MM-DD o YYYY-MM-DD HH:MM:SS"]);
}

// La fecha prevista no puede ser anterior a hoy ni superar DIAS_MAXIMOS días
function validarRangoPrestamo($fecha) {
    if (substr($fecha, 0, 10) < date('Y-m-d')) {
        responder(400, ["error" => "fecha_prevista no puede ser anterior a hoy"]);
    }
    if (substr($fecha, 0, 10) > date('Y-m-d', strtotime('+' . DIAS_MAXIMOS . ' days'))) {
        responder(400, ["error" => "fecha_prevista no puede superar los " . DIAS_MAXIMOS . " días"]);
    }
}

function validarObservaciones($valor) {
    if ($valor === null) {
        return null;
    }
    if (!is_string($valor)) {
        responder(400, ["error" => "Las observaciones deben ser texto"]);
    }
    $valor = trim($valor);
    if (mb_strlen($valor) > 100) {
        responder(400, ["error" => "Las observaciones no pueden superar los 100 caracteres"]);
    }
    return $valor === '' ? null : $valor;
}

function buscarPrestamo($conexion, $id) {
    $stmt = $conexion->prepare("SELECT * FROM Prestamos WHERE id_prestamo = ?");
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

// true si el préstamo pertenece a ese usuario
function prestamoEsDe($conexion, $idPrestamo, $idUsuario) {
    $stmt = $conexion->prepare(
        "SELECT 1 FROM Prestamos_usuarios WHERE fk_id_prestamo = ? AND fk_id_usuario = ?"
    );
    $stmt->execute([$idPrestamo, $idUsuario]);
    return $stmt->fetchColumn() !== false;
}

function clausulaOrden() {
    if (!isset($_GET['orden'])) {
        return "";
    }
    $permitidas = ['id_prestamo', 'estado', 'fecha_prevista', 'fecha_prestamo_devolucion'];
    if (!in_array($_GET['orden'], $permitidas, true)) {
        responder(400, ["error" => "orden debe ser: " . implode(", ", $permitidas)]);
    }
    $direccion = strtolower($_GET['direccion'] ?? 'asc');
    if ($direccion !== 'asc' && $direccion !== 'desc') {
        responder(400, ["error" => "direccion debe ser 'asc' o 'desc'"]);
    }
    return " ORDER BY p." . $_GET['orden'] . " " . strtoupper($direccion);
}

$sqlBase = "SELECT p.id_prestamo, p.observaciones, p.estado, p.fecha_prevista,
                   p.fecha_prestamo_devolucion,
                   pu.fk_id_usuario AS id_usuario,
                   pe.fk_id_pc AS id_pc,
                   p.fk_id_cursos AS id_cursos
            FROM Prestamos p
            LEFT JOIN Prestamos_usuarios pu ON pu.fk_id_prestamo = p.id_prestamo
            LEFT JOIN Prestamos_equipos pe ON pe.fk_id_prestamo = p.id_prestamo";

/* ============================================================
   ENRUTADO POR MÉTODO HTTP
   ============================================================ */
$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // ---------------------------------------------------
        // GET - El usuario común solo ve SUS préstamos
        //   ?vencidos=1 -> préstamos sin devolver pasada la fecha prevista
        //                  (solo administrador y encargado)
        // ---------------------------------------------------
        case 'GET':
            if (isset($_GET['vencidos'])) {
                if ($esUsuarioComun) {
                    responder(403, ["error" => "No tiene permisos para esta acción"]);
                }
                $ahora = date('Y-m-d H:i:s');
                $stmt = $conexion->prepare(
                    "SELECT p.id_prestamo, p.fecha_prevista, p.observaciones,
                            DATEDIFF(?, DATE(p.fecha_prevista)) AS dias_atraso,
                            u.id_usuario, u.ci, u.nombre, u.apellido,
                            e.id_pc, e.inventario, e.marca, e.modelo
                     FROM Prestamos p
                     JOIN Prestamos_usuarios pu ON pu.fk_id_prestamo = p.id_prestamo
                     JOIN Usuarios u            ON u.id_usuario = pu.fk_id_usuario
                     JOIN Prestamos_equipos pe  ON pe.fk_id_prestamo = p.id_prestamo
                     JOIN Equipos e             ON e.id_pc = pe.fk_id_pc
                     WHERE p.estado = 'Prestado' AND p.fecha_prevista < ?
                     ORDER BY p.fecha_prevista ASC"
                );
                $stmt->execute([$ahora, $ahora]);
                $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                responder(200, ["total" => count($filas), "prestamos" => $filas]);
            }

            if ($esUsuarioComun) {
                $_GET['id_usuario'] = $actual['id'];
                unset($_GET['fecha_prevista'], $_GET['fecha_prestamo_devolucion']);

                if (isset($_GET['id_prestamo'])) {
                    $id = entero($_GET['id_prestamo'], "id_prestamo");
                    $stmt = $conexion->prepare(
                        $sqlBase . " WHERE p.id_prestamo = ? AND pu.fk_id_usuario = ?"
                    );
                    $stmt->execute([$id, $actual['id']]);
                    $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$prestamo) {
                        responder(404, ["error" => "Préstamo no encontrado"]);
                    }
                    responder(200, $prestamo);
                }
            }

            if (isset($_GET['id_prestamo'])) {
                $id = entero($_GET['id_prestamo'], "id_prestamo");
                $stmt = $conexion->prepare($sqlBase . " WHERE p.id_prestamo = ?");
                $stmt->execute([$id]);
                $prestamo = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$prestamo) {
                    responder(404, ["error" => "Préstamo no encontrado"]);
                }
                responder(200, $prestamo);
            }

            $orden = clausulaOrden();
            $where = [];
            $params = [];

            // Filtros que se pueden combinar entre sí
            foreach (['fecha_prevista', 'fecha_prestamo_devolucion'] as $campoFecha) {
                if (isset($_GET[$campoFecha])) {
                    $d = DateTime::createFromFormat('Y-m-d', (string) $_GET[$campoFecha]);
                    if (!$d || $d->format('Y-m-d') !== $_GET[$campoFecha]) {
                        responder(400, ["error" => "$campoFecha debe tener formato YYYY-MM-DD"]);
                    }
                    $where[] = "DATE(p.$campoFecha) = ?";
                    $params[] = $_GET[$campoFecha];
                }
            }
            if (isset($_GET['id_usuario'])) {
                $where[] = "pu.fk_id_usuario = ?";
                $params[] = entero($_GET['id_usuario'], "id_usuario");
            }
            if (isset($_GET['id_pc'])) {
                $where[] = "pe.fk_id_pc = ?";
                $params[] = entero($_GET['id_pc'], "id_pc");
            }
            if (isset($_GET['estado'])) {
                $estados = ['Prestado', 'Devuelto'];
                $est = null;
                foreach ($estados as $e) {
                    if (is_string($_GET['estado']) && strcasecmp(trim($_GET['estado']), $e) === 0) {
                        $est = $e;
                    }
                }
                if ($est === null) {
                    responder(400, ["error" => "estado debe ser 'Prestado' o 'Devuelto'"]);
                }
                $where[] = "p.estado = ?";
                $params[] = $est;
            }

            // Rango de fechas (inclusive) sobre la fecha prevista de devolución
            // (o sobre fecha_prestamo_devolucion con ?campo_fecha=fecha_prestamo_devolucion)
            $campoRango = $_GET['campo_fecha'] ?? 'fecha_prevista';
            if (!in_array($campoRango, ['fecha_prevista', 'fecha_prestamo_devolucion'], true)) {
                responder(400, ["error" => "campo_fecha debe ser 'fecha_prevista' o 'fecha_prestamo_devolucion'"]);
            }
            agregarRango($where, $params, "p.$campoRango", leerRangoFechas());

            $csv = quiereCsv();
            if ($csv && $esUsuarioComun) {
                responder(403, ["error" => "No tiene permisos para exportar"]);
            }

            $stmt = $conexion->prepare($sqlBase . ($where ? " WHERE " . implode(" AND ", $where) : "") . $orden);
            $stmt->execute($params);
            $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($csv) {
                enviarCsv("prestamos_" . date('Y-m-d') . ".csv",
                    ['id_prestamo', 'id_usuario', 'id_pc', 'estado', 'fecha_prevista',
                     'fecha_prestamo_devolucion', 'observaciones'], $filas);
            }
            responder(200, $filas);
            break;

        // ---------------------------------------------------
        // POST - Registrar un préstamo
        //   El usuario común siempre pide a su propio nombre
        // ---------------------------------------------------
        case 'POST':
            $datos = leerJson();

            if ($esUsuarioComun) {
                $datos['id_usuario'] = $actual['id'];
                // El estudiante no elige la fecha: se calcula sola
                unset($datos['fecha_prevista']);
            }

            // Si no viene fecha_prevista, se calcula: hoy + DIAS_PRESTAMO (hasta el fin de ese día)
            if (!isset($datos['fecha_prevista'])) {
                $datos['fecha_prevista'] = date('Y-m-d 23:59:59', strtotime('+' . DIAS_PRESTAMO . ' days'));
            }

            foreach (['id_usuario', 'id_pc', 'fecha_prevista'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }
            $id_usuario     = entero($datos['id_usuario'], "id_usuario");
            $id_pc          = entero($datos['id_pc'], "id_pc");
            $fecha_prevista = validarFecha($datos['fecha_prevista'], "fecha_prevista");
            validarRangoPrestamo($fecha_prevista);
            $observaciones  = validarObservaciones($datos['observaciones'] ?? null);
            if (!isset($datos['id_cursos'])) {
                responder(400, ["error" => "El campo id_cursos es obligatorio: elegí el curso o grupo"]);
            }
            $id_cursos      = entero($datos['id_cursos'], "id_cursos");

            $conexion->beginTransaction();

            $stmt = $conexion->prepare("SELECT estado FROM Usuarios WHERE id_usuario = ?");
            $stmt->execute([$id_usuario]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$usuario) {
                responder(404, ["error" => "El usuario no existe"]);
            }
            if (strcasecmp($usuario['estado'], 'Activo') !== 0) {
                responder(409, ["error" => "El usuario está inactivo"]);
            }

            if (MAX_ACTIVOS_USUARIO > 0) {
                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM Prestamos p
                     JOIN Prestamos_usuarios pu ON pu.fk_id_prestamo = p.id_prestamo
                     WHERE pu.fk_id_usuario = ? AND p.estado = 'Prestado'"
                );
                $stmt->execute([$id_usuario]);
                if ((int) $stmt->fetchColumn() >= MAX_ACTIVOS_USUARIO) {
                    responder(409, ["error" => "Ya tenés " . MAX_ACTIVOS_USUARIO . " préstamo(s) activo(s): devolvé el equipo antes de pedir otro"]);
                }
            }

            $stmt = $conexion->prepare("SELECT estado FROM Equipos WHERE id_pc = ? FOR UPDATE");
            $stmt->execute([$id_pc]);
            $equipo = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$equipo) {
                responder(404, ["error" => "El equipo no existe"]);
            }
            if (strcasecmp($equipo['estado'], 'Disponible') !== 0) {
                responder(409, ["error" => "El equipo no está disponible (estado: " . $equipo['estado'] . ")"]);
            }

            $stmt = $conexion->prepare("SELECT 1 FROM Cursos WHERE id_cursos = ?");
            $stmt->execute([$id_cursos]);
            if (!$stmt->fetchColumn()) {
                responder(404, ["error" => "El curso no existe"]);
            }

            $stmt = $conexion->prepare(
                "INSERT INTO Prestamos (observaciones, estado, fecha_prevista, fk_id_cursos)
                 VALUES (?, 'Prestado', ?, ?)"
            );
            $stmt->execute([$observaciones, $fecha_prevista, $id_cursos]);
            $id_prestamo = (int) $conexion->lastInsertId();

            $stmt = $conexion->prepare(
                "INSERT INTO Prestamos_usuarios (fk_id_prestamo, fk_id_usuario) VALUES (?, ?)"
            );
            $stmt->execute([$id_prestamo, $id_usuario]);

            $stmt = $conexion->prepare(
                "INSERT INTO Prestamos_equipos (fk_id_prestamo, fk_id_pc) VALUES (?, ?)"
            );
            $stmt->execute([$id_prestamo, $id_pc]);

            $stmt = $conexion->prepare("UPDATE Equipos SET estado = 'Prestado' WHERE id_pc = ?");
            $stmt->execute([$id_pc]);

            $conexion->commit();

            responder(201, [
                "mensaje" => "Préstamo creado correctamente",
                "id_prestamo" => $id_prestamo,
                "fecha_prevista" => $fecha_prevista
            ]);
            break;

        // ---------------------------------------------------
        // PUT - Actualizar o devolver
        //   El usuario común solo puede devolver SUS préstamos
        // ---------------------------------------------------
        case 'PUT':
            $id = validarId();
            $prestamo = buscarPrestamo($conexion, $id);
            if (!$prestamo) {
                responder(404, ["error" => "Préstamo no encontrado"]);
            }

            $accion = $_GET['accion'] ?? null;

            if ($esUsuarioComun) {
                if ($accion !== 'devolver') {
                    responder(403, ["error" => "No tiene permisos para esta acción"]);
                }
                if (!prestamoEsDe($conexion, $id, $actual['id'])) {
                    responder(403, ["error" => "Este préstamo no es tuyo"]);
                }
            }

            if ($accion === 'devolver') {
                if (strcasecmp($prestamo['estado'], 'Devuelto') === 0) {
                    responder(409, ["error" => "El préstamo ya fue devuelto"]);
                }

                $conexion->beginTransaction();

                $stmt = $conexion->prepare(
                    "UPDATE Prestamos
                     SET estado = 'Devuelto', fecha_prestamo_devolucion = NOW()
                     WHERE id_prestamo = ?"
                );
                $stmt->execute([$id]);

                $stmt = $conexion->prepare(
                    "UPDATE Equipos SET estado = 'Disponible'
                     WHERE id_pc IN (SELECT fk_id_pc FROM Prestamos_equipos WHERE fk_id_prestamo = ?)"
                );
                $stmt->execute([$id]);

                $conexion->commit();

                responder(200, ["mensaje" => "Préstamo devuelto correctamente"]);
            }

            if ($accion !== null) {
                responder(400, ["error" => "Acción no válida. Use accion=devolver"]);
            }

            if (strcasecmp($prestamo['estado'], 'Devuelto') === 0) {
                responder(409, ["error" => "No se puede modificar un préstamo que ya fue devuelto"]);
            }

            $datos = leerJson();
            if (!array_key_exists('observaciones', $datos) && !isset($datos['fecha_prevista'])) {
                responder(400, ["error" => "Debe enviar observaciones y/o fecha_prevista"]);
            }

            $observaciones = array_key_exists('observaciones', $datos)
                ? validarObservaciones($datos['observaciones'])
                : $prestamo['observaciones'];
            $fecha_prevista = isset($datos['fecha_prevista'])
                ? validarFecha($datos['fecha_prevista'], "fecha_prevista")
                : $prestamo['fecha_prevista'];
            if (isset($datos['fecha_prevista'])) {
                validarRangoPrestamo($fecha_prevista);
            }

            $stmt = $conexion->prepare(
                "UPDATE Prestamos SET observaciones = ?, fecha_prevista = ? WHERE id_prestamo = ?"
            );
            $stmt->execute([$observaciones, $fecha_prevista, $id]);

            responder(200, ["mensaje" => "Préstamo actualizado correctamente"]);
            break;

        // ---------------------------------------------------
        // DELETE - Solo administrador (lo filtra autorizar())
        // ---------------------------------------------------
        case 'DELETE':
            $id = validarId();
            $prestamo = buscarPrestamo($conexion, $id);
            if (!$prestamo) {
                responder(404, ["error" => "Préstamo no encontrado"]);
            }

            $conexion->beginTransaction();

            if (strcasecmp($prestamo['estado'], 'Prestado') === 0) {
                $stmt = $conexion->prepare(
                    "UPDATE Equipos SET estado = 'Disponible'
                     WHERE id_pc IN (SELECT fk_id_pc FROM Prestamos_equipos WHERE fk_id_prestamo = ?)"
                );
                $stmt->execute([$id]);
            }

            foreach (['Prestamos_usuarios', 'Prestamos_equipos', 'Prestamos_reportes'] as $tabla) {
                $stmt = $conexion->prepare("DELETE FROM $tabla WHERE fk_id_prestamo = ?");
                $stmt->execute([$id]);
            }

            $stmt = $conexion->prepare("DELETE FROM Prestamos WHERE id_prestamo = ?");
            $stmt->execute([$id]);

            $conexion->commit();

            responder(200, ["mensaje" => "Préstamo eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $e) {
    responder(500, ["error" => "Error en la base de datos"]);
}