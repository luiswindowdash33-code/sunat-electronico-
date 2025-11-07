<?php
// public/index.php
// Punto de entrada principal. Por ahora, simplemente muestra un estado.
// También se asegura de que los errores no se muestren en la salida.
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
echo json_encode(['status' => 'API Endpoint Activo', 'message' => 'Use /factura.php para enviar documentos.']);
?>
