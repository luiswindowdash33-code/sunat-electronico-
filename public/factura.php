<?php
// ✅ LIMPIAR CUALQUIER SALIDA ANTES DEL JSON
if (ob_get_level()) ob_end_clean();
ob_start();

// ✅ CONFIGURACIÓN CORS PARA n8n
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header('Content-Type: application/json; charset=utf-8');

// ✅ DESHABILITAR ERRORES EN PRODUCCIÓN PERO GUARDARLOS EN LOG
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// ✅ RUTAS CORRECTAS desde public/
require_once '../vendor/autoload.php';
require_once '../services/ErrorHandler.php';

use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\PaymentTerms;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Client\Client;

// ✅ FUNCIÓN PARA GUARDAR METADATOS EN CSV
function guardarMetadatos($docData, $mensaje_cdr) {
    $ruc_empresa = $docData['company']['ruc'];
    $archivo_metadatos = "../metadatos/{$ruc_empresa}.csv";
    
    // Datos del nuevo registro
    $nuevo_registro = [
        'fecha_registro' => date('c'),
        'documento' => $docData['serie'] . '-' . $docData['correlativo'],
        'cliente_ruc' => $docData['client']['numDoc'],
        'cliente_razon_social' => $docData['client']['rznSocial'],
        'monto' => $docData['mtoImpVenta'],
        'fecha_emision' => $docData['fechaEmision'],
        'mensaje_cdr' => $mensaje_cdr
    ];
    
    // Crear directorio si no existe
    if (!is_dir('../metadatos')) {
        mkdir('../metadatos', 0755, true);
    }
    
    // Si el archivo no existe, crear encabezados
    if (!file_exists($archivo_metadatos)) {
        $encabezados = [
            'fecha_registro',
            'documento', 
            'cliente_ruc',
            'cliente_razon_social',
            'monto',
            'fecha_emision',
            'mensaje_cdr'
        ];
        $archivo = fopen($archivo_metadatos, 'w');
        fputcsv($archivo, $encabezados);
        fclose($archivo);
    }
    
    // Agregar nueva fila
    $archivo = fopen($archivo_metadatos, 'a');
    fputcsv($archivo, $nuevo_registro);
    fclose($archivo);
    
    error_log("✅ METADATOS CSV GUARDADOS: {$archivo_metadatos}");
}

// ✅ LEER Y VALIDAR JSON DESDE n8n
$jsonInput = file_get_contents('php://input');
error_log("📥 RAW INPUT RECIBIDO: " . substr($jsonInput, 0, 500)); // Log para debugging

if (empty($jsonInput)) {
    http_response_code(400);
    echo json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => 'No se recibió ningún JSON - body vacío',
        'detalle' => 'El cuerpo de la solicitud está vacío'
    ]);
    exit;
}

$data = json_decode($jsonInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("❌ ERROR JSON: " . json_last_error_msg());
    error_log("📝 CONTENIDO RECIBIDO: " . $jsonInput);
    
    http_response_code(400);
    echo json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => 'JSON inválido: ' . json_last_error_msg(),
        'raw_input_sample' => substr($jsonInput, 0, 200),
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'No especificado'
    ]);
    exit;
}

error_log("✅ JSON DECODIFICADO CORRECTAMENTE");
error_log("📊 ESTRUCTURA DATA: " . print_r(array_keys($data), true));

// ✅ DETECTAR ESTRUCTURA DINÁMICAMENTE
$docData = null;
$config = null;
$firstItem = null;

// Caso 1: Es array con documentoSunat
if (is_array($data) && isset($data[0]) && isset($data[0]['documentoSunat'])) {
    error_log("🔍 ESTRUCTURA: Array con documentoSunat");
    $firstItem = $data[0];
    $docData = $firstItem['documentoSunat'];
    $config = $docData['config'] ?? null;
}
// Caso 2: Es objeto directo con documentoSunat  
else if (isset($data['documentoSunat'])) {
    error_log("🔍 ESTRUCTURA: Objeto con documentoSunat");
    $firstItem = $data;
    $docData = $data['documentoSunat'];
    $config = $docData['config'] ?? null;
}
// Caso 3: Es array directo con los datos (sin documentoSunat)
else if (is_array($data) && isset($data[0]) && isset($data[0]['ublVersion'])) {
    error_log("🔍 ESTRUCTURA: Array directo con datos UBL");
    $firstItem = $data[0];
    $docData = $firstItem;
    $config = $docData['config'] ?? null;
}
// Caso 4: Es objeto directo con los datos (sin documentoSunat)
else if (isset($data['ublVersion'])) {
    error_log("🔍 ESTRUCTURA: Objeto directo con datos UBL");
    $firstItem = $data;
    $docData = $data;
    $config = $docData['config'] ?? null;
}
// Caso 5: Estructura diferente - logging completo
else {
    error_log("❌ ESTRUCTURA NO RECONOCIDA");
    error_log("📋 CLAVES DEL DATA: " . print_r(array_keys($data), true));
    if (is_array($data) && isset($data[0])) {
        error_log("📋 CLAVES DEL PRIMER ITEM: " . print_r(array_keys($data[0]), true));
    }
}

if (!$docData || !$config) {
    error_log("❌ FALTA docData O config");
    error_log("📋 docData: " . print_r($docData, true));
    error_log("📋 config: " . print_r($config, true));
    
    http_response_code(400);
    echo json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => 'Estructura JSON incorrecta - falta documentoSunat o config',
        'estructura_recibida' => array_keys($data),
        'primer_item_keys' => isset($data[0]) ? array_keys($data[0]) : 'No es array'
    ]);
    exit;
}

error_log("✅ ESTRUCTURA VALIDADA - Iniciando proceso SUNAT");

// Inicializar ErrorHandler
$errorHandler = new ErrorHandler();

try {
    // ✅ CONFIGURAR GREENTER CON DATOS DEL JSON
    $see = new See();
    
    // 1. CERTIFICADO desde JSON
    $see->setCertificate($config['certificado']);
    
    // 2. CONFIGURAR ENDPOINT (beta/producción)
    $endpoint = ($config['entorno'] === 'produccion') 
        ? SunatEndpoints::FE_PRODUCCION 
        : SunatEndpoints::FE_BETA;
    $see->setService($endpoint);
    
    // 3. CREDENCIALES SOL - RUC desde company
    $see->setClaveSOL(
        $docData['company']['ruc'],   // RUC de company (emisor)
        $config['usuario_sol'],       // Usuario SOL
        $config['clave_sol']          // Clave SOL
    );

    // ✅ CONSTRUIR DOCUMENTO
    $company = new Company();
    $company->setRuc($docData['company']['ruc'])
            ->setRazonSocial($docData['company']['razonSocial'])
            ->setNombreComercial($docData['company']['nombreComercial']);

    $address = new Address();
    $address->setUbigueo($docData['company']['address']['ubigueo'])
            ->setDepartamento($docData['company']['address']['departamento'])
            ->setProvincia($docData['company']['address']['provincia'])
            ->setDistrito($docData['company']['address']['distrito'])
            ->setDireccion($docData['company']['address']['direccion']);
    $company->setAddress($address);
    
    $client = new Client();
    $client->setTipoDoc($docData['client']['tipoDoc'])
           ->setNumDoc($docData['client']['numDoc'])
           ->setRznSocial($docData['client']['rznSocial']);

    $clientAddress = new Address();
    $clientAddress->setUbigueo($docData['client']['address']['ubigueo'])
                  ->setDepartamento($docData['client']['address']['departamento'])
                  ->setProvincia($docData['client']['address']['provincia'])
                  ->setDistrito($docData['client']['address']['distrito'])
                  ->setDireccion($docData['client']['address']['direccion']);
    $client->setAddress($clientAddress);
    
    $details = [];
    foreach ($docData['details'] as $itemData) {
        $item = new SaleDetail();
        $item->setCodProducto($itemData['codProducto'])
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
        $legend = new Legend();
        $legend->setCode($legendData['code'])
               ->setValue($legendData['value']);
        $legends[] = $legend;
    }
    
    $invoice = new Invoice();
    $invoice->setUblVersion($docData['ublVersion'])
            ->setTipoOperacion($docData['tipoOperacion'])
            ->setTipoDoc($docData['tipoDoc'])
            ->setSerie($docData['serie'])
            ->setCorrelativo($docData['correlativo'])
            ->setFechaEmision(new DateTime($docData['fechaEmision']))
            ->setTipoMoneda($docData['tipoMoneda'])
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas($docData['mtoOperGravadas'])
            ->setMtoOperExoneradas($docData['mtoOperExoneradas'])
            ->setMtoIGV($docData['mtoIGV'])
            ->setTotalImpuestos($docData['totalImpuestos'])
            ->setValorVenta($docData['valorVenta'])
            ->setSubTotal($docData['subTotal'])
            ->setMtoImpVenta($docData['mtoImpVenta'])
            ->setDetails($details)
            ->setLegends($legends);
    
    if (isset($docData['formaPago'])) {
        $payment = new PaymentTerms();
        $payment->setMoneda($docData['formaPago']['moneda'])
                ->setTipo($docData['formaPago']['tipo']);
        $invoice->setFormaPago($payment);
    }
    
    // ✅ OBTENER XML FIRMADO
    error_log("🔏 GENERANDO XML FIRMADO...");
    $xml_firmado = $see->getXmlSigned($invoice);
    error_log("✅ XML FIRMADO GENERADO");
    
    // ✅ ENVIAR A SUNAT
    error_log("📤 ENVIANDO A SUNAT...");
    $result = $see->send($invoice);
    error_log("📥 RESPUESTA SUNAT RECIBIDA");
    
    if ($result->isSuccess()) {
        error_log("🎉 DOCUMENTO ACEPTADO POR SUNAT");
        $cdr_zip = $result->getCdrZip();
        
        // EXTRAER XML DEL CDR
        $zip = new ZipArchive();
        $cdr_xml_content = '';
        $temp_zip = tempnam(sys_get_temp_dir(), 'cdr');
        file_put_contents($temp_zip, $cdr_zip);
        
        if ($zip->open($temp_zip) === TRUE) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (pathinfo($filename, PATHINFO_EXTENSION) === 'xml') {
                    $cdr_xml_content = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();
        }
        unlink($temp_zip);
        
        // ✅ GUARDAR METADATOS (ACEPTADO)
        guardarMetadatos($docData, $result->getCdrResponse()->getDescription());
        
        // ✅ RESPONDER JSON SIN CONFIG PERO CON TODO LO DEMÁS
        $respuesta_sin_config = $firstItem;
        if (isset($respuesta_sin_config['documentoSunat']['config'])) {
            unset($respuesta_sin_config['documentoSunat']['config']);
        } else if (isset($respuesta_sin_config['config'])) {
            unset($respuesta_sin_config['config']);
        }
        
        // ✅ XML EN TEXTO PLANO (NO BASE64) - CAMBIO SOLICITADO
        error_log("📦 ENVIANDO RESPUESTA EXITOSA A n8n");
        echo json_encode([
            'estado_sunat' => 'ACEPTADO',
            'mensaje_sunat' => $result->getCdrResponse()->getDescription(),
            'xml_firmado' => $xml_firmado,           // ← XML EN TEXTO
            'xml_cdr' => $cdr_xml_content,           // ← XML EN TEXTO
            'json_original' => $respuesta_sin_config
        ]);
        
    } else {
        $error = $result->getError();
        error_log("❌ DOCUMENTO RECHAZADO POR SUNAT: " . $error->getMessage());
        
        // ✅ CAPTURAR ANÁLISIS COMPLETO DEL ERROR
        $analisisError = $errorHandler->clasificarError($error->getCode(), $error->getMessage(), $docData['tipoDoc'], $docData['serie'], $docData['correlativo']);
        
        // ✅ GUARDAR METADATOS (RECHAZADO)
        guardarMetadatos($docData, $error->getMessage());
        
        // ✅ SOLO ENVIAR ESTO - ELIMINADO TODO LO DEMÁS (CUMPLE ACUERDO)
        error_log("📦 ENVIANDO RESPUESTA DE RECHAZO A n8n");
        echo json_encode([
            'estado_sunat' => 'RECHAZADO',
            'mensaje_sunat' => $error->getMessage(),
            'analisis_detallado' => $analisisError['error']  // ✅ SOLO 3 CAMPOS
        ]);
    }
    
} catch (Exception $e) {
    error_log("💥 EXCEPCIÓN CAPTURADA: " . $e->getMessage());
    error_log("📋 TRAZA: " . $e->getTraceAsString());
    
    // ✅ CAPTURAR ANÁLISIS COMPLETO DEL ERROR
    $analisisError = $errorHandler->clasificarError('CONNECTION_ERROR', $e->getMessage(), $docData['tipoDoc'] ?? '', $docData['serie'] ?? '', $docData['correlativo'] ?? '');
    
    // ✅ GUARDAR METADATOS (ERROR)
    if (isset($docData)) {
        guardarMetadatos($docData, $e->getMessage());
    }
    
    // ✅ SOLO ENVIAR ESTO - ELIMINADO TODO LO DEMÁS (CUMPLE ACUERDO)
    http_response_code(500);
    echo json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => $e->getMessage(),
        'analisis_detallado' => $analisisError['error']  // ✅ SOLO 3 CAMPOS
    ]);
}

error_log("🏁 PROCESO FACTURA.PHP FINALIZADO");
// ✅ LIMPIAR BUFFER Y ENVIAR SOLO JSON
ob_end_clean();
?>
