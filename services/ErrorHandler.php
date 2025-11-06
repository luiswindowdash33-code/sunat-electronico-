<?php
class ErrorHandler {
    
    public function __construct() {
        // ✅ ELIMINADO: creación de carpeta logs y sistema de archivos
    }
    
    public function clasificarError($codigo, $mensaje_original, $tipo_documento = null, $serie = null, $correlativo = null) {
        
        // 1. DETECTAR QUÉ TIPO DE ERROR ES
        $categoria = $this->detectarCategoria($mensaje_original);
        
        // 2. EXTRAER INFORMACIÓN IMPORTANTE DEL MENSAJE
        $informacion_extraida = $this->extraerInformacion($mensaje_original);
        
        // 3. GENERAR SOLUCIÓN AUTOMÁTICA
        $solucion_automatica = $this->generarSolucion($categoria, $informacion_extraida);
        
        // 4. CREAR DOCUMENTO (serie-correlativo)
        $documento = $serie . '-' . $correlativo;

        $resultado = [
            'success' => false,
            'error' => [
                'codigo_sunat' => $codigo,
                'categoria' => $categoria,
                'solucion_automatica' => $solucion_automatica,
                'documento' => $documento
            ]
        ];
        
        // ✅ ELIMINADO: guardarLog($resultado['error']);
        
        return $resultado;
    }
    
    private function detectarCategoria($mensaje) {
        $mensaje_minuscula = strtolower($mensaje);
        
        if (preg_match('/(zip|archivo|filename|nombre.*archivo)/', $mensaje_minuscula)) {
            return 'ERROR_ARCHIVO_ZIP';
        }
        
        if (preg_match('/(cac:|cbc:|ubl:|xml|tag|elemento|esquema|estructura)/', $mensaje_minuscula)) {
            return 'ERROR_XML';
        }
        
        if (preg_match('/(certificado|firma|digital|security|signature|expir|venc)/', $mensaje_minuscula)) {
            return 'ERROR_CERTIFICADO';
        }
        
        if (preg_match('/(ruc|contribuyente|no existe|no activo|invalid)/', $mensaje_minuscula)) {
            return 'ERROR_RUC';
        }
        
        if (preg_match('/(serie|correlativo|no registrada|no autorizada|secuencia)/', $mensaje_minuscula)) {
            return 'ERROR_SERIE';
        }
        
        if (preg_match('/(monto|valor|total|importe|calcul|igv|impuesto)/', $mensaje_minuscula)) {
            return 'ERROR_MONTO';
        }
        
        if (preg_match('/(timeout|conexión|connection|servidor|unavailable|no response)/', $mensaje_minuscula)) {
            return 'ERROR_CONEXION';
        }
        
        if (preg_match('/(cliente|customer|address|dirección|domicilio)/', $mensaje_minuscula)) {
            return 'ERROR_CLIENTE';
        }
        
        return 'ERROR_DESCONOCIDO';
    }
    
    private function extraerInformacion($mensaje) {
        $informacion = [];
        
        // Extraer RUCs (11 dígitos)
        preg_match_all('/\d{11}/', $mensaje, $rucs);
        if (!empty($rucs[0])) {
            $informacion['rucs_mencionados'] = $rucs[0];
        }
        
        // Extraer series (F001, B001, etc)
        preg_match_all('/[A-Z]\d{3}/', $mensaje, $series);
        if (!empty($series[0])) {
            $informacion['series_mencionadas'] = $series[0];
        }
        
        return $informacion;
    }
    
    private function generarSolucion($categoria, $informacion) {
        $base_soluciones = [
            'ERROR_ARCHIVO_ZIP' => 'El nombre del archivo ZIP no cumple con el formato requerido por SUNAT.',
            'ERROR_XML' => 'Verificar la estructura del XML y los tags requeridos por SUNAT.',
            'ERROR_CERTIFICADO' => 'Revisar el certificado digital: vigencia, instalación y configuración.',
            'ERROR_RUC' => 'Validar el RUC en el portal de SUNAT y verificar que esté activo.',
            'ERROR_SERIE' => 'Confirmar que la serie esté autorizada y el correlativo sea correcto.',
            'ERROR_MONTO' => 'Revisar cálculos de montos, impuestos y totales del comprobante.',
            'ERROR_CONEXION' => 'Reintentar la conexión. Verificar estado del servicio SUNAT.',
            'ERROR_CLIENTE' => 'Completar todos los datos del cliente requeridos por SUNAT.',
            'ERROR_DESCONOCIDO' => 'Analizar el mensaje de error para identificar el problema específico.'
        ];
        
        $solucion = $base_soluciones[$categoria] ?? 'Revisar el mensaje de error para identificar la solución.';
        
        // Enriquecer con información extraída
        if (!empty($informacion['series_mencionadas'])) {
            $solucion .= " Series mencionadas: " . implode(', ', array_unique($informacion['series_mencionadas']));
        }
        
        if (!empty($informacion['rucs_mencionados'])) {
            $solucion .= " RUCs mencionados: " . implode(', ', array_unique($informacion['rucs_mencionados']));
        }
        
        return $solucion;
    }
}
?>