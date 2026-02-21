<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gastos Naia — Gestión y visualización de gastos">
    <title>Gastos Naia</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
</head>

<body>

    <!-- ── Header (WordPress Style TopBar) ── -->
    <header class="header">
        <div class="header__inner">
            <div class="header__brand">
                <span class="header__icon">💰</span>
                <h1 class="header__title">Gastos Naia</h1>
            </div>
            <p class="header__subtitle">Gestión de gastos · Sincronizado con Google Sheets</p>

            <!-- ── Navegación (Banner Web) ── -->
            <nav class="nav-banner">
                <button class="nav__btn nav__btn--active" data-view="gastos">
                    <span class="nav__btn-icon">📝</span>
                    <span>Gastos</span>
                </button>
                <button class="nav__btn" data-view="mensual">
                    <span class="nav__btn-icon">📅</span>
                    <span>Vista Mensual</span>
                </button>
                <button class="nav__btn" data-view="anual">
                    <span class="nav__btn-icon">📊</span>
                    <span>Resumen Anual</span>
                </button>
            </nav>
        </div>
    </header>

    <div class="app">

        <!-- ── Contenido principal ── -->
        <main class="main">

            <!-- Loading -->
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <span>Cargando datos…</span>
            </div>

            <!-- Error -->
            <div id="error-container"></div>

            <!-- ═══ Vista: Resumen Anual ═══ -->
            <section id="view-anual" class="view" style="display:none;">
                <div class="dashboard-grid">
                    <div class="card">
                        <div class="card__header">
                            <span class="card__icon">📋</span>
                            <h2 class="card__title">Gastos por Año</h2>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Año</th>
                                        <th class="text-right">Gasto Total</th>
                                    </tr>
                                </thead>
                                <tbody id="annual-tbody"></tbody>
                            </table>
                        </div>
                        <div class="summary-row">
                            <span class="summary-row__label">Total acumulado</span>
                            <span class="summary-row__value" id="annual-total">—</span>
                        </div>
                    </div>
                    <div class="card chart-card">
                        <div class="card__header">
                            <span class="card__icon">📈</span>
                            <h2 class="card__title">Evolución Anual</h2>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="chart-annual"></canvas>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ═══ Vista: Mensual ═══ -->
            <section id="view-mensual" class="view" style="display:none;">
                <div class="view-controls">
                    <label class="select-label">
                        <span>Año</span>
                        <select id="select-year-monthly" class="select-input">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="card">
                    <div class="card__header">
                        <span class="card__icon">📅</span>
                        <h2 class="card__title">Gastos Mensuales <span id="monthly-year-label"></span></h2>
                    </div>
                    <div class="chart-wrapper chart-wrapper--wide">
                        <canvas id="chart-monthly"></canvas>
                    </div>
                </div>
                <div class="months-grid" id="months-grid"></div>
            </section>

            <!-- ═══ Vista: Gastos de un mes ═══ -->
            <section id="view-gastos" class="view" style="display:none;">
                <div class="view-controls">
                    <label class="select-label">
                        <span>Año</span>
                        <select id="select-year-expense" class="select-input">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="select-label">
                        <span>Mes</span>
                        <select id="select-month-expense" class="select-input">
                            <?php foreach ($monthLabels as $m => $label): ?>
                                <option value="<?= $m ?>" <?= $m == date('n') ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="btn btn--primary" id="btn-load-expenses">
                        <span>🔍</span> Ver Gastos
                    </button>
                </div>

                <!-- Formulario añadir/editar gasto -->
                <div class="card" id="add-expense-card">
                    <div class="card__header">
                        <span class="card__icon" id="form-icon">➕</span>
                        <h2 class="card__title" id="form-title">Añadir Gasto</h2>
                    </div>
                    <form id="form-add-expense" class="form">
                        <input type="hidden" id="input-row" value="">
                        <div class="form__row">
                            <label class="form__field">
                                <span class="form__label">Fecha</span>
                                <input type="date" id="input-date" class="form__input" required>
                            </label>
                            <label class="form__field">
                                <span class="form__label">Monto (€)</span>
                                <input type="number" id="input-amount" class="form__input" step="0.01" min="0"
                                    placeholder="0,00" required>
                            </label>
                        </div>
                        <label class="form__field">
                            <span class="form__label">Descripción</span>
                            <input type="text" id="input-description" class="form__input"
                                placeholder="Ej: Comedor, Teatro…" required>
                        </label>

                        <div class="form__actions" style="margin-top: 1.5rem;">
                            <button type="submit" class="btn btn--primary" id="btn-add-expense">
                                <span>💾</span> <span id="btn-submit-text">Guardar</span>
                            </button>
                            <button type="button" class="btn btn--ghost" id="btn-cancel-edit" style="display:none;">
                                Cancelar
                            </button>
                        </div>
                        <div id="add-result" class="form__result"></div>

                        <!-- Adjuntar Recibos (Independiente) -->
                        <div class="form__field"
                            style="margin-top: 2rem; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 1.5rem;">
                            <span class="form__label">Adjuntar Recibos</span>
                            <p
                                style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 0.8rem; margin-top: -0.2rem;">
                                Sube recibos individuales o múltiples. (Se archivan automáticamente en Google Drive).
                            </p>
                            <div class="upload-zone" id="upload-zone"
                                style="margin-top: 0.5rem; padding: 1.5rem; border-color: rgba(255,255,255,0.06); background: rgba(0,0,0,0.15);">
                                <div class="upload-zone__content">
                                    <div
                                        style="display: flex; gap: 1rem; justify-content: center; margin-bottom: 0.8rem;">
                                        <button type="button" class="btn btn--ghost"
                                            onclick="document.getElementById('file-input').click()"
                                            style="padding: 0.5rem 1rem;">
                                            📁 Subir Archivo
                                        </button>
                                        <button type="button" class="btn btn--primary"
                                            onclick="document.getElementById('file-input-camera').click()"
                                            style="padding: 0.5rem 1rem;">
                                            📸 Hacer Foto
                                        </button>
                                    </div>
                                    <p style="margin: 0; font-size: 0.85rem; color: var(--text-muted);">Arrastra
                                        archivos o pulsa los botones para subirlos al instante</p>
                                </div>
                                <!-- File input stándar -->
                                <input type="file" id="file-input" class="upload-zone__input"
                                    accept=".pdf, .doc, .docx, .xls, .xlsx, .txt, image/*" multiple>
                                <!-- Camera input -->
                                <input type="file" id="file-input-camera" class="upload-zone__input" accept="image/*"
                                    capture="environment">
                            </div>
                            <div id="upload-result" class="form__result"></div>
                        </div>
                    </form>
                </div>

                <!-- Tabla de gastos -->
                <div class="card" id="expenses-card" style="display:none;">
                    <div class="card__header">
                        <span class="card__icon">📝</span>
                        <h2 class="card__title">Gastos de <span id="expense-month-label"></span></h2>
                    </div>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Descripción</th>
                                    <th class="text-right">Monto</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="expenses-tbody"></tbody>
                        </table>
                    </div>
                    <div class="summary-row">
                        <span class="summary-row__label">Total del mes</span>
                        <span class="summary-row__value" id="expense-month-total">—</span>
                    </div>
                    <div
                        style="margin-top: 1.5rem; padding: 1rem 1.2rem; border-radius: var(--radius-sm); background: rgba(168, 85, 247, 0.1); border: 1px dashed rgba(168, 85, 247, 0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <span class="summary-row__label" style="color: var(--accent2); font-size: 0.85rem;">Cantidad a
                            entregar (Mitad)</span>
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <span class="summary-row__value" id="expense-month-half-total"
                                style="background: none; -webkit-text-fill-color: var(--accent2); color: var(--accent2); font-size: 1.4rem;">—</span>
                            <button type="button" class="btn" id="btn-pay-bizum" title="Pagar con Bizum"
                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background-color: #00c4b3; color: #fff; border: none; border-radius: var(--radius-sm); font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                                <svg style="width: 16px; height: 16px; fill: currentColor;" viewBox="0 0 24 24">
                                    <path
                                        d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h2.73c.12.98.96 1.46 2.05 1.46 1.24 0 1.95-.61 1.95-1.49 0-.85-.49-1.39-2.32-1.85-2.22-.56-3.51-1.44-3.51-3.27 0-1.63 1.29-2.73 2.92-3.11V4.5h2.67v1.95c1.46.32 2.7 1.29 2.85 2.95h-2.65c-.15-.81-.88-1.27-1.86-1.27-1.07 0-1.83.56-1.83 1.41 0 .88.58 1.34 2.41 1.83 2.15.54 3.42 1.49 3.42 3.29 0 1.76-1.34 2.9-3.09 3.43z" />
                                </svg> Bizum
                            </button>
                        </div>
                    </div>

                    <div style="margin-top: 1rem;">
                        <button type="button" class="btn btn--secondary" id="btn-send-email"
                            style="width: 100%; display: flex; justify-content: center; gap: 0.5rem; align-items: center; border-color: rgba(255,255,255,0.1);">
                            <span style="font-size: 1.2rem;">📧</span> Enviar Resumen
                        </button>
                    </div>
                </div>

                <!-- Lista de recibos subidos -->
                <div class="card" id="files-card">
                    <div class="card__header">
                        <span class="card__icon">📎</span>
                        <h2 class="card__title">Recibos subidos <span id="files-month-label"
                                style="opacity:0.6; font-size:0.9em;"></span></h2>
                    </div>
                    <div id="files-list" class="files-list"></div>
                </div>
            </section>

        </main>

        <!-- Footer -->
        <footer class="footer">
            Gastos Naia · Sincronizado con Google Sheets
        </footer>
    </div>

    <script src="assets/app.js"></script>
</body>

</html>