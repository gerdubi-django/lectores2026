<?php
require_once '../functions/view_control_functions.php';
require_once '../functions/export_excel.php';

// ==============================
// MANEJO DE ACCIONES AJAX
// ==============================

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {
        case 'get_attendance_data':
            handleGetAttendanceData();
            break;
        case 'get_user_details':
            handleGetUserDetails();
            break;
        case 'export_excel':
            handleExportExcel();
            break;
        case 'save_marks':
            handleSaveMarks();
            break;
        case 'get_db_config':
            handleGetDbConfig();
            break;
        case 'save_db_config':
            handleSaveDbConfig();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
    exit;
}

function handleGetAttendanceData() {
    try {
        $deptId = isset($_GET['dept_id']) ? intval($_GET['dept_id']) : 4;
        $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
        $startDate = isset($_GET['start_date']) && $_GET['start_date'] !== '' ? $_GET['start_date'] : null;
        $endDate = isset($_GET['end_date']) && $_GET['end_date'] !== '' ? $_GET['end_date'] : null;

        $result = getAttendanceControlData($deptId, $days, $startDate, $endDate);

        $json = json_encode($result);
        if ($json === false) {
            error_log('json_encode error: ' . json_last_error_msg());
            $json = json_encode(['success' => false, 'message' => 'Error de codificación de datos']);
        }
        echo $json;
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'data' => [],
            'summary' => [],
            'message' => 'Error al obtener datos de asistencia: ' . $e->getMessage()
        ]);
    }

}

function handleGetUserDetails() {
    try {
        $userId = $_GET['user_id'] ?? '';
        $date = $_GET['date'] ?? '';

        if (!$userId || !$date) throw new Exception('Parámetros faltantes');

        $pdo = getConnection();
        $stmt = $pdo->prepare("SELECT u.*, d.DeptName FROM Userinfo u LEFT JOIN Dept d ON u.Deptid = d.Deptid WHERE u.userid = :userId");
        $stmt->bindParam(':userId', $userId);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('Usuario no encontrado');

        $shifts = getUserShifts($userId, $date, $date);
        $attendance = getUserAttendance($userId, $date, $date);
        $grouped = groupAttendanceByDate($attendance);
        $dayAttendance = $grouped[$date] ?? ['entrada' => [], 'salida' => []];

        $status = 'normal';
        if (empty($dayAttendance['entrada']) && empty($dayAttendance['salida']) && !empty($shifts)) {
            $status = 'absent';
        } else {
            foreach ($shifts as $shift) {
                if (!empty($dayAttendance['entrada'])) {
                    $firstEntry = min(array_column($dayAttendance['entrada'], 'full_time'));
                    if (isLate($firstEntry, $shift['Intime'])) {
                        $status = 'warning';
                        break;
                    }
                }
                if (!empty($dayAttendance['salida'])) {
                    $lastExit = max(array_column($dayAttendance['salida'], 'full_time'));
                    if (isEarlyLeave($lastExit, $shift['Outtime'])) {
                        $status = 'warning';
                        break;
                    }
                }
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'name' => $user['Name'],
                'usercode' => $user['UserCode'],
                'department' => $user['DeptName'],
                'date' => $date,
                'day_name' => getDayName($date),
                'shifts' => array_map(fn($s) => [
                    'name' => $s['Timename'],
                    'intime' => $s['Intime'],
                    'outtime' => $s['Outtime']
                ], $shifts),
                'entries' => $dayAttendance['entrada'],
                'exits' => $dayAttendance['salida'],
                'status' => $status,
                'observations' => ''
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleExportExcel() {
    try {
        $deptId = isset($_GET['dept_id']) ? intval($_GET['dept_id']) : 4;
        $days = isset($_GET['days']) ? intval($_GET['days']) : 7;
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        $result = getAttendanceControlData($deptId, $days, $startDate, $endDate);
        exportarAsistenciaExcel($result['data']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleSaveMarks() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) throw new Exception('Datos inválidos');
        $userId = $input['user_id'] ?? null;
        $date = $input['date'] ?? null;
        $entries = $input['entries'] ?? [];
        $exits = $input['exits'] ?? [];
        if (!$userId || !$date) throw new Exception('Parámetros faltantes');

        saveUserDayMarks($userId, $date, $entries, $exits);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleGetDbConfig() {
    try {
        $configPath = __DIR__ . '/../functions/connect.php';
        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new Exception('No se pudo leer el archivo de conexión');
        }

        $server = extractConfigValue($content, 'server');
        $database = extractConfigValue($content, 'database');
        $username = extractConfigValue($content, 'username');
        $password = extractConfigValue($content, 'password');

        $parts = array_map('trim', explode(',', $server));
        $ip = $parts[0] ?? '';
        $port = $parts[1] ?? '';

        echo json_encode([
            'success' => true,
            'data' => [
                'ip' => $ip,
                'port' => $port,
                'database' => $database,
                'username' => $username,
                'password' => $password
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleSaveDbConfig() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) throw new Exception('Datos inválidos');

        $ip = trim($input['ip'] ?? '');
        $port = trim($input['port'] ?? '');
        $database = trim($input['database'] ?? '');
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');

        if ($ip === '' || $database === '' || $username === '') {
            throw new Exception('Completa los campos obligatorios');
        }

        $server = $ip;
        if ($port !== '') {
            $server .= ',' . $port;
        }

        $configPath = __DIR__ . '/../functions/connect.php';
        $content = file_get_contents($configPath);
        if ($content === false) {
            throw new Exception('No se pudo leer el archivo de conexión');
        }

        $content = replaceConfigValue($content, 'server', $server);
        $content = replaceConfigValue($content, 'database', $database);
        $content = replaceConfigValue($content, 'username', $username);
        $content = replaceConfigValue($content, 'password', $password);

        if (file_put_contents($configPath, $content) === false) {
            throw new Exception('No se pudo guardar el archivo de conexión');
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function extractConfigValue($content, $key) {
    // Parse the configuration value from connect.php.
    if (preg_match('/\\$' . preg_quote($key, '/') . '\\s*=\\s*"([^"]*)"/', $content, $matches)) {
        return $matches[1];
    }
    return '';
}

function replaceConfigValue($content, $key, $value) {
    // Update the configuration value in connect.php.
    $escaped = addslashes($value);
    $pattern = '/\\$' . preg_quote($key, '/') . '\\s*=\\s*".*?";/';
    $replacement = '$' . $key . ' = "' . $escaped . '";';
    $result = preg_replace($pattern, $replacement, $content);
    if ($result === null) {
        throw new Exception('Error al actualizar la configuración');
    }
    return $result;
}


$departments = getDepartments();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Asistencia</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../../css/views/view_control.css" rel="stylesheet">
</head>
<body class="attendance-page">
<div class="container-fluid attendance-shell">
    <div class="attendance-control">
    <div class="attendance-header panel-hero d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center header-brand">
            <img src="/donbosco/assets/img/logo.png" alt="Logo Don Bosco"
                 style="height: 50px; margin-right: 12px;">
            <div>
                <h2 class="mb-1">Control de Asistencia</h2>
                <p class="mb-0">Monitoreo de entrada y salida de personal</p>
            </div>
        </div>
        <div class="d-flex gap-2 header-actions">
            <button id="refresh-btn" class="btn btn-primary btn-sm top-action-btn">
                <i class="fas fa-sync-alt"></i> Actualizar
            </button>
            <button id="export-btn" class="btn btn-primary btn-sm top-action-btn">
                <i class="fas fa-download"></i> Exportar
            </button>
            <button id="incomplete-btn" class="btn btn-primary btn-sm top-action-btn">
                <i class="fas fa-exclamation-circle"></i> Ver días incompletos
            </button>
            <button id="clean-btn" class="btn btn-primary btn-sm top-action-btn">
                <i class="fas fa-broom"></i> Corregir duplicados
            </button>
            <button id="config-btn" class="btn btn-primary btn-sm top-action-btn">
                <i class="fas fa-gear"></i> Configuración
            </button>
        </div>
    </div>

        <!-- Filters -->
        <div class="filters-section panel-surface mt-3 row g-3 align-items-end">
            <div class="col-md-2">
                <label for="dept-filter" class="form-label">Departamento</label>
                <select id="dept-filter" class="form-select form-select-sm">
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['Deptid'] ?>" <?= $dept['Deptid'] == 4 ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['DeptName']) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="0">Todos</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="days-filter" class="form-label">Período</label>
                <select id="days-filter" class="form-select form-select-sm">
                    <option value="1">Hoy</option>
                    <option value="3">Últimos 3 días</option>
                    <option value="7" selected>Últimos 7 días</option>
                    <option value="15">Últimos 15 días</option>
                    <option value="30">Último mes</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="date-from" class="form-label">Desde</label>
                <input type="date" id="date-from" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label for="date-to" class="form-label">Hasta</label>
                <input type="date" id="date-to" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label for="search-input" class="form-label">Buscar</label>
                <input type="text" id="search-input" class="form-control form-control-sm" placeholder="Buscar por nombre o código...">
            </div>
            <div class="col-md-1">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="auto-refresh">
                    <label class="form-check-label small" for="auto-refresh">
                        Auto-actualizar
                    </label>
                </div>
            </div>
        </div>

        <div id="alert-container"></div>
        <div id="summary-container" class="summary-container panel-surface mt-3"></div>

        <div class="legend panel-surface mt-3">
            <div class="legend-item"><span class="status-indicator status-normal"></span> Normal</div>
            <div class="legend-item" id="legend-warning"><span class="status-indicator status-warning"></span> Tardanza/Salida temprana (+15 min)</div>
            <div class="legend-item" id="legend-absent"><span class="status-indicator status-absent"></span> Ausente</div>
        </div>

        <div class="attendance-table-container panel-surface mt-3">
            <div class="position-relative">
                <div id="loading-overlay" class="d-none position-absolute w-100 h-100 d-flex align-items-center justify-content-center bg-white bg-opacity-75" style="z-index: 1000;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                </div>
                <table class="table table-striped table-hover table-bordered attendance-table">
                    <thead>
                        <tr>
                            <th class="col-date text-center text-nowrap align-middle">Fecha</th>
                            <th class="col-shifts text-center text-nowrap align-middle w-auto">Turno</th>
                            <th class="col-times text-center text-nowrap align-middle">Entradas</th>
                            <th class="col-times text-center text-nowrap align-middle">Salidas</th>
                            <th class="col-status text-center text-nowrap align-middle">Estado</th>
                            <th class="col-actions text-center text-nowrap align-middle">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="attendance-body">
                        <tr>
                            <td colspan="6" class="text-center">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                Cargando datos...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

    </div>
</div>

<!-- Details modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detalles de Asistencia</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><!-- contenido dinámico --></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Incomplete days modal -->
<div class="modal fade" id="incompleteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Días con una sola marca</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="incomplete-container">
                <!-- contenido dinámico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Time picker modal -->
<div class="modal fade" id="timePickerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Seleccionar hora</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="time" id="time-picker-input" class="form-control">
            </div>
            <div class="modal-footer">
                <button type="button" id="time-picker-save" class="btn btn-primary">Guardar</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- Config modal -->
<div class="modal fade" id="configModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configuración</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#appearance-tab" type="button" role="tab">
                            Apariencia
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#database-tab" type="button" role="tab">
                            Base de datos
                        </button>
                    </li>
                </ul>
                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="appearance-tab" role="tabpanel">
                        <div class="config-card">
                            <h6 class="mb-3">Tema</h6>
                            <div class="d-flex gap-3 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="theme-option" id="theme-light" value="light">
                                    <label class="form-check-label" for="theme-light">Claro</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="theme-option" id="theme-dark" value="dark">
                                    <label class="form-check-label" for="theme-dark">Oscuro</label>
                                </div>
                            </div>
                            <p class="text-muted small mt-2">El tema se guarda en la sesión de este equipo.</p>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="database-tab" role="tabpanel">
                        <div class="config-card">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="db-ip" class="form-label">IP</label>
                                    <input type="text" class="form-control form-control-sm" id="db-ip" placeholder="190.7.11.199">
                                </div>
                                <div class="col-md-6">
                                    <label for="db-port" class="form-label">Puerto</label>
                                    <input type="text" class="form-control form-control-sm" id="db-port" placeholder="1433">
                                </div>
                                <div class="col-md-6">
                                    <label for="db-name" class="form-label">Base de datos</label>
                                    <input type="text" class="form-control form-control-sm" id="db-name">
                                </div>
                                <div class="col-md-6">
                                    <label for="db-user" class="form-label">Usuario</label>
                                    <input type="text" class="form-control form-control-sm" id="db-user">
                                </div>
                                <div class="col-md-6">
                                    <label for="db-password" class="form-label">Contraseña</label>
                                    <input type="password" class="form-control form-control-sm" id="db-password">
                                </div>
                            </div>
                            <div class="d-flex justify-content-end mt-3">
                                <button type="button" class="btn btn-primary" id="db-save-btn">
                                    <i class="fas fa-save me-1"></i> Guardar
                                </button>
                            </div>
                            <div class="text-muted small mt-2">Se actualiza el archivo connect.php al guardar.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div id="toast-container" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1100"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="../../js/asistencia_ajax.js"></script>
<script src="../../js/views/view_control.js"></script>
</body>
</html>
