<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__.'/vendor/autoload.php';
echo "Autoload cargado. \n";
$config = require __DIR__.'/config.php';
echo "Config cargado. \n";
use GastosNaia\SheetsService;
$s = new SheetsService($config);
echo "Servicio instanciado. \n";
