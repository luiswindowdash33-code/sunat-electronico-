<?php
// 🔥 CORRECCIÓN CRÍTICA 1: INICIAR BUFFERING DE SALIDA FORZADO
// Esto previene que cualquier error o byte de archivos incluidos (vendor, ErrorHandler) se filtre.
ob_start();

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

// CONFIGURAR API JSON
header('Content-Type: application/json');

// 🔥 CORRECCIÓN CRÍTICA 2: Limpiar el buffer después de los requires si algo se coló
if (ob_get_length()) {
    ob_clean();
}

// ✅ FUNCIÓN PARA GUARDAR METADATOS EN CSV (Limpiado de caracteres invisibles)
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

// LEER JSON DESDE n8n
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data) {
    $response = json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => 'JSON inválido: ' . json_last_error_msg()
    ]);
    ob_end_clean(); 
    echo $response;
    exit;
}

// ✅ DETECTAR ESTRUCTURA DINÁMICAMENTE (Sin cambios en tu lógica)
$docData = null;
$config = null;
$firstItem = null;

// Caso 1: Es array con documentoSunat
if (is_array($data) && isset($data[0]) && isset($data[0]['documentoSunat'])) {
    $firstItem = $data[0];
    $docData = $firstItem['documentoSunat'];
    $config = $docData['config'] ?? null;
}
// Caso 2: Es objeto directo con documentoSunat  
else if (isset($data['documentoSunat'])) {
    $firstItem = $data;
    $docData = $data['documentoSunat'];
    $config = $docData['config'] ?? null;
}
// Caso 3: Es array directo con los datos (sin documentoSunat)
else if (is_array($data) && isset($data[0]) && isset($data[0]['ublVersion'])) {
    $firstItem = $data[0];
    $docData = $firstItem;
    $config = $docData['config'] ?? null;
}
// Caso 4: Es objeto directo con los datos (sin documentoSunat)
else if (isset($data['ublVersion'])) {
    $firstItem = $data;
    $docData = $data;
    $config = $docData['config'] ?? null;
}

if (!$docData || !$config) {
    $response = json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => 'Estructura JSON incorrecta - falta documentoSunat en el array'
    ]);
    ob_end_clean();
    echo $response;
    exit;
}

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

    // ✅ CONSTRUIR DOCUMENTO (Sección Company y Client limpiadas de caracteres invisibles)
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
    $xml_firmado = $see->getXmlSigned($invoice);
    
    // ✅ ENVIAR A SUNAT
    $result = $see->send($invoice);
    
    if ($result->isSuccess()) {
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
        
        // ✅ XML EN TEXTO PLANO (NO BASE64)
        $response = json_encode([
            'estado_sunat' => 'ACEPTADO',
            'mensaje_sunat' => $result->getCdrResponse()->getDescription(),
            'xml_firmado' => $xml_firmado,
            'xml_cdr' => $cdr_xml_content,
            'json_original' => $respuesta_sin_config
        ]);
        
    } else {
        $error = $result->getError();
        
        // ✅ CAPTURAR ANÁLISIS COMPLETO DEL ERROR
        $analisisError = $errorHandler->clasificarError($error->getCode(), $error->getMessage(), $docData['tipoDoc'], $docData['serie'], $docData['correlativo']);
        
        // ✅ GUARDAR METADATOS (RECHAZADO)
        guardarMetadatos($docData, $error->getMessage());
        
        // ✅ RESPONDER JSON DE RECHAZO
        $response = json_encode([
            'estado_sunat' => 'RECHAZADO',
            'mensaje_sunat' => $error->getMessage(),
            'analisis_detallado' => $analisisError['error']
        ]);
    }
    
} catch (Exception $e) {
    // ✅ CAPTURAR ANÁLISIS COMPLETO DEL ERROR
    $analisisError = $errorHandler->clasificarError('CONNECTION_ERROR', $e->getMessage(), $docData['tipoDoc'] ?? '', $docData['serie'] ?? '', $docData['correlativo'] ?? '');
    
    // ✅ GUARDAR METADATOS (ERROR)
    guardarMetadatos($docData, $e->getMessage());
    
    // ✅ RESPONDER JSON DE ERROR
    $response = json_encode([
        'estado_sunat' => 'ERROR',
        'mensaje_sunat' => $e->getMessage(),
        'analisis_detallado' => $analisisError['error']
    ]);
}

// 🔥 CRÍTICO 3: Limpiar el buffer final y enviar el JSON
ob_end_clean();
echo $response;
// 🔥 ETIQUETA DE CIERRE (?>) ELIMINADA.
