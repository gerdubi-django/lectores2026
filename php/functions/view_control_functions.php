<?php
/**
 * Funciones para el control de asistencia
 */
require_once 'connect.php';
require_once __DIR__ . '/auth.php';

// ==== Utilidades comunes ====

if (!defined('NUPORA_DEPT_ID')) {
    define('NUPORA_DEPT_ID', -10);
}

function isNuporaDepartment($deptId) {
    return (int) $deptId === NUPORA_DEPT_ID;
}

function getDataSourceByDeptId($deptId) {
    return isNuporaDepartment($deptId) ? 'nupora' : 'donbosco';
}

function getConnectionBySource($dataSource) {
    return $dataSource === 'nupora' ? getNuporaConnection() : getConnection();
}

function logDbError($context, $e) {
    error_log("⚠️ [$context] " . $e->getMessage());
    return [];
}

function toUtf8($value) {
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = toUtf8($v);
        }
        return $value;
    }
    if (is_string($value)) {
        $enc = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
        if ($enc && $enc !== 'UTF-8') {
            return mb_convert_encoding($value, 'UTF-8', $enc);
        }
        return $value;
    }
    return $value;
}

function convertRecordSetUtf8($rows) {
    return array_map('toUtf8', $rows);
}

// ==== Consultas de base de datos ====

function getUsersByDepartment($deptId) {
    if (isNuporaDepartment($deptId)) return getNuporaUsers();
    if ($deptId === 0) return getAllUsers();
    if (!$deptId) return [];
    try {
        $pdo = getConnection();
        $sql = "SELECT u.userid, u.Name, u.UserCode, u.Deptid, d.DeptName 
                FROM Userinfo u 
                LEFT JOIN Dept d ON u.Deptid = d.Deptid 
                WHERE u.Deptid = :deptId 
                ORDER BY u.Name";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':deptId', $deptId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getUsersByDepartment', $e);
    }
}

function getNuporaUsers() {
    try {
        $pdo = getNuporaConnection();
        $sql = "SELECT u.userid, u.Name, u.UserCode, u.Deptid, d.DeptName
                FROM Userinfo u
                LEFT JOIN Dept d ON u.Deptid = d.Deptid
                ORDER BY u.Name";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getNuporaUsers', $e);
    }
}

function getAllUsers() {
    try {
        $pdo = getConnection();
        $sql = "SELECT u.userid, u.Name, u.UserCode, u.Deptid, d.DeptName
                FROM Userinfo u
                LEFT JOIN Dept d ON u.Deptid = d.Deptid
                ORDER BY u.Name";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getAllUsers', $e);
    }
}

function getUserShifts($userId, $startDate, $endDate, $dataSource = 'donbosco') {
    if (!$userId || !$startDate || !$endDate) return [];
    try {
        $pdo = getConnectionBySource($dataSource);
        $sql = "SELECT us.userid, us.Schid, us.BeginDate, us.EndDate,
                       s.Schname, st.BeginDay, st.Timeid,
                       tt.Timename, tt.Intime, tt.Outtime
                FROM ((UserShift us
                LEFT JOIN Schedule s ON us.Schid = s.Schid)
                LEFT JOIN SchTime st ON st.Schid = s.Schid)
                LEFT JOIN TimeTable tt ON st.Timeid = tt.Timeid
                WHERE us.userid = :userId
                AND us.BeginDate <= :endDate
                AND us.EndDate >= :startDate
                ORDER BY st.BeginDay, tt.Intime";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':userId', $userId);
        $stmt->bindValue(':startDate', $startDate);
        $stmt->bindValue(':endDate', $endDate);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getUserShifts', $e);
    }
}

function getDepartmentShifts($deptId, $startDate, $endDate) {
    if (!$deptId || !$startDate || !$endDate) return [];
    if (isNuporaDepartment($deptId)) return getNuporaShifts($startDate, $endDate);
    try {
        $pdo = getConnection();
        $sql = "SELECT us.userid, us.Schid, us.BeginDate, us.EndDate,
                       s.Schname, st.BeginDay, st.Timeid,
                       tt.Timename, tt.Intime, tt.Outtime
                FROM (((UserShift us
                LEFT JOIN Schedule s ON us.Schid = s.Schid)
                LEFT JOIN SchTime st ON st.Schid = s.Schid)
                LEFT JOIN TimeTable tt ON st.Timeid = tt.Timeid)
                INNER JOIN Userinfo u ON us.userid = u.userid
                WHERE u.Deptid = :deptId
                AND us.BeginDate <= :endDate
                AND us.EndDate >= :startDate
                ORDER BY us.userid, st.BeginDay, tt.Intime";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':deptId', $deptId, PDO::PARAM_INT);
        $stmt->bindValue(':startDate', $startDate);
        $stmt->bindValue(':endDate', $endDate);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getDepartmentShifts', $e);
    }
}

function getNuporaShifts($startDate, $endDate) {
    if (!$startDate || !$endDate) return [];
    try {
        $pdo = getNuporaConnection();
        $sql = "SELECT us.userid, us.Schid, us.BeginDate, us.EndDate,
                       s.Schname, st.BeginDay, st.Timeid,
                       tt.Timename, tt.Intime, tt.Outtime
                FROM ((UserShift us
                LEFT JOIN Schedule s ON us.Schid = s.Schid)
                LEFT JOIN SchTime st ON st.Schid = s.Schid)
                LEFT JOIN TimeTable tt ON st.Timeid = tt.Timeid
                WHERE us.BeginDate <= :endDate
                AND us.EndDate >= :startDate
                ORDER BY us.userid, st.BeginDay, tt.Intime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':startDate', $startDate);
        $stmt->bindValue(':endDate', $endDate);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getNuporaShifts', $e);
    }
}

function getAllShifts($startDate, $endDate) {
    if (!$startDate || !$endDate) return [];
    try {
        $pdo = getConnection();
        $sql = "SELECT us.userid, us.Schid, us.BeginDate, us.EndDate,
                       s.Schname, st.BeginDay, st.Timeid,
                       tt.Timename, tt.Intime, tt.Outtime
                FROM ((UserShift us
                LEFT JOIN Schedule s ON us.Schid = s.Schid)
                LEFT JOIN SchTime st ON st.Schid = s.Schid)
                LEFT JOIN TimeTable tt ON st.Timeid = tt.Timeid
                WHERE us.BeginDate <= :endDate
                AND us.EndDate >= :startDate
                ORDER BY us.userid, st.BeginDay, tt.Intime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':startDate', $startDate);
        $stmt->bindValue(':endDate', $endDate);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getAllShifts', $e);
    }
}

function getUserAttendance($userId, $startDate, $endDate, $dataSource = 'donbosco') {
    if (!$userId || !$startDate || !$endDate) return [];
    try {
        $pdo = getConnectionBySource($dataSource);
        $sql = "SELECT userid, CheckTime, CheckType, Sensorid
                FROM Checkinout
                WHERE userid = :userId
                AND CheckTime >= :startDate
                AND CheckTime < :endDateNext
                ORDER BY CheckTime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':userId', $userId);
        $stmt->bindValue(':startDate', $startDate);
        $endDateNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $stmt->bindValue(':endDateNext', $endDateNext);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getUserAttendance', $e);
    }
}

function getDepartmentAttendance($deptId, $startDate, $endDate) {
    if (!$deptId || !$startDate || !$endDate) return [];
    if (isNuporaDepartment($deptId)) return getNuporaAttendance($startDate, $endDate);
    try {
        $pdo = getConnection();
        $sql = "SELECT c.userid, c.CheckTime, c.CheckType, c.Sensorid
                FROM (Checkinout c INNER JOIN Userinfo u ON c.userid = u.userid)
                WHERE u.Deptid = :deptId
                AND c.CheckTime >= :startDate
                AND c.CheckTime < :endDateNext
                ORDER BY c.userid, c.CheckTime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':deptId', $deptId, PDO::PARAM_INT);
        $stmt->bindValue(':startDate', $startDate);
        $endDateNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $stmt->bindValue(':endDateNext', $endDateNext);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getDepartmentAttendance', $e);
    }
}

function getNuporaAttendance($startDate, $endDate) {
    if (!$startDate || !$endDate) return [];
    try {
        $pdo = getNuporaConnection();
        $sql = "SELECT userid, CheckTime, CheckType, Sensorid
                FROM Checkinout
                WHERE CheckTime >= :startDate
                AND CheckTime < :endDateNext
                ORDER BY userid, CheckTime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':startDate', $startDate);
        $endDateNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $stmt->bindValue(':endDateNext', $endDateNext);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getNuporaAttendance', $e);
    }
}

function getAllAttendance($startDate, $endDate) {
    if (!$startDate || !$endDate) return [];
    try {
        $pdo = getConnection();
        $sql = "SELECT userid, CheckTime, CheckType, Sensorid
                FROM Checkinout
                WHERE CheckTime >= :startDate
                AND CheckTime < :endDateNext
                ORDER BY userid, CheckTime";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':startDate', $startDate);
        $endDateNext = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $stmt->bindValue(':endDateNext', $endDateNext);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return convertRecordSetUtf8($rows);
    } catch (Exception $e) {
        return logDbError('getAllAttendance', $e);
    }
}

function getDateRange($days = 7) {
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $dates = [];
    $current = strtotime($startDate);
    $end = strtotime($endDate);
    while ($current <= $end) {
        $dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }
    return $dates;
}

// ==== Cálculos de asistencia ====

function isLate($checkTime, $expectedTime) {
    if (!$checkTime || !$expectedTime) return false;
    $check = strtotime($checkTime);
    $expected = strtotime(date('Y-m-d', $check) . ' ' . $expectedTime);
    return (($check - $expected) / 60) > 15;
}

function isEarlyLeave($checkTime, $expectedTime) {
    if (!$checkTime || !$expectedTime) return false;
    $check = strtotime($checkTime);
    $expected = strtotime(date('Y-m-d', $check) . ' ' . $expectedTime);
    return (($expected - $check) / 60) > 15;
}

function groupAttendanceByDate($attendance) {
    $grouped = [];
    foreach ($attendance as $record) {
        $date = date('Y-m-d', strtotime($record['CheckTime']));
        $type = $record['CheckType'] == 0 ? 'entrada' : 'salida';
        if (!isset($grouped[$date])) {
            $grouped[$date] = ['entrada' => [], 'salida' => []];
        }
        $sensorId = isset($record['Sensorid']) ? (int) $record['Sensorid'] : null;
        $isManualNonAdmin = $sensorId === 2;
        $grouped[$date][$type][] = [
            'time' => date('H:i:s', strtotime($record['CheckTime'])),
            'full_time' => $record['CheckTime'],
            'sensor_id' => $sensorId,
            'manual_non_admin' => $isManualNonAdmin
        ];
    }
    return $grouped;
}

function isCalculatedShift($shift) {
    $in = substr($shift['intime'], 0, 5);
    $out = substr($shift['outtime'], 0, 5);
    return $in === '00:00' && $out === '23:59';
}

function getAttendanceControlData($deptId = 4, $days = 7, $startDate = null, $endDate = null) {
    try {
        $deptId = (int) $deptId;
        $dataSource = getDataSourceByDeptId($deptId);
        $startDate = $startDate ?: null;
        $endDate = $endDate ?: null;
        if ($startDate && !$endDate) $endDate = $startDate;
        if ($endDate && !$startDate) $startDate = $endDate;

        $users = getUsersByDepartment($deptId);

        if ($startDate && $endDate) {
            $dateRange = [];
            $current = strtotime($startDate);
            $end = strtotime($endDate);
            while ($current <= $end) {
                $dateRange[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }
        } else {
            $dateRange = getDateRange($days);
            if (!empty($dateRange)) {
                $startDate = min($dateRange);
                $endDate = max($dateRange);
            } else {
                $startDate = $endDate = date('Y-m-d');
            }
        }

        $deptShifts = $deptId === 0
            ? getAllShifts($startDate, $endDate)
            : getDepartmentShifts($deptId, $startDate, $endDate);
        $deptAttendance = $deptId === 0
            ? getAllAttendance($startDate, $endDate)
            : getDepartmentAttendance($deptId, $startDate, $endDate);

        $shiftsByUser = [];
        foreach ($deptShifts as $s) {
            $shiftsByUser[$s['userid']][] = $s;
        }

        $attendanceByUser = [];
        foreach ($deptAttendance as $a) {
            $attendanceByUser[$a['userid']][] = $a;
        }

        $data = [];

        foreach ($users as $user) {
            $userId = $user['userid'];
            $shifts = $shiftsByUser[$userId] ?? [];
            $attendance = $attendanceByUser[$userId] ?? [];
            $groupedAttendance = groupAttendanceByDate($attendance);

        $userShifts = [];
        foreach ($shifts as $shift) {
            $dayOfWeek = $shift['BeginDay'] ?? null;
            $begin = $shift['BeginDate'];
            $end = $shift['EndDate'];

            if (!$dayOfWeek || !$begin || !$end) continue;

            // Filtrar por rango real de asignación del turno
            foreach ($dateRange as $date) {
                if ($date >= $begin && $date <= $end && date('N', strtotime($date)) == $dayOfWeek) {
                    $userShifts[$date][] = [
                        'name' => $shift['Timename'],
                        'intime' => $shift['Intime'],
                        'outtime' => $shift['Outtime']
                    ];
                }
            }
        }


        foreach ($dateRange as $date) {
            $expectedShifts = $userShifts[$date] ?? [];
            $calcShifts = array_values(array_filter($expectedShifts, fn($s) => !isCalculatedShift($s)));
            $dayAttendance = $groupedAttendance[$date] ?? ['entrada' => [], 'salida' => []];

            $hasEntry = !empty($dayAttendance['entrada']);
            $hasExit = !empty($dayAttendance['salida']);

            $tardanzaMin = 0;
            $salidaTempranoMin = 0;
            $extraMin = 0;

            if ($hasEntry && !empty($calcShifts)) {
                $firstEntry = min(array_column($dayAttendance['entrada'], 'full_time'));
                $earliestIntime = min(array_column($calcShifts, 'intime'));
                $diff = (strtotime($firstEntry) - strtotime($date . ' ' . $earliestIntime)) / 60;
                if ($diff > 15) $tardanzaMin = (int) round($diff);
            }

            if ($hasExit && !empty($calcShifts)) {
                $lastExit = max(array_column($dayAttendance['salida'], 'full_time'));
                $latestOuttime = max(array_column($calcShifts, 'outtime'));
                $diffEarly = (strtotime($date . ' ' . $latestOuttime) - strtotime($lastExit)) / 60;
                if ($diffEarly > 15) $salidaTempranoMin = (int) round($diffEarly);
                $diffExtra = (strtotime($lastExit) - strtotime($date . ' ' . $latestOuttime)) / 60;
                if ($diffExtra > 15) $extraMin = (int) round($diffExtra);
            }

            $rowStatus = 'normal';
            if (!$hasEntry && !$hasExit && !empty($expectedShifts)) {
                $rowStatus = 'absent';
            } elseif ($tardanzaMin > 0 || $salidaTempranoMin > 0) {
                $rowStatus = 'warning';
            }

            $data[] = [
                'userid' => $userId,
                'name' => $user['Name'],
                'usercode' => $user['UserCode'],
                'date' => $date,
                'dept_id' => $deptId,
                'data_source' => $dataSource,
                'day_name' => getDayName($date),
                'shifts' => $expectedShifts,
                'entries' => $dayAttendance['entrada'],
                'exits' => $dayAttendance['salida'],
                'status' => $rowStatus,
                'tardanza_minutos' => $tardanzaMin,
                'salida_temprano_minutos' => $salidaTempranoMin,
                'extra_minutos' => $extraMin
            ];
        }
        }

        if (empty($data)) {
            error_log("⚠️ No se generaron datos de asistencia para deptId=$deptId entre $startDate y $endDate");
        }

        $summary = [
            'total' => count($data),
            'normal' => 0,
            'warnings' => 0,
            'absent' => 0
        ];

        foreach ($data as $record) {
            if ($record['status'] === 'warning') {
                $summary['warnings']++;
            } elseif ($record['status'] === 'absent') {
                $summary['absent']++;
            } else {
                $summary['normal']++;
            }
        }

        $data = convertRecordSetUtf8($data);
        return ['success' => true, 'data' => $data, 'summary' => $summary];
    } catch (Throwable $e) {
        error_log('⚠️ [getAttendanceControlData] ' . $e->getMessage());
        return ['success' => false, 'data' => [], 'summary' => []];
    }
}

// ==== Helpers de presentación ====

function getDayName($date) {
    $days = [
        'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
    ];
    $dayName = date('l', strtotime($date));
    return $days[$dayName] ?? $dayName;
}

function formatShifts($shifts) {
    if (empty($shifts)) return 'Sin turno asignado';
    return implode('<br>', array_map(function ($shift) {
        $in = substr($shift['intime'], 0, 5);
        $out = substr($shift['outtime'], 0, 5);
        return ($in === '00:00' && $out === '23:59') ? 'Turno Calculado' : $in . ' - ' . $out;
    }, $shifts));
}

function formatTimes($times) {
    if (empty($times)) return '<span class="text-muted">-</span>';
    return implode(' ', array_map(function ($t) {
        return '<span class="badge badge-info">' . $t['time'] . '</span>';
    }, $times));
}


function getDepartments() {
    try {
        $pdo = getConnection();
        $sql = "SELECT Deptid, DeptName FROM Dept WHERE Deptid <> 1 ORDER BY DeptName";

        $stmt = $pdo->query($sql);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $departments[] = ['Deptid' => NUPORA_DEPT_ID, 'DeptName' => 'Ñu Pora'];
        return $departments;
    } catch (Exception $e) {
        error_log("Error en getDepartments: " . $e->getMessage());
        return [];
    }
}

function getDepartmentsByIds($deptIds) {
    // Fetch department metadata for the provided ids only.
    $deptIds = array_values(array_unique(array_map('intval', $deptIds)));
    if (empty($deptIds)) return [];
    $hasNupora = in_array(NUPORA_DEPT_ID, $deptIds, true);
    $filteredIds = array_values(array_filter($deptIds, fn($id) => $id > 0 && $id !== NUPORA_DEPT_ID));
    $departments = [];
    try {
        if (!empty($filteredIds)) {
            $pdo = getConnection();
            $placeholders = implode(',', array_fill(0, count($filteredIds), '?'));
            $sql = "SELECT Deptid, DeptName FROM Dept WHERE Deptid <> 1 AND Deptid IN ($placeholders) ORDER BY DeptName";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($filteredIds);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($hasNupora) {
            $departments[] = ['Deptid' => NUPORA_DEPT_ID, 'DeptName' => 'Ñu Pora'];
        }
    } catch (Exception $e) {
        error_log("Error en getDepartmentsByIds: " . $e->getMessage());
        return [];
    }
    return $departments;
}

function getAuthUserDepartmentIds($authUserId) {
    // Return department ids assigned to an auth user.
    if (!$authUserId) return [];
    try {
        $pdo = getConnection();
        $stmt = $pdo->prepare('SELECT Deptid FROM AuthUserDepartments WHERE AuthUserId = :authUserId');
        $stmt->bindValue(':authUserId', $authUserId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return logDbError('getAuthUserDepartmentIds', $e);
    }
}

function getAuthorizedDepartments($authUser, $departments) {
    // Filter departments based on the authenticated user access list.
    $authUserId = $authUser['id'] ?? null;
    if (!$authUserId) return [];
    $allowedIds = getAuthorizedDepartmentIds($authUser);
    if (empty($allowedIds)) return [];
    return array_values(array_filter($departments, function ($dept) use ($allowedIds) {
        return in_array((int) $dept['Deptid'], $allowedIds, true);
    }));
}

function getAuthorizedDepartmentIds($authUser) {
    // Resolve authorized department ids from session or database.
    if (!$authUser) {
        return [];
    }
    $sessionIds = $authUser['dept_ids'] ?? [];
    $normalized = array_values(array_filter(array_map('intval', $sessionIds), fn($id) => $id !== 0));
    if (!empty($normalized)) {
        return $normalized;
    }
    $authUserId = $authUser['id'] ?? null;
    return getAuthUserDepartmentIds($authUserId);
}

function getUsersByDepartments($deptIds) {
    // Fetch users across multiple departments.
    if (empty($deptIds)) return [];
    $usersById = [];
    foreach ($deptIds as $deptId) {
        $deptUsers = getUsersByDepartment((int) $deptId);
        foreach ($deptUsers as $user) {
            $usersById[$user['userid']] = $user;
        }
    }
    $users = array_values($usersById);
    usort($users, fn($a, $b) => strcmp($a['Name'], $b['Name']));
    return $users;
}

function saveAuthUserDepartments($authUserId, $deptIds) {
    // Replace the department access list for an auth user.
    if (!$authUserId) return false;
    $pdo = getConnection();
    $deptIds = array_values(array_unique(array_map('intval', $deptIds)));
    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM AuthUserDepartments WHERE AuthUserId = :authUserId');
        $delete->bindValue(':authUserId', $authUserId, PDO::PARAM_INT);
        $delete->execute();
        if (!empty($deptIds)) {
            $insert = $pdo->prepare('INSERT INTO AuthUserDepartments (AuthUserId, Deptid) VALUES (:authUserId, :deptId)');
            foreach ($deptIds as $deptId) {
                $insert->bindValue(':authUserId', $authUserId, PDO::PARAM_INT);
                $insert->bindValue(':deptId', $deptId, PDO::PARAM_INT);
                $insert->execute();
            }
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        logDbError('saveAuthUserDepartments', $e);
        return false;
    }
}

function buildAttendanceSummary($data) {
    // Build summary counts from attendance records.
    $summary = ['total' => 0, 'normal' => 0, 'warnings' => 0, 'absent' => 0];
    foreach ($data as $record) {
        $summary['total']++;
        if ($record['status'] === 'warning') {
            $summary['warnings']++;
        } elseif ($record['status'] === 'absent') {
            $summary['absent']++;
        } else {
            $summary['normal']++;
        }
    }
    return $summary;
}

function getAttendanceControlDataForDepartments($deptIds, $days = 7, $startDate = null, $endDate = null) {
    // Aggregate attendance data across multiple departments.
    if (empty($deptIds)) {
        return ['success' => true, 'data' => [], 'summary' => buildAttendanceSummary([])];
    }
    $data = [];
    foreach ($deptIds as $deptId) {
        $result = getAttendanceControlData((int) $deptId, $days, $startDate, $endDate);
        if (!empty($result['data'])) {
            $data = array_merge($data, $result['data']);
        }
    }
    return ['success' => true, 'data' => $data, 'summary' => buildAttendanceSummary($data)];
}

function saveUserDayMarks($userId, $date, $entries, $exits, $sensorId = 1, $dataSource = 'donbosco') {
    if (!$userId || !$date) return false;
    $pdo = getConnectionBySource($dataSource);
    $start = $date . ' 00:00:00';
    $end = $date . ' 23:59:59';
    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM Checkinout WHERE userid = ? AND CheckTime BETWEEN ? AND ?");
        $del->execute([$userId, $start, $end]);
        $ins = $pdo->prepare("INSERT INTO Checkinout (userid, CheckTime, CheckType, Sensorid) VALUES (?, ?, ?, ?)");
        foreach ($entries as $t) {
            $ins->execute([$userId, "$date $t", 0, $sensorId]);
        }
        foreach ($exits as $t) {
            $ins->execute([$userId, "$date $t", 1, $sensorId]);
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
