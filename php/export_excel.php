<?php
require_once __DIR__ . '/functions/view_control_functions.php';
require_once __DIR__ . '/functions/auth.php';

startAuthSession();

if (!isAuthenticated()) {
    header('Location: views/view_control.php');
    exit;
}

// Include PhpSpreadsheet if available
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}


use PhpOffice\PhpSpreadsheet\Spreadsheet;

if (class_exists(Spreadsheet::class)) {
    require_once __DIR__ . '/functions/export_excel.php';
}

function exportarAsistenciaCSV(array $data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="asistencia.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Usuario', 'Código', 'Fecha', 'Día', 'Turnos', 'Entradas', 'Salidas', 'Estado']);
    foreach ($data as $r) {
        $shifts = strip_tags(formatShifts($r['shifts']));
        $entries = implode(', ', array_column($r['entries'], 'time')) ?: '-';
        $exits = implode(', ', array_column($r['exits'], 'time')) ?: '-';
        $estado = $r['status'] === 'warning' ? 'Tardanza/Salida temprana' : ($r['status'] === 'absent' ? 'Ausente' : 'Normal');
        fputcsv($out, [$r['name'], $r['usercode'], $r['date'], $r['day_name'], $shifts, $entries, $exits, $estado]);
    }
    fclose($out);
    exit;
}

$deptId = isset($_GET['dept_id']) ? intval($_GET['dept_id']) : 4;
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

$result = getAttendanceControlData($deptId, $days, $startDate, $endDate);
$data = $result['data'];
if (class_exists(Spreadsheet::class)) {
    exportarAsistenciaExcel($data);
} else {
    exportarAsistenciaCSV($data);
}
