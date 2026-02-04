<?php
// Iniciar un buffer para detectar cualquier salida inesperada
ob_start();

require_once __DIR__ . '/functions/view_control_functions.php';
require_once __DIR__ . '/functions/auth.php';

startAuthSession();
header('Content-Type: application/json');

function sendJson($data) {
    $extra = ob_get_clean();
    if (trim($extra) !== '') {
        error_log('Salida inesperada en asistencia_ajax.php: ' . $extra);
    }
    echo json_encode($data);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    sendJson(['success' => false, 'message' => 'Datos inválidos']);
}
$action = $input['action'] ?? '';

try {
    if (!isAuthenticated()) {
        sendJson(['success' => false, 'message' => 'Authentication required']);
    }
    $authUser = getAuthenticatedUser();
    $manualSensorId = isAdminUser($authUser) ? 1 : 2;
    $dataSource = $input['data_source'] ?? 'donbosco';
    switch ($action) {
        case 'save_marks':
            $userId = $input['user_id'] ?? null;
            $date = $input['date'] ?? null;
            $entries = $input['entries'] ?? [];
            $exits = $input['exits'] ?? [];
            if (!$userId || !$date) throw new Exception('Parámetros faltantes');
            saveUserDayMarks($userId, $date, $entries, $exits, $manualSensorId, $dataSource);
            sendJson(['success' => true]);
            break;
        case 'add_mark':
            $userId = $input['user_id'] ?? null;
            $date = $input['date'] ?? null;
            $time = $input['time'] ?? null;
            $type = $input['type'] ?? null; // 'entry' o 'exit'
            if (!$userId || !$date || !$time || $type === null) throw new Exception('Parámetros faltantes');
            $pdo = getConnectionBySource($dataSource);
            $stmt = $pdo->prepare('INSERT INTO Checkinout (userid, CheckTime, CheckType, Sensorid) VALUES (?, ?, ?, ?)');
            $stmt->execute([$userId, "$date $time", $type === 'entry' ? 0 : 1, $manualSensorId]);
            sendJson(['success' => true]);
            break;
        case 'delete_mark':
            $userId = $input['user_id'] ?? null;
            $date = $input['date'] ?? null;
            $time = $input['time'] ?? null;
            $type = $input['type'] ?? null;
            if (!$userId || !$date || !$time || $type === null) throw new Exception('Parámetros faltantes');
            $pdo = getConnectionBySource($dataSource);
            $stmt = $pdo->prepare('DELETE FROM Checkinout WHERE userid = ? AND CheckTime = ? AND CheckType = ?');
            $stmt->execute([$userId, "$date $time", $type === 'entry' ? 0 : 1]);
            if ($stmt->rowCount() > 0) {
                sendJson(['success' => true]);
            } else {
                sendJson(['success' => false, 'message' => 'Marca no encontrada']);
            }
            break;
        case 'reassign_mark':
            $userId = $input['user_id'] ?? null;
            $date = $input['date'] ?? null;
            $time = $input['time'] ?? null;
            $new = $input['new_type'] ?? null; // 'entry' o 'exit'
            if (!$userId || !$date || !$time || $new === null) throw new Exception('Parámetros faltantes');
            $pdo = getConnectionBySource($dataSource);
            $stmt = $pdo->prepare('UPDATE Checkinout SET CheckType = ? WHERE userid = ? AND CheckTime = ?');
            $stmt->execute([$new === 'entry' ? 0 : 1, $userId, "$date $time"]);
            sendJson(['success' => true]);
            break;
        default:
            sendJson(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    sendJson(['success' => false, 'message' => $e->getMessage()]);
}
