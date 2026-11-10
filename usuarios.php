<?php
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
autorizar('usuarios');

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

// Comprueba que venga id_usuario en la URL y que sea un entero positivo
function validarId() {
    if (!isset($_GET['id_usuario'])) {
        responder(400, ["error" => "Debe indicar el ID del usuario"]);
    }
    $id = filter_var($_GET['id_usuario'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if ($id === false) {
        responder(400, ["error" => "El id debe ser un número entero positivo"]);
    }
    return $id;
}

// Nombre o apellido: de 3 a 16 caracteres (igual que el CHECK de la tabla)
// y solo letras (con tildes), espacios, apóstrofes o guiones
function validarNombre($valor, $campo) {
    if (!is_string($valor) || mb_strlen(trim($valor)) < 3 || mb_strlen(trim($valor)) > 16) {
        responder(400, ["error" => "El campo $campo debe tener entre 3 y 16 caracteres"]);
    }
    $valor = trim($valor);
    if (!preg_match('/^\p{L}[\p{L}\' -]*$/u', $valor)) {
        responder(400, ["error" => "El campo $campo solo puede tener letras (sin números ni símbolos)"]);
    }
    return $valor;
}

// Cédula: entre 8 y 9 dígitos (el CHECK pide al menos 8 y la columna es INT)
function validarCi($valor) {
    $s = is_int($valor) ? (string) $valor : $valor;
    if (!is_string($s) || !ctype_digit($s) || strlen($s) < 8 || strlen($s) > 9) {
        responder(400, ["error" => "La cédula debe tener entre 8 y 9 dígitos"]);
    }
    return (int) $s;
}

// Nombre de usuario para el login: de 3 a 50 caracteres, sin espacios
// (letras, números, punto, guion y guion bajo)
function validarUsuario($valor) {
    if (!is_string($valor) || trim($valor) === '' || mb_strlen(trim($valor)) > 50) {
        responder(400, ["error" => "El usuario es obligatorio y no puede superar los 50 caracteres"]);
    }
    $valor = trim($valor);
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $valor)) {
        responder(400, ["error" => "El usuario debe tener al menos 3 caracteres: letras, números, punto, guion o guion bajo (sin espacios)"]);
    }
    return $valor;
}

// Valida la contraseña y devuelve su hash (nunca se guarda en texto plano)
function hashContrasenia($valor) {
    if (!is_string($valor) || strlen($valor) < 6 || strlen($valor) > 72) {
        responder(400, ["error" => "La contraseña debe tener entre 6 y 72 caracteres"]);
    }
    return password_hash($valor, PASSWORD_DEFAULT);
}

// Rol del usuario nuevo: usuario (estudiante), encargado (docente) o administrador (funcionario).
// Si no se envía, queda como "usuario", que es el que tiene menos permisos.
function validarRol($valor) {
    if (is_string($valor) && array_key_exists($valor, ROLES_TABLAS)) {
        return $valor;
    }
    responder(400, ["error" => "El rol debe ser: " . implode(", ", array_keys(ROLES_TABLAS))]);
}

function validarEstado($valor) {
    if (is_string($valor)) {
        foreach (['Activo', 'Inactivo'] as $v) {
            if (strcasecmp(trim($valor), $v) === 0) {
                return $v;
            }
        }
    }
    responder(400, ["error" => "El estado debe ser 'Activo' o 'Inactivo'"]);
}

// La foto llega en base64 (opcional). La columna es BLOB: máximo 65535 bytes.
function validarFoto($valor) {
    if ($valor === null || $valor === '') {
        return null;
    }
    $bin = is_string($valor) ? base64_decode($valor, true) : false;
    if ($bin === false) {
        responder(400, ["error" => "foto_perfil debe estar codificada en base64"]);
    }
    if (strlen($bin) > 65535) {
        responder(400, ["error" => "foto_perfil no puede superar los 64 KB"]);
    }
    return $bin;
}

// Columnas que se devuelven. Nunca la contraseña; la foto es binaria y rompería
// el JSON, por eso solo se informa si el usuario tiene una.
const COLUMNAS = "id_usuario, ci, nombre, apellido, usuario, estado, (foto_perfil IS NOT NULL) AS tiene_foto";

function buscarUsuario($conexion, $id) {
    $stmt = $conexion->prepare("SELECT " . COLUMNAS . " FROM Usuarios WHERE id_usuario = ?");
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
        //   /usuarios.php                  -> todos
        //   /usuarios.php?id_usuario=1     -> uno
        // ---------------------------------------------------
        case 'GET':
            if (isset($_GET['id_usuario'])) {
                $usuario = buscarUsuario($conexion, validarId());
                if (!$usuario) {
                    responder(404, ["error" => "Usuario no encontrado"]);
                }
                responder(200, $usuario);
            }

            $stmt = $conexion->query("SELECT " . COLUMNAS . " FROM Usuarios");
            responder(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // ---------------------------------------------------
        // POST - Registrar un usuario
        //   Obligatorios: ci, nombre, apellido, usuario, contrasenia
        //   Opcionales:   estado (Activo), foto_perfil (base64),
        //                 rol (usuario por defecto: usuario / encargado / administrador)
        // ---------------------------------------------------
        case 'POST':
            $datos = leerJson();

            foreach (['ci', 'nombre', 'apellido', 'usuario', 'contrasenia'] as $campo) {
                if (!isset($datos[$campo])) {
                    responder(400, ["error" => "El campo $campo es obligatorio"]);
                }
            }

            $ci          = validarCi($datos['ci']);
            $nombre      = validarNombre($datos['nombre'], 'nombre');
            $apellido    = validarNombre($datos['apellido'], 'apellido');
            $usuario     = validarUsuario($datos['usuario']);
            $contrasenia = hashContrasenia($datos['contrasenia']);
            $estado      = validarEstado($datos['estado'] ?? 'Activo');
            $foto        = validarFoto($datos['foto_perfil'] ?? null);
            $rol         = validarRol($datos['rol'] ?? 'usuario');

            // El usuario y su rol se crean juntos: o quedan los dos o no queda ninguno
            $conexion->beginTransaction();

            $stmt = $conexion->prepare(
                "INSERT INTO Usuarios (ci, nombre, apellido, usuario, contrasenia, foto_perfil, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bindValue(1, $ci, PDO::PARAM_INT);
            $stmt->bindValue(2, $nombre);
            $stmt->bindValue(3, $apellido);
            $stmt->bindValue(4, $usuario);
            $stmt->bindValue(5, $contrasenia);
            $stmt->bindValue(6, $foto, $foto === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $stmt->bindValue(7, $estado);
            $stmt->execute();
            $idNuevo = (int) $conexion->lastInsertId();

            // El nombre de la tabla sale de ROLES_TABLAS (auth.php), nunca del usuario
            $stmt = $conexion->prepare("INSERT INTO " . ROLES_TABLAS[$rol] . " (fk_id_usuario) VALUES (?)");
            $stmt->execute([$idNuevo]);

            $conexion->commit();

            responder(201, [
                "mensaje" => "Usuario creado correctamente",
                "id_usuario" => $idNuevo,
                "rol" => $rol
            ]);
            break;

        // ---------------------------------------------------
        // PUT - Actualizar (solo los campos que se envíen)
        //   Campos: ci, nombre, apellido, usuario, estado, contrasenia
        // ---------------------------------------------------
        case 'PUT':
            $id = validarId();
            if (!buscarUsuario($conexion, $id)) {
                responder(404, ["error" => "Usuario no encontrado"]);
            }

            $datos = leerJson();
            $sets = [];
            $params = [];

            if (array_key_exists('nombre', $datos)) {
                $sets[] = "nombre = ?";
                $params[] = validarNombre($datos['nombre'], 'nombre');
            }
            if (array_key_exists('apellido', $datos)) {
                $sets[] = "apellido = ?";
                $params[] = validarNombre($datos['apellido'], 'apellido');
            }
            if (array_key_exists('ci', $datos)) {
                $sets[] = "ci = ?";
                $params[] = validarCi($datos['ci']);
            }
            if (array_key_exists('usuario', $datos)) {
                $sets[] = "usuario = ?";
                $params[] = validarUsuario($datos['usuario']);
            }
            if (array_key_exists('estado', $datos)) {
                $estadoNuevo = validarEstado($datos['estado']);
                if ($estadoNuevo === 'Inactivo') {
                    // No se puede desactivar a alguien que todavía tiene un equipo sin devolver
                    $stmt = $conexion->prepare(
                        "SELECT COUNT(*) FROM Prestamos p
                         JOIN Prestamos_usuarios pu ON pu.fk_id_prestamo = p.id_prestamo
                         WHERE pu.fk_id_usuario = ? AND p.estado = 'Prestado'"
                    );
                    $stmt->execute([$id]);
                    if ((int) $stmt->fetchColumn() > 0) {
                        responder(409, ["error" => "El usuario tiene préstamos activos: registre la devolución antes de desactivarlo"]);
                    }
                }
                $sets[] = "estado = ?";
                $params[] = $estadoNuevo;
            }
            if (array_key_exists('contrasenia', $datos)) {
                $sets[] = "contrasenia = ?";
                $params[] = hashContrasenia($datos['contrasenia']);
            }

            if (count($sets) === 0) {
                responder(400, ["error" => "Debe enviar al menos un campo: ci, nombre, apellido, usuario, estado o contrasenia"]);
            }

            $params[] = $id;
            $stmt = $conexion->prepare("UPDATE Usuarios SET " . implode(", ", $sets) . " WHERE id_usuario = ?");
            $stmt->execute($params);

            responder(200, ["mensaje" => "Usuario actualizado correctamente"]);
            break;

        // ---------------------------------------------------
        // DELETE - Eliminar
        // ---------------------------------------------------
        case 'DELETE':
            $id = validarId();
            if (!buscarUsuario($conexion, $id)) {
                responder(404, ["error" => "Usuario no encontrado"]);
            }

            $stmt = $conexion->prepare("DELETE FROM Usuarios WHERE id_usuario = ?");
            $stmt->execute([$id]);

            responder(200, ["mensaje" => "Usuario eliminado correctamente"]);
            break;

        default:
            responder(405, ["error" => "Método no permitido"]);
    }
} catch (PDOException $ex) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // 23000 = violación de integridad (UNIQUE o clave foránea)
    if ($ex->getCode() === '23000') {
        if ($metodo === 'DELETE') {
            responder(409, ["error" => "No se puede eliminar: el usuario tiene préstamos asociados"]);
        }
        responder(409, ["error" => "Ya existe un usuario con esa cédula o ese nombre de usuario"]);
    }
    responder(500, ["error" => "Error en la base de datos"]);
}