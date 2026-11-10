<?php
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
require_once "seguridad.php";

$metodo = $_SERVER['REQUEST_METHOD'];

try {
    switch ($metodo) {

        // GET /login.php -> quién es el dueño del token
        case 'GET':
            $usuario = usuarioActual($conexion);
            if ($usuario === null) {
                header("WWW-Authenticate: Bearer");
                rechazar(401, "No hay sesión iniciada");
            }
            echo json_encode(["usuario" => $usuario], JSON_UNESCAPED_UNICODE);
            break;

        // POST /login.php  Body: { "usuario": "...", "contrasenia": "..." }
        case 'POST':
            $datos = json_decode(file_get_contents("php://input"), true);
            if (!is_array($datos) || !isset($datos['usuario'], $datos['contrasenia'])
                || !is_string($datos['usuario']) || !is_string($datos['contrasenia'])) {
                rechazar(400, "Debe enviar usuario y contrasenia");
            }

            $stmt = $conexion->prepare(
                "SELECT id_usuario, usuario, contrasenia, estado FROM Usuarios WHERE usuario = ?"
            );
            $stmt->execute([trim($datos['usuario'])]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);

            // Se verifica siempre contra algún hash para que no se note si el usuario existe o no
            $hash = $u ? $u['contrasenia'] : password_hash('relleno', PASSWORD_DEFAULT);
            $ok = password_verify($datos['contrasenia'], $hash);

            // Mismo mensaje para usuario inexistente, contraseña mala o usuario inactivo
            if (!$u || !$ok || strcasecmp($u['estado'], 'Activo') !== 0) {
                usleep(300000); // frena un poco los intentos por fuerza bruta
                rechazar(401, "Usuario o contraseña incorrectos");
            }

            $rol = buscarRol($conexion, $u['id_usuario']);
            if ($rol === null) {
                rechazar(403, "El usuario no tiene un rol asignado");
            }

            // Si el algoritmo de hash quedó viejo, se actualiza
            if (password_needs_rehash($u['contrasenia'], PASSWORD_DEFAULT)) {
                $stmt = $conexion->prepare("UPDATE Usuarios SET contrasenia = ? WHERE id_usuario = ?");
                $stmt->execute([password_hash($datos['contrasenia'], PASSWORD_DEFAULT), $u['id_usuario']]);
            }

            // Limpieza de sesiones vencidas
            $stmt = $conexion->prepare("DELETE FROM Sesiones WHERE expira_en < ?");
            $stmt->execute([date('Y-m-d H:i:s')]);

            $token = crearSesion($conexion, (int) $u['id_usuario']);

            echo json_encode([
                "mensaje"   => "Sesión iniciada",
                "token"     => $token,
                "expira_en" => DURACION_SESION,
                "usuario"   => [
                    "id"      => (int) $u['id_usuario'],
                    "usuario" => $u['usuario'],
                    "rol"     => $rol
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;

        // DELETE /login.php -> cerrar sesión (borra el token de la base)
        case 'DELETE':
            $token = obtenerToken();
            if ($token === null) {
                rechazar(401, "No hay sesión iniciada");
            }
            $stmt = $conexion->prepare("DELETE FROM Sesiones WHERE token_hash = ?");
            $stmt->execute([hashToken($token)]);
            echo json_encode(["mensaje" => "Sesión cerrada"], JSON_UNESCAPED_UNICODE);
            break;

        default:
            rechazar(405, "Método no permitido");
    }
} catch (PDOException $e) {
    rechazar(500, "Error en la base de datos");
}