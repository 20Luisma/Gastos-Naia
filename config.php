<?php
/**
 * Configuración del Dashboard de Gastos Naia
 * 
 * INSTRUCCIONES:
 * 1. Reemplaza cada 'SPREADSHEET_ID_XXXX' con el ID real de tu Google Sheet.
 *    El ID se encuentra en la URL: https://docs.google.com/spreadsheets/d/AQUI_ESTA_EL_ID/edit
 * 
 * 2. Coloca el JSON del Service Account en credentials/service-account.json
 * 
 * 3. Comparte cada spreadsheet con el email del Service Account (permiso EDITOR)
 */

return [
    // ── Credenciales ──
    'credentials_path' => __DIR__ . '/credentials/service-account.json',

    // ── Nombres de hojas ──
    'sheet_anual' => 'Gastos Anual',
    'search_range' => 'A1:Z200',
    'search_text' => 'Total Final:',

    // ── Nombres de meses (deben coincidir con los nombres de hoja) ──
    'months' => [
        1 => 'Gastos Enero',
        2 => 'Gastos Febrero',
        3 => 'Gastos Marzo',
        4 => 'Gastos Abril',
        5 => 'Gastos Mayo',
        6 => 'Gastos Junio',
        7 => 'Gastos Julio',
        8 => 'Gastos Agosto',
        9 => 'Gastos Septiembre',
        10 => 'Gastos Octubre',
        11 => 'Gastos Noviembre',
        12 => 'Gastos Diciembre',
    ],

    // ── Nombres cortos para UI ──
    'month_labels' => [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ],

    // ── Columnas en cada hoja mensual ──
    // A = Fecha, B = Descripción, C = Monto
    'expense_columns' => 'A:C',
    'expense_start_row' => 2,  // fila 1 es cabecera

    // ── Automatización Ejercicios ──
    // El ID de la hoja de cálculo limpia (sin datos) que se usará como molde al crear un nuevo año
    'template_spreadsheet_id' => '1AmJuPthX2wzv0KkQhSAjDP3AhDQVBRz5vhcKZU6yKIM',
    // La carpeta "Naia General" en Drive donde se crearán las subcarpetas de años y se guardarán copiados los Sheets
    'root_drive_folder_id' => '1n9Tu-GyUglfBhfdThNHR7LV833y02GVp',

    // ── Spreadsheets por año ──
    'spreadsheets' => [
        2020 => '117tfi_rAuW8sFwNf5uhDxKTbmSJcDRGD2lZ8b2YCJ0M',
        2021 => '1OhF_hqyOvfSBYee9WStG9pqr9beeKmba07x-S0OY7tw',
        2022 => '1DWPe-8w9No-CcVk7AWhwsRoSM2sPLUHIralVforf0U4',
        2023 => '1sCO2-tBFokkaHZQqhtyNBWaAHNpCLyb2188DHEnTe7E',
        2024 => '1Cqfq7MSUvNH55dhgYWWbZfhcmr51rI7BbGodlOH_i74',
        2025 => '1otkT6ikYD0sMUMOngMD3TrNmxoenMoOOg-YcQaXJM-M',
        2026 => '1J_Y63fBRbN25W3EvRGzSO2skKwa5vgJPbZSAU7NUngU',
    ],

    // ── Uploads locales (Legacy) ──
    'uploads_path' => __DIR__ . '/uploads',
    'max_file_size' => 10 * 1024 * 1024,  // 10 MB
    'allowed_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp'],

    // ── Google Drive Folders (Carpetas "Renta YYYY") ──
    'drive_folders' => [
        2020 => '1t0RdjIC0AYH063yIUwMWesv1tMj1B8-e',
        2021 => '1HRyl9O9EySKnjYwjS8vh5GOSwCFV9eKn',
        2022 => '1-Fe2qQQTo-tvxi3ttKoeCM_9W2iZgODP',
        2023 => '1EtO7td5mW2fdRAhEnP3TzFzmnHn2St0d',
        2024 => '131jsC1IWbTLR2c3uVlQLYISDr9hEwzqo',
        2025 => '1gmfqyoQr7Fx-J1Rz76Lo0NGq_qTHt9Uj',
        2026 => '1CNY-mYwrG1HCgwSQde_P8sOVt6nWum6e',
    ],
];
