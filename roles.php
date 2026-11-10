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
   Token Bearer + roles definidos en auth.php (PERMISOS['roles']).
   ============================================================ */
autorizar('roles');


// rol => [tabla, columna de clave primaria]
$roles = [
    'estudiante'  => ['Estudiantes',  'id_estudiante'],
    'docente'     => ['Docentes',     'id_docentes'],
    'funcionario' => ['Funcionarios', 'id_funcionario']
];

function validarRol($valor) {
    global $roles;
    $r = is_string($valor) ? strtolower(trim($valor)) : '';
    if (!isset($roles[$r])) {
        responder(400, ["error" => "rol debe ser: " . implode(", ", array_keys($roles))]);
    }
    return $r;
}

// Consulta base de un rol: la fila del rol junto con los datos del usuario (sin contraseña ni foto)
function sqlRol($rol) {
    global $roles;
    [$tabla, $pk] = $roles[$rol];
    return "SELECT r.$pk AS id_rol, u.id_usuario, u.ci, u.nombre, u.apellido, u.usuario, u.estado
            FROM $tabla r
            JOIN Usuarios u ON u.id_usuario = r.fk_id_usuario";
}

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /roles.php?rol=docente            -> todos los docentes
        // GET /roles.php?rol=docente&id=1       -> un docente
        // GET /roles.php?id_usuario=5           -> qué roles tiene un usuario
        case 'GET':
            if (isset($_GET['rol'])) {
                $rol = validarRol($_GET['rol']);
                if (isset($_GET['id'])) {
                    $pk = $roles[$rol][1];
                    $stmt = $conexion->prepare(sqlRol($rol) . " WHERE r.$pk = ?");
                    $stmt->execute([entero($_GET['id'], "id")]);
                    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$fila) {
                        responder(404, ["error" => "No encontrado"]);
                    }
                    responder(200, $fila);
                }
                $stmt = $conexion->query(sqlRol($rol));
                responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }

            if (isset($_GET['id_usuario'])) {
                $idUsuario = entero($_GET['id_usuario'], "id_usuario");
                if (!existe($conexion, "Usuarios", "id_usuario", $idUsuario)) {
                    responder(404, ["error" => "Usuario no encontrado"]);
                }
                $resultado = [];
                foreach ($roles as $nombre => [$tabla, $pk]) {
                    $stmt = $conexion->prepare("SELECT $pk FROM $tabla WHERE fk_id_usuario = ?");
                    $stmt->execute([$idUsuario]);
                    $id = $stmt->fetchColumn();
                    if ($id !== false) {
                        $resultado[] = ["rol" => $nombre, "id_rol" => (int) $id];
                    }
                }
                responder(200, ["id_usuario" => $idUsuario, "roles" => $resultado]);
            }

            responder(400, ["error" => "Debe indicar rol o id_usuario"]);
            break;

        // POST  Body: { "rol": "estudiante", "id_usuario": 1 }
        case 'POST':
            $datos = leerJson();
            foreach (['rol', 'id_usuario'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }
            $rol = validarRol($datos['rol']);
            $idUsuario = entero($datos['id_usuario'], "id_usuario");

            if (!existe($conexion, "Usuarios", "id_usuario", $idUsuario)) {
                responder(404, ["error" => "El usuario no existe"]);
            }

            $tabla = $roles[$rol][0];
            $stmt = $conexion->prepare("INSERT INTO $tabla (fk_id_usuario) VALUES (?)");
            $stmt->execute([$idUsuario]);

            responder(201, [
                "mensaje" => "Rol asignado correctamente",
                "id_rol" => (int) $conexion->lastInsertId()
            ]);
            break;

        // DELETE /roles.php?rol=docente&id=1
        case 'DELETE':
            if (!isset($_GET['rol']) || !isset($_GET['id'])) {
                responder(400, ["error" => "Debe indicar rol e id"]);
            }
            $rol = validarRol($_GET['rol']);
            $id = entero($_GET['id'], "id");
            [$tabla, $pk] = $roles[$rol];

            if (!existe($conexion, $tabla, $pk, $id)) {
                responder(404, ["error" => "No encontrado"]);
            }
            $stmt = $conexion->prepare("DELETE FROM $tabla WHERE $pk = ?");
            $stmt->execute([$id]);
            responder(200, ["mensaje" => "Rol eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    if ($ex->getCode() === '23000') {
        if ($metodo === 'DELETE') {
            responder(409, ["error" => "No se puede eliminar: tiene cursos asociados"]);
        }
        responder(409, ["error" => "El usuario ya tiene ese rol"]);
    }
    responder(500, ["error" => "Error en la base de datos"]);
}