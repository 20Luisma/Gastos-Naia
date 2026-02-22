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
    };

    function switchView(name) {
        clearError();
        Object.entries(views).forEach(([key, el]) => {
            if (el) el.style.display = key === name ? 'block' : 'none';
        });
        document.querySelectorAll('.nav__btn').forEach(btn => {
            btn.classList.toggle('nav__btn--active', btn.dataset.view === name);
        });

        if (name === 'anual') loadAnnual();
        if (name === 'mensual') loadMonthly();
        if (name === 'gastos') loadExpenses();
    }

    document.querySelectorAll('.nav__btn').forEach(btn => {
        btn.addEventListener('click', () => switchView(btn.dataset.view));
    });

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

    // Bizum Payment Button
    const $btnPayBizum = document.getElementById('btn-pay-bizum');
    if ($btnPayBizum) {
        $btnPayBizum.addEventListener('click', () => {
            if (!currentExpensesData || !currentExpensesData.expenses || currentExpensesData.expenses.length === 0) {
                showToast('No hay gastos en este mes para pagar.', 'error');
                return;
            }

            let total = 0;
            currentExpensesData.expenses.forEach(e => {
                let amt = 0;
                if (typeof e.amount === 'number') amt = e.amount;
                else {
                    let cleaned = e.amount.replace(/[€\s]/g, '').trim();
                    if (cleaned.includes(',') && !cleaned.includes('.')) cleaned = cleaned.replace(',', '.');
                    else if (cleaned.includes('.') && cleaned.includes(',')) cleaned = cleaned.replace(/\./g, '').replace(',', '.');
                    amt = parseFloat(cleaned) || 0;
                }
                total += amt;
            });

            const half = (total / 2).toFixed(2);

            // Fallback para HTTP donde navigator.clipboard a menudo falla en móvil
            const fallbackCopy = (text) => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed'; // Evitar scroll
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    showToast(`Cantidad copiada: ${text}€`, 'success');
                } catch (err) {
                    showToast(`Cantidad a pagar: ${text}€`, 'success');
                }
                document.body.removeChild(ta);
            };

            // Copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(half).then(() => {
                    showToast(`Cantidad copiada: ${half}€`, 'success');
                }).catch(() => {
                    fallbackCopy(half);
                });
            } else {
                fallbackCopy(half);
            }
        });
    }

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
        if (!confirm('¿Estás seguro de que quieres eliminar este gasto permanentemente de Google Sheets?')) return;

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

})();
