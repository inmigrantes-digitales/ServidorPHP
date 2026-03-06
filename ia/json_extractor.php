<?php
/**
 * Extractor robusto de JSON desde respuestas de LLM.
 *
 * Las respuestas de modelos de IA a menudo vienen envueltas en markdown
 * (```json ... ```). Esta función limpia el texto y extrae el primer
 * objeto JSON válido encontrado.
 */

/**
 * Extrae el primer objeto JSON válido de un texto que puede contener
 * markdown, bloques de código, y otros caracteres.
 *
 * @param string|null $text Texto crudo de la respuesta del LLM.
 * @return array|null Array asociativo parseado, o null si no se pudo extraer.
 */
function extractFirstJSON(?string $text): ?array
{
    if (empty($text) || !is_string($text)) {
        return null;
    }

    // Limpiar texto: eliminar markdown y bloques de código
    $cleaned = str_replace(['```json', '```', '`'], '', $text);
    $cleaned = trim($cleaned);

    // Buscar el primer '{' en el texto
    $start = strpos($cleaned, '{');
    if ($start === false) {
        return null;
    }

    // Buscar el cierre correspondiente contando llaves
    $braceCount = 0;
    $end = -1;
    $len = strlen($cleaned);

    for ($i = $start; $i < $len; $i++) {
        if ($cleaned[$i] === '{') {
            $braceCount++;
        } elseif ($cleaned[$i] === '}') {
            $braceCount--;
            if ($braceCount === 0) {
                $end = $i;
                break;
            }
        }
    }

    if ($end === -1 || $end <= $start) {
        return null;
    }

    $maybeJSON = substr($cleaned, $start, $end - $start + 1);

    // Intentar parsear directamente
    $parsed = json_decode($maybeJSON, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    // Segunda pasada: limpiar caracteres problemáticos
    $cleanedJSON = preg_replace('/\s+/', ' ', $maybeJSON);
    $cleanedJSON = trim($cleanedJSON);

    $parsed = json_decode($cleanedJSON, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    error_log("JSON EXTRACTION FAILED: " . substr($maybeJSON, 0, 200));
    return null;
}
