<?php session_start(); if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; } ?>
<?php
require_once 'config.php';

// Estrazione sicura dell'utente dalla sessione
$utente_loggato = $_SESSION['utente'];
$utente_id = is_array($utente_loggato) ? ($utente_loggato['id'] ?? ($utente_loggato['utente_id'] ?? null)) : null;
$utente_email = is_array($utente_loggato) ? ($utente_loggato['email'] ?? null) : (is_string($utente_loggato) ? $utente_loggato : null);
$utente_username = is_array($utente_loggato) ? ($utente_loggato['username'] ?? null) : null;

// Recupero studio_id dalla sessione per il multitenant
$studio_id = is_array($utente_loggato) ? ($utente_loggato['studio_id'] ?? null) : null;

$titolo_medico = 'Dr.';
$nome_medico = '';
$cognome_medico = '';
$matricola_medico = 'N/D';
$specializzazione_medico = 'Specialista in Ortopedia e Traumatologia';
$ambulatorio_medico = 'Studio / Ambulatorio Medico Specialistico';

// Interroghiamo la tabella 'medici' filtrando in modo mirato sull'utente loggato corrente
$dati_medico_trovati = null;

if ($utente_id) {
    $res = supabase_request('medici?id=eq.' . urlencode($utente_id), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if (!$dati_medico_trovati && $utente_email) {
    $res = supabase_request('medici?email=eq.' . urlencode($utente_email), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if (!$dati_medico_trovati && $utente_username) {
    $res = supabase_request('medici?username=eq.' . urlencode($utente_username), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if (!$dati_medico_trovati && is_string($utente_loggato)) {
    $res = supabase_request('medici?or=(email.eq.' . urlencode($utente_loggato) . ',cognome.eq.' . urlencode($utente_loggato) . ')', 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if ($dati_medico_trovati) {
    $titolo_medico = $dati_medico_trovati['titolo'] ?? 'Dr.';
    $nome_medico = $dati_medico_trovati['nome'] ?? '';
    $cognome_medico = $dati_medico_trovati['cognome'] ?? '';
    $matricola_medico = $dati_medico_trovati['matricola'] ?? 'N/D';
    
    if (!empty($dati_medico_trovati['specializzazione'])) {
        $specializzazione_medico = $dati_medico_trovati['specializzazione'];
    }
    if (!empty($dati_medico_trovati['ambulatorio'])) {
        $ambulatorio_medico = $dati_medico_trovati['ambulatorio'];
    }
    if (!$studio_id && !empty($dati_medico_trovati['studio_id'])) {
        $studio_id = $dati_medico_trovati['studio_id'];
    }
}

// Nome completo e stringa intestazione/firma
$nome_utente_completo = trim($nome_medico . ' ' . $cognome_medico);
if ($nome_utente_completo === '') {
    $nome_utente_completo = is_array($utente_loggato) ? ($utente_loggato['username'] ?? ($utente_loggato['email'] ?? 'Utente')) : $utente_loggato;
}

// Verifica se i moduli sono attivi nella tabella pagamenti per lo studio corrente
$chirurgia_mano_attiva = false;
$cardiologia_attiva = false;

if ($studio_id) {
    $res_pagamenti = supabase_request("pagamenti?studio_id=eq.$studio_id", 'GET');
    if (!empty($res_pagamenti) && !isset($res_pagamenti['error'])) {
        foreach ($res_pagamenti as $pagamento) {
            $valore_bool = $pagamento['modulo_chirurgia_mano'] ?? false;
            if ($valore_bool === true || $valore_bool === 'true' || $valore_bool === 1 || $valore_bool === '1') {
                $chirurgia_mano_attiva = true;
            }
            $valore_cardio = $pagamento['modulo_cardiologia'] ?? false;
            if ($valore_cardio === true || $valore_cardio === 'true' || $valore_cardio === 1 || $valore_cardio === '1') {
                $cardiologia_attiva = true;
            }
            if ($chirurgia_mano_attiva && $cardiologia_attiva) {
                break;
            }
        }
    }
}

// Recupero dinamico del Logo dalla Repository
$logo_medico = '';
if ($studio_id) {
    $res_logo = supabase_request("repository?studio_id=eq.$studio_id&titolo=eq.logo&select=file_url", 'GET');
    if (!empty($res_logo) && !isset($res_logo['error']) && isset($res_logo[0]['file_url'])) {
        $logo_medico = $res_logo[0]['file_url'];
    } else {
        $res_logo_alt = supabase_request("repository?studio_id=eq.$studio_id&select=file_url", 'GET');
        if (!empty($res_logo_alt) && !isset($res_logo_alt['error']) && isset($res_logo_alt[0]['file_url'])) {
            $logo_medico = $res_logo_alt[0]['file_url'];
        }
    }
}

// ID del controllo (visita) dalla query string
$controllo_id = $_GET['id'] ?? null;

if (empty($controllo_id)) {
    header("Location: planner.php");
    exit;
}

$messaggio_successo = '';
$messaggio_errore = '';

// Recupero preliminare del controllo per identificare il paziente_id e la data attuale
$risultato_controllo_pre = supabase_request('controlli?id=eq.' . urlencode($controllo_id), 'GET');
if (empty($risultato_controllo_pre) || isset($risultato_controllo_pre['error'])) {
    $paziente_id_corrente = null;
    $data_controllo_corrente = date('Y-m-d');
} else {
    $paziente_id_corrente = $risultato_controllo_pre[0]['paziente_id'] ?? null;
    $data_controllo_corrente = $risultato_controllo_pre[0]['data_controllo'] ?? date('Y-m-d');
}

// Estrazione dell'orario attuale dalle note o dalla data di creazione
$orario_attuale = '';
if (!empty($risultato_controllo_pre[0]['note'])) {
    if (preg_match('/(?:Orario:|@|\[ORARIO:)\s*(\d{2}:\d{2})?/i', $risultato_controllo_pre[0]['note'], $m) && !empty($m[1])) {
        $orario_attuale = $m[1];
    }
}
if (empty($orario_attuale) && !empty($risultato_controllo_pre[0]['created_at'])) {
    $orario_attuale = date('H:i', strtotime($risultato_controllo_pre[0]['created_at']));
}

// Gestione del salvataggio dei dati (Form POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_tipo = $_POST['form_inviato'] ?? 'visita';

    if ($form_tipo === 'paziente' && !empty($paziente_id_corrente)) {
        $p_nome = trim($_POST['p_nome'] ?? '');
        $p_cognome = trim($_POST['p_cognome'] ?? '');
        $p_telefono = trim($_POST['p_telefono'] ?? '');
        $p_data_nascita = trim($_POST['p_data_nascita'] ?? '');
        $p_luogo_nascita = trim($_POST['p_luogo_nascita'] ?? '');

        $dati_aggiornamento_paziente = [
            'nome' => $p_nome !== '' ? $p_nome : null,
            'cognome' => $p_cognome !== '' ? $p_cognome : null,
            'telefono' => $p_telefono !== '' ? $p_telefono : null,
            'data_nascita' => $p_data_nascita !== '' ? $p_data_nascita : null,
            'luogo_nascita' => $p_luogo_nascita !== '' ? $p_luogo_nascita : null
        ];

        $risultato_paziente_update = supabase_request('pazienti?id=eq.' . urlencode($paziente_id_corrente), 'PATCH', $dati_aggiornamento_paziente);

        if (empty($risultato_paziente_update) || !isset($risultato_paziente_update['error'])) {
            $messaggio_successo = "Anagrafica paziente aggiornata con successo!";
        } else {
            $messaggio_errore = "Errore durante l'aggiornamento del paziente su Supabase.";
        }

    } else {
        $tipo_controllo = trim($_POST['tipo_controllo'] ?? '');
        $diagnosi_visita = trim($_POST['diagnosi_visita'] ?? '');
        $esito = trim($_POST['esito'] ?? '');
        $terapia = trim($_POST['terapia'] ?? '');
        $note_utente = trim($_POST['note'] ?? '');
        $stato_visita = trim($_POST['stato'] ?? 'Effettuato');
        $data_controllo_post = trim($_POST['data_controllo'] ?? '');
        $orario_inviato = trim($_POST['orario'] ?? $orario_attuale);
        
        $data_intervento_visita = trim($_POST['data_intervento'] ?? '');
        $tipologia_intervento_visita = trim($_POST['tipologia_intervento'] ?? '');
        
        $note_pulite = preg_replace('/(\[ORARIO:\d{2}:\d{2}\]|Orario:\s*\d{2}:\d{2}|@\s*\d{2}:\d{2})/i', '', $note_utente);
        $note = trim($note_pulite);
        if (!empty($orario_inviato)) {
            $note .= " [ORARIO:" . $orario_inviato . "]";
        }
        
        if (!empty($data_controllo_post) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_controllo_post)) {
            $data_controllo_finale = $data_controllo_post;
        } else {
            $data_controllo_finale = $data_controllo_corrente;
        }
        
        $risultato_corrente = supabase_request('controlli?id=eq.' . urlencode($controllo_id), 'GET');
        $file_path_esistente = (!empty($risultato_corrente) && !isset($risultato_corrente['error'])) ? ($risultato_corrente[0]['file_path'] ?? '') : '';
        $log_esistente = (!empty($risultato_corrente) && !isset($risultato_corrente['error'])) ? ($risultato_corrente[0]['log_modifiche'] ?? '') : '';

        // Gestione caricamento file multipli direttamente su Supabase Storage
        $nuovi_file_caricati = [];
        if (isset($_FILES['allegati']) && !empty($_FILES['allegati']['name'][0])) {
            $tot_file = count($_FILES['allegati']['name']);
            $estensioni_consentite = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'mp4', 'mov', 'avi', 'webm', 'svg'];
            
            for ($i = 0; $i < $tot_file; $i++) {
                if ($_FILES['allegati']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['allegati']['tmp_name'][$i];
                    $nome_originale = basename($_FILES['allegati']['name'][$i]);
                    $estensione = strtolower(pathinfo($nome_originale, PATHINFO_EXTENSION));
                    
                    if (in_array($estensione, $estensioni_consentite)) {
                        $mime_type = mime_content_type($file_tmp) ?: 'application/octet-stream';
                        $nome_file_storage = 'visita_' . $controllo_id . '_' . time() . '_' . $i . '.' . $estensione;
                        
                        // Upload cURL su Supabase Storage (bucket 'repository')
                        $url_storage = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/repository/' . $nome_file_storage;
                        $file_data = file_get_contents($file_tmp);

                        $ch = curl_init($url_storage);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_data);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'apikey: ' . SUPABASE_KEY,
                            'Authorization: Bearer ' . SUPABASE_KEY,
                            'Content-Type: ' . $mime_type
                        ]);

                        $response_storage = curl_exec($ch);
                        $http_code_storage = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($http_code_storage >= 200 && $http_code_storage < 300) {
                            // Genera URL pubblico permanente su Supabase Storage
                            $url_pubblico = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/repository/' . $nome_file_storage;
                            $nuovi_file_caricati[] = $url_pubblico;
                        }
                    }
                }
            }
        }

        // Combina i vecchi file con i nuovi se già esistevano
        $array_tutti_file = [];
        if (!empty($file_path_esistente)) {
            $array_tutti_file = array_filter(array_map('trim', explode(',', $file_path_esistente)));
        }
        if (!empty($nuovi_file_caricati)) {
            $array_tutti_file = array_merge($array_tutti_file, $nuovi_file_caricati);
        }
        $file_path_finale = !empty($array_tutti_file) ? implode(',', array_unique($array_tutti_file)) : null;

        if (empty($messaggio_errore)) {
            date_default_timezone_set('Europe/Rome');
            $timestamp_attuale = date('d/m/Y H:i');
            $nuova_riga_log = "• Modificato il " . $timestamp_attuale . " da " . $titolo_medico . " " . $nome_utente_completo;
            
            $log_aggiornato = !empty($log_esistente) ? $log_esistente . "\n" . $nuova_riga_log : $nuova_riga_log;

            $dati_aggiornamento = [
                'tipo_controllo' => $tipo_controllo,
                'diagnosi' => $diagnosi_visita !== '' ? $diagnosi_visita : null,
                'esito' => $esito !== '' ? $esito : null,
                'terapia' => $terapia !== '' ? $terapia : null,
                'note' => $note !== '' ? $note : null,
                'data_controllo' => $data_controllo_finale,
                'data_intervento' => $data_intervento_visita !== '' ? $data_intervento_visita : null,
                'tipologia_intervento' => $tipologia_intervento_visita !== '' ? $tipologia_intervento_visita : null,
                'stato' => $stato_visita,
                'file_path' => $file_path_finale,
                'log_modifiche' => $log_aggiornato
            ];

            $risultato_update = supabase_request('controlli?id=eq.' . urlencode($controllo_id), 'PATCH', $dati_aggiornamento);

            if (empty($risultato_update) || !isset($risultato_update['error'])) {
                $messaggio_successo = "Visita e allegati salvati con successo nel cloud!";
                $data_controllo_corrente = $data_controllo_finale;
                $orario_attuale = $orario_inviato;
            } else {
                $messaggio_errore = "Errore durante il salvataggio su Supabase: " . json_encode($risultato_update);
            }
        }
    }
}

// Recupero dei dati del controllo da Supabase
$risultato_controllo = supabase_request('controlli?id=eq.' . urlencode($controllo_id), 'GET');

if (empty($risultato_controllo) || isset($risultato_controllo['error'])) {
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <title>Errore - Proman</title>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f8f9fa; color: #333; text-align: center; padding: 50px; }
            .error-box { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
            h2 { color: #dc3545; }
            .btn { display: inline-block; background-color: #6c757d; color: white; padding: 10px 20px; border-radius: 4px; text-decoration: none; margin-top: 20px; }
            .btn:hover { background-color: #5a6268; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h2>Controllo non trovato</h2>
            <p>Impossibile trovare il controllo richiesto o si è verificato un errore di connessione a Supabase.</p>
            <p><small style="color: #666;">ID cercato: <?php echo htmlspecialchars($controllo_id); ?></small></p>
            <a href="planner.php" class="btn">&larr; Torna al Planner</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$controllo = $risultato_controllo[0];
$paziente_id = $controllo['paziente_id'] ?? null;
$paziente = null;
$storico_controlli = [];

if (!empty($paziente_id)) {
    $risultato_paziente = supabase_request('pazienti?id=eq.' . urlencode($paziente_id), 'GET');
    if (!empty($risultato_paziente) && !isset($risultato_paziente['error'])) {
        $paziente = $risultato_paziente[0];
    }

    $risultato_storico = supabase_request('controlli?paziente_id=eq.' . urlencode($paziente_id) . '&order=data_controllo.asc', 'GET');
    if (!empty($risultato_storico) && !isset($risultato_storico['error'])) {
        $storico_controlli = $risultato_storico;
    }
}

// Pulizia note correnti per la visualizzazione nel form
$note_pulita = $controllo['note'] ?? '';
$note_pulita = preg_replace('/(\[ORARIO:\d{2}:\d{2}\]|Orario:\s*\d{2}:\d{2}|@\s*\d{2}:\d{2})/i', '', $note_pulita);
$note_pulita = trim($note_pulita);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Visita - Proman</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 10px; background-color: #f8f9fa; color: #333; }
        header { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        header h1 { font-size: 1.4em; margin: 0; }
        .header-actions { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; font-size: 0.9em; }
        .nav-links { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h2 { margin-top: 0; font-size: 1.2em; color: #007bff; border-bottom: 2px solid #f1f3f5; padding-bottom: 8px; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em; }
        .form-group input[type="text"], .form-group select, .form-group textarea, .form-group input[type="file"], .form-group input[type="date"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; }
        
        .form-group textarea { 
            resize: none; 
            overflow: hidden; 
            min-height: 42px; 
            line-height: 1.4;
        }

        .btn-salva { background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; font-size: 1em; font-weight: bold; cursor: pointer; }
        .btn-salva:hover { background-color: #218838; }
        .btn-indietro { background-color: #6c757d; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; }
        .btn-indietro:hover { background-color: #5a6268; }
        .btn-dashboard { background-color: #007bff; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        .btn-dashboard:hover { background-color: #0056b3; }
        .btn-chirurgia { background-color: #6610f2; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        .btn-chirurgia:hover { background-color: #520dc2; }
        .btn-cardiologia { background-color: #e83e8c; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        .btn-cardiologia:hover { background-color: #d63384; }
        .btn-stampa { background-color: #17a2b8; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; font-weight: bold; border: none; cursor: pointer; }
        .btn-stampa:hover { background-color: #138496; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 0.95em; }
        .info-item { background: #f1f3f5; padding: 10px; border-radius: 4px; }
        .info-item strong { display: block; color: #555; font-size: 0.8em; margin-bottom: 3px; }
        
        .table-storico { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; }
        .table-storico th, .table-storico td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; }
        .table-storico th { background-color: #f1f3f5; color: #495057; }
        .badge-futuro { background-color: #cce5ff; color: #004085; padding: 3px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-passato { background-color: #e2e3e5; color: #383d41; padding: 3px 6px; border-radius: 4px; font-size: 0.8em; }
        .badge-corrente { background-color: #d4edda; color: #155724; padding: 3px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-allegato { background-color: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; display: inline-block; text-decoration: none; border: 1px solid #ffeeba; margin-right: 5px; margin-bottom: 5px; }
        .badge-allegato:hover { background-color: #ffeeba; color: #533f03; }

        .print-header { display: none; }
        .print-footer-signature { display: none; }
        .print-only { display: none; }

        @media print {
            @page { size: A4; margin: 8mm; }
            body { background: #fff; color: #111; margin: 0; font-size: 10pt; line-height: 1.3; }
            header, .header-actions, .btn-salva, .btn-indietro, .btn-dashboard, .btn-chirurgia, .btn-cardiologia, .btn-stampa, .no-print, .nascondi-in-stampa { display: none !important; }
            
            .print-header {
                display: block !important;
                border-bottom: 2px solid #222;
                padding-bottom: 8px;
                margin-bottom: 12px;
            }
            .print-header table { width: 100%; border-collapse: collapse; }
            .print-header td { vertical-align: middle; }
            .print-header .clinic-logo img { max-height: 60px; width: auto; display: block; }
            .print-header .clinic-info { font-size: 9.5pt; color: #444; }
            .print-header .doc-info { text-align: right; font-size: 9.5pt; color: #222; }

            .card {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                border-radius: 5px !important;
                padding: 8px 12px !important;
                margin-bottom: 10px !important;
                page-break-inside: avoid;
                background: #fff !important;
            }
            .card h2 {
                font-size: 10pt !important;
                color: #111 !important;
                border-bottom: 1px solid #ddd !important;
                padding-bottom: 3px !important;
                margin-bottom: 6px !important;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            input[type="text"], input[type="date"], select {
                border: none !important;
                border-bottom: 1px dotted #999 !important;
                background: transparent !important;
                padding: 1px 0 !important;
                font-size: 9.5pt !important;
                font-family: Arial, sans-serif;
                color: #000 !important;
                height: auto;
            }
            
            textarea {
                border: none !important;
                background: transparent !important;
                padding: 0 !important;
                margin: 0 !important;
                font-size: 9.5pt !important;
                font-family: Arial, sans-serif;
                color: #000 !important;
                resize: none !important;
                width: 100% !important;
                overflow: hidden !important;
            }

            .info-grid {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 4px 12px !important;
            }
            .form-group { margin-bottom: 6px !important; }
            .form-group label { font-size: 8.5pt !important; color: #555 !important; margin-bottom: 1px !important; }

            .print-footer-signature {
                display: block !important;
                margin-top: 30px;
                page-break-inside: avoid;
            }
            .print-footer-signature table { width: 100%; border-collapse: collapse; }
            .print-footer-signature td { width: 50%; vertical-align: top; }
            .signature-box {
                float: right;
                width: 260px;
                text-align: center;
                border-top: 1px solid #333;
                padding-top: 6px;
                font-size: 9.5pt;
                color: #111;
            }

            .print-only { display: block !important; }

            .is-empty-field {
                display: none !important;
            }
        }

        @media (min-width: 768px) {
            body { margin: 20px; }
            header { flex-direction: row; justify-content: space-between; align-items: center; }
            header h1 { font-size: 1.8em; }
        }
    </style>
</head>
<body>

    <div class="print-header">
        <table>
            <tr>
                <?php if (!empty($logo_medico)): ?>
                <td class="clinic-logo" style="width: 80px; padding-right: 15px;">
                    <img src="<?php echo htmlspecialchars($logo_medico); ?>" alt="Logo Studio">
                </td>
                <?php endif; ?>
                <td class="clinic-info">
                    <strong>PROMAN 2.0 - Gestione Ambulatorio</strong><br>
                    <?php echo htmlspecialchars($ambulatorio_medico); ?><br>
                    Data referto: <?php echo htmlspecialchars($controllo['data_controllo'] ?? date('Y-m-d')); ?>
                </td>
                <td class="doc-info">
                    <strong><?php echo htmlspecialchars($titolo_medico . ' ' . $nome_utente_completo); ?></strong><br>
                    <?php echo htmlspecialchars($specializzazione_medico); ?><br>
                    Matricola: <?php echo htmlspecialchars($matricola_medico); ?>
                </td>
            </tr>
        </table>
    </div>

    <header>
        <h1>Dettaglio e Gestione Visita</h1>
        <div class="header-actions">
            <div class="nav-links">
                <a href="dashboard.php" class="btn-dashboard">🏠 Dashboard</a>
                <?php if ($chirurgia_mano_attiva): ?>
                <a href="chirurgia_della_mano.php?paziente_id=<?php echo urlencode($paziente_id ?? ''); ?>&controllo_id=<?php echo urlencode($controllo_id); ?>" class="btn-chirurgia">✋ Chirurgia della Mano</a>
                <?php endif; ?>
                <?php if ($cardiologia_attiva): ?>
                <a href="cardiologia.php?paziente_id=<?php echo urlencode($paziente_id ?? ''); ?>&controllo_id=<?php echo urlencode($controllo_id); ?>" class="btn-cardiologia">❤️ Eco-cardio</a>
                <?php endif; ?>
                <a href="planner.php?data=<?php echo htmlspecialchars($controllo['data_controllo'] ?? date('Y-m-d')); ?>" class="btn-indietro">&larr; Torna al Planner</a>
            </div>
            <div>
                <button type="button" class="btn-stampa" onclick="preparaStampaEStampa();">🖨️ Stampa Referto Professionale</button>
                <span class="no-print">| Medico: <strong><?php echo htmlspecialchars($titolo_medico . ' ' . $nome_utente_completo); ?></strong> | </span>
                <a href="logout.php" style="color: #920917; text-decoration: none;" class="no-print">Logout</a>
            </div>
        </div>
    </header>

    <?php if ($messaggio_successo): ?>
        <div class="alert-success no-print"><?php echo htmlspecialchars($messaggio_successo); ?></div>
    <?php endif; ?>

    <?php if ($messaggio_errore): ?>
        <div class="alert-danger no-print"><?php echo htmlspecialchars($messaggio_errore); ?></div>
    <?php endif; ?>

    <!-- SEZIONE ANAGRAFICA PAZIENTE -->
    <div class="card">
        <h2>Paziente</h2>
        <?php if ($paziente): ?>
            <form method="POST" action="visita.php?id=<?php echo urlencode($controllo_id); ?>">
                <input type="hidden" name="form_inviato" value="paziente">
                
                <div class="info-grid" style="margin-bottom: 10px;">
                    <?php 
                    $val_p_cognome = trim($paziente['cognome'] ?? '');
                    $class_p_cognome = ($val_p_cognome === '') ? 'form-group is-empty-field' : 'form-group';
                    ?>
                    <div class="<?php echo $class_p_cognome; ?>">
                        <label for="p_cognome">Cognome:</label>
                        <input type="text" id="p_cognome" name="p_cognome" value="<?php echo htmlspecialchars($paziente['cognome'] ?? ''); ?>" required>
                    </div>
                    <?php 
                    $val_p_nome = trim($paziente['nome'] ?? '');
                    $class_p_nome = ($val_p_nome === '') ? 'form-group is-empty-field' : 'form-group';
                    ?>
                    <div class="<?php echo $class_p_nome; ?>">
                        <label for="p_nome">Nome:</label>
                        <input type="text" id="p_nome" name="p_nome" value="<?php echo htmlspecialchars($paziente['nome'] ?? ''); ?>" required>
                    </div>
                    <?php 
                    $val_p_tel = trim($paziente['telefono'] ?? '');
                    $class_p_tel = ($val_p_tel === '') ? 'form-group is-empty-field' : 'form-group';
                    ?>
                    <div class="<?php echo $class_p_tel; ?>">
                        <label for="p_telefono">Telefono:</label>
                        <input type="text" id="p_telefono" name="p_telefono" value="<?php echo htmlspecialchars($paziente['telefono'] ?? ''); ?>">
                    </div>
                    <?php 
                    $val_p_nascita = trim($paziente['data_nascita'] ?? '');
                    $class_p_nascita = ($val_p_nascita === '') ? 'form-group is-empty-field' : 'form-group';
                    ?>
                    <div class="<?php echo $class_p_nascita; ?>">
                        <label for="p_data_nascita">Data di Nascita:</label>
                        <input type="date" id="p_data_nascita" name="p_data_nascita" value="<?php echo htmlspecialchars($paziente['data_nascita'] ?? ''); ?>">
                    </div>
                    <?php 
                    $val_p_luogo = trim($paziente['luogo_nascita'] ?? '');
                    $class_p_luogo = ($val_p_luogo === '') ? 'form-group is-empty-field' : 'form-group';
                    ?>
                    <div class="<?php echo $class_p_luogo; ?>">
                        <label for="p_luogo_nascita">Luogo di Nascita:</label>
                        <input type="text" id="p_luogo_nascita" name="p_luogo_nascita" value="<?php echo htmlspecialchars($paziente['luogo_nascita'] ?? ''); ?>">
                    </div>
                </div>

                <div class="no-print" style="margin-top: 10px;">
                    <button type="submit" class="btn-salva" style="font-size: 0.9em; padding: 8px 15px;">💾 Aggiorna Dati Paziente</button>
                </div>
            </form>
        <?php else: ?>
            <p>Nessun paziente associato a questo controllo.</p>
        <?php endif; ?>
    </div>

    <!-- SEZIONE VISITA / CONTROLLO -->
    <div class="card">
        <h2>Dettagli Visita / Controllo</h2>
        <form method="POST" action="visita.php?id=<?php echo urlencode($controllo_id); ?>" enctype="multipart/form-data">
            <input type="hidden" name="form_inviato" value="visita">

            <div class="info-grid" style="margin-bottom: 15px;">
                <div class="form-group">
                    <label for="data_controllo">Data Visita:</label>
                    <input type="date" id="data_controllo" name="data_controllo" value="<?php echo htmlspecialchars($data_controllo_corrente); ?>">
                </div>
                <div class="form-group">
                    <label for="orario">Orario:</label>
                    <input type="text" id="orario" name="orario" value="<?php echo htmlspecialchars($orario_attuale); ?>" placeholder="HH:MM">
                </div>
                <div class="form-group">
                    <label for="tipo_controllo">Tipo Controllo:</label>
                    <input type="text" id="tipo_controllo" name="tipo_controllo" value="<?php echo htmlspecialchars($controllo['tipo_controllo'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="stato">Stato:</label>
                    <select id="stato" name="stato">
                        <option value="Effettuato" <?php echo (($controllo['stato'] ?? '') === 'Effettuato') ? 'selected' : ''; ?>>Effettuato</option>
                        <option value="Programmato" <?php echo (($controllo['stato'] ?? '') === 'Programmato') ? 'selected' : ''; ?>>Programmato</option>
                        <option value="Annullato" <?php echo (($controllo['stato'] ?? '') === 'Annullato') ? 'selected' : ''; ?>>Annullato</option>
                    </select>
                </div>
                
                <?php 
                $val_c_data_int = trim($controllo['data_intervento'] ?? '');
                $class_c_data_int = ($val_c_data_int === '') ? 'form-group is-empty-field' : 'form-group';
                ?>
                <div class="<?php echo $class_c_data_int; ?>">
                    <label for="data_intervento">Data Intervento:</label>
                    <input type="date" id="data_intervento" name="data_intervento" value="<?php echo htmlspecialchars($controllo['data_intervento'] ?? ''); ?>">
                </div>

                <?php 
                $val_c_tip_int = trim($controllo['tipologia_intervento'] ?? '');
                $class_c_tip_int = ($val_c_tip_int === '') ? 'form-group is-empty-field' : 'form-group';
                ?>
                <div class="<?php echo $class_c_tip_int; ?>">
                    <label for="tipologia_intervento">Tipologia Intervento:</label>
                    <input type="text" id="tipologia_intervento" name="tipologia_intervento" value="<?php echo htmlspecialchars($controllo['tipologia_intervento'] ?? ''); ?>">
                </div>
            </div>

            <?php 
            $val_diagnosi = trim($controllo['diagnosi'] ?? '');
            $class_diagnosi = ($val_diagnosi === '') ? 'form-group is-empty-field' : 'form-group';
            ?>
            <div class="<?php echo $class_diagnosi; ?>">
                <label for="diagnosi_visita">Diagnosi / Anamnesi:</label>
                <textarea id="diagnosi_visita" name="diagnosi_visita"><?php echo htmlspecialchars($controllo['diagnosi'] ?? ''); ?></textarea>
            </div>

            <?php 
            $val_esito = trim($controllo['esito'] ?? '');
            $class_esito = ($val_esito === '') ? 'form-group is-empty-field' : 'form-group';
            ?>
            <div class="<?php echo $class_esito; ?>">
                <label for="esito">Esito / Esame Obiettivo:</label>
                <textarea id="esito" name="esito"><?php echo htmlspecialchars($controllo['esito'] ?? ''); ?></textarea>
            </div>

            <?php 
            $val_terapia = trim($controllo['terapia'] ?? '');
            $class_terapia = ($val_terapia === '') ? 'form-group is-empty-field' : 'form-group';
            ?>
            <div class="<?php echo $class_terapia; ?>">
                <label for="terapia">Terapia / Prescrizioni:</label>
                <textarea id="terapia" name="terapia"><?php echo htmlspecialchars($controllo['terapia'] ?? ''); ?></textarea>
            </div>

            <?php 
            $val_note = trim($note_pulita);
            $class_note = ($val_note === '') ? 'form-group is-empty-field' : 'form-group';
            ?>
            <div class="<?php echo $class_note; ?>">
                <label for="note">Note Aggiuntive:</label>
                <textarea id="note" name="note"><?php echo htmlspecialchars($note_pulita); ?></textarea>
            </div>

            <!-- GESTIONE ALLEGATI MULTIPLI SU SUPABASE CLOUD -->
            <div class="form-group no-print">
                <label for="allegati">Allega File / Documenti / Immagini / Video:</label>
                <input type="file" id="allegati" name="allegati[]" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.mp4,.mov,.avi,.webm,.svg">
                <small style="color: #666; display: block; margin-top: 4px;">I file vengono salvati direttamente nel cloud Supabase Storage (persistenti).</small>
            </div>

            <?php if (!empty($controllo['file_path'])): ?>
                <div class="form-group" style="margin-top: 15px;">
                    <label>File allegati a questa visita:</label>
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;">
                        <?php 
                        $file_salvati = explode(',', $controllo['file_path']);
                        foreach ($file_salvati as $f_path) {
                            $f_path = trim($f_path);
                            if (!empty($f_path)) {
                                $nome_file_visivo = basename($f_path);
                                ?>
                                <a href="<?php echo htmlspecialchars($f_path); ?>" target="_blank" class="badge-allegato">📎 <?php echo htmlspecialchars($nome_file_visivo); ?></a>
                                <?php
                            }
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($controllo['log_modifiche'])): ?>
                <div class="form-group no-print" style="margin-top: 15px; background: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px dashed #ccc;">
                    <label style="font-size: 0.85em; color: #666; margin-bottom: 3px;">Cronologia Modifiche:</label>
                    <pre style="margin: 0; font-size: 0.8em; color: #444; white-space: pre-wrap; font-family: Arial, sans-serif;"><?php echo htmlspecialchars($controllo['log_modifiche']); ?></pre>
                </div>
            <?php endif; ?>

            <div style="margin-top: 20px;" class="no-print">
                <button type="submit" class="btn-salva">💾 Salva Modifiche Visita</button>
            </div>
        </form>
    </div>

    <!-- STORICO CONTROLLI DEL PAZIENTE -->
    <?php if (!empty($storico_controlli)): ?>
    <div class="card no-print">
        <h2>Storico Clinico / Visite del Paziente</h2>
        <div style="overflow-x: auto;">
            <table class="table-storico">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Orario</th>
                        <th>Tipo Controllo</th>
                        <th>Diagnosi</th>
                        <th>Stato</th>
                        <th>Azione</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storico_controlli as $item): 
                        $s_id = $item['id'];
                        $s_data = $item['data_controllo'] ?? '';
                        $s_tipo = $item['tipo_controllo'] ?? '';
                        $s_diag = $item['diagnosi'] ?? '';
                        $s_stato = $item['stato'] ?? '';
                        
                        $s_orario = '';
                        if (!empty($item['note'])) {
                            if (preg_match('/(?:Orario:|@|\[ORARIO:)\s*(\d{2}:\d{2})?/i', $item['note'], $m) && !empty($m[1])) {
                                $s_orario = $m[1];
                            }
                        }
                        if (empty($s_orario) && !empty($item['created_at'])) {
                            $s_orario = date('H:i', strtotime($item['created_at']));
                        }

                        $classe_badge = 'badge-passato';
                        if ($s_id == $controllo_id) {
                            $classe_badge = 'badge-corrente';
                        } elseif ($s_data > date('Y-m-d')) {
                            $classe_badge = 'badge-futuro';
                        }
                    ?>
                    <tr>
                        <td>
                            <span class="<?php echo $classe_badge; ?>"><?php echo htmlspecialchars($s_data); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars($s_orario); ?></td>
                        <td><?php echo htmlspecialchars($s_tipo); ?></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth($s_diag, 0, 50, '...')); ?></td>
                        <td><?php echo htmlspecialchars($s_stato); ?></td>
                        <td>
                            <?php if ($s_id == $controllo_id): ?>
                                <strong>(Visita Corrente)</strong>
                            <?php else: ?>
                                <a href="visita.php?id=<?php echo urlencode($s_id); ?>" class="btn-indietro" style="padding: 3px 8px; font-size: 0.8em;">Apri</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="print-footer-signature">
        <table>
            <tr>
                <td></td>
                <td>
                    <div class="signature-box">
                        <br><br>
                        <strong><?php echo htmlspecialchars($titolo_medico . ' ' . $nome_utente_completo); ?></strong><br>
                        <span style="font-size: 8.5pt; color: #555;"><?php echo htmlspecialchars($specializzazione_medico); ?></span>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <script>
        // Auto-resize delle textarea in base al contenuto
        function autoResizeTextareas() {
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(ta => {
                ta.style.height = 'auto';
                ta.style.height = (ta.scrollHeight + 4) + 'px';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            autoResizeTextareas();
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(ta => {
                ta.addEventListener('input', () => {
                    ta.style.height = 'auto';
                    ta.style.height = (ta.scrollHeight + 4) + 'px';
                });
            });
        });

        function preparaStampaEStampa() {
            // Espande tutte le textarea prima di stampare per catturare tutto il testo
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(ta => {
                ta.style.height = 'auto';
                ta.style.height = (ta.scrollHeight + 10) + 'px';
            });
            window.print();
        }
    </script>
</body>
</html>
