<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

// Para saber qué día es "hoy" en Colombia (afecta la regla de fecha futura)
date_default_timezone_set('America/Bogota');

// Si tu database.php llama a la conexión de otra forma, se busca aquí con los nombres más comunes
if (!isset($pdo)) {
    foreach (['conexion', 'conn', 'con', 'db', 'dbh'] as $nombreVariable) {
        if (isset($$nombreVariable) && $$nombreVariable instanceof PDO) {
            $pdo = $$nombreVariable;
            break;
        }
    }
}

const METODOS_VALIDOS = ['Efectivo', 'Transferencia', 'Tarjeta de Crédito'];
const ESTADOS_REGISTRO = ['Completado', 'Pendiente']; // "Anulado" solo se llega con la acción anular

/** Responde en JSON y termina. */
function responderJson(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Responde con un error en JSON (el JS lo traduce a un mensaje). */
function fallo(string $codigo, int $http): void
{
    responderJson(['ok' => false, 'error' => $codigo], $http);
}

/** Lee un campo POST como texto limpio. */
function campoPost(string $nombre): string
{
    return trim((string)($_POST[$nombre] ?? ''));
}

/** Valida los datos del pago. Devuelve el código de error o null si todo está bien. */
function validar(string $estudiante, string $valor, string $fecha, string $medio, string $estado, string $concepto): ?string
{
    if ($estudiante === '' || $valor === '' || $fecha === '' || $medio === '' || $estado === '') {
        return 'campos_vacios';
    }
    $largoEstudiante = mb_strlen($estudiante);
    if ($largoEstudiante < 2 || $largoEstudiante > 100) {
        return 'estudiante_invalido';
    }
    // Hasta 8 enteros y 2 decimales (cabe en DECIMAL(10,2)) y mayor que cero
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valor) || (float)$valor <= 0) {
        return 'monto_invalido';
    }
    $fechaObjeto = DateTime::createFromFormat('!Y-m-d', $fecha);
    if ($fechaObjeto === false || $fechaObjeto->format('Y-m-d') !== $fecha) {
        return 'fecha_invalida';
    }
    if ($fecha > date('Y-m-d')) {
        return 'fecha_futura';
    }
    if (!in_array($medio, METODOS_VALIDOS, true)) {
        return 'metodo_invalido';
    }
    if (!in_array($estado, ESTADOS_REGISTRO, true)) {
        return 'estado_invalido';
    }
    if (mb_strlen($concepto) > 255) {
        return 'concepto_invalido';
    }
    return null;
}

// ---------- 1. Qué acción piden y con qué método ----------
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';

$metodoOk = ($accion === 'listar' && $metodo === 'GET')
         || (in_array($accion, ['registrar', 'anular'], true) && $metodo === 'POST');

if (!$metodoOk) {
    fallo('metodo_no_permitido', 405);
}

// ---------- 2. Conexión disponible ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[pagos.php] database.php no entregó una conexión PDO en $pdo');
    fallo('error_servidor', 500);
}

// ---------- 3. Ejecutar la acción ----------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($accion) {

        // ===== LISTAR =====
        case 'listar':
            $stmt = $pdo->query(
                'SELECT id_pago, estudiante, valor_pagado, fecha_pago, medio_pago, estado, concepto
                   FROM pago
                  ORDER BY fecha_pago DESC, id_pago DESC'
            );
            $pagos = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $id = (int)$f['id_pago'];
                $pagos[] = [
                    'id'         => $id,
                    // Número de comprobante: se calcula con el año del pago y el id (ej. REC-2026-001)
                    'codigo'     => 'REC-' . substr((string)$f['fecha_pago'], 0, 4) . '-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT),
                    'estudiante' => (string)$f['estudiante'],
                    'monto'      => (float)$f['valor_pagado'],
                    'fecha'      => (string)$f['fecha_pago'],
                    'metodo'     => (string)$f['medio_pago'],
                    'estado'     => (string)$f['estado'],
                    'concepto'   => (string)($f['concepto'] ?? ''),
                ];
            }
            responderJson(['ok' => true, 'pagos' => $pagos]);
            break;

        // ===== REGISTRAR =====
        case 'registrar':
            $estudiante = campoPost('estudiante');
            $valor      = campoPost('valor_pagado');
            $fecha      = campoPost('fecha_pago');
            $medio      = campoPost('medio_pago');
            $estado     = campoPost('estado');
            $concepto   = campoPost('concepto');

            $error = validar($estudiante, $valor, $fecha, $medio, $estado, $concepto);
            if ($error !== null) {
                fallo($error, 400);
            }

            // id_inscripcion queda en NULL. El concepto vacío se guarda como NULL.
            $stmt = $pdo->prepare(
                'INSERT INTO pago (estudiante, valor_pagado, medio_pago, fecha_pago, concepto, estado)
                 VALUES (:estudiante, :valor, :medio, :fecha, :concepto, :estado)'
            );
            $stmt->execute([
                ':estudiante' => $estudiante,
                ':valor'      => number_format((float)$valor, 2, '.', ''),
                ':medio'      => $medio,
                ':fecha'      => $fecha,
                ':concepto'   => $concepto === '' ? null : $concepto,
                ':estado'     => $estado,
            ]);
            responderJson(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        // ===== ANULAR (el pago no se borra, solo cambia de estado) =====
        case 'anular':
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id === false || $id <= 0) {
                fallo('no_encontrado', 404);
            }

            $stmt = $pdo->prepare('SELECT estado FROM pago WHERE id_pago = :id');
            $stmt->execute([':id' => $id]);
            $estadoActual = $stmt->fetchColumn();

            if ($estadoActual === false) {
                fallo('no_encontrado', 404);
            }
            if ($estadoActual === 'Anulado') {
                fallo('ya_anulado', 409);
            }

            $stmt = $pdo->prepare('UPDATE pago SET estado = \'Anulado\' WHERE id_pago = :id');
            $stmt->execute([':id' => $id]);
            responderJson(['ok' => true]);
            break;
    }
} catch (PDOException $e) {
    // El detalle va al log del servidor; el usuario solo ve un mensaje genérico
    error_log('[pagos.php] ' . $e->getMessage());
    fallo('error_servidor', 500);
}