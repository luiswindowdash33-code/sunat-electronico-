<?php
// src/procesador.php
// Este archivo contiene TODA la lógica de negocio. No imprime nada.

// Cargar dependencias de Composer una sola vez.
require_once __DIR__ . '/../vendor/autoload.php';

// Usar las clases necesarias de Greenter.
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\PaymentTerms;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Client\Client;
use Greenter\Model\Response\BillResult;

// Función principal que encapsula toda la lógica de procesamiento.
function procesarFactura(): array
{
    try {
        $jsonInput = file_get_contents('php://input');
        if (empty($jsonInput)) {
            throw new Exception("No se recibió ningún JSON - body vacío.", 400);
        }

        $data = json_decode($jsonInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON inválido: ' . json_last_error_msg(), 400);
        }

        // Desenvolver el body si viene de n8n.
        $docData = $data['body'] ?? $data;
        if (isset($docData['documentoSunat'])) {
            $docData = $docData['documentoSunat'];
        }

        // Validar que los datos del documento y la configuración existan.
        $config = $docData['config'] ?? null;
        if (!$docData || !$config) {
            throw new Exception("Estructura JSON incorrecta, falta 'config' o datos del documento.", 400);
        }

        // --- Configuración de Greenter ---
        $see = new See();
        $see->setCertificate($config['certificado']);
        $endpoint = ($config['entorno'] === 'produccion') ? SunatEndpoints::FE_PRODUCCION : SunatEndpoints::FE_BETA;
        $see->setService($endpoint);
        $see->setClaveSOL($docData['company']['ruc'], $config['usuario_sol'], $config['clave_sol']);

        // --- Creación del objeto Factura (Invoice) ---
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

        $legends = array_map(function($legendData) {
            return (new Legend())
                ->setCode($legendData['code'])
                ->setValue($legendData['value']);
        }, $docData['legends']);

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
            ->setTotalImpuestos($docData['mtoImpVenta'])
            ->setValorVenta($docData['mtoOperGravadas'] + $docData['mtoOperExoneradas'])
            ->setSubTotal($docData['mtoImpVenta'])
            ->setMtoImpVenta($docData['mtoImpVenta'])
            ->setDetails($details)
            ->setLegends($legends);

        if (isset($docData['formaPago'])) {
            $invoice->setFormaPago((new PaymentTerms())
                ->setMoneda($docData['formaPago']['moneda'])
                ->setTipo($docData['formaPago']['tipo']));
        }

        // --- Envío a SUNAT ---
        $result = $see->send($invoice);
        $xml_firmado = $see->getXmlSigned($invoice);

        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            return [
                'statusCode' => 200,
                'data' => [
                    'estado_sunat' => 'ACEPTADO',
                    'mensaje_sunat' => $cdr->getDescription(),
                    'xml_request_base64' => base64_encode($xml_firmado),
                    'hash_cdr' => $result->getCdrHash(),
                    'xml_cdr_base64' => base64_encode($result->getCdrZip())
                ]
            ];
        } else {
            $error = $result->getError();
            return [
                'statusCode' => 400,
                'data' => [
                    'estado_sunat' => 'RECHAZADO',
                    'mensaje_sunat' => $error->getMessage(),
                    'codigo_error' => $error->getCode()
                ]
            ];
        }

    } catch (Throwable $e) {
        // Captura cualquier excepción (incluyendo errores fatales de PHP si es posible).
        error_log("💥 EXCEPCIÓN GLOBAL: " . $e->getMessage() . " en " . $e->getFile() . ":" . $e->getLine());
        return [
            'statusCode' => 500,
            'data' => [
                'estado_sunat' => 'ERROR_APLICACION',
                'mensaje_sunat' => 'Error interno en la aplicación PHP.',
                'raw_error' => $e->getMessage()
            ]
        ];
    }
}
?>
