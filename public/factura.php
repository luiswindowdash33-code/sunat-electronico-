?php
// ✅ LIMPIAR CUALQUIER SALIDA ANTES DEL JSON
if (ob_get_level()) ob_end_clean();
ob_start();

// ✅ CONFIGURACIÓN CORS PARA n8n
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

// ✅ DESHABILITAR ERRORES EN PANTALLA PERO GUARDARLOS EN LOG
ini_set('display_errors', 0);
error_reporting(0); // Forzar la supresión de todos los errores en la salida
ini_set('log_errors', 1);

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ✅ FUNCIÓN PARA ENVIAR RESPUESTA Y TERMINAR SCRIPT
function send_json_response($data, $statusCode = 200) {
    if (ob_get_level()) ob_end_clean(); // Limpiar buffer antes de la salida final
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// ✅ RUTAS ABSOLUTAS Y MANEJO DE ERRORES DE REQUIRE
try {
    // Usar __DIR__ para crear rutas absolutas desde la ubicación actual del script
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../services/ErrorHandler.php';
} catch (Throwable $e) {
    error_log("💥 ERROR CRÍTICO: No se pudieron cargar las dependencias. " . $e->getMessage());
    send_json_response([
        'estado_sunat' => 'ERROR_INTERNO',
        'mensaje_sunat' => 'Error de configuración del servidor: no se pueden cargar las librerías.',
        'detalle' => $e->getMessage()
    ], 500);
}

use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\PaymentTerms;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Client\Client;

$errorHandler = new ErrorHandler();

try {
    $jsonInput = file_get_contents('php://input');
    if (empty($jsonInput)) {
        throw new Exception("No se recibió ningún JSON - body vacío", 400);
    }
    
    $data = json_decode($jsonInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido: ' . json_last_error_msg(), 400);
    }

    $docData = $data['documentoSunat'] ?? $data;
    if (!isset($docData['ublVersion'])) {
         // Si sigue anidado, lo extraemos
        if (isset($docData[0]['documentoSunat'])) {
            $docData = $docData[0]['documentoSunat'];
        } else if (isset($data[0])) { // Si es un array de documentos
            $docData = $data[0];
        } else {
            throw new Exception("Estructura JSON no reconocida. No se encontró el objeto del documento.", 400);
        }
    }
    
    $config = $docData['config'] ?? null;
    if (!$config) {
        throw new Exception("No se encontró la sección 'config' en los datos del documento.", 400);
    }

    // El resto de tu lógica de negocio aquí...
    $see = new See();
    $see->setCertificate($config['certificado']);
    $endpoint = ($config['entorno'] === 'produccion') ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA;
    $see->setService($endpoint);
    $see->setClaveSOL($docData['company']['ruc'], $config['usuario_sol'], $config['clave_sol']);

    $company = (new Company())
            ->setRuc($docData['company']['ruc'])
            ->setRazonSocial($docData['company']['razonSocial'])
            ->setNombreComercial($docData['company']['nombreComercial'])
            ->setAddress((new Address())
                ->setUbigueo($docData['company']['address']['ubigueo'])
                ->setDepartamento($docData['company']['address']['departamento'])
                ->setProvincia($docData['company']['address']['provincia'])
                ->setDistrito($docData['company']['address']['distrito'])
                ->setDireccion($docData['company']['address']['direccion']));

    $client = (new Client())
           ->setTipoDoc($docData['client']['tipoDoc'])
           ->setNumDoc($docData['client']['numDoc'])
           ->setRznSocial($docData['client']['rznSocial']);
    
    $details = [];
    foreach ($docData['details'] as $itemData) {
        $item = (new SaleDetail())
             ->setCodProducto($itemData['codProducto'])
             ->setUnidad($itemData['unidad'])
             ->setDescripcion($itemData['descripcion'])
             ->setCantidad($itemData['cantidad'])
             ->setMtoValorUnitario($itemData['mtoValorUnitario'])
             ->setMtoValorVenta($itemData['mtoValorVenta'])
             ->setMtoBaseIgv($itemData['mtoBaseIgv'])
             ->setPorcentajeIgv($itemData['porcentajeIgv'])
             ->setIgv($itemData['igv'])
             ->setTipAfeIgv($itemData['tipAfeIgv'])
             ->setTotalImpuestos($itemData['totalImpuestos'])
             ->setMtoPrecioUnitario($itemData['mtoPrecioUnitario']);
        $details[] = $item;
    }
    
    $legends = [];
    foreach ($docData['legends'] as $legendData) {
        $legends[] = (new Legend())
               ->setCode($legendData['code'])
               ->setValue($legendData['value']);
    }

    $invoice = (new Invoice())
            ->setUblVersion($docData['ublVersion'])
            ->setTipoOperacion($docData['tipoOperacion'])
            ->setTipoDoc($docData['tipoDoc'])
            ->setSerie($docData['serie'])
            ->setCorrelativo($docData['correlativo'])
            ->setFechaEmision(new DateTime($docData['fechaEmision']))
            ->setTipoMoneda($docData['tipoMoneda'])
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas($docData['mtoOperGravadas'])
            ->setMtoOperExoneradas($docData['mtoOperExoneradas'] ?? 0)
            ->setMtoIGV($docData['mtoIGV'])
            ->setTotalImpuestos($docData['totalImpuestos'])
            ->setValorVenta($docData['valorVenta'])
            ->setSubTotal($docData['subTotal'])
            ->setMtoImpVenta($docData['mtoImpVenta'])
            ->setDetails($details)
            ->setLegends($legends);

    if (isset($docData['formaPago'])) {
        $invoice->setFormaPago((new PaymentTerms())
                ->setMoneda($docData['formaPago']['moneda'])
                ->setTipo($docData['formaPago']['tipo']));
    }

    $result = $see->send($invoice);
    $xml_firmado = $see->getXmlSigned($invoice);

    if ($result->isSuccess()) {
        $cdr = $result->getCdrResponse();
        $response = [
            'estado_sunat' => 'ACEPTADO',
            'mensaje_sunat' => $cdr->getDescription(),
            'xml_request_base64' => base64_encode($xml_firmado),
            'hash_cdr' => $result->getCdrHash(),
            'xml_cdr_base64' => base64_encode($result->getCdrZip())
        ];
        send_json_response($response, 200);
    } else {
        $error = $result->getError();
        $analisis = $errorHandler->clasificarError($error->getCode(), $error->getMessage(), $docData['tipoDoc'], $docData['serie'], $docData['correlativo']);
        send_json_response([
            'estado_sunat' => 'RECHAZADO',
            'mensaje_sunat' => $error->getMessage(),
            'analisis_detallado' => $analisis['error']
        ], 400); // Bad Request, ya que SUNAT lo rechazó.
    }

} catch (Exception $e) {
    error_log("💥 EXCEPCIÓN GLOBAL CAPTURADA: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
    
    $analisis = $errorHandler->clasificarError($e->getCode(), $e->getMessage(), 'N/A', 'N/A', 'N/A');
    
    send_json_response([
        'estado_sunat' => 'ERROR_APLICACION',
        'mensaje_sunat' => 'Error interno en la aplicación PHP.',
        'analisis_detallado' => $analisis['error'],
        'raw_error' => $e->getMessage()
    ], 500);
}
?>
