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
   Token Bearer + roles definidos en auth.php (PERMISOS['inscripciones']).
   ============================================================ */
autorizar('inscripciones');


// tipo => [tabla intermedia, columna de la persona, tabla de la persona, pk de la persona]
$tipos = [
    'estudiantes' => ['Estudiantes_cursos', 'fk_id_estudiante', 'Estudiantes', 'id_estudiante'],
    'docentes'    => ['Docentes_cursos',    'fk_id_docentes',   'Docentes',    'id_docentes']
];

function validarTipo($valor) {
    global $tipos;
    $t = is_string($valor) ? strtolower(trim($valor)) : '';
    if (!isset($tipos[$t])) {
        responder(400, ["error" => "tipo debe ser: " . implode(", ", array_keys($tipos))]);
    }
    return $t;
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /inscripciones.php?tipo=estudiantes                  -> todas
        // GET /inscripciones.php?tipo=estudiantes&id_cursos=1      -> de un curso
        // GET /inscripciones.php?tipo=docentes&id_persona=2        -> de un docente
        case 'GET':
            $tipo = validarTipo($_GET['tipo'] ?? null);
            [$tabla, $fkp, $tablaP, $pk] = $tipos[$tipo];

            $sql = "SELECT i.fk_id_cursos AS id_cursos, c.nombre AS curso,
                           i.$fkp AS id_persona, u.nombre, u.apellido
                    FROM $tabla i
                    JOIN Cursos c ON c.id_cursos = i.fk_id_cursos
                    JOIN $tablaP p ON p.$pk = i.$fkp
                    JOIN Usuarios u ON u.id_usuario = p.fk_id_usuario";
            $where = [];
            $params = [];
            if (isset($_GET['id_cursos'])) {
                $where[] = "i.fk_id_cursos = ?";
                $params[] = entero($_GET['id_cursos'], "id_cursos");
            }
            if (isset($_GET['id_persona'])) {
                $where[] = "i.$fkp = ?";
                $params[] = entero($_GET['id_persona'], "id_persona");
            }
            if ($where) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            $stmt = $conexion->prepare($sql);
            $stmt->execute($params);
            responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // POST /inscripciones.php?tipo=estudiantes
        //   Body: { "id_cursos": 1, "id_persona": 2 }
        //   (id_persona = id_estudiante o id_docentes según el tipo)
        case 'POST':
            $tipo = validarTipo($_GET['tipo'] ?? null);
            [$tabla, $fkp, $tablaP, $pk] = $tipos[$tipo];
            $datos = leerJson();
            foreach (['id_cursos', 'id_persona'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }
            $idCurso = entero($datos['id_cursos'], "id_cursos");
            $idPersona = entero($datos['id_persona'], "id_persona");

            if (!existe($conexion, "Cursos", "id_cursos", $idCurso)) {
                responder(404, ["error" => "El curso no existe"]);
            }
            if (!existe($conexion, $tablaP, $pk, $idPersona)) {
                responder(404, ["error" => "El $tipo indicado no existe"]);
            }

            $stmt = $conexion->prepare("INSERT INTO $tabla (fk_id_cursos, $fkp) VALUES (?, ?)");
            $stmt->execute([$idCurso, $idPersona]);
            responder(201, ["mensaje" => "Inscripción creada correctamente"]);
            break;

        // DELETE /inscripciones.php?tipo=estudiantes&id_cursos=1&id_persona=2
        case 'DELETE':
            $tipo = validarTipo($_GET['tipo'] ?? null);
            [$tabla, $fkp] = $tipos[$tipo];
            if (!isset($_GET['id_cursos']) || !isset($_GET['id_persona'])) {
                responder(400, ["error" => "Debe indicar id_cursos e id_persona"]);
            }
            $idCurso = entero($_GET['id_cursos'], "id_cursos");
            $idPersona = entero($_GET['id_persona'], "id_persona");

            $stmt = $conexion->prepare("DELETE FROM $tabla WHERE fk_id_cursos = ? AND $fkp = ?");
            $stmt->execute([$idCurso, $idPersona]);
            if ($stmt->rowCount() === 0) {
                responder(404, ["error" => "Inscripción no encontrada"]);
            }
            responder(200, ["mensaje" => "Inscripción eliminada correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        responder(409, ["error" => "Esa inscripción ya existe"]);
    }
    responder(500, ["error" => "Error en la base de datos"]);
}