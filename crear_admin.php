<?php
// crear_admin.php: crea el PRIMER administrador desde la consola. Uso: php crear_admin.php correo@ejemplo.com ClaveSegura123
if (PHP_SAPI !== 'cli') {
http_response_code(404);
exit;
}
require_once "conexion.php";
$email = strtolower(trim($argv[1] ?? ''));
$password = $argv[2] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
fwrite(STDERR, "Email inválido.\nUso: php crear_admin.php correo@ejemplo.com Clave\n");
exit(1);
}
if (strlen($password) < 8 || strlen($password) > 72) {
fwrite(STDERR, "La contraseña debe tener entre 8 y 72 caracteres.\n");
exit(1);
}
try {
$stmt = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch() !== false) {
fwrite(STDERR, "Ya existe un usuario con ese email.\n");
exit(1);
}
$stmt = $conexion->prepare(
"INSERT INTO usuarios (email, password_hash, rol) VALUES (?, ?, 'admin')"
);
$stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
echo "Administrador creado: $email\n";
} catch (PDOException $e) {
fwrite(STDERR, "Error de base de datos.\n");
exit(1);
}