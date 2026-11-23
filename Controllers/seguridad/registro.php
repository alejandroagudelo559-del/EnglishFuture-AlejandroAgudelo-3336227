<?php
/**
 * Controlador de Registro de Usuarios - EnglishFuture
 * Ubicación: controllers/seguridad/registro.php
 */

// ============================================================
// MODO DESARROLLO
// true  = si algo falla en la base de datos, muestra el error SQL real en pantalla.
// false = (producción) oculta el detalle y solo redirige con un mensaje genérico.
// ¡Cámbialo a false cuando publiques el proyecto!
// ============================================================
const MODO_DESARROLLO = true;

/**
 * Redirige de vuelta al formulario con un código de error.
 */
function volverConError(string $codigo): void
{
    header('Location: ../../views/seguridad/registro.html?error=' . urlencode($codigo));
    exit;
}

// 1. Verificar que la solicitud se realice estrictamente mediante método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    volverConError('metodo_no_permitido');
}

// 2. Captura y saneamiento básico de los datos enviados desde el formulario
//
// IMPORTANTE: las contraseñas NO se llaman $password. El archivo config/database.php
// suele usar $password para la clave de MySQL (en XAMPP es vacía) y, al incluirlo más
// abajo, sobrescribiría la variable: el hash se calcularía sobre una contraseña vacía
// y la cuenta quedaría guardada con clave vacía. Por eso se usan nombres propios.
$nombres            = trim($_POST['nombre'] ?? '');
$apellidos          = trim($_POST['apellido'] ?? '');
$tipoDocumento      = strtoupper(trim($_POST['tipo_documento'] ?? ''));
$numeroDocumento    = strtoupper(trim($_POST['numero_documento'] ?? ''));
$telefono           = trim($_POST['telefono'] ?? '');
$direccion          = trim($_POST['direccion'] ?? '');
$correo             = strtolower(trim($_POST['email'] ?? ''));
$passwordIngresada  = $_POST['password'] ?? '';
$passwordConfirmada = $_POST['confirmPassword'] ?? '';

// 3. Validación de campos obligatorios (antes de abrir conexión a la base de datos)
if (
    $nombres === '' ||
    $apellidos === '' ||
    $tipoDocumento === '' ||
    $numeroDocumento === '' ||
    $telefono === '' ||
    $direccion === '' ||
    $correo === '' ||
    $passwordIngresada === '' ||
    $passwordConfirmada === ''
) {
    volverConError('campos_vacios');
}

// 4. Validación del tipo y número de documento
// El tipo solo puede ser uno de los valores permitidos (lista blanca)
$tiposPermitidos = ['CC', 'TI', 'CE', 'PA', 'PEP', 'PPT'];
if (!in_array($tipoDocumento, $tiposPermitidos, true)) {
    volverConError('tipo_documento_invalido');
}

// El número solo admite letras y números, entre 5 y 20 caracteres
if (!preg_match('/^[A-Z0-9]{5,20}$/', $numeroDocumento)) {
    volverConError('documento_invalido');
}

// 5. Validación del formato del correo electrónico
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    volverConError('correo_invalido');
}

// 6. Validación de longitud mínima (8 caracteres reales UTF-8) y coincidencia de contraseñas
if (mb_strlen($passwordIngresada, 'UTF-8') < 8) {
    volverConError('password_corta');
}

if ($passwordIngresada !== $passwordConfirmada) {
    volverConError('password_no_coincide');
}

// 7. Importar la conexión PDO existente
require_once __DIR__ . '/../../config/database.php';

/** @var PDO|null $pdo */
if (!isset($pdo)) {
    if (isset($conn) && $conn instanceof PDO) {
        $pdo = $conn;
    } elseif (isset($conexion) && $conexion instanceof PDO) {
        $pdo = $conexion;
    }
}

// Verificar que exista una instancia válida de PDO antes de consultar
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('Error crítico (EnglishFuture): No se encontró una instancia válida de PDO en config/database.php');
    if (MODO_DESARROLLO) {
        die('Error: config/database.php no está creando una variable $pdo (o $conn / $conexion) de tipo PDO.');
    }
    volverConError('error_servidor');
}

// Forzar que PDO lance excepciones (así un INSERT fallido nunca pasa en silencio)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 8. Reglas de negocio controladas exclusivamente por el servidor
// Nunca se lee $_POST['id_rol'] ni $_POST['estado'] para evitar escalada de privilegios
$idRolCliente  = 2;        // Rol asignado para el registro público (debe existir en tu tabla de roles)
$estadoInicial = 'activo'; // Cambia a 1 si tu columna 'estado' en MySQL es TINYINT/INT

try {
    // 9. Verificar que el correo no esté registrado previamente
    $stmtCorreo = $pdo->prepare("SELECT COUNT(*) FROM estudiante WHERE correo = :correo");
    $stmtCorreo->bindValue(':correo', $correo, PDO::PARAM_STR);
    $stmtCorreo->execute();

    if ((int) $stmtCorreo->fetchColumn() > 0) {
        volverConError('correo_registrado');
    }

    // 10. Verificar que el número de documento no esté registrado previamente
    $stmtDocumento = $pdo->prepare("SELECT COUNT(*) FROM estudiante WHERE numero_documento = :numero_documento");
    $stmtDocumento->bindValue(':numero_documento', $numeroDocumento, PDO::PARAM_STR);
    $stmtDocumento->execute();

    if ((int) $stmtDocumento->fetchColumn() > 0) {
        volverConError('documento_registrado');
    }

    // 11. Cifrar la contraseña utilizando el algoritmo seguro por defecto
    $passwordHash = password_hash($passwordIngresada, PASSWORD_DEFAULT);

    // 12. Preparar la inserción en la tabla 'estudiante'
    // (la clave primaria se genera sola con AUTO_INCREMENT, por eso no aparece aquí)
    $sqlInsertar = "INSERT INTO estudiante (
                        nombres,
                        apellidos,
                        tipo_documento,
                        numero_documento,
                        correo,
                        telefono,
                        direccion,
                        contrasena,
                        estado,
                        id_rol
                    ) VALUES (
                        :nombres,
                        :apellidos,
                        :tipo_documento,
                        :numero_documento,
                        :correo,
                        :telefono,
                        :direccion,
                        :contrasena,
                        :estado,
                        :id_rol
                    )";

    $stmtInsertar = $pdo->prepare($sqlInsertar);

    // Vinculación segura de parámetros
    $stmtInsertar->bindValue(':nombres', $nombres, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':apellidos', $apellidos, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':tipo_documento', $tipoDocumento, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':numero_documento', $numeroDocumento, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':correo', $correo, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':telefono', $telefono, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':direccion', $direccion, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':contrasena', $passwordHash, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':estado', $estadoInicial, PDO::PARAM_STR);
    $stmtInsertar->bindValue(':id_rol', $idRolCliente, PDO::PARAM_INT);

    // Ejecutar la inserción y comprobar que realmente se guardó una fila
    $stmtInsertar->execute();

    if ($stmtInsertar->rowCount() !== 1) {
        error_log('Registro EnglishFuture: el INSERT no afectó ninguna fila.');
        if (MODO_DESARROLLO) {
            die('El INSERT se ejecutó pero no insertó ninguna fila.');
        }
        volverConError('error_servidor');
    }

    // 13. Redirigir al login tras un registro exitoso
    header('Location: ../../views/seguridad/login.html?registro=exitoso');
    exit;

} catch (PDOException $e) {
    // 14. Manejo de errores: registra el detalle interno en el log de PHP/Apache
    error_log('Error en registro de estudiante (EnglishFuture): ' . $e->getMessage());

    if (MODO_DESARROLLO) {
        // Muestra el error real para que puedas corregir la tabla o el INSERT
        die('Error SQL: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    // En producción no se expone información sensible al usuario final
    volverConError('error_servidor');
}