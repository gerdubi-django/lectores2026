document.addEventListener('DOMContentLoaded', function () {
    initializeAttendanceControl();
});

let searchQuery = '';
let absentFilterActive = false;
let attendanceData = [];
let alertsVisible = false;
let incompleteList = [];
let currentRow = null;
let currentType = null;
let timeModal = null;
let timeInput = null;

function initializeAttendanceControl() {
    setupFilters();
    setupButtons();
    setupTooltips();
    timeModal = new bootstrap.Modal(document.getElementById('timePickerModal'));
    timeInput = document.getElementById('time-picker-input');
    document.getElementById('time-picker-save').addEventListener('click', () => {
        if (currentRow && timeInput.value) {
            agregarMarca(currentRow, currentType, timeInput.value);
            timeModal.hide();
        }
    });
    loadAttendanceData();
    setInterval(function () {
        const autoRefresh = document.getElementById('auto-refresh');
        if (autoRefresh && autoRefresh.checked) {
            refreshData();
        }
    }, 300000);
}

function setupFilters() {
    const deptSelect = document.getElementById('dept-filter');
    const daysSelect = document.getElementById('days-filter');
    const dateFromInput = document.getElementById('date-from');
    const dateToInput = document.getElementById('date-to');
    const searchInput = document.getElementById('search-input');

    if (deptSelect) deptSelect.addEventListener('change', loadAttendanceData);

    if (daysSelect) {
        daysSelect.addEventListener('change', function () {
            const days = parseInt(this.value);
            const endDate = new Date();
            const startDate = new Date();
            startDate.setDate(endDate.getDate() - (days - 1));
            if (dateFromInput) dateFromInput.value = formatDateForInput(startDate);
            if (dateToInput) dateToInput.value = formatDateForInput(endDate);
            loadAttendanceData();
        });
        // Establecer rango inicial usando el valor por defecto
        daysSelect.dispatchEvent(new Event('change'));
    }

    if (dateFromInput && dateToInput) {
        dateFromInput.addEventListener('change', () => {
            if (dateToInput.value) loadAttendanceData();
        });
        dateToInput.addEventListener('change', () => {
            if (dateFromInput.value) loadAttendanceData();
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => filterTable(searchInput.value));
    }
}

function setupButtons() {
    const refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn) refreshBtn.addEventListener('click', refreshData);
    const exportBtn = document.getElementById('export-btn');
    if (exportBtn) exportBtn.addEventListener('click', exportAttendance);
    const incBtn = document.getElementById('incomplete-btn');
    if (incBtn) incBtn.addEventListener('click', showIncompleteDays);
    const cleanBtn = document.getElementById('clean-btn');
    if (cleanBtn) cleanBtn.addEventListener('click', cleanAttendanceData);
}

function setupTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(el => new bootstrap.Tooltip(el));
}


function loadAttendanceData() {
    const deptId = document.getElementById('dept-filter').value;
    const days = document.getElementById('days-filter').value;
    const startDate = document.getElementById('date-from').value;
    const endDate = document.getElementById('date-to').value;
    const tableBody = document.getElementById('attendance-body');
    if (!tableBody) return;

    tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary me-2"></div> Cargando datos...</td></tr>';

    const params = new URLSearchParams({
        action: 'get_attendance_data',
        dept_id: deptId,
        days: days,
        start_date: startDate,
        end_date: endDate
    });

    fetch(`view_control.php?${params.toString()}`)
        .then(async response => {
            const text = await response.text();
            if (!response.ok) {
                try {
                    const data = JSON.parse(text);
                    throw new Error(data.message || `Error HTTP ${response.status}`);
                } catch {
                    throw new Error(`Error HTTP ${response.status}`);
                }
            }
            try {
                return JSON.parse(text);
            } catch {
                throw new Error(text || 'Respuesta no válida del servidor');
            }
        })
        .then(result => {
            if (!result.success) {
                throw new Error(result.message || "Error desconocido del servidor");
            }

            attendanceData = result.data;
            alertsVisible = false;
            const alertContainer = document.getElementById('alert-container');
            if (alertContainer) alertContainer.innerHTML = '';

            updateSummary(result.summary);
            renderAttendanceTable();
        })
        .catch(err => {
            showToast(err.message || 'Error cargando asistencia', 'danger');
            tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${err.message}</td></tr>`;
            showToast(err.message || 'Error', 'danger');
        });
}


function formatDateForInput(date) {
    return date.toISOString().split('T')[0];
}

function formatDateDisplay(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr + 'T00:00:00');
    let dayName = date.toLocaleDateString('es-ES', { weekday: 'long' });
    dayName = dayName.charAt(0).toUpperCase() + dayName.slice(1);
    const [year, month, day] = dateStr.split('-');
    return `${dayName} ${day}-${month}-${year}`;
}

function formatTime(timeStr) {
    return timeStr ? timeStr.slice(0, 5) : '';
}

function formatShifts(shifts) {
    if (!shifts.length) return 'Sin turno asignado';
    return shifts.map(s => {
        const inTime = s.intime.slice(0, 5);
        const outTime = s.outtime.slice(0, 5);
        return (inTime === '00:00' && outTime === '23:59')
            ? 'Turno Calculado'
            : `${inTime} - ${outTime}`;
    }).join('<br>');
}

function isCalculatedShiftJs(shift) {
    const inTime = shift.intime.slice(0, 5);
    const outTime = shift.outtime.slice(0, 5);
    return inTime === '00:00' && outTime === '23:59';
}

function updateShiftCell(cell, shifts) {
    if (!cell) return;

    // Eliminar duplicados antes de renderizar (por Intime y Outtime)
    const uniqueShifts = [];
    const seen = new Set();

    for (const shift of shifts || []) {
        const key = `${shift.intime}-${shift.outtime}`;
        if (!seen.has(key)) {
            seen.add(key);
            uniqueShifts.push(shift);
        }
    }

    const html = formatShifts(uniqueShifts);
    if (cell.dataset.lastRendered === html) return;

    cell.dataset.lastRendered = html;
    cell.innerHTML = html;
}


function formatTimes(times) {
    if (!times.length) return '<span class="text-muted">-</span>';
    return times
        .map(t => `<span class="badge bg-info">${formatTime(t.time)}</span>`)
        .join(' ');
}

function formatStatus(status) {
    switch (status) {
        case 'warning':
            return 'Tardanza/Salida temprana';
        case 'absent':
            return 'Ausente';
        default:
            return 'Normal';
    }
}

function filterTable(query) {
    searchQuery = query.toLowerCase();
    applyFilters();
}

function applyFilters() {
    const rows = document.querySelectorAll('#attendance-body tr[data-user]');
    rows.forEach(row => {
        const matchesSearch = row.dataset.user.toLowerCase().includes(searchQuery);
        const matchesAbsent = !absentFilterActive || row.dataset.status === 'absent' || row.dataset.header === '1';
        row.style.display = matchesSearch && matchesAbsent ? '' : 'none';
    });

    const headers = document.querySelectorAll('#attendance-body tr[data-header="1"]');
    headers.forEach(header => {
        let show = false;
        let next = header.nextElementSibling;
        while (next && !next.dataset.header) {
            if (next.style.display !== 'none') { show = true; break; }
            next = next.nextElementSibling;
        }
        header.style.display = show ? '' : 'none';
    });

    highlightRows();
}

function highlightRows() {
    document.querySelectorAll('#attendance-body tr.row-warning').forEach(row => {
        if (alertsVisible) row.classList.add('highlight-warning');
        else row.classList.remove('highlight-warning');
    });
    document.querySelectorAll('#attendance-body tr.row-absent').forEach(row => {
        if (absentFilterActive) row.classList.add('highlight-absent');
        else row.classList.remove('highlight-absent');
    });
}

function updateSummary(summary) {
    const container = document.getElementById('summary-container');
    if (!container) return;
    if (!summary) { container.innerHTML = ''; return; }
    container.innerHTML = `
        <div class="attendance-summary">
            <span class="summary-item text-success">Normal <span class="badge bg-success">${summary.normal}</span></span>
            <span id="summary-warning" class="summary-item text-warning">Alertas <span class="badge bg-warning text-dark">${summary.warnings}</span></span>
            <span class="summary-item text-danger" id="summary-absent">Ausentes <span class="badge bg-danger">${summary.absent}</span></span>
        </div>`;

    const legendAbsent = document.getElementById('legend-absent');
    const summaryAbsent = document.getElementById('summary-absent');
    [legendAbsent, summaryAbsent].forEach(el => {
        if (!el) return;
        el.style.cursor = 'pointer';
        el.onclick = () => {
            absentFilterActive = !absentFilterActive;
            applyFilters();
        };
    });

    const legendWarning = document.getElementById('legend-warning');
    const summaryWarning = document.getElementById('summary-warning');
    [legendWarning, summaryWarning].forEach(el => {
        if (!el) return;
        el.style.cursor = 'pointer';
        el.onclick = () => showAlertDetails();
    });
}

function renderAttendanceTable() {
    const tableBody = document.getElementById('attendance-body');
    if (!tableBody) return;

    if (!attendanceData.length) {
        tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No hay datos disponibles</td></tr>';
        return;
    }

    const groupedByUser = {};
    attendanceData.forEach(item => {
        if (!groupedByUser[item.userid]) groupedByUser[item.userid] = [];
        groupedByUser[item.userid].push(item);
    });

    tableBody.innerHTML = '';

    Object.keys(groupedByUser).forEach(userId => {
        const records = groupedByUser[userId];
        const userName = records[0].name;

        const headerRow = document.createElement('tr');
        const headerCell = document.createElement('td');
        headerCell.colSpan = 6;
        headerCell.innerHTML = `<strong>${userName}</strong>`;
        headerCell.className = 'table-primary text-start';
        headerRow.dataset.user = `${userName} ${records[0].usercode}`;
        headerRow.dataset.header = '1';
        headerRow.appendChild(headerCell);
        tableBody.appendChild(headerRow);

        records.forEach(record => {
            const row = document.createElement('tr');
            row.dataset.user = `${userName} ${records[0].usercode}`;
            row.dataset.status = record.status;
            row.dataset.userid = record.userid;
            row.dataset.date = record.date;
            row.classList.add(
                record.status === 'absent' ? 'row-absent' :
                record.status === 'warning' ? 'row-warning' :
                'row-normal'
            );

            row.innerHTML = `
                <td class="text-nowrap align-middle">${formatDateDisplay(record.date)}</td>
                <td class="col-shifts text-nowrap align-middle"></td>
                <td class="entry-cell align-middle">${formatTimes(record.entries)}</td>
                <td class="exit-cell align-middle">${formatTimes(record.exits)}</td>
                <td class="align-middle">${formatStatus(record.status)}</td>
                <td class="actions-cell text-nowrap align-middle">
                    <button class="btn btn-sm btn-outline-success me-1 add-entry-btn">+ Entrada</button>
                    <button class="btn btn-sm btn-outline-danger me-1 add-exit-btn">+ Salida</button>
                    <span class="delete-drop ms-1" title="Arrastrar aquí para eliminar">
                        <i class="fas fa-trash-alt"></i>
                    </span>
                </td>
            `;
            tableBody.appendChild(row);
            updateShiftCell(row.querySelector('.col-shifts'), record.shifts);
            setupRowActions(row);
        });
    });

    const searchInput = document.getElementById('search-input');
    if (searchInput) filterTable(searchInput.value); else applyFilters();
}

function computeSummary(data) {
    const summary = { total: 0, normal: 0, warnings: 0, absent: 0 };
    data.forEach(rec => {
        summary.total++;
        if (rec.status === 'warning') summary.warnings++;
        else if (rec.status === 'absent') summary.absent++;
        else summary.normal++;
    });
    return summary;
}

function refreshData() {
    loadAttendanceData();
}

function showAlertDetails() {
    const container = document.getElementById('alert-container');
    const mainTable = document.querySelector('.attendance-table-container');
    if (!container || !mainTable) return;

    if (alertsVisible) {
        container.innerHTML = '';
        container.classList.add('d-none');
        mainTable.classList.remove('d-none');
        alertsVisible = false;
        highlightRows();
        return;
    }

    const grouped = {};
    const search = searchQuery.toLowerCase();
    attendanceData.forEach(rec => {
        const userStr = `${rec.name} ${rec.usercode}`.toLowerCase();
        const hasAlert = rec.tardanza_minutos > 0 || rec.salida_temprano_minutos > 0 || rec.extra_minutos > 0;
        if (hasAlert && userStr.includes(search)) {
            if (!grouped[rec.userid]) grouped[rec.userid] = [];
            grouped[rec.userid].push(rec);
        }
    });

    container.classList.remove('d-none');
    mainTable.classList.add('d-none');
    container.innerHTML = '';

    const backBtn = document.createElement('button');
    backBtn.className = 'btn btn-link mb-2';
    backBtn.innerHTML = '<i class="fas fa-arrow-left"></i> Volver';
    backBtn.onclick = showAlertDetails;
    container.appendChild(backBtn);

    if (!Object.keys(grouped).length) {
        const msg = document.createElement('div');
        msg.className = 'alert alert-info mt-3';
        msg.textContent = 'No hay alertas en el período seleccionado.';
        container.appendChild(msg);
        alertsVisible = true;
        return;
    }

    Object.keys(grouped).forEach(uid => {
        const records = grouped[uid];
        const title = document.createElement('h5');
        title.className = 'mt-3';
        title.textContent = `${records[0].name} (${records[0].usercode})`;
        container.appendChild(title);

        const table = document.createElement('table');
        table.className = 'table table-sm table-striped table-bordered attendance-table mb-4';
        table.innerHTML = `<thead>
            <tr>
                <th>Fecha</th>
                <th>Día</th>
                <th>Turno</th>
                <th>Entradas</th>
                <th>Salidas</th>
                <th>Tardanza (min)</th>
                <th>Salida temprano (min)</th>
                <th>Extra (min)</th>
            </tr>
        </thead><tbody></tbody>`;
        const tbody = table.querySelector('tbody');

        let totLate = 0, totEarly = 0, totExtra = 0;
        records.forEach(r => {
            const row = document.createElement('tr');
            row.classList.add(
                r.status === 'warning' ? 'row-warning' :
                r.status === 'absent' ? 'row-absent' :
                'row-normal'
            );
            row.innerHTML = `
                <td>${formatDateDisplay(r.date)}</td>
                <td>${r.day_name}</td>
                <td class="text-nowrap">${formatShifts(r.shifts)}</td>
                <td>${formatTimes(r.entries)}</td>
                <td>${formatTimes(r.exits)}</td>
                <td class="text-end">${r.tardanza_minutos}</td>
                <td class="text-end">${r.salida_temprano_minutos}</td>
                <td class="text-end">${r.extra_minutos}</td>
            `;
            tbody.appendChild(row);
            totLate += r.tardanza_minutos;
            totEarly += r.salida_temprano_minutos;
            totExtra += r.extra_minutos;
        });

        const tfoot = document.createElement('tfoot');
        tfoot.innerHTML = `<tr class="table-secondary fw-bold">
            <td colspan="5" class="text-end">Totales</td>
            <td class="text-end">${totLate}</td>
            <td class="text-end">${totEarly}</td>
            <td class="text-end">${totExtra}</td>
        </tr>`;
        table.appendChild(tfoot);
        container.appendChild(table);
    });

    alertsVisible = true;
    highlightRows();
}

function setupRowActions(row) {
    const entryCell = row.querySelector('.entry-cell');
    const exitCell = row.querySelector('.exit-cell');
    const group = `marks-${row.dataset.userid}-${row.dataset.date}`;

    const actionsCell = row.querySelector('.actions-cell');
    if (actionsCell) {
        const zones = actionsCell.querySelectorAll('.delete-drop');
        zones.forEach((z, i) => { if (i > 0) z.remove(); });
    }

    [entryCell, exitCell].forEach(el => {
        if (!el || el.dataset.sortableInitialized) return;
        Sortable.create(el, {
            group: group,
            animation: 150,
            onEnd: evt => {
                guardarMarcaAjax(row);
                if (evt.from !== evt.to) {
                    const msg = evt.to.classList.contains('entry-cell')
                        ? 'Marca cambiada a entrada'
                        : 'Marca cambiada a salida';
                    showToast(msg);
                }
            }
        });
        el.dataset.sortableInitialized = '1';
    });

    row.querySelector('.add-entry-btn').onclick = () => openTimePicker(row, 'entry');
    row.querySelector('.add-exit-btn').onclick = () => openTimePicker(row, 'exit');

    const deleteZone = row.querySelector('.delete-drop');
    if (deleteZone && !deleteZone.dataset.sortableInitialized) {
        Sortable.create(deleteZone, {
            group: group,
            animation: 150,
            onAdd: evt => {
                evt.item.remove();
                guardarMarcaAjax(row);
                showToast('Marca eliminada');
            }
        });
        deleteZone.dataset.sortableInitialized = '1';
        deleteZone.addEventListener('dragover', e => {
            e.preventDefault();
            deleteZone.classList.add('drag-over');
        });
        ['dragleave', 'drop'].forEach(ev =>
            deleteZone.addEventListener(ev, () => deleteZone.classList.remove('drag-over')));
    }
}

function openTimePicker(row, type) {
    currentRow = row;
    currentType = type;
    if (timeInput) timeInput.value = '';
    if (timeModal) timeModal.show();
}

function agregarMarca(row, type, time) {
    if (validarMarcaDuplicada(row, type, time)) {
        showToast('Ya existe una marca similar', 'danger');
        return;
    }
    const cell = row.querySelector(type === 'entry' ? '.entry-cell' : '.exit-cell');
    const badge = document.createElement('span');
    badge.className = 'badge bg-info me-1 badge-new';
    badge.textContent = time;
    cell.appendChild(badge);
    showToast(type === 'entry' ? 'Entrada agregada' : 'Salida agregada');
    guardarMarcaAjax(row);
}

function validarMarcaDuplicada(row, type, time) {
    const cell = row.querySelector(type === 'entry' ? '.entry-cell' : '.exit-cell');
    return collectTimes(cell).some(t => diffMinutes(`${row.dataset.date}T${t}`, `${row.dataset.date}T${time}`) <= 15);
}

function guardarMarcaAjax(row) {
    const data = {
        user_id: row.dataset.userid,
        date: row.dataset.date,
        entries: collectTimes(row.querySelector('.entry-cell')),
        exits: collectTimes(row.querySelector('.exit-cell'))
    };

    const actionsCell = row.querySelector('.actions-cell');
    // Remove any existing spinner in this row to avoid duplicates
    const prev = actionsCell.querySelector('.spinner-border');
    if (prev) prev.remove();

    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm text-success ms-2';
    actionsCell.appendChild(spinner);

    sendAttendance('save_marks', data)
        .then(resp => {
            if (!resp.success) throw new Error(resp.message || 'Error al guardar');
            showToast('Cambios guardados');
            refreshRow(row);
        })
        .catch(err => showToast(err.message || 'Error', 'danger'))
        .finally(() => spinner.remove());
}

function refreshRow(row) {
    const params = new URLSearchParams({
        action: 'get_user_details',
        user_id: row.dataset.userid,
        date: row.dataset.date
    });
    fetch(`view_control.php?${params.toString()}`)
        .then(r => r.json())
        .then(result => {
            if (!result.success) throw new Error(result.message || 'Error');
            applyRowData(row, result.data);
            const idx = attendanceData.findIndex(
                d => d.userid == row.dataset.userid && d.date == row.dataset.date
            );
            if (idx !== -1) {
                attendanceData[idx] = Object.assign(attendanceData[idx], result.data);
                updateSummary(computeSummary(attendanceData));
            }
        })
        .catch(err => {
            console.error('Error actualizando fila:', err);
            showToast(err.message || 'Error', 'danger');
        });
}

function applyRowData(row, data) {
    updateShiftCell(row.querySelector('.col-shifts'), data.shifts);
    row.querySelector('.entry-cell').innerHTML = formatTimes(data.entries);
    row.querySelector('.exit-cell').innerHTML = formatTimes(data.exits);
    row.cells[4].innerHTML = formatStatus(data.status);
    row.dataset.status = data.status;
    row.classList.remove('row-normal', 'row-warning', 'row-absent');
    row.classList.add(
        data.status === 'warning' ? 'row-warning' :
        data.status === 'absent' ? 'row-absent' :
        'row-normal'
    );
    setupRowActions(row);
    highlightRows();
}

function collectTimes(cell) {
    return Array.from(cell.querySelectorAll('.badge')).map(b => b.textContent.trim());
}


function findIncompleteDays() {
    const search = searchQuery.toLowerCase();
    return attendanceData.filter(item => {
        const total = item.entries.length + item.exits.length;
        if (!item.shifts.length) return false;
        if (total !== 1) return false;
        const userStr = `${item.name} ${item.usercode}`.toLowerCase();
        return userStr.includes(search);
    });
}

function showIncompleteDays() {
    incompleteList = findIncompleteDays();
    const container = document.getElementById('incomplete-container');
    if (!container) return;
    container.innerHTML = '';

    if (!incompleteList.length) {
        const msg = document.createElement('p');
        msg.className = 'text-center my-3';
        msg.textContent = 'No hay días incompletos detectados';
        container.appendChild(msg);
    } else {
        const grouped = {};
        incompleteList.forEach((item, idx) => {
            if (!grouped[item.userid]) grouped[item.userid] = [];
            grouped[item.userid].push({ item, idx });
        });

        Object.keys(grouped).forEach(uid => {
            const records = grouped[uid];
            const title = document.createElement('h6');
            title.className = 'mt-3';
            title.textContent = `${records[0].item.name} (${records[0].item.usercode})`;
            container.appendChild(title);

            const table = document.createElement('table');
            table.className = 'table table-sm table-bordered mb-4';
            table.innerHTML = `<thead>
                <tr>
                    <th>Fecha</th>
                    <th>Turno</th>
                    <th>Marca existente</th>
                    <th>Falta</th>
                    <th></th>
                </tr>
            </thead><tbody></tbody>`;
            const tbody = table.querySelector('tbody');

            records.forEach(r => {
                const item = r.item;
                const idx = r.idx;
                const row = document.createElement('tr');
                row.dataset.index = idx;
                row.classList.add(
                    item.status === 'warning' ? 'row-warning' :
                    item.status === 'absent' ? 'row-absent' :
                    'row-normal'
                );
                const missing = item.entries.length ? 'Salida' : 'Entrada';
                const existing = item.entries.length ? item.entries : item.exits;
                const calcShift = item.shifts.some(s => isCalculatedShiftJs(s));
                row.innerHTML = `
                    <td>${formatDateDisplay(item.date)}</td>
                    <td class="text-nowrap">${formatShifts(item.shifts)}</td>
                    <td>${formatTimes(existing)}</td>
                    <td>${missing}</td>
                    <td></td>
                `;
                if (!calcShift) {
                    row.cells[4].innerHTML = '<button class="btn btn-sm btn-primary auto-fix-btn">Corregir automáticamente</button>';
                }
                tbody.appendChild(row);
            });

            container.appendChild(table);
        });
    }

    container.querySelectorAll('.auto-fix-btn').forEach(btn => {
        btn.addEventListener('click', () => autoFix(btn.closest('tr').dataset.index));
    });

    const modalEl = document.getElementById('incompleteModal');
    if (modalEl) new bootstrap.Modal(modalEl).show();
}

function autoFix(index) {
    const item = incompleteList[index];
    if (!item || !item.shifts.length) return;
    const added = [];
    if (item.entries.length + item.exits.length === 0) {
        item.shifts.forEach(sh => {
            const inTime = sh.intime.slice(0, 5);
            const outTime = sh.outtime.slice(0, 5);
            item.entries.push({ time: inTime });
            item.exits.push({ time: outTime });
            added.push({ type: 'entry', time: inTime });
            added.push({ type: 'exit', time: outTime });
        });
    } else {
        const shift = item.shifts[0];
        const missingEntry = !item.entries.length;
        const t = missingEntry ? shift.intime.slice(0, 5) : shift.outtime.slice(0, 5);
        if (missingEntry) {
            item.entries.push({ time: t });
            added.push({ type: 'entry', time: t });
        } else {
            item.exits.push({ time: t });
            added.push({ type: 'exit', time: t });
        }
        for (let i = 1; i < item.shifts.length; i++) {
            const sh = item.shifts[i];
            const inT = sh.intime.slice(0, 5);
            const outT = sh.outtime.slice(0, 5);
            item.entries.push({ time: inT });
            item.exits.push({ time: outT });
            added.push({ type: 'entry', time: inT });
            added.push({ type: 'exit', time: outT });
        }
    }

    const row = document.querySelector(`#incomplete-container tr[data-index="${index}"]`);
    if (row) {
        row.cells[2].innerHTML = formatTimes(item.entries.length ? item.entries : item.exits);
        row.cells[3].textContent = 'Completo';
        row.querySelector('.auto-fix-btn').remove();
        const msg = document.createElement('span');
        msg.className = 'badge bg-success ms-2';
        msg.textContent = 'Marcas agregadas automáticamente';
        row.cells[3].appendChild(msg);
    }

    const mainRow = document.querySelector(`#attendance-body tr[data-userid="${item.userid}"][data-date="${item.date}"]`);
    if (mainRow) {
        added.forEach(m => {
            const cell = mainRow.querySelector(m.type === 'entry' ? '.entry-cell' : '.exit-cell');
            const badge = document.createElement('span');
            badge.className = 'badge bg-info me-1 badge-new';
            badge.textContent = m.time;
            cell.appendChild(badge);
        });
    }

    const promises = added.map(m => sendAttendance('add_mark', {
        user_id: item.userid,
        date: item.date,
        time: m.time,
        type: m.type
    }));
    Promise.all(promises)
        .then(() => {
            showToast('Corrección guardada');
            if (mainRow) {
                refreshRow(mainRow);
            }
        })
        .catch(err => showToast(err.message || 'Error', 'danger'));
}

function diffMinutes(a, b) {
    return Math.abs((new Date(b) - new Date(a)) / 60000);
}

function filtrarDuplicadas(marks, mantenerPrimera) {
    if (marks.length < 2) return { marks: marks.slice(), changed: false };
    const ordenadas = marks.slice().sort((x, y) => new Date(x.full_time) - new Date(y.full_time));
    const result = [];
    let changed = false;
    if (mantenerPrimera) {
        let ultima = ordenadas[0];
        result.push(ultima);
        for (let i = 1; i < ordenadas.length; i++) {
            if (diffMinutes(ultima.full_time, ordenadas[i].full_time) > 15) {
                result.push(ordenadas[i]);
                ultima = ordenadas[i];
            } else {
                changed = true;
            }
        }
    } else {
        let ultima = ordenadas[ordenadas.length - 1];
        result.unshift(ultima);
        for (let i = ordenadas.length - 2; i >= 0; i--) {
            if (diffMinutes(ultima.full_time, ordenadas[i].full_time) > 15) {
                result.unshift(ordenadas[i]);
                ultima = ordenadas[i];
            } else {
                changed = true;
            }
        }
    }
    return { marks: result, changed };
}

function asignarEntradasYSalidas(record) {
    let mod = false;
    let res = filtrarDuplicadas(record.entries, true);
    record.entries = res.marks; if (res.changed) mod = true;
    res = filtrarDuplicadas(record.exits, false);
    record.exits = res.marks; if (res.changed) mod = true;

    if (record.entries.length === 2 && record.exits.length === 0) {
        record.exits = [record.entries[1]];
        record.entries = [record.entries[0]];
        mod = true;
    } else if (record.exits.length === 2 && record.entries.length === 0) {
        record.entries = [record.exits[0]];
        record.exits = [record.exits[1]];
        mod = true;
    }

    if (record.entries.length + record.exits.length === 4) {
        const combinadas = [
            ...record.entries.map(m => Object.assign({ type: 'entry' }, m)),
            ...record.exits.map(m => Object.assign({ type: 'exit' }, m))
        ].sort((a, b) => new Date(a.full_time) - new Date(b.full_time));
        const entradas = [];
        const salidas = [];
        let tipo = 'entry';
        combinadas.forEach(m => {
            if (tipo === 'entry') entradas.push({ time: m.time, full_time: m.full_time });
            else salidas.push({ time: m.time, full_time: m.full_time });
            tipo = tipo === 'entry' ? 'exit' : 'entry';
        });
        record.entries = entradas;
        record.exits = salidas;
        mod = true;
    }
    return mod;
}

function detectarDuplicadosPorUsuario() {
    const cambios = [];
    attendanceData.forEach(rec => {
        const beforeEntries = rec.entries.map(m => Object.assign({}, m));
        const beforeExits = rec.exits.map(m => Object.assign({}, m));
        asignarEntradasYSalidas(rec);
        const beforeStr = JSON.stringify({ e: beforeEntries, s: beforeExits });
        const afterStr = JSON.stringify({ e: rec.entries, s: rec.exits });
        if (beforeStr !== afterStr) {
            cambios.push({ record: rec, beforeEntries, beforeExits });
        }
    });
    return cambios;
}

function guardarCorreccionAjax(change) {
    const { record, beforeEntries, beforeExits } = change;
    const ops = [];
    beforeEntries.forEach(m => {
        const inEntry = record.entries.some(x => x.full_time === m.full_time);
        const inExit = record.exits.some(x => x.full_time === m.full_time);
        if (!inEntry && !inExit) {
            ops.push(sendAttendance('delete_mark', {
                user_id: record.userid,
                date: record.date,
                time: m.time,
                type: 'entry'
            }));
        } else if (!inEntry && inExit) {
            ops.push(sendAttendance('reassign_mark', {
                user_id: record.userid,
                date: record.date,
                time: m.time,
                new_type: 'exit'
            }));
        }
    });
    beforeExits.forEach(m => {
        const inExit = record.exits.some(x => x.full_time === m.full_time);
        const inEntry = record.entries.some(x => x.full_time === m.full_time);
        if (!inExit && !inEntry) {
            ops.push(sendAttendance('delete_mark', {
                user_id: record.userid,
                date: record.date,
                time: m.time,
                type: 'exit'
            }));
        } else if (!inExit && inEntry) {
            ops.push(sendAttendance('reassign_mark', {
                user_id: record.userid,
                date: record.date,
                time: m.time,
                new_type: 'entry'
            }));
        }
    });
    return Promise.all(ops);
}

function cleanAttendanceData() {
    const cambios = detectarDuplicadosPorUsuario();
    if (!cambios.length) {
        showToast('No se encontraron duplicados', 'info');
        return;
    }
    const promises = [];
    cambios.forEach(change => {
        const rec = change.record;
        const row = document.querySelector(`#attendance-body tr[data-userid="${rec.userid}"][data-date="${rec.date}"]`);
        if (row) {
            applyRowData(row, rec);
        }
        promises.push(guardarCorreccionAjax(change));
    });
    updateSummary(computeSummary(attendanceData));
    Promise.allSettled(promises).then(() => {
        showToast(`Se corrigieron ${cambios.length} registros`);
    });
}

function exportAttendance() {
    const deptId = document.getElementById('dept-filter').value;
    const days = document.getElementById('days-filter').value;
    const startDate = document.getElementById('date-from').value;
    const endDate = document.getElementById('date-to').value;

    const params = new URLSearchParams({
        dept_id: deptId,
        days: days,
        start_date: startDate,
        end_date: endDate
    });

    fetch(`../export_excel.php?${params.toString()}`)
        .then(response => {
            if (!response.ok) throw new Error('Error generando archivo');
            return Promise.all([
                response.blob(),
                response.headers.get('Content-Disposition')
            ]);
        })
        .then(([blob, disposition]) => {
            let filename = 'asistencia.xlsx';
            if (disposition && disposition.includes('filename=')) {
                filename = disposition.split('filename=')[1].replace(/"/g, '');
            }
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
        })
        .catch(err => showToast(err.message || 'Error', 'danger'));
}


