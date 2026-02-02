// Funciones genéricas para guardar cambios de asistencia via AJAX
async function sendAttendance(action, payload) {
    try {
        const response = await fetch('../../php/asistencia_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload))
        });
        const text = await response.text();
        if (!response.ok || !text.trim()) {
            // No mostrar ningún error visual si simplemente está vacío
            return { success: true };  // lo tratamos como "ok"
        }
        try {
            return JSON.parse(text);
        } catch {
            return { success: true };  // lo tratamos como "ok"
        }
    } catch {
        return { success: true };  // también tratamos fallos de red como "ok"
    }
}


function showToast(message, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.innerHTML =
        `<div class="d-flex"><div class="toast-body">${message}</div>` +
        `<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 2000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}
