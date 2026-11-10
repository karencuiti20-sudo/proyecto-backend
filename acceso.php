<?php
// Acceso rápido de ESTUDIANTES con solo la cédula (sistema autogestionado).
//   POST /acceso.php   Body: { "ci": "12345678" }
// Devuelve el mismo formato que login.php (token, expira_en, usuario), pero el token
// SOLO sirve con el rol "usuario": ve equipos y cursos, y pide / devuelve SUS préstamos.
// Docentes (encargado) y funcionarios (administrador) NO pueden entrar por acá:
// ellos siguen usando usuario y contraseña en login.php.
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rechazar(405, "Método no permitido");
}

try {
    $datos = json_decode(file_get_contents("php://input"), true);
    if (!is_array($datos) || !isset($datos['ci'])) {
        rechazar(400, "Debe enviar la cédula (ci)");
    }

    // Cédula: entre 8 y 9 dígitos (se aceptan puntos y guion: 1.234.567-8)
    $ci = is_int($datos['ci']) ? (string) $datos['ci'] : $datos['ci'];
    $ci = is_string($ci) ? preg_replace('/[.\-\s]/', '', $ci) : '';
    if (!ctype_digit($ci) || strlen($ci) < 8 || strlen($ci) > 9) {
        rechazar(400, "La cédula debe tener entre 8 y 9 dígitos");
    }

    $stmt = $conexion->prepare("SELECT id_usuario, nombre, apellido, usuario, estado FROM Usuarios WHERE ci = ?");
    $stmt->execute([(int) $ci]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        usleep(300000); // frena un poco los intentos de adivinar cédulas
        rechazar(404, "No hay ningún usuario registrado con esa cédula");
    }
    if (strcasecmp($u['estado'], 'Activo') !== 0) {
        rechazar(403, "El usuario está inactivo. Consulte con el encargado");
    }

    // Solo el rol más bajo entra con cédula
    $rol = buscarRol($conexion, $u['id_usuario']);
    if ($rol === null) {
        rechazar(403, "El usuario no tiene un rol asignado");
    }
    if ($rol !== 'usuario') {
        rechazar(403, "Esta cédula es de un docente o funcionario: ingrese con usuario y contraseña");
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
            "id"       => (int) $u['id_usuario'],
            "usuario"  => $u['usuario'],
            "nombre"   => $u['nombre'],
            "apellido" => $u['apellido'],
            "rol"      => $rol
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    rechazar(500, "Error en la base de datos");
}
