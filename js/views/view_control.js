document.addEventListener('DOMContentLoaded', () => {
    AttendanceControl.init();
});

const AttendanceControl = (() => {
    // Shared UI state for the attendance control view.
    const state = {
        searchQuery: '',
        absentFilterActive: false,
        attendanceData: [],
        alertsVisible: false,
        incompleteList: [],
        currentRow: null,
        currentType: null,
        timeModal: null,
        timeInput: null
    };

    // Cached DOM references for faster access.
    const dom = {};

    const byId = (id) => document.getElementById(id);

    // Entry point to wire listeners and load data.
    const init = () => {
        cacheDom();
        setupFilters();
        setupButtons();
        setupTooltips();
        setupTimePicker();
        loadAttendanceData();
        setInterval(() => {
            if (dom.autoRefresh && dom.autoRefresh.checked) {
                refreshData();
            }
        }, 300000);
    };

    const cacheDom = () => {
        dom.deptFilter = byId('dept-filter');
        dom.daysFilter = byId('days-filter');
        dom.dateFrom = byId('date-from');
        dom.dateTo = byId('date-to');
        dom.searchInput = byId('search-input');
        dom.autoRefresh = byId('auto-refresh');
        dom.refreshBtn = byId('refresh-btn');
        dom.exportBtn = byId('export-btn');
        dom.incompleteBtn = byId('incomplete-btn');
        dom.cleanBtn = byId('clean-btn');
        dom.alertContainer = byId('alert-container');
        dom.summaryContainer = byId('summary-container');
        dom.tableBody = byId('attendance-body');
        dom.mainTableContainer = document.querySelector('.attendance-table-container');
        dom.incompleteContainer = byId('incomplete-container');
    };

    const setupFilters = () => {
        if (dom.deptFilter) {
            dom.deptFilter.addEventListener('change', loadAttendanceData);
        }

        if (dom.daysFilter) {
            dom.daysFilter.addEventListener('change', () => {
                const days = parseInt(dom.daysFilter.value, 10);
                const endDate = new Date();
                const startDate = new Date();
                startDate.setDate(endDate.getDate() - (days - 1));
                if (dom.dateFrom) dom.dateFrom.value = formatDateForInput(startDate);
                if (dom.dateTo) dom.dateTo.value = formatDateForInput(endDate);
                loadAttendanceData();
            });
            dom.daysFilter.dispatchEvent(new Event('change'));
        }

        if (dom.dateFrom && dom.dateTo) {
            dom.dateFrom.addEventListener('change', () => {
                if (dom.dateTo.value) loadAttendanceData();
            });
            dom.dateTo.addEventListener('change', () => {
                if (dom.dateFrom.value) loadAttendanceData();
            });
        }

        if (dom.searchInput) {
            dom.searchInput.addEventListener('input', () => filterTable(dom.searchInput.value));
        }
    };

    const setupButtons = () => {
        if (dom.refreshBtn) dom.refreshBtn.addEventListener('click', refreshData);
        if (dom.exportBtn) dom.exportBtn.addEventListener('click', exportAttendance);
        if (dom.incompleteBtn) dom.incompleteBtn.addEventListener('click', showIncompleteDays);
        if (dom.cleanBtn) dom.cleanBtn.addEventListener('click', cleanAttendanceData);
    };

    const setupTooltips = () => {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(el => new bootstrap.Tooltip(el));
    };

    const setupTimePicker = () => {
        const modalEl = byId('timePickerModal');
        if (!modalEl) return;
        state.timeModal = new bootstrap.Modal(modalEl);
        state.timeInput = byId('time-picker-input');
        const saveBtn = byId('time-picker-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                if (state.currentRow && state.timeInput && state.timeInput.value) {
                    addMark(state.currentRow, state.currentType, state.timeInput.value);
                    state.timeModal.hide();
                }
            });
        }
    };

    const loadAttendanceData = () => {
        if (!dom.tableBody) return;

        dom.tableBody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary me-2"></div> Cargando datos...</td></tr>';

        const params = new URLSearchParams({
            action: 'get_attendance_data',
            dept_id: dom.deptFilter ? dom.deptFilter.value : '4',
            days: dom.daysFilter ? dom.daysFilter.value : '7',
            start_date: dom.dateFrom ? dom.dateFrom.value : '',
            end_date: dom.dateTo ? dom.dateTo.value : ''
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
                    throw new Error(result.message || 'Error desconocido del servidor');
                }

                state.attendanceData = result.data;
                state.alertsVisible = false;
                if (dom.alertContainer) dom.alertContainer.innerHTML = '';

                updateSummary(result.summary);
                renderAttendanceTable();
            })
            .catch(err => {
                showToast(err.message || 'Error cargando asistencia', 'danger');
                dom.tableBody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">${err.message}</td></tr>`;
            });
    };

    const formatDateForInput = (date) => date.toISOString().split('T')[0];

    const formatDateDisplay = (dateStr) => {
        if (!dateStr) return '';
        const date = new Date(`${dateStr}T00:00:00`);
        let dayName = date.toLocaleDateString('es-ES', { weekday: 'long' });
        dayName = dayName.charAt(0).toUpperCase() + dayName.slice(1);
        const [year, month, day] = dateStr.split('-');
        return `${dayName} ${day}-${month}-${year}`;
    };

    const formatDateShort = (dateStr) => {
        if (!dateStr) return '';
        const [year, month, day] = dateStr.split('-');
        return `${day}-${month}-${year}`;
    };

    const formatTime = (timeStr) => (timeStr ? timeStr.slice(0, 5) : '');

    const formatShifts = (shifts) => {
        if (!shifts.length) return 'Sin turno asignado';
        return shifts.map(shift => {
            const inTime = shift.intime.slice(0, 5);
            const outTime = shift.outtime.slice(0, 5);
            return (inTime === '00:00' && outTime === '23:59')
                ? 'Turno Calculado'
                : `${inTime} - ${outTime}`;
        }).join('<br>');
    };

    const isCalculatedShift = (shift) => {
        const inTime = shift.intime.slice(0, 5);
        const outTime = shift.outtime.slice(0, 5);
        return inTime === '00:00' && outTime === '23:59';
    };

    const updateShiftCell = (cell, shifts) => {
        if (!cell) return;

        // Remove duplicated shifts before rendering.
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
    };

    const formatTimes = (times) => {
        if (!times.length) return '<span class="text-muted">-</span>';
        return times
            .map(t => `<span class="badge bg-info">${formatTime(t.time)}</span>`)
            .join(' ');
    };

    const formatStatus = (status) => {
        switch (status) {
            case 'warning':
                return 'Tardanza/Salida temprana';
            case 'absent':
                return 'Ausente';
            default:
                return 'Normal';
        }
    };

    const filterTable = (query) => {
        state.searchQuery = query.toLowerCase();
        applyFilters();
    };

    const applyFilters = () => {
        const rows = document.querySelectorAll('#attendance-body tr[data-user]');
        rows.forEach(row => {
            const matchesSearch = row.dataset.user.toLowerCase().includes(state.searchQuery);
            const matchesAbsent = !state.absentFilterActive || row.dataset.status === 'absent' || row.dataset.header === '1';
            row.style.display = matchesSearch && matchesAbsent ? '' : 'none';
        });

        const headers = document.querySelectorAll('#attendance-body tr[data-header="1"]');
        headers.forEach(header => {
            let show = false;
            let next = header.nextElementSibling;
            while (next && !next.dataset.header) {
                if (next.style.display !== 'none') {
                    show = true;
                    break;
                }
                next = next.nextElementSibling;
            }
            header.style.display = show ? '' : 'none';
        });

        highlightRows();
    };

    const highlightRows = () => {
        document.querySelectorAll('#attendance-body tr.row-warning').forEach(row => {
            if (state.alertsVisible) row.classList.add('highlight-warning');
            else row.classList.remove('highlight-warning');
        });
        document.querySelectorAll('#attendance-body tr.row-absent').forEach(row => {
            if (state.absentFilterActive) row.classList.add('highlight-absent');
            else row.classList.remove('highlight-absent');
        });
    };

    const updateSummary = (summary) => {
        if (!dom.summaryContainer) return;
        if (!summary) {
            dom.summaryContainer.innerHTML = '';
            return;
        }

        dom.summaryContainer.innerHTML = `
            <div class="attendance-summary">
                <span class="summary-item text-success">Normal <span class="badge bg-success">${summary.normal}</span></span>
                <span id="summary-warning" class="summary-item text-warning">Alertas <span class="badge bg-warning text-dark">${summary.warnings}</span></span>
                <span class="summary-item text-danger" id="summary-absent">Ausentes <span class="badge bg-danger">${summary.absent}</span></span>
            </div>`;

        const legendAbsent = byId('legend-absent');
        const summaryAbsent = byId('summary-absent');
        [legendAbsent, summaryAbsent].forEach(el => {
            if (!el) return;
            el.style.cursor = 'pointer';
            el.onclick = () => {
                state.absentFilterActive = !state.absentFilterActive;
                applyFilters();
            };
        });

        const legendWarning = byId('legend-warning');
        const summaryWarning = byId('summary-warning');
        [legendWarning, summaryWarning].forEach(el => {
            if (!el) return;
            el.style.cursor = 'pointer';
            el.onclick = () => showAlertDetails();
        });
    };

    const renderAttendanceTable = () => {
        if (!dom.tableBody) return;

        if (!state.attendanceData.length) {
            dom.tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No hay datos disponibles</td></tr>';
            return;
        }

        const groupedByUser = {};
        state.attendanceData.forEach(item => {
            if (!groupedByUser[item.userid]) groupedByUser[item.userid] = [];
            groupedByUser[item.userid].push(item);
        });

        dom.tableBody.innerHTML = '';

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
            dom.tableBody.appendChild(headerRow);

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
                    <td class="col-date-cell text-nowrap align-middle">
                        <span class="date-long">${formatDateDisplay(record.date)}</span>
                        <span class="date-short">${formatDateShort(record.date)}</span>
                    </td>
                    <td class="col-shifts text-nowrap align-middle"></td>
                    <td class="entry-cell align-middle">${formatTimes(record.entries)}</td>
                    <td class="exit-cell align-middle">${formatTimes(record.exits)}</td>
                    <td class="col-status align-middle">${formatStatus(record.status)}</td>
                    <td class="actions-cell col-actions text-nowrap align-middle">
                        <button class="btn btn-sm btn-outline-success me-1 add-entry-btn">+ Entrada</button>
                        <button class="btn btn-sm btn-outline-danger me-1 add-exit-btn">+ Salida</button>
                        <span class="delete-drop ms-1" title="Arrastrar aquí para eliminar">
                            <i class="fas fa-trash-alt"></i>
                        </span>
                    </td>
                `;
                dom.tableBody.appendChild(row);
                updateShiftCell(row.querySelector('.col-shifts'), record.shifts);
                setupRowActions(row);
            });
        });

        if (dom.searchInput) filterTable(dom.searchInput.value); else applyFilters();
    };

    const computeSummary = (data) => {
        const summary = { total: 0, normal: 0, warnings: 0, absent: 0 };
        data.forEach(rec => {
            summary.total += 1;
            if (rec.status === 'warning') summary.warnings += 1;
            else if (rec.status === 'absent') summary.absent += 1;
            else summary.normal += 1;
        });
        return summary;
    };

    const refreshData = () => {
        loadAttendanceData();
    };

    const showAlertDetails = () => {
        if (!dom.alertContainer || !dom.mainTableContainer) return;

        if (state.alertsVisible) {
            dom.alertContainer.innerHTML = '';
            dom.alertContainer.classList.add('d-none');
            dom.mainTableContainer.classList.remove('d-none');
            state.alertsVisible = false;
            highlightRows();
            return;
        }

        const grouped = {};
        const search = state.searchQuery.toLowerCase();
        state.attendanceData.forEach(rec => {
            const userStr = `${rec.name} ${rec.usercode}`.toLowerCase();
            const hasAlert = rec.tardanza_minutos > 0 || rec.salida_temprano_minutos > 0 || rec.extra_minutos > 0;
            if (hasAlert && userStr.includes(search)) {
                if (!grouped[rec.userid]) grouped[rec.userid] = [];
                grouped[rec.userid].push(rec);
            }
        });

        dom.alertContainer.classList.remove('d-none');
        dom.mainTableContainer.classList.add('d-none');
        dom.alertContainer.innerHTML = '';

        const backBtn = document.createElement('button');
        backBtn.className = 'btn btn-link mb-2';
        backBtn.innerHTML = '<i class="fas fa-arrow-left"></i> Volver';
        backBtn.onclick = showAlertDetails;
        dom.alertContainer.appendChild(backBtn);

        if (!Object.keys(grouped).length) {
            const msg = document.createElement('div');
            msg.className = 'alert alert-info mt-3';
            msg.textContent = 'No hay alertas en el período seleccionado.';
            dom.alertContainer.appendChild(msg);
            state.alertsVisible = true;
            return;
        }

        Object.keys(grouped).forEach(uid => {
            const records = grouped[uid];
            const title = document.createElement('h5');
            title.className = 'mt-3';
            title.textContent = `${records[0].name} (${records[0].usercode})`;
            dom.alertContainer.appendChild(title);

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

            let totLate = 0;
            let totEarly = 0;
            let totExtra = 0;
            records.forEach(record => {
                const row = document.createElement('tr');
                row.classList.add(
                    record.status === 'warning' ? 'row-warning' :
                    record.status === 'absent' ? 'row-absent' :
                    'row-normal'
                );
                row.innerHTML = `
                    <td>${formatDateDisplay(record.date)}</td>
                    <td>${record.day_name}</td>
                    <td class="text-nowrap">${formatShifts(record.shifts)}</td>
                    <td>${formatTimes(record.entries)}</td>
                    <td>${formatTimes(record.exits)}</td>
                    <td class="text-end">${record.tardanza_minutos}</td>
                    <td class="text-end">${record.salida_temprano_minutos}</td>
                    <td class="text-end">${record.extra_minutos}</td>
                `;
                tbody.appendChild(row);
                totLate += record.tardanza_minutos;
                totEarly += record.salida_temprano_minutos;
                totExtra += record.extra_minutos;
            });

            const tfoot = document.createElement('tfoot');
            tfoot.innerHTML = `<tr class="table-secondary fw-bold">
                <td colspan="5" class="text-end">Totales</td>
                <td class="text-end">${totLate}</td>
                <td class="text-end">${totEarly}</td>
                <td class="text-end">${totExtra}</td>
            </tr>`;
            table.appendChild(tfoot);
            dom.alertContainer.appendChild(table);
        });

        state.alertsVisible = true;
        highlightRows();
    };

    const setupRowActions = (row) => {
        const entryCell = row.querySelector('.entry-cell');
        const exitCell = row.querySelector('.exit-cell');
        const group = `marks-${row.dataset.userid}-${row.dataset.date}`;

        const actionsCell = row.querySelector('.actions-cell');
        if (actionsCell) {
            const zones = actionsCell.querySelectorAll('.delete-drop');
            zones.forEach((zone, index) => {
                if (index > 0) zone.remove();
            });
        }

        [entryCell, exitCell].forEach(cell => {
            if (!cell || cell.dataset.sortableInitialized) return;
            Sortable.create(cell, {
                group,
                animation: 150,
                onEnd: evt => {
                    saveMarks(row);
                    if (evt.from !== evt.to) {
                        const msg = evt.to.classList.contains('entry-cell')
                            ? 'Marca cambiada a entrada'
                            : 'Marca cambiada a salida';
                        showToast(msg);
                    }
                }
            });
            cell.dataset.sortableInitialized = '1';
        });

        const addEntryBtn = row.querySelector('.add-entry-btn');
        const addExitBtn = row.querySelector('.add-exit-btn');
        if (addEntryBtn) addEntryBtn.onclick = () => openTimePicker(row, 'entry');
        if (addExitBtn) addExitBtn.onclick = () => openTimePicker(row, 'exit');

        const deleteZone = row.querySelector('.delete-drop');
        if (deleteZone && !deleteZone.dataset.sortableInitialized) {
            Sortable.create(deleteZone, {
                group,
                animation: 150,
                onAdd: evt => {
                    evt.item.remove();
                    saveMarks(row);
                    showToast('Marca eliminada');
                }
            });
            deleteZone.dataset.sortableInitialized = '1';
            deleteZone.addEventListener('dragover', event => {
                event.preventDefault();
                deleteZone.classList.add('drag-over');
            });
            ['dragleave', 'drop'].forEach(eventName =>
                deleteZone.addEventListener(eventName, () => deleteZone.classList.remove('drag-over')));
        }
    };

    const openTimePicker = (row, type) => {
        state.currentRow = row;
        state.currentType = type;
        if (state.timeInput) state.timeInput.value = '';
        if (state.timeModal) state.timeModal.show();
    };

    const addMark = (row, type, time) => {
        if (isDuplicateMark(row, type, time)) {
            showToast('Ya existe una marca similar', 'danger');
            return;
        }
        const cell = row.querySelector(type === 'entry' ? '.entry-cell' : '.exit-cell');
        const badge = document.createElement('span');
        badge.className = 'badge bg-info me-1 badge-new';
        badge.textContent = time;
        cell.appendChild(badge);
        showToast(type === 'entry' ? 'Entrada agregada' : 'Salida agregada');
        saveMarks(row);
    };

    const isDuplicateMark = (row, type, time) => {
        const cell = row.querySelector(type === 'entry' ? '.entry-cell' : '.exit-cell');
        return collectTimes(cell).some(existingTime => diffMinutes(`${row.dataset.date}T${existingTime}`, `${row.dataset.date}T${time}`) <= 15);
    };

    const saveMarks = (row) => {
        const data = {
            user_id: row.dataset.userid,
            date: row.dataset.date,
            entries: collectTimes(row.querySelector('.entry-cell')),
            exits: collectTimes(row.querySelector('.exit-cell'))
        };

        const actionsCell = row.querySelector('.actions-cell');
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
    };

    const refreshRow = (row) => {
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
                const idx = state.attendanceData.findIndex(
                    d => d.userid == row.dataset.userid && d.date == row.dataset.date
                );
                if (idx !== -1) {
                    state.attendanceData[idx] = Object.assign(state.attendanceData[idx], result.data);
                    updateSummary(computeSummary(state.attendanceData));
                }
            })
            .catch(err => {
                console.error('Error actualizando fila:', err);
                showToast(err.message || 'Error', 'danger');
            });
    };

    const applyRowData = (row, data) => {
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
    };

    const collectTimes = (cell) => Array.from(cell.querySelectorAll('.badge')).map(badge => badge.textContent.trim());

    const findIncompleteDays = () => {
        const search = state.searchQuery.toLowerCase();
        return state.attendanceData.filter(item => {
            const total = item.entries.length + item.exits.length;
            if (!item.shifts.length) return false;
            if (total !== 1) return false;
            const userStr = `${item.name} ${item.usercode}`.toLowerCase();
            return userStr.includes(search);
        });
    };

    const showIncompleteDays = () => {
        state.incompleteList = findIncompleteDays();
        if (!dom.incompleteContainer) return;
        dom.incompleteContainer.innerHTML = '';

        if (!state.incompleteList.length) {
            const msg = document.createElement('p');
            msg.className = 'text-center my-3';
            msg.textContent = 'No hay días incompletos detectados';
            dom.incompleteContainer.appendChild(msg);
        } else {
            const grouped = {};
            state.incompleteList.forEach((item, idx) => {
                if (!grouped[item.userid]) grouped[item.userid] = [];
                grouped[item.userid].push({ item, idx });
            });

            Object.keys(grouped).forEach(uid => {
                const records = grouped[uid];
                const title = document.createElement('h6');
                title.className = 'mt-3';
                title.textContent = `${records[0].item.name} (${records[0].item.usercode})`;
                dom.incompleteContainer.appendChild(title);

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

                records.forEach(record => {
                    const item = record.item;
                    const idx = record.idx;
                    const row = document.createElement('tr');
                    row.dataset.index = idx;
                    row.classList.add(
                        item.status === 'warning' ? 'row-warning' :
                        item.status === 'absent' ? 'row-absent' :
                        'row-normal'
                    );
                    const missing = item.entries.length ? 'Salida' : 'Entrada';
                    const existing = item.entries.length ? item.entries : item.exits;
                    const calcShift = item.shifts.some(shift => isCalculatedShift(shift));
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

                dom.incompleteContainer.appendChild(table);
            });
        }

        dom.incompleteContainer.querySelectorAll('.auto-fix-btn').forEach(btn => {
            btn.addEventListener('click', () => autoFix(btn.closest('tr').dataset.index));
        });

        const modalEl = byId('incompleteModal');
        if (modalEl) new bootstrap.Modal(modalEl).show();
    };

    const autoFix = (index) => {
        const item = state.incompleteList[index];
        if (!item || !item.shifts.length) return;
        const added = [];
        if (item.entries.length + item.exits.length === 0) {
            item.shifts.forEach(shift => {
                const inTime = shift.intime.slice(0, 5);
                const outTime = shift.outtime.slice(0, 5);
                item.entries.push({ time: inTime });
                item.exits.push({ time: outTime });
                added.push({ type: 'entry', time: inTime });
                added.push({ type: 'exit', time: outTime });
            });
        } else {
            const shift = item.shifts[0];
            const missingEntry = !item.entries.length;
            const timeValue = missingEntry ? shift.intime.slice(0, 5) : shift.outtime.slice(0, 5);
            if (missingEntry) {
                item.entries.push({ time: timeValue });
                added.push({ type: 'entry', time: timeValue });
            } else {
                item.exits.push({ time: timeValue });
                added.push({ type: 'exit', time: timeValue });
            }
            for (let i = 1; i < item.shifts.length; i += 1) {
                const currentShift = item.shifts[i];
                const inTime = currentShift.intime.slice(0, 5);
                const outTime = currentShift.outtime.slice(0, 5);
                item.entries.push({ time: inTime });
                item.exits.push({ time: outTime });
                added.push({ type: 'entry', time: inTime });
                added.push({ type: 'exit', time: outTime });
            }
        }

        const row = document.querySelector(`#incomplete-container tr[data-index="${index}"]`);
        if (row) {
            row.cells[2].innerHTML = formatTimes(item.entries.length ? item.entries : item.exits);
            row.cells[3].textContent = 'Completo';
            const autoFixBtn = row.querySelector('.auto-fix-btn');
            if (autoFixBtn) autoFixBtn.remove();
            const msg = document.createElement('span');
            msg.className = 'badge bg-success ms-2';
            msg.textContent = 'Marcas agregadas automáticamente';
            row.cells[3].appendChild(msg);
        }

        const mainRow = document.querySelector(`#attendance-body tr[data-userid="${item.userid}"][data-date="${item.date}"]`);
        if (mainRow) {
            added.forEach(mark => {
                const cell = mainRow.querySelector(mark.type === 'entry' ? '.entry-cell' : '.exit-cell');
                const badge = document.createElement('span');
                badge.className = 'badge bg-info me-1 badge-new';
                badge.textContent = mark.time;
                cell.appendChild(badge);
            });
        }

        const promises = added.map(mark => sendAttendance('add_mark', {
            user_id: item.userid,
            date: item.date,
            time: mark.time,
            type: mark.type
        }));

        Promise.all(promises)
            .then(() => {
                showToast('Corrección guardada');
                if (mainRow) {
                    refreshRow(mainRow);
                }
            })
            .catch(err => showToast(err.message || 'Error', 'danger'));
    };

    const diffMinutes = (a, b) => Math.abs((new Date(b) - new Date(a)) / 60000);

    const filterDuplicates = (marks, keepFirst) => {
        if (marks.length < 2) return { marks: marks.slice(), changed: false };
        const ordered = marks.slice().sort((x, y) => new Date(x.full_time) - new Date(y.full_time));
        const result = [];
        let changed = false;
        if (keepFirst) {
            let last = ordered[0];
            result.push(last);
            for (let i = 1; i < ordered.length; i += 1) {
                if (diffMinutes(last.full_time, ordered[i].full_time) > 15) {
                    result.push(ordered[i]);
                    last = ordered[i];
                } else {
                    changed = true;
                }
            }
        } else {
            let last = ordered[ordered.length - 1];
            result.unshift(last);
            for (let i = ordered.length - 2; i >= 0; i -= 1) {
                if (diffMinutes(last.full_time, ordered[i].full_time) > 15) {
                    result.unshift(ordered[i]);
                    last = ordered[i];
                } else {
                    changed = true;
                }
            }
        }
        return { marks: result, changed };
    };

    const assignEntriesAndExits = (record) => {
        let modified = false;
        let result = filterDuplicates(record.entries, true);
        record.entries = result.marks;
        if (result.changed) modified = true;
        result = filterDuplicates(record.exits, false);
        record.exits = result.marks;
        if (result.changed) modified = true;

        if (record.entries.length === 2 && record.exits.length === 0) {
            record.exits = [record.entries[1]];
            record.entries = [record.entries[0]];
            modified = true;
        } else if (record.exits.length === 2 && record.entries.length === 0) {
            record.entries = [record.exits[0]];
            record.exits = [record.exits[1]];
            modified = true;
        }

        if (record.entries.length + record.exits.length === 4) {
            const combined = [
                ...record.entries.map(mark => Object.assign({ type: 'entry' }, mark)),
                ...record.exits.map(mark => Object.assign({ type: 'exit' }, mark))
            ].sort((a, b) => new Date(a.full_time) - new Date(b.full_time));
            const entries = [];
            const exits = [];
            let type = 'entry';
            combined.forEach(mark => {
                if (type === 'entry') entries.push({ time: mark.time, full_time: mark.full_time });
                else exits.push({ time: mark.time, full_time: mark.full_time });
                type = type === 'entry' ? 'exit' : 'entry';
            });
            record.entries = entries;
            record.exits = exits;
            modified = true;
        }
        return modified;
    };

    const detectDuplicates = () => {
        const changes = [];
        state.attendanceData.forEach(record => {
            const beforeEntries = record.entries.map(mark => Object.assign({}, mark));
            const beforeExits = record.exits.map(mark => Object.assign({}, mark));
            assignEntriesAndExits(record);
            const beforeStr = JSON.stringify({ e: beforeEntries, s: beforeExits });
            const afterStr = JSON.stringify({ e: record.entries, s: record.exits });
            if (beforeStr !== afterStr) {
                changes.push({ record, beforeEntries, beforeExits });
            }
        });
        return changes;
    };

    const saveCorrection = (change) => {
        const { record, beforeEntries, beforeExits } = change;
        const operations = [];
        beforeEntries.forEach(mark => {
            const inEntry = record.entries.some(item => item.full_time === mark.full_time);
            const inExit = record.exits.some(item => item.full_time === mark.full_time);
            if (!inEntry && !inExit) {
                operations.push(sendAttendance('delete_mark', {
                    user_id: record.userid,
                    date: record.date,
                    time: mark.time,
                    type: 'entry'
                }));
            } else if (!inEntry && inExit) {
                operations.push(sendAttendance('reassign_mark', {
                    user_id: record.userid,
                    date: record.date,
                    time: mark.time,
                    new_type: 'exit'
                }));
            }
        });
        beforeExits.forEach(mark => {
            const inExit = record.exits.some(item => item.full_time === mark.full_time);
            const inEntry = record.entries.some(item => item.full_time === mark.full_time);
            if (!inExit && !inEntry) {
                operations.push(sendAttendance('delete_mark', {
                    user_id: record.userid,
                    date: record.date,
                    time: mark.time,
                    type: 'exit'
                }));
            } else if (!inExit && inEntry) {
                operations.push(sendAttendance('reassign_mark', {
                    user_id: record.userid,
                    date: record.date,
                    time: mark.time,
                    new_type: 'entry'
                }));
            }
        });
        return Promise.all(operations);
    };

    const cleanAttendanceData = () => {
        const changes = detectDuplicates();
        if (!changes.length) {
            showToast('No se encontraron duplicados', 'info');
            return;
        }
        const promises = [];
        changes.forEach(change => {
            const record = change.record;
            const row = document.querySelector(`#attendance-body tr[data-userid="${record.userid}"][data-date="${record.date}"]`);
            if (row) {
                applyRowData(row, record);
            }
            promises.push(saveCorrection(change));
        });
        updateSummary(computeSummary(state.attendanceData));
        Promise.allSettled(promises).then(() => {
            showToast(`Se corrigieron ${changes.length} registros`);
        });
    };

    const exportAttendance = () => {
        const params = new URLSearchParams({
            dept_id: dom.deptFilter ? dom.deptFilter.value : '4',
            days: dom.daysFilter ? dom.daysFilter.value : '7',
            start_date: dom.dateFrom ? dom.dateFrom.value : '',
            end_date: dom.dateTo ? dom.dateTo.value : ''
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
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            })
            .catch(err => showToast(err.message || 'Error', 'danger'));
    };

    return {
        init
    };
})();
