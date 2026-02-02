<?php
require_once '../functions/view_control_functions.php';
require_once '../functions/export_excel.php';
require_once '../functions/auth.php';

startAuthSession();

$authUser = getAuthenticatedUser();

// ==============================
// MANEJO DE ACCIONES AJAX
// ==============================

if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($_GET['action']) {
        case 'login':
            handleLogin();
            break;
        case 'logout':
            handleLogout();
            break;
        default:
            if (!isAuthenticated()) {
                echo json_encode(['success' => false, 'message' => 'Authentication required']);
                break;
            }
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
        case 'get_department_users':
            handleGetDepartmentUsers();
            break;
        case 'create_auth_user':
            handleCreateAuthUser();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
            }
    }
    exit;
}

if (!isAuthenticated()) {
    $loginError = isset($_GET['login_error']) ? 'Usuario o contraseña inválidos.' : '';
    $logoutNotice = isset($_GET['logout']) ? 'Sesión cerrada correctamente.' : '';
    renderLoginView($loginError, $logoutNotice);
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

        $authUser = getAuthenticatedUser();
        $manualSensorId = isAdminUser($authUser) ? 1 : 2;
        saveUserDayMarks($userId, $date, $entries, $exits, $manualSensorId);
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

function handleGetDepartmentUsers() {
    try {
        $deptId = isset($_GET['dept_id']) ? intval($_GET['dept_id']) : 0;
        $users = getUsersByDepartment($deptId);
        echo json_encode(['success' => true, 'data' => $users]);
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

function handleLogin() {
    // Handle login via POST and set the session.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        return;
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Credenciales incompletas']);
        return;
    }
    $user = authenticateUser($username, $password);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Credenciales inválidas']);
        return;
    }
    setAuthenticatedUser($user);
    echo json_encode(['success' => true]);
}

function handleLogout() {
    // Clear the authentication session.
    clearAuthenticatedUser();
    echo json_encode(['success' => true, 'redirect' => 'view_control.php?logout=1']);
}

function handleCreateAuthUser() {
    // Create a new authentication user when the requester is admin.
    $authUser = getAuthenticatedUser();
    if (!$authUser || !isAdminUser($authUser)) {
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        return;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role = trim($input['role'] ?? 'user');
    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Completa todos los campos']);
        return;
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        echo json_encode(['success' => false, 'message' => 'Rol inválido']);
        return;
    }
    try {
        $created = createAuthUser($username, $password, $role);
        if (!$created) {
            echo json_encode(['success' => false, 'message' => 'El usuario ya existe']);
            return;
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function renderLoginView($errorMessage, $logoutNotice) {
    // Render the login view when no session is active.
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Ingreso - Control de Asistencia</title>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <link href="../../css/views/view_control.css" rel="stylesheet">
    </head>
    <body class="attendance-page login-page">
    <div class="login-shell">
        <div class="login-card panel-surface">
            <div class="login-header text-center mb-4">
                <img src="/donbosco/assets/img/logo.png" alt="Logo Don Bosco" class="login-logo">
                <h2 class="mt-3">Control de Asistencia</h2>
                <p class="text-muted mb-0">Ingresa con tu usuario para continuar.</p>
            </div>
            <?php if ($logoutNotice): ?>
                <div class="alert alert-success"><?= htmlspecialchars($logoutNotice) ?></div>
            <?php endif; ?>
            <?php if ($errorMessage): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
            <?php endif; ?>
            <form id="login-form" class="login-form">
                <div class="mb-3">
                    <label for="login-username" class="form-label">Usuario</label>
                    <input type="text" class="form-control" id="login-username" name="username" required autocomplete="username">
                </div>
                <div class="mb-4">
                    <label for="login-password" class="form-label">Contraseña</label>
                    <input type="password" class="form-control" id="login-password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-right-to-bracket me-1"></i> Ingresar
                </button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(loginForm);
                const response = await fetch('view_control.php?action=login', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    window.location.href = 'view_control.php';
                } else {
                    window.location.href = 'view_control.php?login_error=1';
                }
            });
        }
    </script>
    </body>
    </html>
    <?php
}


$departments = getDepartments();
$authUser = getAuthenticatedUser();
$isAdmin = isAdminUser($authUser);
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
            <button id="logout-btn" class="btn btn-outline-light btn-sm top-action-btn">
                <i class="fas fa-right-from-bracket"></i> Cerrar sesión
            </button>
            <div class="d-flex flex-column gap-2 w-100 manual-mark-actions d-md-none">
                <button id="manual-entry-btn" class="btn btn-outline-success btn-sm top-action-btn">
                    <i class="fas fa-door-open"></i> Entrada manual
                </button>
                <button id="manual-exit-btn" class="btn btn-outline-danger btn-sm top-action-btn">
                    <i class="fas fa-door-closed"></i> Salida manual
                </button>
            </div>
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
            <div class="legend-item"><span class="status-indicator status-manual-non-admin"></span> Marca manual (no admin)</div>
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

<!-- Manual mark modal -->
<div class="modal fade" id="manualMarkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="manual-mark-title">Entrada manual</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="manual-mark-form">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="manual-dept" class="form-label">Departamento</label>
                        <select id="manual-dept" class="form-select form-select-sm" required>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['Deptid'] ?>">
                                    <?= htmlspecialchars($dept['DeptName']) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="0">Todos</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="manual-user" class="form-label">Persona</label>
                        <select id="manual-user" class="form-select form-select-sm" required>
                            <option value="">Seleccione una persona</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="manual-date" class="form-label">Fecha</label>
                        <input type="date" id="manual-date" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-3">
                        <label for="manual-time" class="form-label" id="manual-time-label">Hora de entrada</label>
                        <input type="time" id="manual-time" class="form-control form-control-sm" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="manual-mark-submit">Guardar entrada</button>
                </div>
            </form>
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
                    <?php if ($isAdmin): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#users-tab" type="button" role="tab">
                                Usuarios
                            </button>
                        </li>
                    <?php endif; ?>
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
                    <?php if ($isAdmin): ?>
                        <div class="tab-pane fade" id="users-tab" role="tabpanel">
                            <div class="config-card">
                                <h6 class="mb-3">Registrar nuevo usuario</h6>
                                <form id="register-form">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="register-username" class="form-label">Usuario</label>
                                            <input type="text" class="form-control form-control-sm" id="register-username" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="register-role" class="form-label">Rol</label>
                                            <select class="form-select form-select-sm" id="register-role">
                                                <option value="user">Usuario</option>
                                                <option value="admin">Administrador</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="register-password" class="form-label">Contraseña</label>
                                            <input type="password" class="form-control form-control-sm" id="register-password" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="register-confirm" class="form-label">Confirmar contraseña</label>
                                            <input type="password" class="form-control form-control-sm" id="register-confirm" required>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-end mt-3">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-user-plus me-1"></i> Registrar
                                        </button>
                                    </div>
                                </form>
                                <div class="text-muted small mt-2">Solo los administradores pueden crear usuarios.</div>
                            </div>
                        </div>
                    <?php endif; ?>
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
