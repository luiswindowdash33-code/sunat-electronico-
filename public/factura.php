<?php
// public/factura.php

// 1. Limpiar cualquier salida previa
if (ob_get_level()) ob_end_clean();

// 2. Configurar cabeceras
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

// 3. Manejar peticiones OPTIONS (pre-flight)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 4. Configurar manejo de errores
ini_set('display_errors', 0); // No mostrar errores en la salida
error_reporting(0);
ini_set('log_errors', 1); // Guardar errores en el log del servidor

// 5. Incluir el procesador y ejecutar la lógica
try {
    require_once __DIR__ . '/procesador.php';
    $resultado = procesarFactura();
    
    // 6. Enviar la respuesta JSON
    http_response_code($resultado['statusCode']);
    echo json_encode($resultado['data']);

} catch (Throwable $e) {
    // Captura de emergencia por si 'procesador.php' no se puede cargar
    error_log("💥 ERROR CRÍTICO en factura.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'estado_sunat' => 'ERROR_FATAL',
        'mensaje_sunat' => 'No se pudo cargar el motor de facturación.',
        'raw_error' => $e->getMessage()
    ]);
}

exit; // Terminar la ejecución explícitamente
?>
