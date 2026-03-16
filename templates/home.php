<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gastos Naia — Gestión y visualización de gastos">
    <title>Gastos Naia</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="assets/styles.css?v=3.4">
    <script src="assets/app.js?v=3.4" defer></script>
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
</head>

<body>
    <!-- DEBUG SCRIPT: TEMPORAL - will be removed after fixing -->
    <div id="js-debug"
        style="position:fixed;bottom:0;left:0;right:0;background:#ff0055;color:#fff;padding:8px 12px;font-size:12px;z-index:99999;display:none;max-height:200px;overflow-y:auto;white-space:pre-wrap;">
    </div>
    <script>
        window.addEventListener('error', function (e) {
            var d = document.getElementById('js-debug');
            if (d) { d.style.display = 'block'; d.textContent += 'ERROR: ' + e.message + ' (' + e.filename + ':' + e.lineno + ')\n'; }
        });
        window.addEventListener('unhandledrejection', function (e) {
            var d = document.getElementById('js-debug');
            if (d) { d.style.display = 'block'; d.textContent += 'PROMISE ERROR: ' + e.reason + '\n'; }
        });
    </script>

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
                <!-- Dropdown Gastos -->
                <div class="nav-dropdown">
                    <button class="nav__btn nav__btn--dropdown" id="btn-gastos-root" data-view="gastos">
                        <span class="nav__btn-icon">💰</span>
                        <span>Gastos</span>
                        <span class="nav__dropdown-caret">▼</span>
                    </button>
                    <div class="nav-dropdown__content">
                        <button class="nav-dropdown__item" data-view="mensual">
                            <span class="nav__btn-icon">📅</span>
                            <span>Vista Mensual</span>
                        </button>
                        <button class="nav-dropdown__item" data-view="anual">
                            <span class="nav__btn-icon">📊</span>
                            <span>Resumen Anual</span>
                        </button>
                        <button class="nav-dropdown__item" id="btn-new-year" data-view="nuevo-anio">
                            <span class="nav__btn-icon">✨</span>
                            <span>Nuevo Año</span>
                        </button>
                        <hr style="border:none; border-top:1px solid rgba(255,255,255,0.08); margin:4px 0;">
                        <button class="nav-dropdown__item" data-view="ai">
                            <span class="nav__btn-icon">🤖</span>
                            <span>Agente IA Alfred</span>
                        </button>
                    </div>
                </div>

                <!-- Calendario -->
                <button class="nav__btn" data-view="calendario" title="Calendario de Naia">
                    <span class="nav__btn-icon">📅</span>
                    <span>Calendario</span>
                </button>

                <!-- Comunicados -->
                <button class="nav__btn" data-view="comunicados" title="Diario y Comunicados">
                    <span class="nav__btn-icon">📝</span>
                    <span>Diario de Naia</span>
                </button>


                <!-- Salir -->
                <a href="?action=logout" class="nav__btn nav__btn--logout" title="Cerrar sesión" onclick="handleLogout(event); return false;">
                    <span class="nav__btn-icon">🔒</span>
                    <span>Salir</span>
                </a>
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
                    <div class="card__header"
                        style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="card__icon" id="form-icon">➕</span>
                            <h2 class="card__title" id="form-title" style="margin: 0;">Añadir Gasto</h2>
                        </div>
                        <button type="button" class="btn btn--secondary" id="btn-ocr-scan"
                            style="font-size: 0.85rem; padding: 0.4rem 0.8rem; background: linear-gradient(135deg, #8B5CF6 0%, #3B82F6 100%); color: white; border: none;">
                            ✨ Autocompletar con Recibo
                        </button>
                        <input type="file" id="input-ocr-file"
                            accept="image/jpeg, image/png, image/webp, application/pdf" style="display:none;">
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
                            <button type="button" class="btn" id="btn-notify-telegram" title="Avisar por Telegram"
                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background-color: #3b82f6; color: #fff; border: none; border-radius: var(--radius-sm); font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                                <span>📱</span> Avisar Telegram
                            </button>
                        </div>
                    </div>

                    <!-- NUEVO: Bloque de Pensión Alimenticia Editable -->
                    <div
                        style="margin-top: 0.5rem; padding: 1rem 1.2rem; border-radius: var(--radius-sm); background: rgba(0, 196, 179, 0.1); border: 1px dashed rgba(0, 196, 179, 0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <span class="summary-row__label" style="color: #00c4b3; font-size: 0.85rem;">Pensión
                            Alimenticia</span>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="number" id="input-pension" step="0.01" min="0" placeholder="0.00"
                                style="width: 100px; padding: 0.4rem; font-size: 1.1rem; text-align: right; border-radius: var(--radius-sm); border: 1px solid rgba(255,255,255,0.2); background: rgba(0,0,0,0.2); color: white;">
                            <span style="color: #00c4b3; font-size: 1.1rem; margin-right: 0.5rem;">€</span>
                            <button type="button" class="btn" id="btn-save-pension" title="Guardar Pensión"
                                style="padding: 0.4rem 0.8rem; font-size: 0.85rem; background-color: #00c4b3; color: #fff; border: none; border-radius: var(--radius-sm); font-weight: bold; cursor: pointer; display: flex; align-items: center; gap: 0.4rem;">
                                <span>💾</span> Guardar
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


            <!-- ═══ Vista: Nuevo Año ═══ -->
            <section id="section-nuevo-anio" class="view" style="display:none;" data-view="nuevo-anio">
                <div class="card" style="max-width: 480px; margin: 2rem auto;">
                    <div class="card__header">
                        <span class="card__icon">✨</span>
                        <h2 class="card__title">Crear Nuevo Año</h2>
                    </div>
                    <form id="form-new-year" class="form">
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            Escribe el año que quieres generar (ej: 2027). Esto clonará la plantilla maestra, creará la
                            carpeta "Renta 2027" y configurará los 12 meses de recibos.
                        </p>
                        <label class="form__field">
                            <span class="form__label">Año a crear</span>
                            <input type="number" id="input-new-year" class="form__input" required min="2020" max="2100"
                                value="<?= date('Y') + 1 ?>">
                        </label>
                        <div class="form__actions" style="margin-top: 1rem;">
                            <button type="submit" class="btn btn--primary" id="btn-submit-new-year">
                                <span>🚀</span> Generar
                            </button>
                            <button type="button" class="btn btn--ghost" id="btn-cancel-new-year"
                                data-view="gastos">Cancelar</button>
                        </div>
                    </form>

                    <!-- Estado de Carga / Éxito -->
                    <div id="new-year-loading" class="loading" style="display:none; padding: 1.5rem 1rem;">
                        <div class="spinner"></div>
                        <span id="new-year-status-text" style="text-align: center; font-size: 0.9rem;">Clonando
                            plantilla...<br><small style="opacity: 0.6">Esto puede tardar unos 15
                                segundos</small></span>
                    </div>
                    <div id="new-year-result" class="form__result" style="text-align:center;"></div>
                </div>
            </section>

            <!-- ═══ Vista: Agente IA (Alfred) ═══ -->
            <section id="view-ai" class="view" style="display:none;" data-view="ai">
                <div class="card"
                    style="height: calc(100vh - 220px); display: flex; flex-direction: column; padding: 0; overflow: hidden;">
                    <div class="ai-panel__header">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 1.5rem;">🤖</span>
                            <h2 class="card__title" style="margin:0;">Agente IA Alfred</h2>
                        </div>
                        <button class="ai-panel__close" data-view="gastos">×</button>
                    </div>
                    <div id="ai-messages" class="ai-panel__messages" style="flex: 1;">
                        <div class="ai-msg ai-msg--system">
                            ¡Hola! Soy Alfred, tu asistente de IA. Puedo ayudarte a analizar tus gastos, crear
                            presupuestos
                            o responder dudas sobre tus finanzas. ¿En qué puedo ayudarte hoy?
                        </div>
                    </div>
                    <div class="ai-panel__input-area">
                        <form id="ai-form" class="ai-panel__form">
                            <textarea id="ai-input" rows="3" placeholder="Escribe tu consulta aquí..."
                                required></textarea>
                            <button type="submit" class="btn btn--primary" id="ai-submit-btn"
                                style="width: 100%; justify-content: center;">
                                <span class="ai-submit-text">Preguntar a Alfred</span>
                                <span class="ai-submit-loader" hidden>⌛</span>
                            </button>
                        </form>
                    </div>
                </div>
            </section>


            <!-- ═══ Vista: Calendario + Tareas ═══ -->
            <section id="view-calendario" class="view" style="display:none;" data-view="calendario">
                <div class="gcal-layout">

                    <!-- ── Sidebar Izquierdo ── -->
                    <aside class="gcal-sidebar">

                        <!-- Botón + Crear con dropdown -->
                        <div class="gcal-create-wrapper" id="gcal-create-wrapper">
                            <button class="gcal-create-btn" id="gcal-create-btn" type="button">
                                <span class="gcal-create-icon">＋</span>
                                <span>Crear</span>
                                <svg class="gcal-create-caret" viewBox="0 0 24 24" width="18" height="18">
                                    <path fill="currentColor" d="M7 10l5 5 5-5z" />
                                </svg>
                            </button>
                            <div class="gcal-create-dropdown" id="gcal-create-dropdown">
                                <button class="gcal-dropdown-item" id="drop-evento" type="button">
                                    <span>📅</span> Extraescolares
                                </button>
                                <button class="gcal-dropdown-item" id="drop-visita" type="button">
                                    <span>🟠</span> Citas / Visitas de Naia
                                </button>
                                <button class="gcal-dropdown-item" id="drop-cita" type="button">
                                    <span>🕒</span> Agenda de citas
                                </button>
                                <button class="gcal-dropdown-item" id="drop-tarea" type="button">
                                    <span>📋</span> Notas importantes
                                </button>
                            </div>
                        </div>

                        <!-- Botón IA (Varita Mágica) -->
                        <div style="margin-top: 1.2rem;">
                            <button id="btn-ia-plan" type="button" style="
                                width: 100%;
                                display: flex;
                                align-items: center;
                                gap: 0.5rem;
                                background: rgba(139, 92, 246, 0.08);
                                border: 1px solid rgba(139, 92, 246, 0.35);
                                border-radius: 10px;
                                color: rgba(200, 180, 255, 0.9);
                                font-size: 0.82rem;
                                font-weight: 500;
                                letter-spacing: 0.03em;
                                padding: 0.65rem 0.9rem;
                                cursor: pointer;
                                transition: all 0.2s ease;
                            "
                            onmouseover="this.style.background='rgba(139,92,246,0.18)'; this.style.borderColor='rgba(139,92,246,0.6)';"
                            onmouseout="this.style.background='rgba(139,92,246,0.08)'; this.style.borderColor='rgba(139,92,246,0.35)';">
                                <span style="font-size:1rem;">✨</span>
                                <span>Sugerir plan para hoy</span>
                            </button>
                        </div>

                        <!-- Mini-calendar -->
                        <div class="gcal-mini-cal">
                            <div class="gcal-mini-header">
                                <button class="gcal-mini-nav" id="mini-prev">‹</button>
                                <span id="mini-month-label"></span>
                                <button class="gcal-mini-nav" id="mini-next">›</button>
                            </div>
                            <div class="gcal-mini-weekdays">
                                <span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span><span>D</span>
                            </div>
                            <div id="mini-cal-grid" class="gcal-mini-grid"></div>
                        </div>

                        <!-- Lista de calendarios -->
                        <div class="gcal-sidebar-section">
                            <p class="gcal-sidebar-title">Leyenda de colores</p>
                            <label class="gcal-cal-item">
                                <span class="gcal-cal-dot" style="background:#0b8043;"></span>
                                Extraescolares
                            </label>
                            <label class="gcal-cal-item">
                                <span class="gcal-cal-dot" style="background:#eab308;"></span>
                                Visitas de Naia
                            </label>
                            <label class="gcal-cal-item">
                                <span class="gcal-cal-dot" style="background:#7c3aed;"></span>
                                Agenda de citas
                            </label>
                            <label class="gcal-cal-item">
                                <span class="gcal-cal-dot" style="background:#d50000;"></span>
                                Notas importantes
                            </label>
                        </div>
                    </aside>

                    <!-- ── Panel Central: Calendario ── -->
                    <div class="gcal-main">
                        <!-- Header del mes -->
                        <div class="gcal-main-header">
                            <div class="gcal-nav-group">
                                <button class="gcal-nav-btn" id="cal-prev" title="Mes anterior">‹</button>
                                <button class="gcal-nav-btn" id="cal-today" title="Hoy">Hoy</button>
                                <button class="gcal-nav-btn" id="cal-next" title="Mes siguiente">›</button>
                            </div>
                            <h2 id="cal-month-label" class="gcal-month-title"></h2>
                        </div>

                        <!-- Grid -->
                        <div class="gcal-weekdays-row">
                            <span>LUN</span><span>MAR</span><span>MIÉ</span>
                            <span>JUE</span><span>VIE</span><span>SÁB</span><span>DOM</span>
                        </div>
                        <div id="cal-grid" class="gcal-grid"></div>

                        <!-- Panel día seleccionado -->
                        <div id="cal-day-panel" class="gcal-day-panel" style="display:none;">
                            <div class="gcal-day-panel__header">
                                <span id="cal-day-title"></span>
                                <button id="cal-day-close" class="gcal-icon-btn" title="Cerrar">✕</button>
                            </div>
                            <div id="cal-day-events" class="gcal-day-events"></div>

                        </div>
                    </div>

                    <!-- ── Panel Derecho: Tareas ── -->
                    <aside class="gcal-tasks-panel">
                        <div class="gcal-tasks-header">
                            <span>📋</span>
                            <h3>Notas importantes</h3>
                        </div>
                        <div id="task-list" class="gcal-task-list"></div>
                        <p id="task-empty" class="gcal-task-empty"
                            style="display:block; text-align:center; padding:20px; color:var(--text-muted); font-size:0.85rem;">
                            Hoy no hay notas importantes ✨
                        </p>
                    </aside>

                </div>
            </section>

            <!-- ═══ Vista: Comunicados ═══ -->
            <section id="view-comunicados" class="view" style="display:none; padding: 20px;">
                <div class="comunicados-header"
                    style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                    <h2 class="view-title"
                        style="margin:0; font-size:1.5rem; color:var(--text); display:flex; align-items:center; gap:10px;">
                        <span style="font-size:1.8rem;">📝</span> Diario de Naia
                    </h2>
                    <button id="btn-add-comunicado" class="btn btn--primary"
                        style="display:flex; align-items:center; gap:8px;">
                        <span>➕</span> Nuevo Comunicado
                    </button>
                </div>

                <div id="comunicados-timeline" class="comunicados-timeline"
                    style="max-width: 800px; margin: 0 auto; position:relative;">
                    <!-- Las tarjetas se inyectarán aquí vía JS -->
                </div>
            </section>


            <!-- Modal Nuevo Evento / Tarea (se muestra como popup centrado) -->
            <div id="gcal-modal-overlay" class="gcal-modal-overlay" style="display:none;">
                <div class="gcal-modal" id="gcal-modal">
                    <div class="gcal-modal__header">
                        <span id="gcal-modal-title-icon">📅</span>
                        <h3 id="gcal-modal-title">Extraescolares</h3>
                        <button id="gcal-modal-close" class="gcal-icon-btn">✕</button>
                    </div>

                    <!-- FORM EVENTO -->
                    <form id="cal-event-form" class="form" style="display:none;">
                        <label class="form__field">
                            <span class="form__label">Título</span>
                            <input type="text" id="cal-event-title" class="form__input" placeholder="Añade un título"
                                required>
                        </label>
                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">📍 Ubicación</span>
                            <input type="text" id="cal-event-location" class="form__input"
                                placeholder="Lugar o dirección">
                        </label>
                        <div class="form__row" style="margin-top:10px;">
                            <label class="form__field">
                                <span class="form__label">Fecha inicio</span>
                                <input type="date" id="cal-event-start" class="form__input" required>
                            </label>
                            <label class="form__field">
                                <span class="form__label">Fecha fin</span>
                                <input type="date" id="cal-event-end" class="form__input">
                            </label>
                        </div>
                        <label class="form__field" style="margin-top:10px; display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" id="cal-event-allday" style="width:18px;height:18px;">
                            <span class="form__label" style="margin:0; cursor:pointer;">Todo el día</span>
                        </label>
                        <div id="cal-event-time-row" class="form__row" style="margin-top:10px; display:grid;">
                            <label class="form__field">
                                <span class="form__label">Hora inicio</span>
                                <input type="time" id="cal-event-time-start" class="form__input" value="09:00">
                            </label>
                            <label class="form__field">
                                <span class="form__label">Hora fin</span>
                                <input type="time" id="cal-event-time-end" class="form__input" value="10:00">
                            </label>
                        </div>
                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">Descripción (opcional)</span>
                            <input type="text" id="cal-event-desc" class="form__input" placeholder="Notas adicionales">
                        </label>

                        <!-- Sección Repetir -->
                        <div class="gcal-repeat-section" style="margin-top:14px;">
                            <label class="gcal-repeat-toggle">
                                <input type="checkbox" id="cal-event-repeat">
                                <span class="form__label" style="margin:0; cursor:pointer;">🔁 Repetir cada
                                    semana</span>
                            </label>

                            <div id="cal-repeat-options"
                                style="display:none; margin-top:12px; padding:12px; background:rgba(124,58,237,0.07); border-radius:8px; border:1px solid rgba(124,58,237,0.2);">
                                <!-- Frecuencia (solo Visitas) -->
                                <div id="cal-frecuencia-container" style="display:none; margin-bottom:14px;">
                                    <p class="form__label" style="margin-bottom:8px; letter-spacing:0.8px; font-size:0.75rem;">FRECUENCIA:</p>
                                    <div style="display:flex; align-items:center; gap:12px;">
                                        <button type="button" id="btn-freq-minus" style="width:36px;height:36px;border-radius:8px;border:none;background:rgba(255,255,255,0.07);color:#eab308;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">−</button>
                                        <span id="cal-freq-label" style="color:#fff;font-weight:600;font-size:0.9rem;white-space:nowrap;">Cada semana</span>
                                        <button type="button" id="btn-freq-plus" style="width:36px;height:36px;border-radius:8px;border:none;background:rgba(255,255,255,0.07);color:#eab308;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">+</button>
                                    </div>
                                </div>
                                <p class="form__label" style="margin-bottom:8px;" id="cal-wd-label">Días de la semana:</p>
                                <div class="gcal-weekday-picker">
                                    <label class="gcal-wd-btn"><input type="checkbox" value="1"> Lun</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="2"> Mar</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="3"> Mié</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="4"> Jue</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="5"> Vie</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="6"> Sáb</label>
                                    <label class="gcal-wd-btn"><input type="checkbox" value="0"> Dom</label>
                                </div>
                                <label class="form__field" style="margin-top:10px;">
                                    <span class="form__label">Repetir hasta</span>
                                    <input type="date" id="cal-repeat-until" class="form__input">
                                </label>
                                <p id="cal-repeat-preview"
                                    style="margin-top:8px; font-size:0.75rem; color:var(--accent-light); min-height:1.2em;">
                                </p>
                            </div>
                        </div>

                        <div class="form__actions" style="margin-top:1.5rem;">
                            <button type="submit" class="btn btn--primary" id="btn-save-event">💾 Guardar</button>
                            <button type="button" class="btn btn--ghost" id="btn-cancel-event">Cancelar</button>
                        </div>
                        <div id="cal-event-result" class="form__result"></div>

                    </form>

                    <!-- FORM NOTAS IMPORTANTES (desde modal) -->
                    <form id="modal-task-form" class="form" style="display:none;">
                        <label class="form__field">
                            <span class="form__label">Contenido de la nota</span>
                            <input type="text" id="modal-task-title" class="form__input"
                                placeholder="¿Qué necesitas recordar?" required>
                        </label>
                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">📍 Ubicación</span>
                            <input type="text" id="modal-task-location" class="form__input"
                                placeholder="Dirección o lugar">
                        </label>

                        <div class="form__row"
                            style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                            <label class="form__field">
                                <span class="form__label">Fecha</span>
                                <input type="date" id="modal-task-date" class="form__input" required>
                            </label>
                            <label class="form__field">
                                <span class="form__label">¿Todo el día?</span>
                                <div style="display: flex; align-items: center; height: 38px;">
                                    <input type="checkbox" id="modal-task-allday" checked
                                        style="width: 20px; height: 20px;">
                                </div>
                            </label>
                        </div>

                        <div id="modal-task-time-row" class="form__row"
                            style="display: none; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px;">
                            <label class="form__field">
                                <span class="form__label">Hora inicio</span>
                                <input type="time" id="modal-task-time-start" class="form__input" value="09:00">
                            </label>
                            <label class="form__field">
                                <span class="form__label">Hora fin</span>
                                <input type="time" id="modal-task-time-end" class="form__input" value="10:00">
                            </label>
                        </div>

                        <div class="form__actions" style="margin-top:1.5rem;">
                            <button type="submit" class="btn btn--primary" id="btn-save-modal-task">📋 Guardar
                                nota</button>
                            <button type="button" class="btn btn--ghost" id="btn-cancel-modal-task">Cancelar</button>
                        </div>
                    </form>

                    <!-- FORM AGENDA DE CITAS -->
                    <form id="form-cita" class="form" style="display:none;">
                        <label class="form__field">
                            <span class="form__label">Título de los bloques</span>
                            <input type="text" id="cita-title" class="form__input"
                                placeholder="Ej: Consulta, Clase, Estudio..." required>
                        </label>
                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">📍 Ubicación</span>
                            <input type="text" id="cita-location" class="form__input"
                                placeholder="Dirección para los bloques">
                        </label>
                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">Fecha</span>
                            <input type="date" id="cita-date" class="form__input" required>
                        </label>
                        <div class="form__row" style="margin-top:10px;">
                            <label class="form__field">
                                <span class="form__label">Desde</span>
                                <input type="time" id="cita-time-start" class="form__input" value="10:00">
                            </label>
                            <label class="form__field">
                                <span class="form__label">Hasta</span>
                                <input type="time" id="cita-time-end" class="form__input" value="12:00">
                            </label>
                        </div>

                        <div class="form__actions" style="margin-top:1.5rem;">
                            <button type="submit" class="btn btn--primary" id="btn-save-cita">🕒 Generar
                                Agenda</button>
                            <button type="button" class="btn btn--ghost" id="btn-cancel-cita">Cancelar</button>
                        </div>
                        <div id="cita-result" class="form__result"></div>
                    </form>

                    <!-- FORM COMUNICADOS -->
                    <form id="form-comunicado" class="form" style="display:none;" enctype="multipart/form-data">
                        <input type="hidden" id="comunicado-id">
                        <label class="form__field">
                            <span class="form__label">Fecha</span>
                            <input type="date" id="comunicado-date" class="form__input" required>
                        </label>

                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">Título (Ej: Visita al Pediatra)</span>
                            <input type="text" id="comunicado-title" class="form__input" placeholder="¿De qué trata?"
                                required>
                        </label>

                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">Detalles / Notas</span>
                            <textarea id="comunicado-desc" class="form__input" rows="4"
                                placeholder="Escribe aquí todo lo importante..."></textarea>
                        </label>

                        <label class="form__field" style="margin-top:10px;">
                            <span class="form__label">Archivo adjunto (Receta, PDF notas...)</span>
                            <input type="file" id="comunicado-file" class="form__input" accept="image/*,.pdf"
                                style="padding:4px;">
                        </label>

                        <!-- Barra de progreso para subida -->
                        <div id="comunicado-upload-progress"
                            style="display:none; margin-top:10px; font-size:0.85rem; color:var(--text-muted); text-align:center;">
                            <div class="spinner"
                                style="width:16px;height:16px;display:inline-block;vertical-align:middle;margin-right:5px;">
                            </div>
                            <span id="comunicado-progress-text">Subiendo archivo a Google Drive...</span>
                        </div>

                        <div class="form__actions" style="margin-top:1.5rem;">
                            <button type="submit" id="btn-save-comunicado" class="btn btn--primary" style="width:100%;">
                                <span>💾</span> Guardar Comunicado
                            </button>
                        </div>
                    </form>

                </div>
            </div>
            </section>

        </main>

        <!-- Footer -->
        <footer class="footer">
            Gastos Naia · Sincronizado con Google Sheets
        </footer>
    </div>

</body>

</html>