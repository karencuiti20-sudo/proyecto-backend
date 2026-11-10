<?php
// Ingreso del estudiante solo con la cédula (sin contraseña).
//
//   POST /ingreso.php  { "ci": 53180998 }
//       -> si la cédula existe: inicia sesión (misma respuesta que login.php)
//       -> si no existe: 404 "Esa cédula no está registrada"
//   POST /ingreso.php  { "ci": 53180998, "nombre": "Paulina", "apellido": "Martinez" }
//       -> si la cédula no existe: registra al estudiante nuevo e inicia sesión
//
// Solo sirve para el rol "usuario" (estudiante). Administradores y encargados
// siguen entrando por login.php con usuario y contraseña.
header("Content-Type: application/json");
require_once "conexion.php";
require_once "auth.php";
require_once "seguridad.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rechazar(405, "Método no permitido");
}

// Nombre o apellido: 3 a 16 caracteres, solo letras (igual que usuarios.php)
function nombreIngreso($valor, $campo) {
    $valor = is_string($valor) ? trim($valor) : '';
    $largo = mb_strlen($valor);
    if ($largo < 3 || $largo > 16) {
        rechazar(400, "El campo $campo debe tener entre 3 y 16 caracteres");
    }
    if (!preg_match('/^\p{L}[\p{L}\' -]*$/u', $valor)) {
        rechazar(400, "El campo $campo solo puede tener letras (sin números ni símbolos)");
    }
    return $valor;
}

$datos = json_decode(file_get_contents("php://input"), true);
if (!is_array($datos) || !isset($datos['ci'])) {
    rechazar(400, "Debe enviar la cédula");
}

// Cédula: 8 o 9 dígitos
$s = is_int($datos['ci']) ? (string) $datos['ci'] : $datos['ci'];
if (!is_string($s) || !ctype_digit($s) || strlen($s) < 8 || strlen($s) > 9) {
    rechazar(400, "La cédula debe tener entre 8 y 9 números, sin puntos ni guión");
}
$ci = (int) $s;

try {
    $stmt = $conexion->prepare("SELECT id_usuario, nombre, usuario, estado FROM Usuarios WHERE ci = ?");
    $stmt->execute([$ci]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    $esNuevo = false;

    if (!$u) {
        // Cédula desconocida: sin nombre y apellido solo avisamos para que el front pida los datos
        if (!isset($datos['nombre'], $datos['apellido'])) {
            usleep(300000); // frena un poco los intentos por fuerza bruta
            rechazar(404, "Esa cédula no está registrada");
        }
        $nombre   = nombreIngreso($datos['nombre'], 'nombre');
        $apellido = nombreIngreso($datos['apellido'], 'apellido');

        // El "usuario" es la propia cédula (es única). La contraseña es aleatoria:
        // el estudiante entra siempre por cédula, nadie la conoce.
        $usuario = (string) $ci;
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $conexion->beginTransaction();
        try {
            $stmt = $conexion->prepare(
                "INSERT INTO Usuarios (ci, nombre, apellido, usuario, contrasenia, estado)
                 VALUES (?, ?, ?, ?, ?, 'Activo')"
            );
            $stmt->execute([$ci, $nombre, $apellido, $usuario, $hash]);
            $idNuevo = (int) $conexion->lastInsertId();

            $stmt = $conexion->prepare("INSERT INTO Estudiantes (fk_id_usuario) VALUES (?)");
            $stmt->execute([$idNuevo]);
            $conexion->commit();
        } catch (PDOException $e) {
            $conexion->rollBack();
            if ($e->getCode() === '23000') {
                rechazar(409, "Ya existe un usuario con esa cédula");
            }
            throw $e;
        }

        $u = ["id_usuario" => $idNuevo, "nombre" => $nombre, "usuario" => $usuario, "estado" => "Activo"];
        $esNuevo = true;
    }

    if (strcasecmp($u['estado'], 'Activo') !== 0) {
        usleep(300000);
        rechazar(403, "Tu usuario está inactivo. Hablá con un administrador");
    }

    $rol = buscarRol($conexion, $u['id_usuario']);
    if ($rol === null) {
        // Registrado sin rol (por ejemplo, creado desde Postman): queda como estudiante,
        // que es el rol con menos permisos
        $stmt = $conexion->prepare("INSERT INTO Estudiantes (fk_id_usuario) VALUES (?)");
        $stmt->execute([$u['id_usuario']]);
        $rol = 'usuario';
    }
    if ($rol !== 'usuario') {
        usleep(300000);
        rechazar(403, "Esa cédula es de personal del sistema: entrá por el botón Administrador");
    }

    // Limpieza de sesiones vencidas
    $stmt = $conexion->prepare("DELETE FROM Sesiones WHERE expira_en < ?");
    $stmt->execute([date('Y-m-d H:i:s')]);

    $token = crearSesion($conexion, (int) $u['id_usuario']);

    echo json_encode([
        "mensaje"   => $esNuevo ? "Usuario registrado e ingreso correcto" : "Sesión iniciada",
        "nuevo"     => $esNuevo,
        "token"     => $token,
        "expira_en" => DURACION_SESION,
        "usuario"   => [
            "id"      => (int) $u['id_usuario'],
            "usuario" => $u['usuario'],
            "nombre"  => $u['nombre'],
            "rol"     => $rol
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    rechazar(500, "Error en la base de datos");
}