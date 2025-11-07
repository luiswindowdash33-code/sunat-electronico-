<?php
// MODO DE DEPURACIÓN GLOBAL: DEVOLVER EXACTAMENTE LO QUE RECIBIMOS
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(204);
    exit;
}

$response = [
    'diagnostico' => 'Respuesta desde el script de depuración factura.php',
    'timestamp' => date('c'),
    'metodo_http' => $_SERVER['REQUEST_METHOD'],
    'content_type_recibido' => $_SERVER['CONTENT_TYPE'] ?? 'No especificado',
    'input_bruto' => '',
    'json_decodificado' => null,
    'error_json' => null,
];

$jsonInput = file_get_contents('php://input');
$response['input_bruto'] = $jsonInput;

if ($jsonInput === false) {
    $response['error_json'] = 'file_get_contents(\'php://input\') devolvió false. No se pudo leer el cuerpo de la solicitud.';
} elseif (empty($jsonInput)) {
    $response['error_json'] = 'El cuerpo de la solicitud (body) está vacío.';
} else {
    $decoded = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['error_json'] = 'Error al decodificar JSON: ' . json_last_error_msg();
    } else {
        $response['json_decodificado'] = $decoded;
    }
}

http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT);
exit;
?>
