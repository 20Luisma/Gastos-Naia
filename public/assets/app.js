/**
 * Gastos Naia — SPA Frontend
 * Navegación por vistas, CRUD de gastos, gráficos Chart.js, subida de recibos
 */

(function () {
    'use strict';

    // ──────────────────────────────────────────────
    //  State
    // ──────────────────────────────────────────────
    let chartAnnual = null;
    let chartMonthly = null;

    // ──────────────────────────────────────────────
    //  Utils
    // ──────────────────────────────────────────────

    function formatEuro(value) {
        return new Intl.NumberFormat('es-ES', {
            style: 'currency', currency: 'EUR',
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(value);
    }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    function showToast(message, type = 'success') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.textContent = message;

        container.appendChild(toast);

        // Remove slightly before 3s to match animation
        setTimeout(() => toast.remove(), 2900);
    }

    /**
     * Standard horizontal, delicate confirm dialog via SweetAlert2
     */
    function standardConfirm(message) {
        if (typeof Swal === 'undefined') {
            return confirm(message);
        }

        return new Promise((resolve) => {
            Swal.fire({
                html: `<div style="display:flex; align-items:center; gap:20px; text-align:left;">` +
                    `<span style="font-size:0.95rem; font-weight:500; white-space:nowrap;">${message}</span>` +
                    `<div style="display:flex; gap:10px;">` +
                    `<button id="swal-btn-yes" class="btn-swal-mini btn-swal-primary">Aceptar</button>` +
                    `<button id="swal-btn-no" class="btn-swal-mini btn-swal-ghost">Cancelar</button>` +
                    `</div></div>`,
                showConfirmButton: false,
                showCancelButton: false,
                background: '#1a1a2e',
                color: '#fff',
                width: 'auto',
                padding: '12px 20px',
                position: 'center',
                grow: false,
                didOpen: () => {
                    const popup = Swal.getPopup();
                    popup.style.borderRadius = '12px';
                    popup.style.border = '1px solid rgba(255,255,255,0.1)';

                    document.getElementById('swal-btn-yes').onclick = () => {
                        resolve(true);
                        Swal.close();
                    };
                    document.getElementById('swal-btn-no').onclick = () => {
                        resolve(false);
                        Swal.close();
                    };
                }
            });
        });
    }

    function generateColors(count) {
        const colors = [], bgColors = [];
        for (let i = 0; i < count; i++) {
            const hue = 240 + (180 * i) / Math.max(count - 1, 1);
            colors.push(`hsla(${hue},70%,60%,1)`);
            bgColors.push(`hsla(${hue},70%,60%,0.7)`);
        }
        return { colors, bgColors };
    }

    function fileIcon(type) {
        const icons = { pdf: '📕', doc: '📘', docx: '📘', jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️', webp: '🖼️' };
        return icons[type] || '📄';
    }

    // ──────────────────────────────────────────────
    //  API
    // ──────────────────────────────────────────────

    async function api(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params }).toString();
        const res = await fetch(`?${qs}`);
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        return json;
    }

    async function apiPost(action, body) {
        const res = await fetch(`?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        return json;
    }

    async function apiUpload(formData) {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        return json;
    }

    async function apiOcrUpload(formData) {
        const res = await fetch('?action=scan_receipt', { method: 'POST', body: formData });
        const json = await res.json();
        if (json.error) throw new Error(json.error);
        return json;
    }

    // ──────────────────────────────────────────────
    //  Loading / Error
    // ──────────────────────────────────────────────

    const $loading = document.getElementById('loading');
    const $error = document.getElementById('error-container');

    function showLoading() { $loading.style.display = 'flex'; }
    function hideLoading() { $loading.style.display = 'none'; }
    function showError(msg) {
        $error.innerHTML = `<div class="error-message"><strong>❌ Error</strong>${escapeHtml(msg)}</div>`;
    }
    function clearError() { $error.innerHTML = ''; }

    // ──────────────────────────────────────────────
    //  Navigation
    // ──────────────────────────────────────────────

    const views = {
        anual: document.getElementById('view-anual'),
        mensual: document.getElementById('view-mensual'),
        gastos: document.getElementById('view-gastos'),
        'nuevo-anio': document.getElementById('section-nuevo-anio'),
        ai: document.getElementById('view-ai'),
        calendario: document.getElementById('view-calendario'),
        comunicados: document.getElementById('view-comunicados')
    };

    function switchView(name) {
        clearError();
        // Ocultar todas las vistas estandar
        Object.entries(views).forEach(([key, el]) => {
            if (el) el.style.display = key === name ? 'block' : 'none';
        });

        // Hide special Google Calendar view
        const calView = document.getElementById('view-calendario');
        if (calView) {
            if (name === 'calendario') {
                calView.style.display = 'flex';
            } else {
                calView.style.display = 'none';
            }
        }

        // Hide special Comunicados view
        const comView = document.getElementById('view-comunicados');
        if (comView) {
            if (name === 'comunicados') {
                comView.style.display = 'block';
            } else {
                comView.style.display = 'none';
            }
        }

        document.querySelectorAll('.nav__btn, .nav-dropdown__item').forEach(btn => {
            btn.classList.toggle('nav__btn--active', btn.dataset.view === name);
        });

        if (name === 'anual') loadAnnual();
        if (name === 'mensual') loadMonthly();
        if (name === 'gastos') loadExpenses();
        if (name === 'comunicados') loadComunicados();
    }


    document.querySelectorAll('.nav__btn, .nav-dropdown__item').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (btn.classList.contains('nav__btn--logout')) return;
            const view = btn.dataset.view;
            if (view) switchView(view);
        });
    });

    window.handleLogout = function (e) {
        if (e && e.preventDefault) e.preventDefault();

        standardConfirm('¿Cerrar sesión?').then(confirmed => {
            if (confirmed) window.location.href = '?action=logout';
        });
    };

    // ──────────────────────────────────────────────
    //  Vista: Resumen Anual
    // ──────────────────────────────────────────────

    async function loadAnnual() {
        showLoading();
        try {
            const data = await api('years');
            hideLoading();
            renderAnnualTable(data.rows);
            renderAnnualChart(data.rows);
        } catch (e) {
            hideLoading();
            showError(e.message);
        }
    }

    function renderAnnualTable(rows) {
        const tbody = document.getElementById('annual-tbody');
        let total = 0;
        tbody.innerHTML = rows.map(r => {
            total += r.total;
            return `<tr>
                <td class="cell-year"><span class="year-badge">📅 ${r.year}</span></td>
                <td class="cell-total">${formatEuro(r.total)}</td>
            </tr>`;
        }).join('');
        document.getElementById('annual-total').textContent = formatEuro(total);
    }

    function renderAnnualChart(rows) {
        const ctx = document.getElementById('chart-annual');
        if (chartAnnual) chartAnnual.destroy();
        const { colors, bgColors } = generateColors(rows.length);
        chartAnnual = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: rows.map(r => r.year.toString()),
                datasets: [{
                    label: 'Gasto Anual (€)',
                    data: rows.map(r => r.total),
                    backgroundColor: bgColors,
                    borderColor: colors,
                    borderWidth: 2, borderRadius: 8, borderSkipped: false,
                    hoverBackgroundColor: colors,
                }]
            },
            options: chartOptions('€'),
        });
    }

    // ──────────────────────────────────────────────
    //  Vista: Mensual
    // ──────────────────────────────────────────────

    const $selectYearMonthly = document.getElementById('select-year-monthly');
    $selectYearMonthly.addEventListener('change', loadMonthly);

    async function loadMonthly() {
        const year = $selectYearMonthly.value;
        showLoading();
        try {
            const data = await api('months', { year });
            hideLoading();
            document.getElementById('monthly-year-label').textContent = year;
            renderMonthlyChart(data.months);
            renderMonthsGrid(data.months, year);
        } catch (e) {
            hideLoading();
            showError(e.message);
        }
    }

    function renderMonthlyChart(months) {
        const ctx = document.getElementById('chart-monthly');
        if (chartMonthly) chartMonthly.destroy();
        const { colors, bgColors } = generateColors(12);
        chartMonthly = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: months.map(m => m.name),
                datasets: [{
                    label: 'Gasto Mensual (€)',
                    data: months.map(m => m.total),
                    backgroundColor: bgColors,
                    borderColor: colors,
                    borderWidth: 2, borderRadius: 6, borderSkipped: false,
                    hoverBackgroundColor: colors,
                }]
            },
            options: chartOptions('€'),
        });
    }

    function renderMonthsGrid(months, year) {
        const grid = document.getElementById('months-grid');
        grid.innerHTML = months.map(m => `
            <div class="month-card" data-year="${year}" data-month="${m.month}">
                <div class="month-card__name">${m.name}</div>
                <div class="month-card__amount">${formatEuro(m.total)}</div>
            </div>
        `).join('');

        grid.querySelectorAll('.month-card').forEach(card => {
            card.addEventListener('click', () => {
                const y = card.dataset.year;
                const mo = card.dataset.month;
                document.getElementById('select-year-expense').value = y;
                document.getElementById('select-month-expense').value = mo;
                switchView('gastos');
                loadExpenses();
            });
        });
    }

    // ──────────────────────────────────────────────
    //  Vista: Gastos de un mes
    // ──────────────────────────────────────────────

    const $selectYearExpense = document.getElementById('select-year-expense');
    const $selectMonthExpense = document.getElementById('select-month-expense');
    const $btnLoadExpenses = document.getElementById('btn-load-expenses');
    let currentExpensesData = null;

    $btnLoadExpenses.addEventListener('click', loadExpenses);

    async function loadExpenses() {
        const year = $selectYearExpense.value;
        const month = $selectMonthExpense.value;
        showLoading();
        try {
            const data = await api('expenses', { year, month });
            currentExpensesData = data;
            hideLoading();

            document.getElementById('expense-month-label').textContent =
                `${data.monthName} ${data.year}`;

            const fml = document.getElementById('files-month-label');
            if (fml) fml.textContent = `${data.monthName} ${data.year}`;

            document.getElementById('expenses-card').style.display = 'block';

            renderExpensesTable(data.expenses);
            renderFilesList(data.files, year, month);

            // Poblar campo de pensión
            const pensionInput = document.getElementById('input-pension');
            if (pensionInput && data.summary) {
                pensionInput.value = data.summary.pension || '';
            }

            // Set default date for expense form
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('input-date').value = today;
        } catch (e) {
            hideLoading();
            showError(e.message);
        }
    }

    function renderExpensesTable(expenses) {
        const tbody = document.getElementById('expenses-tbody');
        let total = 0;

        if (expenses.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:1.5rem;">No hay gastos este mes</td></tr>';
            document.getElementById('expense-month-total').textContent = formatEuro(0);
            document.getElementById('expense-month-half-total').textContent = formatEuro(0);
            return;
        }

        tbody.innerHTML = expenses.map(e => {
            // Parse amount - it might be formatted as string
            let amt = 0;
            if (typeof e.amount === 'number') {
                amt = e.amount;
            } else if (typeof e.amount === 'string') {
                // Try to parse European format: "43,00 €" or "129,83 €"
                let cleaned = e.amount.replace(/[€\s]/g, '').trim();
                // European: replace , with .
                if (cleaned.includes(',') && !cleaned.includes('.')) {
                    cleaned = cleaned.replace(',', '.');
                } else if (cleaned.includes('.') && cleaned.includes(',')) {
                    // Full European: 1.234,56
                    cleaned = cleaned.replace(/\./g, '').replace(',', '.');
                }
                amt = parseFloat(cleaned) || 0;
            }
            total += amt;

            return `<tr id="expense-row-${e.row}">
                <td>${escapeHtml(e.date)}</td>
                <td>${escapeHtml(e.description)}</td>
                <td class="cell-total">${escapeHtml(e.amount)}</td>
                <td class="text-right">
                    <div class="row-actions">
                        <button class="btn btn--icon btn--ghost" onclick="editRow(${e.row}, '${escapeHtml(e.date)}', '${escapeHtml(e.description)}', ${amt})" title="Editar">✏️</button>
                        <button class="btn btn--icon btn--danger-ghost" onclick="deleteRow(${e.row})" title="Eliminar">🗑️</button>
                    </div>
                </td>
            </tr>`;
        }).join('');

        document.getElementById('expense-month-total').textContent = formatEuro(total);
        document.getElementById('expense-month-half-total').textContent = formatEuro(total / 2);
    }

    // Email summary
    const $btnSendEmail = document.getElementById('btn-send-email');
    if ($btnSendEmail) {
        $btnSendEmail.addEventListener('click', () => {
            if (!currentExpensesData || !currentExpensesData.expenses) {
                showToast('No hay datos cargados para enviar.', 'error');
                return;
            }

            const m = currentExpensesData;
            const exps = m.expenses;
            if (exps.length === 0) {
                showToast('No hay gastos en este mes para enviar.', 'error');
                return;
            }

            let total = 0;
            let listText = '';
            exps.forEach(e => {
                let amt = 0;
                if (typeof e.amount === 'number') amt = e.amount;
                else {
                    let cleaned = e.amount.replace(/[€\s]/g, '').trim();
                    if (cleaned.includes(',') && !cleaned.includes('.')) cleaned = cleaned.replace(',', '.');
                    else if (cleaned.includes('.') && cleaned.includes(',')) cleaned = cleaned.replace(/\./g, '').replace(',', '.');
                    amt = parseFloat(cleaned) || 0;
                }
                total += amt;
                listText += `- ${e.date}: ${e.description} (${formatEuro(amt)})\n`;
            });

            const half = total / 2;

            const subject = encodeURIComponent(`Resumen de Gastos Gastos Naia - ${m.monthName} ${m.year}`);

            let body = `Hola,\n\n`;
            body += `Este es el resumen de nuestros gastos compartidos del mes de ${m.monthName} ${m.year}:\n\n`;
            body += `💰 *Gasto Total del mes:* ${formatEuro(total)}\n`;
            body += `👉 *Cantidad a entregar (Mitad):* ${formatEuro(half)}\n\n`;
            body += `--- Desglose de gastos ---\n`;
            body += listText;
            body += `\nUn saludo.\n--\nGenerado por Gastos Naia App.`;

            const encodedBody = encodeURIComponent(body);
            // Mejor compatibilidad móvil para mailto creando un enlace clickeable temporalmente
            const link = document.createElement('a');
            link.href = `mailto:?subject=${subject}&body=${encodedBody}`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    // Telegram Notification Button
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('#btn-notify-telegram');
        if (!btn) return;
        
        if (!currentExpensesData || !currentExpensesData.expenses || currentExpensesData.expenses.length === 0) {
            showToast('No hay gastos en este mes para notificar.', 'error');
            return;
        }

        const year = parseInt($selectYearExpense.value, 10);
        const month = parseInt($selectMonthExpense.value, 10);
        
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Enviando...';

        try {
            const res = await apiPost('send_balance_telegram', { year, month });
            if (res.success) {
                showToast('Notificación enviada por Telegram exitosamente.', 'success');
            } else {
                showToast('Ocurrió un error al enviar la notificación.', 'error');
            }
        } catch (err) {
            showToast(`Error: ${err.message}`, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    });

    // ──────────────────────────────────────────────
    // ──────────────────────────────────────────────
    //  Añadir / Editar Gasto
    // ──────────────────────────────────────────────

    const $btnOcrScan = document.getElementById('btn-ocr-scan');
    const $inputOcrFile = document.getElementById('input-ocr-file');

    if ($btnOcrScan && $inputOcrFile) {
        $btnOcrScan.addEventListener('click', () => {
            $inputOcrFile.click();
        });

        $inputOcrFile.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;

            const originalText = $btnOcrScan.innerHTML;
            $btnOcrScan.innerHTML = '⏳ Analizando...';
            $btnOcrScan.disabled = true;

            const formData = new FormData();
            formData.append('file', file);

            // Recoger Año y Mes activos en la vista para subir el recibo a la carpeta correcta
            const year = document.getElementById('select-year-expense').value;
            const month = document.getElementById('select-month-expense').value;
            formData.append('year', year);
            formData.append('month', month);

            try {
                // Lanzamos la subida a Drive y el escaneo Inteligente simultáneamente
                const uploadPromise = apiUpload(formData);
                const ocrPromise = apiOcrUpload(formData);

                const [uploadResult, ocrData] = await Promise.all([uploadPromise, ocrPromise]);

                // Rellenar las casillas si la IA dio en el clavo
                if (ocrData.date) document.getElementById('input-date').value = ocrData.date;
                if (ocrData.description) document.getElementById('input-description').value = ocrData.description;
                if (ocrData.amount !== undefined) document.getElementById('input-amount').value = ocrData.amount;

                // Forzar la recarga de los recibos que salen listados abajo (para que lo vean)
                setTimeout(() => loadExpenses(), 500);

                showToast('✨ Ticket auto-leído y subido a Drive', 'success');
            } catch (err) {
                showToast('❌ Error IA: ' + err.message, 'error');
            } finally {
                $btnOcrScan.innerHTML = originalText;
                $btnOcrScan.disabled = false;
                $inputOcrFile.value = ''; // allow picking same file again if aborted
            }
        });
    }

    const $formAdd = document.getElementById('form-add-expense');
    const $addResult = document.getElementById('add-result');
    const $inputRow = document.getElementById('input-row');
    const $formIcon = document.getElementById('form-icon');
    const $formTitle = document.getElementById('form-title');
    const $btnSubmitText = document.getElementById('btn-submit-text');
    const $btnCancelEdit = document.getElementById('btn-cancel-edit');

    $formAdd.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-add-expense');
        btn.disabled = true;
        $addResult.className = 'form__result';
        $addResult.textContent = 'Guardando…';

        const year = $selectYearExpense.value;
        const month = $selectMonthExpense.value;
        const row = $inputRow.value;
        const date = document.getElementById('input-date').value;
        const description = document.getElementById('input-description').value.trim();
        const amount = parseFloat(document.getElementById('input-amount').value);

        const action = row ? 'edit' : 'add';
        const payload = row ? { year, month, row, date, description, amount } : { year, month, date, description, amount };

        try {
            await apiPost(action, payload);
            $addResult.className = 'form__result';
            $addResult.textContent = '';
            showToast('Gasto guardado correctamente');

            cancelEdit(); // Reset form state

            // Recargar lista
            setTimeout(() => loadExpenses(), 500);
        } catch (err) {
            $addResult.className = 'form__result form__result--error';
            $addResult.textContent = '❌ ' + err.message;
            showToast(err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    });

    window.editRow = function (row, date, description, amount) {
        // Enlazar datos al formulario
        $inputRow.value = row;

        // Convert format DD/MM/YYYY or YYYY-MM-DD to YYYY-MM-DD for the input type="date"
        let isoDate = date;
        if (date.includes('/')) {
            const parts = date.split('/');
            if (parts.length === 3) isoDate = `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
        }

        document.getElementById('input-date').value = isoDate;
        document.getElementById('input-description').value = description;
        document.getElementById('input-amount').value = amount;

        // Cambiar estilos del formulario para modo "Editar"
        $formIcon.textContent = '✏️';
        $formTitle.textContent = 'Editar Gasto';
        $btnSubmitText.textContent = 'Actualizar Gasto';
        $btnCancelEdit.style.display = 'inline-block';

        // Scroll hacia el formulario
        document.getElementById('add-expense-card').scrollIntoView({ behavior: 'smooth' });
    };

    $btnCancelEdit.addEventListener('click', cancelEdit);

    function cancelEdit() {
        $inputRow.value = '';
        document.getElementById('input-description').value = '';
        document.getElementById('input-amount').value = '';
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('input-date').value = today;

        $formIcon.textContent = '➕';
        $formTitle.textContent = 'Añadir Gasto';
        $btnSubmitText.textContent = 'Guardar';
        $btnCancelEdit.style.display = 'none';
        clearErrorAdd();

        // Limpiar también el panel de subida de archivos para que no queden restos visuales
        const $uploadResult = document.getElementById('upload-result');
        if ($uploadResult) {
            $uploadResult.className = 'form__result';
            $uploadResult.textContent = '';
        }
    }

    function clearErrorAdd() {
        $addResult.className = 'form__result';
        $addResult.textContent = '';
    }

    window.deleteRow = async function (row) {
        if (!await standardConfirm('¿Eliminar este gasto?')) return;

        try {
            const year = $selectYearExpense.value;
            const month = $selectMonthExpense.value;

            showToast('Eliminando...', 'success');
            // Optimistic UI update
            document.getElementById(`expense-row-${row}`)?.remove();

            await apiPost('delete', { year, month, row });
            showToast('Gasto eliminado correctamente');

            // Reload para actualizar totales
            loadExpenses();
        } catch (err) {
            showToast(err.message, 'error');
            loadExpenses(); // Restaurar UI state
        }
    };

    // ──────────────────────────────────────────────
    //  Upload de recibos
    // ──────────────────────────────────────────────

    const $uploadZone = document.getElementById('upload-zone');
    const $fileInput = document.getElementById('file-input');
    const $uploadResult = document.getElementById('upload-result');

    // Drag & Drop
    ['dragenter', 'dragover'].forEach(evt => {
        $uploadZone.addEventListener(evt, (e) => {
            e.preventDefault();
            $uploadZone.classList.add('upload-zone--dragover');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        $uploadZone.addEventListener(evt, (e) => {
            e.preventDefault();
            $uploadZone.classList.remove('upload-zone--dragover');
        });
    });
    $uploadZone.addEventListener('drop', (e) => {
        const files = e.dataTransfer.files;
        if (files.length) uploadFiles(files);
    });

    $fileInput.addEventListener('change', () => {
        if ($fileInput.files.length) {
            uploadFiles($fileInput.files);
            $fileInput.value = '';
        }
    });

    const $fileInputCamera = document.getElementById('file-input-camera');
    if ($fileInputCamera) {
        $fileInputCamera.addEventListener('change', () => {
            if ($fileInputCamera.files.length) {
                uploadFiles($fileInputCamera.files);
                $fileInputCamera.value = '';
            }
        });
    }

    async function uploadFiles(fileList) {
        const year = $selectYearExpense.value;
        const month = $selectMonthExpense.value;
        const total = fileList.length;

        $uploadResult.className = 'form__result';
        $uploadResult.textContent = `Subiendo ${total} archivo${total > 1 ? 's' : ''}…`;

        let successCount = 0;
        let errorCount = 0;

        const uploadPromises = Array.from(fileList).map(async (file) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('year', year);
            fd.append('month', month);

            try {
                await apiUpload(fd);
                successCount++;
            } catch (err) {
                errorCount++;
                showToast(`Error al subir ${file.name}`, 'error');
            }
        });

        await Promise.allSettled(uploadPromises);

        if (errorCount === 0) {
            $uploadResult.className = 'form__result';
            $uploadResult.textContent = '';
            showToast(`${total} archivo${total > 1 ? 's' : ''} subido${total > 1 ? 's' : ''} correctamente`);
        } else {
            $uploadResult.className = 'form__result';
            $uploadResult.textContent = '';
            showToast(`Se subieron ${successCount}, fallaron ${errorCount}`, 'error');
        }

        // Recargar lista de archivos
        setTimeout(() => loadExpenses(), 300);
    }

    // ──────────────────────────────────────────────
    //  Files List
    // ──────────────────────────────────────────────

    function renderFilesList(files, year, month) {
        const container = document.getElementById('files-list');
        if (!files || files.length === 0) {
            container.innerHTML = '<p style="color:var(--text-muted);font-size:0.85rem;padding:0.5rem 0;">No hay recibos para este mes</p>';
            return;
        }

        container.innerHTML = files.map(f => `
            <div class="file-item">
                <span class="file-item__icon">${fileIcon(f.type)}</span>
                <div class="file-item__info">
                    <div class="file-item__name">${escapeHtml(f.filename)}</div>
                    <div class="file-item__meta">${f.size_text} · ${f.date}</div>
                </div>
                <div class="file-item__actions">
                    <a href="${escapeHtml(f.url)}" target="_blank" class="btn btn--sm btn--ghost">👁️ Ver en Drive</a>
                    <button class="btn btn--sm btn--danger" onclick="deleteFile('${escapeHtml(f.path)}')">🗑️</button>
                </div>
            </div>
        `).join('');
    }

    // Global delete function
    window.deleteFile = async function (path) {
        if (!confirm('¿Eliminar este archivo?')) return;
        try {
            await apiPost('delete-file', { path });
            showToast('Archivo eliminado');
            loadExpenses();
        } catch (err) {
            showToast(err.message, 'error');
        }
    };

    // ──────────────────────────────────────────────
    //  Chart Options
    // ──────────────────────────────────────────────

    function chartOptions(prefix) {
        return {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15,15,26,0.95)',
                    borderColor: 'rgba(99,102,241,0.3)',
                    borderWidth: 1,
                    titleFont: { family: 'Inter', size: 13, weight: '600' },
                    bodyFont: { family: 'Inter', size: 12 },
                    padding: 12, cornerRadius: 8,
                    callbacks: {
                        label: (ctx) => ' ' + formatEuro(ctx.parsed.y),
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: '#a0a0b8', font: { family: 'Inter', size: 11, weight: '500' } },
                    border: { display: false },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(255,255,255,0.04)' },
                    ticks: {
                        color: '#6b6b80',
                        font: { family: 'Inter', size: 11 },
                        callback: v => formatEuro(v),
                        maxTicksLimit: 7,
                    },
                    border: { display: false },
                }
            },
            animation: { duration: 800, easing: 'easeOutQuart' },
        };
    }

    // ──────────────────────────────────────────────
    //  Init
    // ──────────────────────────────────────────────

    // Initialize
    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const targetView = urlParams.get('view') || 'gastos';
        switchView(targetView);

        // Lógica de Vista Nuevo Año (incrustada, mismo sistema que otras vistas)
        const $btnNewYear = document.getElementById('btn-new-year');
        const $btnCancelNewYear = document.getElementById('btn-cancel-new-year');
        const $formNewYear = document.getElementById('form-new-year');
        const $inputNewYear = document.getElementById('input-new-year');
        const $newYearLoading = document.getElementById('new-year-loading');
        const $newYearResult = document.getElementById('new-year-result');

        // El botón ya tiene data-view="nuevo-anio" así que el sistema global lo gestiona automáticamente
        // Solo necesitamos: reset del estado cuando se entra a la vista
        if ($btnNewYear) {
            $btnNewYear.addEventListener('click', () => {
                // Reset ui state en cada visita
                if ($formNewYear) $formNewYear.style.display = '';
                if ($newYearLoading) $newYearLoading.style.display = 'none';
                if ($newYearResult) $newYearResult.textContent = '';
                if ($inputNewYear) $inputNewYear.focus();
            });
        }

        if ($formNewYear) {
            $formNewYear.addEventListener('submit', async (e) => {
                e.preventDefault();
                const yearToCreate = $inputNewYear.value;



                $formNewYear.style.display = 'none';
                $newYearLoading.style.display = 'flex';
                $newYearResult.textContent = '';

                try {
                    const res = await fetch(`?action=create_year`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ year: yearToCreate })
                    });

                    let data;
                    try {
                        data = await res.json();
                    } catch (e) {
                        throw new Error("Respuesta no válida del servidor.");
                    }

                    $newYearLoading.style.display = 'none';

                    if (!res.ok) {
                        throw new Error(data.error || data.message || `Error HTTP: ${res.status}`);
                    }

                    if (data.status === 'success') {
                        if (data.config_saved === false) {
                            $newYearResult.className = 'form__result form__result--success';
                            $newYearResult.style.textAlign = 'left';

                            let manualMsg = `<h3 style="margin:0 0 10px;color:#00c4b3">✅ Carpetas e Excel Creados</h3>`;
                            manualMsg += `<p style="font-size:0.85rem;margin-bottom:10px;">Tu servidor bloquea autoguardados por seguridad. Abre tu <b>config.php</b> en el Panel y añade estas 2 líneas al año ${yearToCreate}:</p>`;
                            manualMsg += `<div style="background:rgba(0,0,0,0.5);padding:10px;border-radius:6px;font-family:monospace;font-size:0.75rem;margin-bottom:10px;color:#cbd5e1;overflow-wrap:anywhere;">`;
                            manualMsg += `// En 'spreadsheets':<br><span style="color:#a855f7">${data.manualCodeSpreadsheet.trim()}</span><br><br>`;
                            manualMsg += `// En 'drive_folders':<br><span style="color:#a855f7">${data.manualCodeFolder.trim()}</span>`;
                            manualMsg += `</div>`;
                            manualMsg += `<button type="button" class="btn btn--ghost" style="width:100%" onclick="window.location.reload()">Refrescar App Completado</button>`;

                            $newYearResult.innerHTML = manualMsg;
                        } else {
                            $newYearResult.className = 'form__result form__result--success';
                            $newYearResult.innerHTML = `✅ Año ${yearToCreate} creado y auto-configurado con éxito.<br>Refrescando en 3s...`;
                            setTimeout(() => window.location.reload(), 3000);
                        }
                    } else {
                        throw new Error(data.error || data.message || 'Error desconocido.');
                    }

                } catch (err) {
                    $newYearLoading.style.display = 'none';
                    $formNewYear.style.display = 'flex';
                    $newYearResult.className = 'form__result form__result--error';
                    $newYearResult.textContent = "Error: " + err.message;
                }
            });
        }
    });

    // ──────────────────────────────────────────────
    //  AI Assistant Panel (Embedded View)
    // ──────────────────────────────────────────────

    const $aiForm = document.getElementById('ai-form');
    const $aiInput = document.getElementById('ai-input');
    const $aiMessages = document.getElementById('ai-messages');
    const $aiSubmitBtn = document.getElementById('ai-submit-btn');
    const $aiSubmitText = $aiSubmitBtn?.querySelector('.ai-submit-text');
    const $aiSubmitLoader = $aiSubmitBtn?.querySelector('.ai-submit-loader');

    // Clean AI chat when navigating to this view if desired? For now, we keep the chat alive while session is open.
    // Opcional: escuchar los cambios de vista si se quiere hacer algo al entrar en la IA.

    // Simple Markdown to HTML parser for Gemini responses
    function parseMarkdown(text) {
        let html = text;
        // Bold
        html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Italic
        html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');
        // Lists (simple)
        html = html.replace(/^\* (.*$)/gim, '<li>$1</li>');
        html = html.replace(/^- (.*$)/gim, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');

        // Tables (basic support for Gemini tables "| header | header |\n|---|---|")
        if (html.includes('|---|') || html.includes('| --- |')) {
            const rows = html.split('\n');
            let inTable = false;
            let tableHtml = '<div style="overflow-x:auto;"><table>';
            let parsedHtml = '';

            for (let i = 0; i < rows.length; i++) {
                let row = rows[i].trim();
                if (row.startsWith('|') && row.endsWith('|')) {
                    if (row.includes('|---|') || row.includes('| --- |')) continue; // Skip separator

                    if (!inTable) { inTable = true; }

                    const cells = row.split('|').filter(c => c.trim() !== '');
                    tableHtml += '<tr>';
                    cells.forEach(cell => {
                        const tag = i === 0 || rows[i - 1].includes('|---|') ? 'th' : 'td';
                        tableHtml += `<${tag}>${cell.trim()}</${tag}>`;
                    });
                    tableHtml += '</tr>';
                } else {
                    if (inTable) {
                        inTable = false;
                        tableHtml += '</table></div>';
                        parsedHtml += tableHtml + '\n';
                        tableHtml = '<div style="overflow-x:auto;"><table>'; // reset if multiple tables
                    }
                    parsedHtml += row + '\n';
                }
            }
            if (inTable) { parsedHtml += tableHtml + '</table></div>'; }
            html = parsedHtml;
        }

        // Paragraphs
        html = html.split('\n\n').map(p => {
            if (p.trim().startsWith('<ul') || p.trim().startsWith('<div')) return p;
            return `<p>${p}</p>`;
        }).join('');

        // Line breaks
        html = html.replace(/\n/g, '<br>');

        return html;
    }

    function addChatMessage(text, sender = 'user') {
        const msgDiv = document.createElement('div');
        msgDiv.className = `ai-msg ai-msg--${sender}`;

        if (sender === 'system') {
            msgDiv.innerHTML = parseMarkdown(text);
        } else {
            msgDiv.textContent = text;
        }

        $aiMessages.appendChild(msgDiv);
        $aiMessages.scrollTop = $aiMessages.scrollHeight;
    }

    if ($aiForm) {
        $aiForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const question = $aiInput.value.trim();
            if (!question) return;

            // UI Loading state
            addChatMessage(question, 'user');
            $aiInput.value = '';
            $aiSubmitBtn.disabled = true;
            $aiSubmitText.setAttribute('hidden', '');
            $aiSubmitLoader.classList.add('is-loading');
            $aiSubmitLoader.removeAttribute('hidden');

            try {
                const res = await apiPost('ai_ask', { question });
                addChatMessage(res.answer || 'No hubo respuesta', 'system');
            } catch (err) {
                addChatMessage('❌ Error de conexión con la IA: ' + err.message, 'system');
            } finally {
                $aiSubmitBtn.disabled = false;
                $aiSubmitText.removeAttribute('hidden');
                $aiSubmitLoader.classList.remove('is-loading');
                $aiSubmitLoader.setAttribute('hidden', '');
                $aiInput.focus();
            }
        });

        // Enter to submit
        $aiInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (!$aiSubmitBtn.disabled) {
                    $aiForm.dispatchEvent(new Event('submit'));
                }
            }
        });
    }

    // ──────────────────────────────────────────────
    //  Guardar Pensión Alimenticia
    // ──────────────────────────────────────────────
    const $btnSavePension = document.getElementById('btn-save-pension');
    if ($btnSavePension) {
        $btnSavePension.addEventListener('click', async () => {
            const $inputPension = document.getElementById('input-pension');
            const amount = parseFloat($inputPension.value);

            if (isNaN(amount) || amount < 0) {
                showToast('Introduce un importe válido para la pensión.', 'error');
                return;
            }

            const year = parseInt($selectYearExpense.value, 10);
            const month = parseInt($selectMonthExpense.value, 10);

            showLoading();
            $btnSavePension.disabled = true;
            $btnSavePension.innerHTML = '<span>⏳</span>...';

            try {
                const res = await apiPost('set_pension', { year, month, amount });
                hideLoading();
                if (res.success) {
                    showToast('Pensión guardada en Sheets y Firebase sincronizado.');
                    loadExpenses(); // refrescar
                } else {
                    showToast('Error desconocido al guardar la pensión.', 'error');
                }
            } catch (e) {
                hideLoading();
                showToast(`Error al guardar: ${e.message}`, 'error');
            } finally {
                $btnSavePension.disabled = false;
                $btnSavePension.innerHTML = '<span>💾</span> Guardar';
            }
        });
    }
    // ──────────────────────────────────────────────
    //  Vista: Calendario estilo Google Calendar
    // ──────────────────────────────────────────────

    const MONTH_NAMES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    let calYear = new Date().getFullYear();
    let calMonth = new Date().getMonth() + 1; // 1-12
    let miniYear = calYear;
    let miniMonth = calMonth;
    let calEvents = [];
    let calSelectedDay = null;

    // ── Helpers ──────────────────────────────────

    function getDaysInMonth(y, m) { return new Date(y, m, 0).getDate(); }

    function startDowOf(y, m) {
        let d = new Date(y, m - 1, 1).getDay(); // 0=dom
        return d === 0 ? 6 : d - 1; // Lun=0…Dom=6
    }

    // ── Botón CREAR dropdown ──────────────────────

    const $createWrapper = document.getElementById('gcal-create-wrapper');
    const $createBtn = document.getElementById('gcal-create-btn');
    const $createDropdown = document.getElementById('gcal-create-dropdown');

    if ($createBtn) {
        $createBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            $createWrapper.classList.toggle('open');
        });
    }

    document.addEventListener('click', () => {
        if ($createWrapper) $createWrapper.classList.remove('open');
    });

    document.getElementById('drop-evento')?.addEventListener('click', () => {
        $createWrapper.classList.remove('open');
        openModal('evento');
    });

    document.getElementById('drop-visita')?.addEventListener('click', () => {
        $createWrapper.classList.remove('open');
        openModal('visita');
    });

    document.getElementById('drop-tarea')?.addEventListener('click', () => {
        $createWrapper.classList.remove('open');
        openModal('tarea');
    });

    document.getElementById('drop-cita')?.addEventListener('click', () => {
        $createWrapper.classList.remove('open');
        openModal('cita');
    });

    // ── Modal ─────────────────────────────────────

    const $modalOverlay = document.getElementById('gcal-modal-overlay');
    const $modalTitle = document.getElementById('gcal-modal-title');
    const $modalIcon = document.getElementById('gcal-modal-title-icon');
    const $formEvento = document.getElementById('cal-event-form');
    const $formTarea = document.getElementById('modal-task-form');
    const $formCita = document.getElementById('form-cita');

    let editingEventId = null; // Guardar ID si estamos editando
    let editingEventSource = null; // 'local' o 'gcal'
    let repeatEveryNWeeks = 1; // Para Visitas: frecuencia cada N semanas

    function openModal(type, day = null, editEv = null) {
        if (!$modalOverlay) return;
        $formEvento.style.display = 'none';
        $formTarea.style.display = 'none';
        if ($formCita) $formCita.style.display = 'none';
        const $formComunicado = document.getElementById('form-comunicado');
        if ($formComunicado) $formComunicado.style.display = 'none';
        editingEventId = editEv ? editEv.id : null;
        editingEventSource = editEv ? (editEv.isLocal ? 'local' : 'gcal') : null;

        if (type === 'evento' || type === 'visita') {
            const isVisita = type === 'visita';
            // Guardamos el tipo actual en el form para usarlo en el submit
            $formEvento.dataset.currentType = type;

            $modalTitle.textContent = isVisita ? 'Citas / Visitas de Naia' : 'Extraescolares';
            $modalIcon.textContent = isVisita ? '🟠' : '📅';
            $formEvento.style.display = 'block';
            if (day) {
                const y = calYear;
                const m = String(calMonth).padStart(2, '0');
                const d = String(day).padStart(2, '0');
                document.getElementById('cal-event-start').value = `${y}-${m}-${d}`;
                document.getElementById('cal-event-end').value = `${y}-${m}-${d}`;
            }
            if (editEv) {
                document.getElementById('cal-event-title').value = editEv.title || '';
                document.getElementById('cal-event-location').value = editEv.location || '';
                document.getElementById('cal-event-desc').value = editEv.description || '';
                const start = new Date(editEv.start);
                const end = new Date(editEv.end);
                document.getElementById('cal-event-start').value = start.toISOString().split('T')[0];
                document.getElementById('cal-event-end').value = end.toISOString().split('T')[0];
                document.getElementById('cal-event-allday').checked = editEv.allDay;
                document.getElementById('cal-event-time-start').value = start.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', hour12: false });
                document.getElementById('cal-event-time-end').value = end.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', hour12: false });
                if ($timeRow) $timeRow.style.display = editEv.allDay ? 'none' : 'grid';
                $modalTitle.textContent = isVisita ? 'Editar Visita' : 'Editar Extraescolar';
            }
        } else if (type === 'tarea') {
            $modalTitle.textContent = 'Notas importantes';
            $modalIcon.textContent = '📋';
            $formTarea.style.display = 'block';
            if (day) {
                const y = calYear;
                const m = String(calMonth).padStart(2, '0');
                const d = String(day).padStart(2, '0');
                document.getElementById('modal-task-date').value = `${y}-${m}-${d}`;
            }
            if (editEv) {
                document.getElementById('modal-task-title').value = editEv.title || '';
                document.getElementById('modal-task-location').value = editEv.location || '';
                const start = new Date(editEv.start);
                const end = new Date(editEv.end);
                document.getElementById('modal-task-date').value = start.toISOString().split('T')[0];
                document.getElementById('modal-task-allday').checked = editEv.allDay;
                document.getElementById('modal-task-time-start').value = start.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', hour12: false });
                document.getElementById('modal-task-time-end').value = end.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit', hour12: false });
                if ($taskTimeRow) $taskTimeRow.style.display = editEv.allDay ? 'none' : 'grid';
                $modalTitle.textContent = 'Editar Nota';
            }
        } else if (type === 'cita') {
            $modalTitle.textContent = 'Agenda de citas';
            $modalIcon.textContent = '🕒';
            $formCita.style.display = 'block';
            if (day) {
                const y = calYear;
                const m = String(calMonth).padStart(2, '0');
                const d = String(day).padStart(2, '0');
                document.getElementById('cita-date').value = `${y}-${m}-${d}`;
            }
        }

        // Mostrar/ocultar selector de frecuencia según tipo
        const $freqContainer = document.getElementById('cal-frecuencia-container');
        const $wdLabel = document.getElementById('cal-wd-label');
        if ($freqContainer) {
            const showFreq = (type === 'visita');
            $freqContainer.style.display = showFreq ? 'block' : 'none';
            if ($wdLabel) $wdLabel.textContent = showFreq ? 'DÍA DE LA SEMANA:' : 'DÍAS DE LA SEMANA:';
        }
        // Resetear frecuencia al abrir el modal
        repeatEveryNWeeks = 1;
        const $freqLabel = document.getElementById('cal-freq-label');
        if ($freqLabel) $freqLabel.textContent = 'Cada semana';

        $modalOverlay.style.display = 'flex';
    }

    function closeModal() {
        if ($modalOverlay) $modalOverlay.style.display = 'none';
    }

    document.getElementById('gcal-modal-close')?.addEventListener('click', closeModal);
    document.getElementById('btn-cancel-event')?.addEventListener('click', closeModal);
    document.getElementById('btn-cancel-modal-task')?.addEventListener('click', closeModal);
    document.getElementById('btn-cancel-cita')?.addEventListener('click', closeModal);

    // Toggle hora en modal de tareas
    const $taskAllDay = document.getElementById('modal-task-allday');
    const $taskTimeRow = document.getElementById('modal-task-time-row');
    if ($taskAllDay && $taskTimeRow) {
        $taskAllDay.addEventListener('change', () => {
            $taskTimeRow.style.display = $taskAllDay.checked ? 'none' : 'grid';
        });
    }

    // ── Lógica Agenda de Citas ──────────────────

    $formCita?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const $result = document.getElementById('cita-result');
        const $btn = document.getElementById('btn-save-cita');

        const title = document.getElementById('cita-title').value.trim();
        const date = document.getElementById('cita-date').value;
        const location = document.getElementById('cita-location').value.trim();
        const tStart = document.getElementById('cita-time-start').value;
        const tEnd = document.getElementById('cita-time-end').value;

        if (!title || !date || !tStart || !tEnd) return;

        $btn.disabled = true;
        $result.textContent = 'Guardando cita...';

        const payload = {
            title: title,
            description: 'Añadido desde Agenda de Citas.',
            location,
            allDay: false,
            start: `${date}T${tStart}:00`,
            end: `${date}T${tEnd}:00`,
            colorId: null // Morado/Lavanda (Default)
        };

        try {
            await apiPost('calendar_create', payload);
            $formCita.reset();
            closeModal();
            showToast('✓ Cita añadida correctamente');
            await loadCalendarEvents();
        } catch (err) {
            $result.className = 'form__result form__result--error';
            $result.textContent = '❌ Error al crear la cita.';
        } finally {
            $btn.disabled = false;
            $result.textContent = '';
        }
    });

    $modalOverlay?.addEventListener('click', (e) => {
        if (e.target === $modalOverlay) closeModal();
    });

    // ── Toggle hora en form evento ────────────────

    const $allDayChk = document.getElementById('cal-event-allday');
    const $timeRow = document.getElementById('cal-event-time-row');
    if ($allDayChk && $timeRow) {
        $allDayChk.addEventListener('change', () => {
            $timeRow.style.display = $allDayChk.checked ? 'none' : 'grid';
        });
    }

    // ── Guardar evento desde modal ────────────────

    // ── Repetición semanal: toggle y preview ──────

    const $repeatChk = document.getElementById('cal-event-repeat');
    const $repeatOptions = document.getElementById('cal-repeat-options');
    const $repeatPreview = document.getElementById('cal-repeat-preview');
    const $repeatUntil = document.getElementById('cal-repeat-until');

    function updateRepeatPreview() {
        if (!$repeatPreview) return;
        const checked = [...document.querySelectorAll('#cal-repeat-options .gcal-wd-btn input:checked')].map(i => parseInt(i.value));
        const until = $repeatUntil?.value;
        const start = document.getElementById('cal-event-start')?.value;
        if (!checked.length || !until || !start) { $repeatPreview.textContent = ''; return; }
        const count = getRecurringDates(start, until, checked, repeatEveryNWeeks).length;
        $repeatPreview.textContent = `Se crearán ${count} eventos`;
    }

    $repeatChk?.addEventListener('change', () => {
        if ($repeatOptions) $repeatOptions.style.display = $repeatChk.checked ? 'block' : 'none';
        updateRepeatPreview();
    });

    $repeatUntil?.addEventListener('change', updateRepeatPreview);
    document.querySelectorAll('#cal-repeat-options .gcal-wd-btn input').forEach(cb => {
        cb.addEventListener('change', (e) => {
            // Si es modo Visita, funciona como radio: solo un día seleccionado
            const currentType = $formEvento?.dataset.currentType || 'evento';
            if (currentType === 'visita') {
                document.querySelectorAll('#cal-repeat-options .gcal-wd-btn input').forEach(other => {
                    if (other !== e.target) other.checked = false;
                });
            }
            updateRepeatPreview();
        });
    });

    // Botones de frecuencia
    document.getElementById('btn-freq-minus')?.addEventListener('click', () => {
        if (repeatEveryNWeeks > 1) repeatEveryNWeeks--;
        const lbl = document.getElementById('cal-freq-label');
        if (lbl) lbl.textContent = repeatEveryNWeeks === 1 ? 'Cada semana' : `Cada ${repeatEveryNWeeks} semanas`;
        updateRepeatPreview();
    });
    document.getElementById('btn-freq-plus')?.addEventListener('click', () => {
        if (repeatEveryNWeeks < 8) repeatEveryNWeeks++;
        const lbl = document.getElementById('cal-freq-label');
        if (lbl) lbl.textContent = repeatEveryNWeeks === 1 ? 'Cada semana' : `Cada ${repeatEveryNWeeks} semanas`;
        updateRepeatPreview();
    });

    // Genera lista de fechas (YYYY-MM-DD) recurrentes con soporte a cada N semanas
    function getRecurringDates(startDate, untilDate, weekdays, nWeeks = 1) {
        const dates = [];
        const start = new Date(startDate + 'T12:00:00');
        const until = new Date(untilDate + 'T23:59:59');
        const cur = new Date(start);
        const n = nWeeks < 1 ? 1 : nWeeks;
        while (cur <= until) {
            if (weekdays.includes(cur.getDay())) {
                // Calcula semanas transcurridas desde el inicio
                const weeksElapsed = Math.round((cur - start) / (7 * 24 * 60 * 60 * 1000));
                if (weeksElapsed % n === 0) {
                    dates.push(cur.toISOString().slice(0, 10));
                }
            }
            cur.setDate(cur.getDate() + 1);
        }
        return dates;
    }

    // ── Almacenamiento Servidor (Extraescolares) ─────
    async function getLocalExtra() {
        try { const r = await api('extraescolar_list'); return r.items || []; } catch { return []; }
    }
    async function saveExtraEvent(item) {
        return await apiPost('extraescolar_save', item);
    }
    async function saveExtraEventBatch(items) {
        return await apiPost('extraescolar_save_batch', items);
    }
    async function deleteExtraEvent(id) {
        return await apiPost('extraescolar_delete', { id });
    }

    // ── Submit formulario de evento (ahora LOCAL para Extraescolares) ──

    $formEvento?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const $result = document.getElementById('cal-event-result');
        const $btn = document.getElementById('btn-save-event');
        $btn.disabled = true;

        const allDay = document.getElementById('cal-event-allday').checked;
        const startDate = document.getElementById('cal-event-start').value;
        const endDate = document.getElementById('cal-event-end').value || startDate;
        const title = document.getElementById('cal-event-title').value.trim();
        const desc = document.getElementById('cal-event-desc').value.trim();
        let startVal = startDate;
        let endVal = endDate;
        let tStart = '09:00', tEnd = '10:00';

        if (!allDay) {
            tStart = document.getElementById('cal-event-time-start').value || '09:00';
            tEnd = document.getElementById('cal-event-time-end').value || '10:00';
            startVal = `${startDate}T${tStart}:00`;
            endVal = `${endDate}T${tEnd}:00`;
        }

        // ── Modo recurrente ──
        const isRepeat = $repeatChk?.checked;
        const weekdays = [...document.querySelectorAll('#cal-repeat-options .gcal-wd-btn input:checked')].map(i => parseInt(i.value));
        const untilDate = $repeatUntil?.value;
        const location = document.getElementById('cal-event-location').value.trim();

        const currentType = $formEvento.dataset.currentType || 'evento';
        const isVisita = currentType === 'visita';

        if (isRepeat && weekdays.length > 0 && untilDate) {
            const dates = getRecurringDates(startDate, untilDate, weekdays, isVisita ? repeatEveryNWeeks : 1);
            if (dates.length === 0) {
                $result.className = 'form__result form__result--error';
                $result.textContent = '❌ No hay fechas que coincidan con esos criterios.';
                $btn.disabled = false;
                return;
            }

            const newEvents = [];
            for (let d of dates) {
                newEvents.push(isVisita ? {
                    title: title,
                    description: desc,
                    location: location,
                    allDay: allDay,
                    start: allDay ? d : `${d}T${tStart}:00`,
                    end: allDay ? d : `${d}T${tEnd}:00`,
                    colorId: "6" // Amarillo Google Calendar
                } : {
                    title: title,
                    description: desc,
                    location: location,
                    allDay: allDay,
                    start: allDay ? d : `${d}T${tStart}:00`,
                    end: allDay ? d : `${d}T${tEnd}:00`,
                    color: "10",
                    isLocal: true,
                    type: 'extraescolar'
                });
            }

            if (isVisita) {
                await apiPost('calendar_create_batch', newEvents);
            } else {
                await saveExtraEventBatch(newEvents);
            }

            $formEvento.reset();
            if ($repeatOptions) $repeatOptions.style.display = 'none';
            closeModal();
            showToast(`✓ ${dates.length} ${isVisita ? 'visitas creadas' : 'eventos creados'}`);
            await loadCalendarEvents();
            return;
        }

        const payload = isVisita ? {
            title, description: desc, location, allDay,
            start: allDay ? startDate : startVal,
            end: allDay ? (endDate || startDate) : endVal,
            colorId: "6"
        } : {
            title, description: desc, location, allDay,
            start: allDay ? startDate : startVal,
            end: allDay ? (endDate || startDate) : endVal,
            color: "10", isLocal: true, type: 'extraescolar'
        };

        if (editingEventId) {
            if (isVisita && editingEventSource === 'gcal') {
                payload.eventId = editingEventId;
                await apiPost('calendar_update', payload);
                showToast('Visita actualizada ✓');
            } else if (!isVisita && editingEventSource === 'local') {
                payload.id = editingEventId;
                await saveExtraEvent(payload);
                showToast('Extraescolar actualizado ✓');
            }
        } else {
            // Crear nuevo
            if (isVisita) {
                await apiPost('calendar_create', payload);
                showToast('Visita guardada ✓');
            } else {
                await saveExtraEvent(payload);
                showToast('Extraescolar guardado ✓');
            }
        }

        $formEvento.reset();
        closeModal();
        await loadCalendarEvents();
        if (calSelectedDay) showDayPanel(calSelectedDay);
        $btn.disabled = false;
    });



    // ── Añadir tarea desde modal ──────────────────

    $formTarea?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const $btn = document.getElementById('btn-save-modal-task');
        const title = document.getElementById('modal-task-title').value.trim();
        const date = document.getElementById('modal-task-date').value;
        const allDay = document.getElementById('modal-task-allday').checked;
        const tStart = document.getElementById('modal-task-time-start').value;
        const tEnd = document.getElementById('modal-task-time-end').value;
        const location = document.getElementById('modal-task-location').value.trim();

        if (!title || !date) return;
        $btn.disabled = true;

        try {
            const payload = {
                title,
                description: 'Nota importante añadida o editada desde el calendario.',
                location,
                allDay: allDay,
                start: allDay ? date : `${date}T${tStart}:00`,
                end: allDay ? date : `${date}T${tEnd}:00`,
                colorId: "11"
            };

            if (editingEventId && editingEventSource === 'gcal') {
                payload.eventId = editingEventId;
                await apiPost('calendar_update', payload);
                showToast('Nota actualizada ✓');
            } else {
                await apiPost('calendar_create', payload);
                showToast('Nota guardada correctamente ✓');
            }

            $formTarea.reset();
            if ($taskTimeRow) $taskTimeRow.style.display = 'none';
            closeModal();
            await loadCalendarEvents();
            renderDailyNotes();
        } catch (err) {
            showToast('Error: ' + err.message, 'error');
        } finally {
            $btn.disabled = false;
        }
    });

    // ── Mini-calendario ───────────────────────────

    function renderMiniCal() {
        const label = document.getElementById('mini-month-label');
        const grid = document.getElementById('mini-cal-grid');
        if (!label || !grid) return;

        label.textContent = `${MONTH_NAMES[miniMonth - 1].slice(0, 3)} ${miniYear}`;

        const today = new Date();
        const dim = getDaysInMonth(miniYear, miniMonth);
        const startDow = startDowOf(miniYear, miniMonth);

        let html = '';
        for (let i = 0; i < startDow; i++) html += '<span></span>';
        for (let d = 1; d <= dim; d++) {
            const isToday = today.getFullYear() === miniYear && today.getMonth() + 1 === miniMonth && today.getDate() === d;
            const isSelected = calYear === miniYear && calMonth === miniMonth && calSelectedDay === d;
            let cls = 'gcal-mini-day';
            if (isToday) cls += ' gcal-mini-day--today';
            if (isSelected) cls += ' gcal-mini-day--selected';
            html += `<span class="${cls}" data-day="${d}">${d}</span>`;
        }
        grid.innerHTML = html;

        grid.querySelectorAll('.gcal-mini-day').forEach(el => {
            el.addEventListener('click', () => {
                const day = parseInt(el.dataset.day, 10);
                calYear = miniYear;
                calMonth = miniMonth;
                calSelectedDay = day;
                updateMainHeader();
                loadCalendarEvents().then(() => showDayPanel(day));
                renderMiniCal();
            });
        });
    }

    document.getElementById('mini-prev')?.addEventListener('click', () => {
        miniMonth--;
        if (miniMonth < 1) { miniMonth = 12; miniYear--; }
        renderMiniCal();
    });

    document.getElementById('mini-next')?.addEventListener('click', () => {
        miniMonth++;
        if (miniMonth > 12) { miniMonth = 1; miniYear++; }
        renderMiniCal();
    });

    // ── Header principal ──────────────────────────

    function updateMainHeader() {
        const label = document.getElementById('cal-month-label');
        if (label) label.textContent = `${MONTH_NAMES[calMonth - 1]} ${calYear}`;
    }

    // ── Navegación mes principal ──────────────────

    document.getElementById('cal-prev')?.addEventListener('click', () => {
        calMonth--;
        if (calMonth < 1) { calMonth = 12; calYear--; }
        miniMonth = calMonth; miniYear = calYear;
        updateMainHeader();
        loadCalendarEvents();
        renderMiniCal();
    });

    document.getElementById('cal-next')?.addEventListener('click', () => {
        calMonth++;
        if (calMonth > 12) { calMonth = 1; calYear++; }
        miniMonth = calMonth; miniYear = calYear;
        updateMainHeader();
        loadCalendarEvents();
        renderMiniCal();
    });

    document.getElementById('cal-today')?.addEventListener('click', () => {
        const now = new Date();
        calYear = now.getFullYear();
        calMonth = now.getMonth() + 1;
        miniYear = calYear; miniMonth = calMonth;
        calSelectedDay = null;
        updateMainHeader();
        loadCalendarEvents();
        renderMiniCal();
    });

    // ── Cargar eventos del mes ────────────────────

    async function loadCalendarEvents() {
        const grid = document.getElementById('cal-grid');
        if (!grid) return;
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--text-muted);">Cargando eventos...</div>';
        hideDayPanel();

        let gcalEvents = [];
        let googleError = false;

        // Intentar Google
        try {
            const data = await api('calendar_events', { year: calYear, month: calMonth });
            if (data.events) gcalEvents = data.events;
        } catch (e) {
            console.error("Error GCal:", e);
            googleError = true;
        }

        // Cargar Locales (Siempre se intenta)
        let localEvents = [];
        try {
            const items = await getLocalExtra();
            localEvents = items.filter(ev => {
                const d = new Date(ev.start);
                return d.getFullYear() === calYear && d.getMonth() + 1 === calMonth;
            });
        } catch (e) {
            console.error("Error Locales:", e);
        }

        calEvents = [...gcalEvents, ...localEvents];
        renderMainGrid();
        renderDailyNotes();

        if (googleError) {
            const footer = document.querySelector('.calendar-header + div'); // O algún sitio discreto
            // Avisamos pero no bloqueamos
            console.warn("Google Calendar no disponible. Mostrando solo eventos locales.");
        }
    }

    // ── Grid mensual principal ────────────────────

    function renderMainGrid() {
        const grid = document.getElementById('cal-grid');
        if (!grid) return;

        const today = new Date();
        const dim = getDaysInMonth(calYear, calMonth);
        const startDow = startDowOf(calYear, calMonth);
        const prevDim = getDaysInMonth(calYear, calMonth - 1 === 0 ? 12 : calMonth - 1);

        const eventsByDay = {};
        calEvents.forEach(ev => {
            const d = new Date(ev.start);
            if (d.getFullYear() === calYear && d.getMonth() + 1 === calMonth) {
                const dd = d.getDate();
                if (!eventsByDay[dd]) eventsByDay[dd] = [];
                eventsByDay[dd].push(ev);
            }
        });

        let html = '';

        for (let i = startDow - 1; i >= 0; i--) {
            const d = prevDim - i;
            html += `<div class="gcal-cell gcal-cell--other-month"><span class="gcal-cell__num">${d}</span></div>`;
        }

        for (let day = 1; day <= dim; day++) {
            const isToday = today.getFullYear() === calYear && today.getMonth() + 1 === calMonth && today.getDate() === day;
            const evs = eventsByDay[day] || [];
            let cls = 'gcal-cell';
            if (isToday) cls += ' gcal-cell--today';

            const pills = evs.slice(0, 3).map(ev => {
                const colorCls = ev.color ? ` gcal-event--color-${ev.color}` : '';
                return `<span class="gcal-event-pill${colorCls}" title="${escapeHtml(ev.title)}">${escapeHtml(ev.title)}</span>`;
            }).join('');
            const more = evs.length > 3 ? `<span class="gcal-event-pill" style="background:rgba(124,58,237,0.35);">+${evs.length - 3} más</span>` : '';

            html += `<div class="${cls}" data-day="${day}">
                <span class="gcal-cell__num">${day}</span>
                ${pills}${more}
            </div>`;
        }

        const totalCells = startDow + dim;
        const remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
        for (let i = 1; i <= remaining; i++) {
            html += `<div class="gcal-cell gcal-cell--other-month"><span class="gcal-cell__num">${i}</span></div>`;
        }

        grid.innerHTML = html;

        grid.querySelectorAll('.gcal-cell:not(.gcal-cell--other-month)').forEach(el => {
            el.addEventListener('click', () => {
                calSelectedDay = parseInt(el.dataset.day, 10);
                renderMiniCal();
                showDayPanel(calSelectedDay);
                renderDailyNotes();
            });
        });
    }

    // ── Panel del día ─────────────────────────────

    function showDayPanel(day) {
        const panel = document.getElementById('cal-day-panel');
        const titleEl = document.getElementById('cal-day-title');
        const eventsEl = document.getElementById('cal-day-events');
        if (!panel) return;

        const dateStr = `${String(day).padStart(2, '0')}/${String(calMonth).padStart(2, '0')}/${calYear}`;
        titleEl.textContent = `📅 ${dateStr}`;

        const dayEvents = calEvents.filter(ev => {
            const d = new Date(ev.start);
            return d.getFullYear() === calYear && d.getMonth() + 1 === calMonth && d.getDate() === day;
        });

        if (dayEvents.length === 0) {
            eventsEl.innerHTML = '<p style="color:var(--text-muted);font-size:0.82rem;text-align:center;">Sin eventos este día</p>';
        } else {
            eventsEl.innerHTML = dayEvents.map(ev => {
                const startStr = ev.allDay ? '' : formatEventTime(ev.start);
                const endStr = ev.allDay ? '' : formatEventTime(ev.end);
                const timeStr = ev.allDay ? 'Todo el día' : `${startStr} - ${endStr}`;
                const colorCls = ev.color ? ` gcal-event--color-${ev.color}` : '';
                return `<div class="gcal-day-event-item${colorCls}">
                    <span style="flex:1;">
                        <strong style="font-size:0.82rem;">${escapeHtml(ev.title)}</strong>
                        ${formatLocationHtml(ev.location)}
                        <span style="color:var(--text-muted);font-size:0.75rem;display:block;margin-top:2px;">${timeStr}</span>
                    </span>
                    <div style="display:flex; gap:5px;">
                        <button class="gcal-icon-btn cal-event-edit" data-id="${escapeHtml(ev.id)}" title="Editar">✏️</button>
                        <button class="gcal-icon-btn cal-event-del" data-id="${escapeHtml(ev.id)}" title="Eliminar">✕</button>
                    </div>
                </div>`;
            }).join('');

            eventsEl.querySelectorAll('.cal-event-edit').forEach(btn => {
                btn.addEventListener('click', () => {
                    const ev = calEvents.find(e => e.id == btn.dataset.id);
                    if (!ev) return;
                    let type = 'evento';
                    if (ev.color === '11') type = 'tarea';
                    else if (ev.color === 'default' || !ev.isLocal) {
                        // Si es GCal y no es rojo, es una cita
                        type = 'cita';
                        // Nota: por ahora redirigimos al form de evento general si es edición individual
                        type = 'evento';
                    }
                    openModal(type, null, ev);
                });
            });

            eventsEl.querySelectorAll('.cal-event-del').forEach(btn => {
                btn.addEventListener('click', () => deleteCalEvent(btn.dataset.id));
            });
        }

        panel.style.display = 'block';
    }

    function hideDayPanel() {
        const panel = document.getElementById('cal-day-panel');
        if (panel) panel.style.display = 'none';
        calSelectedDay = null;
    }

    document.getElementById('cal-day-close')?.addEventListener('click', () => {
        hideDayPanel();
        renderMainGrid(); // Changed from renderCalendar to renderMainGrid
        renderMiniCal();
        renderDailyNotes();
    });



    function formatEventTime(dateStr) {
        try {
            const d = new Date(dateStr);
            return d.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        } catch { return ''; }
    }

    function formatLocationHtml(location) {
        if (!location) return '';
        let url = location;
        if (!location.startsWith('http')) {
            url = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location)}`;
        }
        return `
            <a href="${url}" target="_blank" class="gcal-loc-link" style="color:var(--accent-light); text-decoration:none; display:inline-flex; align-items:center; gap:4px; font-size:0.75rem; margin-top:4px; padding: 2px 8px; background: rgba(124,58,237,0.15); border-radius: 4px; border: 1px solid rgba(124,58,237,0.3);">
                📍 <strong>Ir a Google Maps</strong>
            </a>`;
    }

    async function deleteCalEvent(eventId) {
        if (!confirm('¿Eliminar este evento?')) return;

        // CHECK SI ES LOCAL (guardado en servidor)
        if (eventId.toString().startsWith('local-')) {
            await deleteExtraEvent(eventId);
            showToast('Extraescolar eliminado ✓');
            await loadCalendarEvents();
            hideDayPanel();
            renderDailyNotes();
            return;
        }

        try {
            await apiPost('calendar_delete', { eventId });
            showToast('Evento eliminado ✓');
            await loadCalendarEvents();
            hideDayPanel();
            renderDailyNotes(); // Actualizar notas después de eliminar evento
        } catch (e) {
            showToast('Error: ' + e.message, 'error');
        }
    }

    // ── Notas importantes del día (Panel derecho) ──

    function renderDailyNotes() {
        const list = document.getElementById('task-list');
        const empty = document.getElementById('task-empty');
        if (!list) return;

        // Si no hay día seleccionado, usamos HOY por defecto para el panel lateral si se desea, 
        // pero el usuario pide "nota del día", así que usamos calSelectedDay o el día actual.
        const day = calSelectedDay || new Date().getDate();

        const dayEvents = calEvents.filter(ev => {
            const d = new Date(ev.start);
            // Comprobamos que sea el día seleccionado Y que el colorId sea "11" (Rojo) o "6" (Amarillo)
            return d.getFullYear() === calYear &&
                d.getMonth() + 1 === calMonth &&
                d.getDate() === day &&
                (ev.color === "11" || ev.color === "6");
        });

        if (dayEvents.length === 0) {
            list.innerHTML = '';
            if (empty) empty.style.display = 'block';
            return;
        }

        if (empty) empty.style.display = 'none';
        list.innerHTML = dayEvents.map(ev => `
            <div class="gcal-day-event-item gcal-event--color-${ev.color}" style="margin-bottom:8px; padding:10px; display:block;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <strong style="font-size:0.85rem; color:#fff;">${escapeHtml(ev.title)}</strong>
                    <div style="display:flex; gap:8px;">
                        <span class="cal-event-edit" data-id="${ev.id}" style="cursor:pointer; font-size:0.75rem; opacity:0.7;">✏️</span>
                        ${ev.allDay ? '' : `<span style="color:rgba(255,255,255,0.7); font-size:0.7rem;">${formatEventTime(ev.start)}</span>`}
                    </div>
                </div>
                ${formatLocationHtml(ev.location)}
            </div>
        `).join('');

        list.querySelectorAll('.cal-event-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const ev = calEvents.find(e => e.id == btn.dataset.id);
                if (ev) openModal('tarea', null, ev);
            });
        });
    }

    // ── Activar vista Calendario ──────────────────

    views['calendario'] = document.getElementById('view-calendario');

    document.querySelectorAll('.nav__btn[data-view="calendario"]').forEach(btn => {
        btn.addEventListener('click', () => {
            switchView('calendario');
            updateMainHeader();
            loadCalendarEvents();
            renderMiniCal();
            renderDailyNotes();
        });
    });

    // Init
    updateMainHeader();
    renderMiniCal();

    // ──────────────────────────────────────────────
    //  Vista: Comunicados (Diario de Naia)
    // ──────────────────────────────────────────────

    const viewComunicados = document.getElementById('view-comunicados');
    const timelineContainer = document.getElementById('comunicados-timeline');
    const btnAddComunicado = document.getElementById('btn-add-comunicado');
    const formComunicado = document.getElementById('form-comunicado');
    const uploadProgress = document.getElementById('comunicado-upload-progress');


    async function loadComunicados() {
        showLoading();
        try {
            const data = await api('getComunicados');
            renderComunicadosTimeline(data);
        } catch (e) {
            showError("No se pudieron cargar los comunicados: " + e.message);
        } finally {
            hideLoading();
        }
    }

    function renderComunicadosTimeline(items) {
        if (!timelineContainer) return;

        if (!items || items.length === 0) {
            timelineContainer.innerHTML = `
                <div style="text-align:center; padding: 40px; color: var(--text-muted);">
                    <div style="font-size:3rem; margin-bottom:10px;">📭</div>
                    <p>Aún no hay comunicados ni notas registradas.</p>
                </div>
            `;
            return;
        }

        // Agrupar por mes/año
        const groups = {};
        items.forEach(item => {
            const d = new Date(item.date);
            const monthName = d.toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
            const monthCap = monthName.charAt(0).toUpperCase() + monthName.slice(1);
            if (!groups[monthCap]) groups[monthCap] = [];
            groups[monthCap].push(item);
        });

        let html = '';

        for (const [month, monthItems] of Object.entries(groups)) {
            // Cabecera de Mes
            html += `
                <div style="margin: 30px 0 15px 0; border-left: 4px solid var(--primary); padding-left: 15px; display: flex; align-items:center; gap: 10px;">
                    <span style="font-size: 1.4rem;">📅</span>
                    <h2 style="margin:0; font-size: 1.25rem; font-weight:700; color: var(--text);">${month}</h2>
                </div>
            `;

            monthItems.forEach(item => {
                const d = new Date(item.date);
                const dateStr = d.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric' });
                const timeStr = d.toLocaleDateString('es-ES', { month: 'short', year: 'numeric' });

                let fileAttachmentHtml = '';
                if (item.fileUrl) {
                    const icon = fileIcon(item.fileType?.toLowerCase() || 'pdf');
                    fileAttachmentHtml = `
                        <div style="margin-top: 15px; background: rgba(255,255,255,0.05); padding: 10px 15px; border-radius: 8px; display:inline-flex; align-items:center; gap:10px; border: 1px solid rgba(255,255,255,0.1);">
                            <span style="font-size:1.5rem;">${icon}</span>
                            <div style="display:flex; flex-direction:column; max-width:200px;">
                                <span style="font-size:0.85rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(item.fileName || 'Documento adjunto')}</span>
                                <a href="${item.fileUrl}" target="_blank" style="font-size:0.75rem; color: var(--accent); text-decoration:none;">🔗 Ver adjunto</a>
                            </div>
                        </div>
                    `;
                }

                html += `
                    <div class="comunicado-card" style="background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); position:relative;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 12px;">
                            <div>
                                <h3 style="margin:0; font-size:1.2rem; color:var(--text);">${escapeHtml(item.title)}</h3>
                                <div style="color: var(--text-muted); font-size: 0.85rem; margin-top: 4px; text-transform: capitalize;">
                                    ${dateStr}
                                </div>
                            </div>
                            <div style="display:flex; gap: 8px;">
                                <button class="btn-icon" onclick="window.gastosNaia.editComunicado('${item.id}')" title="Editar" style="background: rgba(255,255,255,0.05); border:0; color:var(--text-muted); width:32px; height:32px; border-radius:6px; cursor:pointer;">✏️</button>
                                <button class="btn-icon" onclick="window.gastosNaia.deleteComunicado('${item.id}')" title="Eliminar" style="background: rgba(255,255,255,0.05); border:0; color:var(--danger); width:32px; height:32px; border-radius:6px; cursor:pointer;">🗑️</button>
                            </div>
                        </div>
                        
                        ${item.description ? `<div style="color: var(--text); font-size: 0.95rem; line-height: 1.5; white-space: pre-wrap; background: rgba(0,0,0,0.15); padding: 12px; border-radius: 8px;">${escapeHtml(item.description)}</div>` : ''}
                        
                        ${fileAttachmentHtml}
                    </div>
                `;
            });
        }

        timelineContainer.innerHTML = html;
        // Guardar items para edición rápida
        window.gastosNaia.comunicadosItems = items;
    }

    // Exponer funciones globales para los botones onclick
    window.gastosNaia = window.gastosNaia || {};

    window.gastosNaia.editComunicado = function (id) {
        const item = (window.gastosNaia.comunicadosItems || []).find(i => i.id === id);
        if (!item) return;

        // Limpiar y poblar formulario
        formComunicado.reset();
        document.getElementById('comunicado-id').value = item.id;
        document.getElementById('comunicado-date').value = item.date;
        document.getElementById('comunicado-title').value = item.title;
        document.getElementById('comunicado-desc').value = item.description || '';

        // El input de archivo no se puede poblar por seguridad, mostramos aviso si ya tiene uno
        if (item.fileUrl) {
            showToast('Nota: El archivo adjunto se mantendrá si no subes uno nuevo.', 'info');
        }

        document.getElementById('cal-event-form').style.display = 'none';
        document.getElementById('modal-task-form').style.display = 'none';
        document.getElementById('form-cita').style.display = 'none';
        document.getElementById('form-comunicado').style.display = 'block';

        document.getElementById('gcal-modal-title').innerText = "Editar Nota";
        document.getElementById('gcal-modal-title-icon').innerText = "✏️";
        document.getElementById('gcal-modal-overlay').style.display = 'flex';
    };

    window.gastosNaia.deleteComunicado = async function (id) {
        if (!await standardConfirm('¿Borrar esta nota?')) return;

        showLoading();
        try {
            await apiPost('deleteComunicado', { id: id });
            showToast('Comunicado eliminado correctamente');
            loadComunicados();
        } catch (e) {
            showError("No se pudo borrar: " + e.message);
        } finally {
            hideLoading();
        }
    };

    if (btnAddComunicado) {
        btnAddComunicado.onclick = () => {
            formComunicado.reset();
            document.getElementById('comunicado-id').value = '';
            document.getElementById('comunicado-date').valueAsDate = new Date();

            document.getElementById('cal-event-form').style.display = 'none';
            document.getElementById('modal-task-form').style.display = 'none';
            document.getElementById('form-cita').style.display = 'none';
            document.getElementById('form-comunicado').style.display = 'block';

            document.getElementById('gcal-modal-title').innerText = "Nueva Nota / Comunicado";
            document.getElementById('gcal-modal-title-icon').innerText = "📝";
            document.getElementById('gcal-modal-overlay').style.display = 'flex';
        };
    }

    if (formComunicado) {
        formComunicado.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btnSave = document.getElementById('btn-save-comunicado');
            const id = document.getElementById('comunicado-id').value;
            const date = document.getElementById('comunicado-date').value;
            const title = document.getElementById('comunicado-title').value;
            const desc = document.getElementById('comunicado-desc').value;
            const fileInput = document.getElementById('comunicado-file');

            // Bugfix: Si estamos editando y no subimos archivo, queremos mantener el existente
            let existingItem = null;
            if (id) {
                existingItem = (window.gastosNaia.comunicadosItems || []).find(i => i.id === id);
            }

            btnSave.disabled = true;
            btnSave.style.opacity = '0.5';

            let fileUrl = existingItem ? existingItem.fileUrl : null;
            let fileName = existingItem ? existingItem.fileName : null;
            let fileType = existingItem ? existingItem.fileType : null;

            try {
                // 1. Si hay un archivo nuevo, lo subimos
                if (fileInput.files.length > 0) {
                    const file = fileInput.files[0];
                    fileName = file.name;
                    fileType = file.name.split('.').pop();

                    uploadProgress.style.display = 'block';

                    const formData = new FormData();
                    formData.append('file', file);

                    const res = await fetch('?action=uploadComunicado', { method: 'POST', body: formData });
                    const json = await res.json();

                    if (json.error) throw new Error(json.error);
                    fileUrl = json.url;
                }

                // 2. Guardamos todo en Firebase
                uploadProgress.style.display = 'none';

                await apiPost('saveComunicado', {
                    id: id || null,
                    date: date,
                    title: title,
                    description: desc,
                    fileUrl: fileUrl,
                    fileName: fileName,
                    fileType: fileType
                });

                showToast(id ? 'Resumen actualizado correctamente' : '¡Nota guardada con éxito!');
                document.getElementById('gcal-modal-overlay').style.display = 'none';
                loadComunicados();

            } catch (err) {
                showToast('Error: ' + err.message, 'error');
                uploadProgress.style.display = 'none';
            } finally {
                btnSave.disabled = false;
                btnSave.style.opacity = '1';
            }
        });
    }

    // ── Botón IA "Sugerir plan para hoy" en Calendario ──────────────────────
    const btnIaPlan = document.getElementById('btn-ia-plan');
    if (btnIaPlan) {
        btnIaPlan.addEventListener('click', async () => {
            
            // 1. Intentar obtener el tiempo real en Barcelona via wttr.in
            let weatherDesc = null;
            let weatherEmoji = '🌤️';
            try {
                const wRes = await fetch('https://wttr.in/Barcelona?format=j1', { signal: AbortSignal.timeout(5000) });
                if (wRes.ok) {
                    const wData = await wRes.json();
                    const current = wData.current_condition?.[0];
                    if (current) {
                        const tempC = current.temp_C;
                        const desc = current.lang_es?.[0]?.value || current.weatherDesc?.[0]?.value || '';
                        const code = parseInt(current.weatherCode);
                        // Asignar emoji según código de tiempo
                        if ([113].includes(code))               weatherEmoji = '☀️';
                        else if ([116, 119].includes(code))      weatherEmoji = '⛅';
                        else if ([122, 143, 248].includes(code)) weatherEmoji = '☁️';
                        else if ([176,263,266,281,284,293,296,299,302,305,308,311,314,317,320,323,326,329,332,335,338,350,353,356,359,362,365,368,371,374,377].includes(code)) weatherEmoji = '🌧️';
                        else if ([389,392,395].includes(code))   weatherEmoji = '⛈️';
                        weatherDesc = `${weatherEmoji} ${desc}, ${tempC}°C`;
                    }
                }
            } catch (_) { /* Si falla la API de tiempo, continuamos sin él */ }
            
            // 2. Mostrar opciones: clima detectado o manual
            const opcionesClima = {
                'sol': '☀️ Soleado / Buen tiempo',
                'nublado': '⛅ Nublado / Fresco',
                'lluvia': '🌧️ Lluvia / Mal tiempo',
                'calor': '🌡️ Mucho calor',
                'frio': '❄️ Frío intenso',
            };
            
            let climaSeleccionado;
            let presupuestoSeleccionado = 'medio';
            
            const detectMsg = weatherDesc
                ? `<p style="color:rgba(255,255,255,0.7); margin-bottom:1rem;">Tiempo detectado en Barcelona: <b style="color:#fff">${weatherDesc}</b></p>`
                : `<p style="color:rgba(255,255,255,0.6); margin-bottom:1rem; font-size:0.85rem;">No se pudo detectar el tiempo automáticamente.</p>`;

            const selectHtml = `
                ${detectMsg}
                <p style="color:rgba(255,255,255,0.5); font-size:0.8rem; margin-bottom:0.5rem;">¿Con qué tiempo quieres planear?</p>
                <div style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center; margin-bottom:1.2rem;">
                    ${Object.entries(opcionesClima).map(([k, v]) => `
                        <button onclick="this.closest('.swal2-popup').querySelectorAll('.clima-btn').forEach(b=>b.style.background='rgba(255,255,255,0.06)'); this.style.background='rgba(139,92,246,0.35)'; document.getElementById('clima-sel').value='${k}';" 
                            class="clima-btn"
                            style="background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#fff; padding:7px 11px; cursor:pointer; font-size:0.83rem; transition:all 0.15s;">
                            ${v}
                        </button>`).join('')}
                </div>
                <p style="color:rgba(255,255,255,0.5); font-size:0.8rem; margin-bottom:0.5rem;">💰 Presupuesto para el día</p>
                <div style="display:flex; gap:10px; justify-content:center;">
                    <button onclick="this.parentElement.querySelectorAll('button').forEach(b=>{b.style.background='rgba(255,255,255,0.06)';b.style.borderColor='rgba(255,255,255,0.15)'}); this.style.background='rgba(34,197,94,0.25)'; this.style.borderColor='rgba(34,197,94,0.6)'; document.getElementById('presup-sel').value='bajo';"
                        style="flex:1; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#fff; padding:9px; cursor:pointer; font-size:0.88rem; transition:all 0.15s;">
                        💚 Bajo<br><span style="font-size:0.72rem; opacity:0.6;">Gratuito o casi</span>
                    </button>
                    <button onclick="this.parentElement.querySelectorAll('button').forEach(b=>{b.style.background='rgba(255,255,255,0.06)';b.style.borderColor='rgba(255,255,255,0.15)'}); this.style.background='rgba(234,179,8,0.25)'; this.style.borderColor='rgba(234,179,8,0.6)'; document.getElementById('presup-sel').value='medio';"
                        style="flex:1; background:rgba(234,179,8,0.25); border:1px solid rgba(234,179,8,0.6); border-radius:8px; color:#fff; padding:9px; cursor:pointer; font-size:0.88rem; transition:all 0.15s;">
                        🟡 Medio<br><span style="font-size:0.72rem; opacity:0.6;">~20-40€ en total</span>
                    </button>
                    <button onclick="this.parentElement.querySelectorAll('button').forEach(b=>{b.style.background='rgba(255,255,255,0.06)';b.style.borderColor='rgba(255,255,255,0.15)'}); this.style.background='rgba(239,68,68,0.25)'; this.style.borderColor='rgba(239,68,68,0.6)'; document.getElementById('presup-sel').value='alto';"
                        style="flex:1; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.15); border-radius:8px; color:#fff; padding:9px; cursor:pointer; font-size:0.88rem; transition:all 0.15s;">
                        🔴 Alto<br><span style="font-size:0.72rem; opacity:0.6;">Sin límite</span>
                    </button>
                </div>
                <input type="hidden" id="clima-sel" value="">
                <input type="hidden" id="presup-sel" value="medio">
            `;
            
            const { isConfirmed } = await Swal.fire({
                title: '✨ Plan para hoy',
                html: selectHtml,
                background: '#1a1a2e',
                color: '#fff',
                confirmButtonText: 'Generar plan →',
                confirmButtonColor: '#8B5CF6',
                showCancelButton: true,
                cancelButtonText: 'Cancelar',
                preConfirm: () => {
                    const val = document.getElementById('clima-sel')?.value;
                    climaSeleccionado = val || (weatherDesc ? weatherDesc : 'tiempo normal');
                    presupuestoSeleccionado = document.getElementById('presup-sel')?.value || 'medio';
                    return true;
                }
            });
            
            if (!isConfirmed) return;
            
            // Texto legible del clima y presupuesto para el prompt
            const climaTexto = opcionesClima[climaSeleccionado] 
                || (weatherDesc ? weatherDesc : 'tiempo agradable');
            const presupTexto = presupuestoSeleccionado === 'bajo'
                ? 'PRESUPUESTO BAJO: prioriza actividades gratuitas o de muy bajo coste (parques, zonas peatonales, mercados, playas), máximo 10€ por persona en total'
                : presupuestoSeleccionado === 'alto'
                ? 'PRESUPUESTO ALTO: puedes incluir entradas, restaurantes, talleres de pago o experiencias premium'
                : 'PRESUPUESTO MEDIO: mezcla actividades gratuitas con algún pago puntual (una entrada de museo, una merienda), máximo 20-40€ en total';

            
            // 3. Mostrar loading y llamar a la IA
            const hoy = new Date().toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });
            const tematicas = [
                'aventura urbana y exploración de barrios', 
                'cultura y museos interactivos',
                'naturaleza y aire libre',
                'creatividad y manualidades o talleres',
                'deporte y movimiento',
                'gastronomía y descubrimiento de comidas',
                'cine, libros y ocio tranquilo',
                'tecnología y ciencia',
                'historia y monumentos de Barcelona',
                'mercados, escapadas y compras divertidas'
            ];
            const tematica = tematicas[Math.floor(Math.random() * tematicas.length)];
            const prompt = `Actúa como un experto planificador familiar local y práctico. Hoy es ${hoy} en Barcelona. El tiempo que hace es: ${climaTexto}. Diseña un plan ORIGINAL de temática "${tematica}", de 6 horas (aprox 12:00-18:00) para hacer con mi hija Naia de 10 años. REGLAS IMPORTANTES: (1) Todo el plan debe estar concentrado en UNA SOLA ZONA de Barcelona o alrededores (máximo 20km del centro); (2) Los desplazamientos entre actividades deben ser cortos, máximo 10-15 minutos andando o en metro; (3) Adapta al clima: si llueve, interior; si sol, al aire libre; (4) ${presupTexto}; (5) IMPORTANTE: para cada lugar o restaurante que menciones, añade al final del punto el enlace de Google Maps usando este formato exacto: [Ver en Maps](https://www.google.com/maps/search/NOMBRE+DEL+LUGAR+Barcelona). Propón lugares CONCRETOS y conocidos. Incluye una sugerencia para comer/merendar. Formato lista con emojis y horarios. Sin introducción.`;

            Swal.fire({
                title: '⏳ Generando plan...',
                html: `<span style="color:rgba(255,255,255,0.6); font-size:0.9rem;">Adaptando el plan al tiempo de hoy en Barcelona...</span>`,
                allowOutsideClick: false,
                background: '#1a1a2e',
                color: '#fff',
                didOpen: () => { Swal.showLoading(); }
            });

            try {
                const res = await fetch('?action=ai_ask', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ question: prompt })
                });
                if (!res.ok) throw new Error('Error de conexión con la IA');
                const data = await res.json();
                if (data.error) throw new Error(data.error);

                const rawPlan = data.answer;

                // Formato HTML para mostrar en pantalla
                let htmlPlan = rawPlan
                    .replace(/\*\*(.*?)\*\*/g, '<b>$1</b>')
                    .replace(/\[Ver en Maps\]\((https?:\/\/[^\)]+)\)/g, '<a href="$1" target="_blank" style="color:#8B5CF6; font-size:0.8rem;">📍 Ver en Maps</a>')
                    .replace(/\n\n/g, '<br><br>')
                    .replace(/\n/g, '<br>');

                Swal.fire({
                    title: `✨ Plan para hoy · ${climaTexto.split(' ').slice(0,2).join(' ')}`,
                    html: `<div style="text-align:left; font-size:0.93rem; line-height:1.6; color:rgba(255,255,255,0.85); max-height:55vh; overflow-y:auto; padding-right:6px;">${htmlPlan}</div>`,
                    background: '#1a1a2e',
                    color: '#fff',
                    confirmButtonText: '¡Perfecto!',
                    confirmButtonColor: '#8B5CF6',
                    showCancelButton: true,
                    cancelButtonText: '📱 Enviar a Telegram',
                    cancelButtonColor: '#2563EB',
                    width: '580px',
                    reverseButtons: true,
                }).then(async (result) => {
                    if (result.isDismissed && result.dismiss === Swal.DismissReason.cancel) {
                        // Enviar a Telegram
                        try {
                            await fetch('?action=telegram_send_plan', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ plan: rawPlan, clima: climaTexto })
                            });
                            Swal.fire({ icon: 'success', title: '¡Enviado!', text: 'El plan ya está en tu Telegram 📱', background: '#1a1a2e', color: '#fff', timer: 2000, showConfirmButton: false });
                        } catch (e) {
                            Swal.fire({ icon: 'error', title: 'Error', text: 'No se pudo enviar a Telegram', background: '#1a1a2e', color: '#fff' });
                        }
                    }
                });

                // Enviar automáticamente a Telegram también al generar
                fetch('?action=telegram_send_plan', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ plan: rawPlan, clima: climaTexto })
                }).catch(() => {});  // Silencioso si falla

            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: err.message, background: '#1a1a2e', color: '#fff' });
            }
        });
    }

})();
