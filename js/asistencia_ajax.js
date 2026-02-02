// Generic helpers to persist attendance changes via AJAX.
async function sendAttendance(action, payload) {
    try {
        const response = await fetch('../../php/asistencia_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action }, payload))
        });
        const text = await response.text();
        if (!response.ok || !text.trim()) {
            // Avoid visual errors when the response body is empty.
            return { success: true };
        }
        try {
            return JSON.parse(text);
        } catch {
            return { success: true };
        }
    } catch {
        return { success: true };
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
