<?php
// config.php - Configurazione centrale e funzioni per Supabase
// Regola: Da includere con require_once in tutte le pagine

define('SUPABASE_URL', 'https://ruvdlcgsmtwszxsposjt.supabase.co');
define('SUPABASE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InJ1dmRsY2dzbXR3c3p4c3Bvc2p0Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODMxNjQ4MzksImV4cCI6MjA5ODc0MDgzOX0.V_nFon6WsICyaiiN1bujrg5P9ORKb8-L1eMBlCFKZF8');

/**
 * Funzione unificata per eseguire richieste cURL verso le API REST di Supabase
 */
function supabase_request($endpoint, $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Prefer: return=representation',
        'Accept: application/json'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Timeout ridotti per evitare blocchi a cascata su Tophost
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    
    // Soluzione cruciale per server Tophost: forza IPv4 ed evita problemi di risoluzione SSL
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    if ($curl_error) {
        $error_msg = 'Errore di connessione cURL: ' . $curl_error;
        curl_close($ch);
        return ['error' => $error_msg];
    }

    curl_close($ch);

    if ($http_code >= 400) {
        return [
            'error' => 'Errore HTTP ' . $http_code, 
            'details' => json_decode($response, true)
        ];
    }

    $decoded = json_decode($response, true);
    return ($decoded === null && !empty($response)) ? ['error' => 'Errore di decodifica JSON'] : $decoded;
}

/**
 * Funzione per verificare lo stato dell'abbonamento basata sull'ultimo pagamento registrato nella tabella pagamenti
 */
function verifica_abbonamento($studio_id) {
    if (empty($studio_id)) {
        return ['stato' => 'scaduto', 'giorni' => -999, 'data_scadenza' => 'N/D'];
    }

    // Recupera l'ultimo pagamento effettuato dallo studio ordinato per data decrescente
    $endpoint = 'pagamenti?studio_id=eq.' . urlencode($studio_id) . '&select=data_pagamento,durata_mesi&order=data_pagamento.desc&limit=1';
    $risultato = supabase_request($endpoint, 'GET');

    // Fallback se la colonna fosse azienda_id
    if (empty($risultato) || isset($risultato['error'])) {
        $endpoint = 'pagamenti?azienda_id=eq.' . urlencode($studio_id) . '&select=data_pagamento,durata_mesi&order=data_pagamento.desc&limit=1';
        $risultato = supabase_request($endpoint, 'GET');
    }

    $oggi = date('Y-m-d');

    if (empty($risultato) || isset($risultato['error']) || !is_array($risultato) || count($risultato) === 0) {
        return ['stato' => 'scaduto', 'giorni' => -999, 'data_scadenza' => 'N/D'];
    }

    $data_pagamento = $risultato[0]['data_pagamento'] ?? '';
    $durata_mesi = intval($risultato[0]['durata_mesi'] ?? 1);

    if (empty($data_pagamento)) {
        return ['stato' => 'scaduto', 'giorni' => -999, 'data_scadenza' => 'N/D'];
    }

    // Calcola la data di scadenza aggiungendo i mesi del pagamento alla data di pagamento
    $data_scadenza = date('Y-m-d', strtotime("+$durata_mesi months", strtotime($data_pagamento)));

    // Calcola i giorni rimanenti puliti basati sulle date
    $ts_scadenza = strtotime($data_scadenza);
    $ts_oggi = strtotime($oggi);
    $giorni_rimanenti = round(($ts_scadenza - $ts_oggi) / (60 * 60 * 24));

    if ($giorni_rimanenti < 0) {
        return ['stato' => 'scaduto', 'giorni' => intval($giorni_rimanenti), 'data_scadenza' => $data_scadenza];
    } elseif ($giorni_rimanenti <= 7) {
        return ['stato' => 'in_scadenza', 'giorni' => intval($giorni_rimanenti), 'data_scadenza' => $data_scadenza];
    }

    return ['stato' => 'valido', 'giorni' => intval($giorni_rimanenti), 'data_scadenza' => $data_scadenza];
}
?>