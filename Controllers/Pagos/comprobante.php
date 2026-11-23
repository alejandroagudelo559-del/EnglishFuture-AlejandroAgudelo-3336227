<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

// Si tu database.php llama a la conexión de otra forma, se busca aquí con los nombres más comunes
if (!isset($pdo)) {
    foreach (['conexion', 'conn', 'con', 'db', 'dbh'] as $nombreVariable) {
        if (isset($$nombreVariable) && $$nombreVariable instanceof PDO) {
            $pdo = $$nombreVariable;
            break;
        }
    }
}

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

// ---------- 1. Solo se permite consultar (GET) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    fallo('metodo_no_permitido', 405);
}

// ---------- 2. Conexión disponible ----------
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[comprobante.php] database.php no entregó una conexión PDO en $pdo');
    fallo('error_servidor', 500);
}

// ---------- 3. El id del pago debe ser un entero positivo ----------
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if ($id === false || $id <= 0) {
    fallo('no_encontrado', 404);
}

// ---------- 4. Buscar el pago con una consulta preparada ----------
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare(
        'SELECT id_pago, estudiante, valor_pagado, fecha_pago, medio_pago, estado, concepto
           FROM pago
          WHERE id_pago = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$f) {
        fallo('no_encontrado', 404);
    }

    $fecha = (string)$f['fecha_pago'];

    responderJson([
        'ok' => true,
        'comprobante' => [
            // Mismo formato que en la tabla de Pagos: REC-año-id (ej. REC-2026-001)
            'codigo'     => 'REC-' . substr($fecha, 0, 4) . '-' . str_pad((string)$f['id_pago'], 3, '0', STR_PAD_LEFT),
            'fecha'      => $fecha,
            'estudiante' => (string)$f['estudiante'],
            'metodo'     => (string)$f['medio_pago'],
            'estado'     => (string)$f['estado'],
            'concepto'   => (string)($f['concepto'] ?? ''),
            'monto'      => (float)$f['valor_pagado'],
        ],
    ]);
} catch (PDOException $e) {
    // El detalle va al log del servidor; el usuario solo ve un mensaje genérico
    error_log('[comprobante.php] ' . $e->getMessage());
    fallo('error_servidor', 500);
}