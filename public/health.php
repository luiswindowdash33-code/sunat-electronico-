<?php
// Asegura que la respuesta sea JSON y código 200
header('Content-Type: application/json');
http_response_code(200); 

// Mensaje de estado
echo json_encode(['status' => 'ok', 'message' => 'API is alive']);
?>
