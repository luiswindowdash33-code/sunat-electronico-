<?php
// public/factura.php
// Este archivo ahora actúa como un simple "controlador" o "router".

// 1. Configurar el entorno para asegurar que no haya salidas de error inesperadas.
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);
if (ob_get_level()) ob_end_clean();

// 2. Establecer cabeceras CORS y de tipo de contenido.
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

// 3. Manejar solicitudes OPTIONS (pre-flight).
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 4. Incluir el archivo que contiene toda la lógica de negocio.
// Se usa __DIR__ para garantizar que la ruta sea siempre correcta.
require_once __DIR__ . '/../src/procesador.php';

// 5. Ejecutar el procesador y obtener la respuesta.
// Toda la lógica compleja está ahora dentro de la función `procesarFactura()`.
$respuesta = procesarFactura();

// 6. Enviar la respuesta JSON y terminar.
// La función `send_json_response` (dentro de procesador.php) se encarga de esto.
send_json_response($respuesta['data'], $respuesta['statusCode']);
?>
