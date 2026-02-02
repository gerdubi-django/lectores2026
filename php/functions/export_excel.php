<?php
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

function exportarAsistenciaExcel($data) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Asistencia');
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

    $headers = ['FECHA', 'TURNO', 'ENTRADA 1', 'SALIDA 1', 'ENTRADA 2', 'SALIDA 2', 'TARDANZAS', 'RETIROS', 'EXTRA'];

    $headerStyle = [
        'font' => ['bold' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'D9D9D9']
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ];

    $rowStyle = [
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ];

    // Agrupar por usuario
    $grouped = [];
    foreach ($data as $row) {
        $grouped[$row['userid']]['name'] = $row['name'];
        $grouped[$row['userid']]['records'][] = $row;
    }

    $currentRow = 1;

    foreach ($grouped as $user) {
        // Nombre del usuario
        $sheet->mergeCells("A{$currentRow}:I{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", $user['name']);
        $sheet->getStyle("A{$currentRow}:I{$currentRow}")->applyFromArray($headerStyle);
        $currentRow++;

        // Encabezado de la tabla
        $sheet->fromArray($headers, null, "A{$currentRow}");
        $sheet->getStyle("A{$currentRow}:I{$currentRow}")->applyFromArray($headerStyle);
        $currentRow++;

        $totalTardanza = $totalRetiros = $totalExtras = 0;
        $workedSeconds = 0;

        foreach ($user['records'] as $rec) {
            $shiftText = 'Sin turno asignado';
            if (!empty($rec['shifts'])) {
                $parts = [];
                foreach ($rec['shifts'] as $s) {
                    $in = substr($s['intime'], 0, 5);
                    $out = substr($s['outtime'], 0, 5);
                    $parts[] = "$in - $out";
                }
                $shiftText = implode(' / ', $parts);
            }

            $e1 = $rec['entries'][0]['time'] ?? '';
            $s1 = $rec['exits'][0]['time'] ?? '';
            $e2 = $rec['entries'][1]['time'] ?? '';
            $s2 = $rec['exits'][1]['time'] ?? '';

            $sheet->fromArray([
                $rec['date'],
                $shiftText,
                $e1,
                $s1,
                $e2,
                $s2,
                $rec['tardanza_minutos'],
                $rec['salida_temprano_minutos'],
                $rec['extra_minutos']
            ], null, "A{$currentRow}");
            $sheet->getStyle("A{$currentRow}:I{$currentRow}")->applyFromArray($rowStyle);

            $totalTardanza += $rec['tardanza_minutos'];
            $totalRetiros += $rec['salida_temprano_minutos'];
            $totalExtras += $rec['extra_minutos'];

            $entriesTimes = array_column($rec['entries'], 'time');
            $exitsTimes = array_column($rec['exits'], 'time');
            $pairs = min(count($entriesTimes), count($exitsTimes));
            for ($i = 0; $i < $pairs; $i++) {
                $inTime = strtotime($rec['date'] . ' ' . $entriesTimes[$i]);
                $outTime = strtotime($rec['date'] . ' ' . $exitsTimes[$i]);
                if ($outTime > $inTime) {
                    $workedSeconds += ($outTime - $inTime);
                }
            }

            $currentRow++;
        }

        // Fila de totales
        $sheet->setCellValue("A{$currentRow}", 'TOTAL');
        $sheet->setCellValue("G{$currentRow}", $totalTardanza);
        $sheet->setCellValue("H{$currentRow}", $totalRetiros);
        $sheet->setCellValue("I{$currentRow}", $totalExtras);
        $sheet->getStyle("A{$currentRow}:I{$currentRow}")->applyFromArray($rowStyle);
        $currentRow++;

        // Fila de horas trabajadas
        $hours = floor($workedSeconds / 3600);
        $minutes = floor(($workedSeconds % 3600) / 60);
        $sheet->mergeCells("A{$currentRow}:I{$currentRow}");
        $sheet->setCellValue("A{$currentRow}", 'TRABAJADO: ' . sprintf('%02d:%02d', $hours, $minutes));
        $sheet->getStyle("A{$currentRow}:I{$currentRow}")->applyFromArray($rowStyle);

        $currentRow += 3; // dos filas en blanco entre tablas
    }

    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="asistencia.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}
