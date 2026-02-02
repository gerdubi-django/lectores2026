<?php
require_once '../functions/view_control_functions.php';

$userId = '1'; // Cambiá esto por el userid que querés testear
$startDate = '2025-05-01';
$endDate = '2025-06-10';

$shifts = getUserShifts($userId, $startDate, $endDate);

echo "<h2>Turnos del usuario $userId desde $startDate hasta $endDate</h2>";

if (empty($shifts)) {
    echo "<p>No se encontraron turnos.</p>";
} else {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr>
            <th>Schid</th>
            <th>Schname</th>
            <th>BeginDay</th>
            <th>Timeid</th>
            <th>Timename</th>
            <th>Intime</th>
            <th>Outtime</th>
          </tr>";

    foreach ($shifts as $shift) {
        echo "<tr>
                <td>{$shift['Schid']}</td>
                <td>{$shift['Schname']}</td>
                <td>{$shift['BeginDay']}</td>
                <td>{$shift['Timeid']}</td>
                <td>{$shift['Timename']}</td>
                <td>{$shift['Intime']}</td>
                <td>{$shift['Outtime']}</td>
              </tr>";
    }

    echo "</table>";
}
?>
