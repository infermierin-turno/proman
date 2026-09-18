<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$utente = $_SESSION['utente'];
$studio_id = $utente['studio_id'] ?? null;
$utente_id = $utente['id'] ?? null;
$ruolo = strtolower(trim($utente['ruolo'] ?? ''));

// VERIFICA ABBONAMENTO
$info_abbonamento = verifica_abbonamento($studio_id);
if ($info_abbonamento['stato'] === 'scaduto') {
    header("Location: dashboard.php");
    exit;
}

// Estrazione della lista dei medici dello studio in base al ruolo
$medici_studio = [];
if (!empty($studio_id)) {
    $ris_medici = supabase_request('medici?studio_id=eq.' . urlencode($studio_id) . '&select=id,nome,cognome,titolo,ruolo', 'GET');
    if (!empty($ris_medici) && !isset($ris_medici['error'])) {
        foreach ($ris_medici as $m) {
            $r_m = strtolower(trim($m['ruolo'] ?? ''));
            if ($r_m !== 'segretario' && $r_m !== 'segreteria' && $r_m !== 'admin') {
                $medici_studio[] = $m;
            }
        }
        if (empty($medici_studio)) {
            foreach ($ris_medici as $m) {
                $r_m = strtolower(trim($m['ruolo'] ?? ''));
                if ($r_m === 'medico' || empty($r_m)) {
                    $medici_studio[] = $m;
                }
            }
        }
    }
}

// Fallback se la lista è vuota
if (empty($medici_studio)) {
    $medici_studio[] = [
        'id' => $utente_id,
        'titolo' => $utente['titolo'] ?? 'Dr.',
        'nome' => $utente['nome'] ?? 'Mio Profilo',
        'cognome' => $utente['cognome'] ?? '',
        'ruolo' => $ruolo
    ];
}

// REGOLA RUOLO: Se l'utente è un medico, il medico selezionato DEVE essere tassativamente il suo ID.
if ($ruolo === 'medico') {
    $medico_selezionato_id = $utente_id;
} else {
    $medico_selezionato_id = $_GET['medico_id'] ?? null;
    if (empty($medico_selezionato_id)) {
        $medico_selezionato_id = $medici_studio[0]['id'] ?? $utente_id;
    }
}

// Gestione aggiornamento rapido dello stato dal planner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione_stato'], $_POST['controllo_id'], $_POST['nuovo_stato'])) {
    $controllo_id_post = $_POST['controllo_id'];
    $nuovo_stato = trim($_POST['nuovo_stato']);
    
    $paziente_id_post = $_POST['paziente_id'] ?? ($_GET['paziente_id'] ?? '');

    $stati_ammessi = ['Programmato', 'Effettuato', 'Annullato'];
    if (in_array($nuovo_stato, $stati_ammessi)) {
        $dati_aggiornamento = ['stato' => $nuovo_stato];
        if (!empty($medico_selezionato_id)) {
            $dati_aggiornamento['medico_id'] = $medico_selezionato_id;
        }
        $risultato_patch = supabase_request('controlli?id=eq.' . urlencode($controllo_id_post) . '&studio_id=eq.' . urlencode($studio_id), 'PATCH', $dati_aggiornamento);
    }
    
    $data_redir = $_GET['data'] ?? date('Y-m-d');
    $paz_redir = !empty($paziente_id_post) ? '&paziente_id=' . urlencode($paziente_id_post) : '';
    $med_redir = ($ruolo !== 'medico') ? '&medico_id=' . urlencode($medico_selezionato_id) : '';
    header("Location: planner.php?data=" . urlencode($data_redir) . $paz_redir . $med_redir . "&msg=" . urlencode("Stato aggiornato con successo"));
    exit;
}

// Estrazione sicura dei dati utente
$nome_utente = '';
if (is_array($utente)) {
    $nome_utente = trim(($utente['titolo'] ?? '') . ' ' . ($utente['cognome'] ?? ($utente['nome'] ?? 'Utente')));
} else {
    $nome_utente = $utente;
}

// Gestione paziente preselezionato da anagrafica (UUID string)
$paziente_id = isset($_GET['paziente_id']) ? trim($_GET['paziente_id']) : null;
$paziente_selezionato = null;
$url_params_paziente = '';

if (!empty($paziente_id)) {
    $risultato_paziente_sel = supabase_request('pazienti?id=eq.' . urlencode($paziente_id), 'GET');
    if (!empty($risultato_paziente_sel) && !isset($risultato_paziente_sel['error'])) {
        $paziente_selezionato = $risultato_paziente_sel[0];
        $url_params_paziente = '&paziente_id=' . urlencode($paziente_id);
    } else {
        $paziente_id = null;
    }
}

$url_params_medico = ($ruolo !== 'medico') ? '&medico_id=' . urlencode($medico_selezionato_id) : '';
$url_params_totale = $url_params_paziente . $url_params_medico;

// Data selezionata
$data_corrente = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

// Mini calendario mensile
$timestamp_corrente = strtotime($data_corrente);
$anno_corrente = date('Y', $timestamp_corrente);
$mese_corrente = date('m', $timestamp_corrente);

$primo_giorno_mese = strtotime("$anno_corrente-$mese_corrente-01");
$numero_giorni_mese = date('t', $primo_giorno_mese);
$giorno_settimana_inizio = date('N', $primo_giorno_mese);

$nomi_mesi = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
    5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
    9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];
$nome_mese_corrente = $nomi_mesi[(int)$mese_corrente];

$mese_precedente = date('Y-m-d', strtotime("-1 month", $primo_giorno_mese));
$mese_successivo = date('Y-m-d', strtotime("+1 month", $primo_giorno_mese));
$oggi = date('Y-m-d');

$data_ieri = date('Y-m-d', strtotime('-1 day', strtotime($data_corrente)));
$data_domani = date('Y-m-d', strtotime('+1 day', strtotime($data_corrente)));

// --- GENERAZIONE DINAMICA DEGLI ORARI IN BASE A MEDICI_DISPONIBILITA ---
$giorno_settimana_num = (int)date('w', $timestamp_corrente);
$orari = [];

if (!empty($medico_selezionato_id) && !empty($studio_id)) {
    $url_disp = "medici_disponibilita?medico_id=eq." . urlencode($medico_selezionato_id) . "&studio_id=eq." . urlencode($studio_id) . "&giorno_settimana=eq." . $giorno_settimana_num . "&attivo=eq.true&select=*";
    $disponibilita_medico = supabase_request($url_disp, 'GET');

    if (!empty($disponibilita_medico) && is_array($disponibilita_medico) && !isset($disponibilita_medico['error'])) {
        foreach ($disponibilita_medico as $disp) {
            $inizio_fascia = strtotime($disp['ora_inizio']);
            $fine_fascia = strtotime($disp['ora_fine']);
            $durata_slot = isset($disp['durata_slot_minuti']) && (int)$disp['durata_slot_minuti'] > 0 ? (int)$disp['durata_slot_minuti'] : 30;

            while ($inizio_fascia <= $fine_fascia) {
                $ora_formattata = date('H:i', $inizio_fascia);
                if (!in_array($ora_formattata, $orari)) {
                    $orari[] = $ora_formattata;
                }
                $inizio_fascia = strtotime("+{$durata_slot} minutes", $inizio_fascia);
            }
        }
        sort($orari);
    }
}

// Mini-calendario mensile (conteggio controlli dello studio per colorare i giorni)
$data_inizio_mese = "$anno_corrente-$mese_corrente-01";
$data_fine_mese = "$anno_corrente-$mese_corrente-$numero_giorni_mese";
$risultato_mese = [];
if (!empty($studio_id)) {
    $query_mese = "controlli?studio_id=eq.{$studio_id}&data_controllo=gte.{$data_inizio_mese}&data_controllo=lte.{$data_fine_mese}&select=data_controllo,note,id";
    $risultato_mese = supabase_request($query_mese, 'GET');
}

$occupazione_giorni = [];
if (!empty($risultato_mese) && !isset($risultato_mese['error'])) {
    foreach ($risultato_mese as $c) {
        $d_c = $c['data_controllo'] ?? '';
        if (strlen($d_c) >= 10) {
            $d_c = substr($d_c, 0, 10);
        }
        if ($d_c) {
            if (!isset($occupazione_giorni[$d_c])) {
                $occupazione_giorni[$d_c] = 0;
            }
            $occupazione_giorni[$d_c]++;
        }
    }
}
$totale_slot_giornalieri = max(count($orari), count($risultato_mese));

// --- ESTRAZIONE ROBUSTA DI TUTTI I CONTROLLI GIORNALIERI E RISOLUZIONE PAZIENTI ---
$appuntamenti_per_orario = [];
$risultato_controlli = [];

if (!empty($studio_id)) {
    $giorno_inizio_filtro = $data_corrente . ' 00:00:00';
    $giorno_fine_filtro = $data_corrente . ' 23:59:59';
    $query_controlli = 'controlli?studio_id=eq.' . urlencode($studio_id) . '&data_controllo=gte.' . urlencode($giorno_inizio_filtro) . '&data_controllo=lte.' . urlencode($giorno_fine_filtro) . '&select=*';
    $risultato_controlli = supabase_request($query_controlli, 'GET');
    
    if (empty($risultato_controlli) || isset($risultato_controlli['error'])) {
        $query_controlli_alt = 'controlli?studio_id=eq.' . urlencode($studio_id) . '&data_controllo=eq.' . urlencode($data_corrente) . '&select=*';
        $risultato_controlli = supabase_request($query_controlli_alt, 'GET');
    }
}

if (!empty($risultato_controlli) && !isset($risultato_controlli['error'])) {
    foreach ($risultato_controlli as $key => $c) {
        $medico_record = $c['medico_id'] ?? null;
        
        if (!empty($medico_record) && $medico_record != $medico_selezionato_id) {
            continue;
        }

        $orario_trovato = '';
        $note_controllo = $c['note'] ?? '';
        
        if (!empty($c['orario'])) {
            $orario_trovato = $c['orario'];
        }
        
        if (empty($orario_trovato) && !empty($note_controllo)) {
            if (preg_match('/\[ORARIO:(\d{2}:\d{2})\]/i', $note_controllo, $matches) && !empty($matches[1])) {
                $orario_trovato = $matches[1];
            } elseif (preg_match('/(?:Orario:|@|ore)\s*(\d{2}:\d{2})/i', $note_controllo, $matches2) && !empty($matches2[1])) {
                $orario_trovato = $matches2[1];
            }
        }

        if (empty($orario_trovato) && !empty($c['data_controllo']) && strlen($c['data_controllo']) > 10) {
            $potenziale_ora = substr($c['data_controllo'], 11, 5);
            if (preg_match('/^\d{2}:\d{2}$/', $potenziale_ora) && $potenziale_ora !== '00:00') {
                $orario_trovato = $potenziale_ora;
            }
        }
        
        if (empty($orario_trovato)) {
            if (!empty($c['created_at'])) {
                $orario_trovato = date('H:i', strtotime($c['created_at']));
            } else {
                $orario_trovato = !empty($orari) && isset($orari[$key % count($orari)]) ? $orari[$key % count($orari)] : sprintf('%02d:00', 8 + ($key % 10));
            }
        }

        if ($orario_trovato) {
            $orario_trovato = date('H:i', strtotime($orario_trovato));
            
            $tentativi = 0;
            $orario_finale = $orario_trovato;
            while (isset($appuntamenti_per_orario[$orario_finale]) && $tentativi < 50) {
                $timestamp_orario = strtotime($orario_finale);
                $timestamp_orario = strtotime('+15 minutes', $timestamp_orario);
                $orario_finale = date('H:i', $timestamp_orario);
                $tentativi++;
            }

            if (!in_array($orario_finale, $orari)) {
                $orari[] = $orario_finale;
                sort($orari);
            }

            // Risoluzione dati paziente tramite UUID (paziente_id)
            $paziente_dati = null;
            $id_paziente_controllo = $c['paziente_id'] ?? null;
            
            if (!empty($id_paziente_controllo)) {
                $risultato_paziente = supabase_request('pazienti?id=eq.' . urlencode($id_paziente_controllo), 'GET');
                if (!empty($risultato_paziente) && !isset($risultato_paziente['error'])) {
                    $paziente_dati = $risultato_paziente[0];
                }
            }
            
            if (empty($paziente_dati)) {
                $paziente_dati = [
                    'nome' => $c['paziente_nome'] ?? ($c['nome_paziente'] ?? 'Paziente Esterno'),
                    'cognome' => $c['paziente_cognome'] ?? ($c['cognome_paziente'] ?? ''),
                    'telefono' => $c['telefono'] ?? ($c['paziente_telefono'] ?? '')
                ];
            }

            $c['pazienti'] = $paziente_dati;
            $appuntamenti_per_orario[$orario_finale] = $c;
        }
    }
}

function getBadgeStyle($tipo_controllo) {
    $tipo = mb_strtolower(trim($tipo_controllo));
    $bg = '#ced4da';     // <-- Sfondo predefinito più marcato
    $color = '#212529';  // <-- Colore testo più marcato

    if (strpos($tipo, 'prima visita') !== false) {
        $bg = '#a3cfbb';  // <-- Sfondo Prima Visita più marcato
        $color = '#032810'; // <-- Colore testo più marcato
    } elseif (strpos($tipo, 'medicazione') !== false) {
        $bg = '#f1aeb5';  // <-- Sfondo Medicazione più marcato
        $color = '#58151c'; // <-- Colore testo più marcato
    } elseif (strpos($tipo, 'rimozione punti') !== false) {
        $bg = '#ffe69c';  // <-- Sfondo Rimozione Punti più marcato
        $color = '#382a01'; // <-- Colore testo più marcato
    } elseif (strpos($tipo, 'controllo') !== false) {
        $bg = '#9eeaf9';  // <-- Sfondo Controllo più marcato
        $color = '#032830'; // <-- Colore testo più marcato
    } elseif (strpos($tipo, 'visita legale') !== false) {
        $bg = '#c5b8e8';  // <-- Sfondo Visita Legale più marcato
        $color = '#210d4e'; // <-- Colore testo più marcato
    }

    return "background-color: {$bg}; color: {$color}; padding: 4px 10px; border-radius: 6px; font-size: 0.8em; display: inline-block; font-weight: 600;";
}

function getCardHighlightStyle($tipo_controllo) {
    $tipo = mb_strtolower(trim($tipo_controllo));
    $border_color = '#0b5ed7'; // <-- Bordo predefinito più marcato
    $bg_color = '#f8f9fa';     // <-- Sfondo scheda predefinito leggermente più marcato

    if (strpos($tipo, 'prima visita') !== false) {
        $border_color = '#146c43';
        $bg_color = '#e8f6f0';  // <-- Sfondo scheda Prima Visita più marcato
    } elseif (strpos($tipo, 'medicazione') !== false) {
        $border_color = '#b02a37';
        $bg_color = '#fcf0f1';  // <-- Sfondo scheda Medicazione più marcato
    } elseif (strpos($tipo, 'rimozione punti') !== false) {
        $border_color = '#ffaa00';
        $bg_color = '#fdfae6';  // <-- Sfondo scheda Rimozione Punti più marcato
    } elseif (strpos($tipo, 'controllo') !== false) {
        $border_color = '#08b1d9';
        $bg_color = '#eaf8fc';  // <-- Sfondo scheda Controllo più marcato
    } elseif (strpos($tipo, 'visita legale') !== false) {
        $border_color = '#59359a';
        $bg_color = '#f4f0fa';  // <-- Sfondo scheda Visita Legale più marcato
    }

    return "border-left-color: {$border_color}; background-color: {$bg_color};";
}

function getBadgeStatoStyle($stato_visita) {
    $stato = mb_strtolower(trim($stato_visita));
    $bg = '#ced4da';     // <-- Sfondo predefinito più marcato
    $color = '#212529';  // <-- Colore testo più marcato

    if (strpos($stato, 'effettuato') !== false) {
        $bg = '#a3cfbb';  // <-- Sfondo Effettuato più marcato
        $color = '#032810'; // <-- Colore testo più marcato
    } elseif (strpos($stato, 'programmato') !== false) {
        $bg = '#9eeaf9';  // <-- Sfondo Programmato più marcato
        $color = '#032830'; // <-- Colore testo più marcato
    } elseif (strpos($stato, 'annullato') !== false) {
        $bg = '#f1aeb5';  // <-- Sfondo Annullato più marcato
        $color = '#58151c'; // <-- Colore testo più marcato
    } elseif (strpos($stato, 'spostato') !== false) {
        $bg = '#ffe69c';  // <-- Sfondo Spostato più marcato
        $color = '#382a01'; // <-- Colore testo più marcato
    }

    return "background-color: {$bg}; color: {$color}; padding: 4px 10px; border-radius: 6px; font-size: 0.8em; display: inline-block; font-weight: 600;";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planner Appuntamenti - Proman</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 12px; background-color: #f8f9fa; color: #333; }
        header { display: flex; flex-direction: column; gap: 10px; margin-bottom: 15px; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        header h1 { font-size: 1.4em; margin: 0; color: #1a1a1a; }
        .header-actions { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; font-size: 0.9em; }
        .banner-paziente { background-color: #e3f2fd; color: #0d47a1; padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; border: 1px solid #bbdefb; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
        .banner-paziente strong { font-size: 1.05em; }
        .btn-annulla { background-color: #0d47a1; color: white; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        .btn-annulla:hover { background-color: #1565c0; }
        
        .selettore-medico-box, .nav-date, .legenda-box { margin-bottom: 15px; background: #fff; padding: 15px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .legenda-box h3 { margin-top: 0; margin-bottom: 8px; font-size: 0.95em; color: #555; }
        .legenda-items { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        
        .calendar-accordion { margin-bottom: 15px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
        .accordion-toggle { width: 100%; background: #fff; border: none; padding: 15px; font-size: 1em; font-weight: bold; color: #333; display: flex; justify-content: space-between; align-items: center; cursor: pointer; text-align: left; }
        .accordion-toggle:hover { background-color: #f1f3f5; }
        .accordion-content { display: none; padding: 0 15px 15px 15px; border-top: 1px solid #eee; }
        .accordion-content.open { display: block; }

        .cal-header { display: flex; justify-content: space-between; align-items: center; margin: 15px 0 10px 0; font-weight: bold; font-size: 0.95em; }
        .cal-header a { text-decoration: none; background: #0d6efd; color: white; padding: 6px 12px; border-radius: 6px; font-size: 0.85em; }
        .cal-header a:hover { background: #0b5ed7; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
        .cal-day-name { font-weight: bold; font-size: 0.75em; color: #6c757d; padding: 4px 0; }
        .cal-day { padding: 10px 0; background: #f8f9fa; border-radius: 6px; text-decoration: none; color: #333; font-size: 0.85em; font-weight: bold; display: block; border: 1px solid #e9ecef; }
        .cal-day:hover { background: #e9ecef; }
        .cal-day.selected { border: 2px solid #0d6efd; background: #e7f1ff; color: #0d6efd; }
        .cal-day.today { font-weight: 900; text-decoration: underline; }
        .cal-day.pieno { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; } 
        .cal-day.parziale { background-color: #fff3cd; color: #664d03; border: 1px solid #ffecb5; } 
        .cal-empty { background: transparent; border: none; }

        .quick-date-bar { display: flex; gap: 8px; margin-bottom: 15px; }
        .btn-quick-date { flex: 1; text-align: center; background: #fff; border: 1px solid #ced4da; padding: 10px; border-radius: 8px; text-decoration: none; font-size: 0.9em; font-weight: bold; color: #495057; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .btn-quick-date:hover { background: #f1f3f5; color: #212529; }
        .btn-quick-date.active { background: #0d6efd; color: white; border-color: #0d6efd; }

        .planner-container { display: flex; flex-direction: column; gap: 10px; margin-bottom: 30px; }
        .slot-card { background: #fff; border-radius: 12px; padding: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 10px; border-left: 5px solid #ced4da; transition: background-color 0.2s ease, border-color 0.2s ease; }
        .slot-card.libero { border-left-color: #198754; background-color: #fcfdfd; }
        
        .slot-header-row { display: flex; justify-content: space-between; align-items: center; }
        .slot-time { font-size: 1.1em; font-weight: 800; color: #212529; }
        
        .patient-info { display: flex; flex-direction: column; gap: 4px; }
        .patient-name { font-size: 1.05em; font-weight: bold; color: #212529; }
        .patient-details { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 0.9em; color: #6c757d; }
        
        .phone-link { color: #0d6efd; text-decoration: none; font-weight: bold; display: inline-flex; align-items: center; gap: 4px; }
        .phone-link:hover { text-decoration: underline; }

        .slot-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; border-top: 1px solid #f1f3f5; padding-top: 10px; margin-top: 2px; }
        
        .btn-gestisci { background-color: #0d6efd; color: white; padding: 8px 14px; border-radius: 6px; font-weight: bold; text-decoration: none; font-size: 0.85em; display: inline-block; }
        .btn-gestisci:hover { background-color: #0b5ed7; }
        
        .form-cambio-stato { display: flex; gap: 6px; align-items: center; flex-grow: 1; justify-content: flex-end; }
        .form-cambio-stato select { padding: 6px; font-size: 0.85em; border-radius: 6px; border: 1px solid #ced4da; background: #fff; }
        .form-cambio-stato button { padding: 6px 12px; font-size: 0.85em; background: #212529; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .form-cambio-stato button:hover { background: #000; }

        .btn-prenota { background-color: #198754; color: white; padding: 8px 14px; border-radius: 6px; font-weight: bold; text-decoration: none; font-size: 0.9em; display: block; text-align: center; width: 100%; }
        .btn-prenota:hover { background-color: #157347; }

        input[type="date"], select.form-control { padding: 10px; border: 1px solid #ced4da; border-radius: 8px; font-size: 1em; width: 100%; background: #fff; }

        @media (min-width: 768px) {
            body { padding: 20px; }
            header { flex-direction: row; justify-content: space-between; align-items: center; padding: 20px; }
            header h1 { font-size: 1.8em; }
            .cal-day { padding: 12px 0; font-size: 0.95em; }
            input[type="date"], select.form-control { width: auto; }
            .slot-card { flex-direction: row; align-items: center; justify-content: space-between; }
            .slot-header-row { flex-direction: column; align-items: flex-start; min-width: 90px; }
            .patient-info { flex-grow: 1; padding: 0 15px; }
            .slot-actions { border-top: none; padding-top: 0; margin-top: 0; justify-content: flex-end; }
        }
    </style>
</head>
<body>

    <header>
        <h1>Planner Appuntamenti</h1>
        <div class="header-actions">
            <a href="dashboard.php" class="btn-dashboard" style="background:#6c757d; color:#fff; padding:6px 12px; border-radius:6px; text-decoration:none; font-size:0.85em;">&larr; Dashboard</a>
            <div>
                Utente: <strong><?php echo htmlspecialchars($nome_utente); ?></strong> (<?php echo ucfirst($ruolo); ?>) | 
                <a href="logout.php" style="color: #dc3545; text-decoration: none; font-weight: bold;">Logout</a>
            </div>
        </div>
    </header>

    <?php if (!empty($msg)): ?>
        <div style="background: #d1e7dd; color: #0f5132; padding: 12px 15px; margin-bottom: 15px; border-radius: 8px; border: 1px solid #badbcc; font-weight: bold;">
            <?php echo $msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($paziente_selezionato): ?>
        <div class="banner-paziente">
            <div>
                <span style="font-size: 0.85em; display: block; margin-bottom: 2px;">Seleziona uno slot libero per prenotare:</span>
                <strong><?php echo htmlspecialchars($paziente_selezionato['cognome'] . ' ' . $paziente_selezionato['nome']); ?></strong> (UUID: <?php echo htmlspecialchars($paziente_id); ?>)
            </div>
            <a href="planner.php?data=<?php echo htmlspecialchars($data_corrente); ?><?php echo ($ruolo !== 'medico') ? '&medico_id=' . urlencode($medico_selezionato_id) : ''; ?>" class="btn-annulla" title="Annulla selezione paziente">Annulla</a>
        </div>
    <?php endif; ?>

    <!-- SELETTORE MEDICO / AMBULATORIO -->
    <?php if ($ruolo !== 'medico'): ?>
    <div class="selettore-medico-box">
        <form method="GET" action="planner.php" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <?php if (!empty($paziente_id)): ?>
                <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_id); ?>">
            <?php endif; ?>
            <input type="hidden" name="data" value="<?php echo htmlspecialchars($data_corrente); ?>">
            
            <label for="medico_id" style="font-weight: bold; font-size: 0.95em; width: 100%;">Seleziona Medico dello Studio:</label>
            <select name="medico_id" id="medico_id" class="form-control" onchange="this.form.submit()" style="flex-grow: 1;">
                <?php foreach ($medici_studio as $med): ?>
                    <?php 
                        $label_titolo = trim($med['titolo'] ?? 'Dr.');
                        $label_nome_completo = trim(($med['cognome'] ?? '') . ' ' . ($med['nome'] ?? ''));
                        if (empty($label_nome_completo)) {
                            $label_nome_completo = $med['username'] ?? 'Medico';
                        }
                    ?>
                    <option value="<?php echo htmlspecialchars($med['id']); ?>" <?php if ($med['id'] == $medico_selezionato_id) echo 'selected'; ?>>
                        <?php echo htmlspecialchars("$label_titolo $label_nome_completo"); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php else: ?>
    <div class="selettore-medico-box" style="background: #e7f1ff; border: 1px solid #b6d4fe; color: #084298;">
        <span style="font-size: 0.95em; font-weight: bold;">Visualizzazione Planner Personale:</span> 
        <?php 
            $medico_corrente_info = current(array_filter($medici_studio, function($m) use ($utente_id) { return $m['id'] == $utente_id; }));
            $titolo_m = $medico_corrente_info['titolo'] ?? ($utente['titolo'] ?? 'Dr.');
            $nome_m = trim(($medico_corrente_info['cognome'] ?? ($utente['cognome'] ?? '')) . ' ' . ($medico_corrente_info['nome'] ?? ($utente['nome'] ?? '')));
            echo htmlspecialchars("$titolo_m $nome_m");
        ?>
    </div>
    <?php endif; ?>

    <!-- BARRA NAVIGAZIONE RAPIDA GIORNALIERA -->
    <div class="quick-date-bar">
        <a href="planner.php?data=<?php echo $data_ieri; ?><?php echo $url_params_totale; ?>" class="btn-quick-date">&larr; Ieri</a>
        <a href="planner.php?data=<?php echo $oggi; ?><?php echo $url_params_totale; ?>" class="btn-quick-date <?php echo ($data_corrente === $oggi) ? 'active' : ''; ?>">Oggi</a>
        <a href="planner.php?data=<?php echo $data_domani; ?><?php echo $url_params_totale; ?>" class="btn-quick-date">Domani &rarr;</a>
    </div>

    <!-- CALENDARIO MENSILE A SCOMPARSA (ACCORDION) -->
    <div class="calendar-accordion">
        <button type="button" class="accordion-toggle" onclick="toggleCalendar()">
            <span>📅 Calendario Mensile (<?php echo $nome_mese_corrente . ' ' . $anno_corrente; ?>)</span>
            <span id="accordion-icon">&#9660;</span>
        </button>
        <div id="calendar-content" class="accordion-content">
            <div class="cal-header">
                <a href="planner.php?data=<?php echo $mese_precedente; ?><?php echo $url_params_totale; ?>">&larr; Prec.</a>
                <span><?php echo $nome_mese_corrente . ' ' . $anno_corrente; ?></span>
                <a href="planner.php?data=<?php echo $mese_successivo; ?><?php echo $url_params_totale; ?>">Succ. &rarr;</a>
            </div>
            <div class="cal-grid">
                <div class="cal-day-name">Lun</div>
                <div class="cal-day-name">Mar</div>
                <div class="cal-day-name">Mer</div>
                <div class="cal-day-name">Gio</div>
                <div class="cal-day-name">Ven</div>
                <div class="cal-day-name">Sab</div>
                <div class="cal-day-name">Dom</div>

                <?php
                for ($i = 1; $i < $giorno_settimana_inizio; $i++) {
                    echo '<div class="cal-empty"></div>';
                }

                for ($giorno = 1; $giorno <= $numero_giorni_mese; $giorno++) {
                    $data_stringa = sprintf('%04d-%02d-%02d', $anno_corrente, $mese_corrente, $giorno);
                    $classi = 'cal-day';
                    
                    $num_appuntamenti = $occupazione_giorni[$data_stringa] ?? 0;
                    if ($num_appuntamenti > 0 && $totale_slot_giornalieri > 0 && $num_appuntamenti >= $totale_slot_giornalieri) {
                        $classi .= ' pieno'; 
                    } elseif ($num_appuntamenti > 0) {
                        $classi .= ' parziale'; 
                    }

                    if ($data_stringa === $data_corrente) {
                        $classi .= ' selected';
                    }
                    if ($data_stringa === $oggi) {
                        $classi .= ' today';
                    }

                    echo '<a href="planner.php?data=' . $data_stringa . $url_params_totale . '" class="' . $classi . '" title="' . $num_appuntamenti . ' appuntamenti">' . $giorno . '</a>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- LEGENDA -->
    <div class="legenda-box">
        <h3>Legenda Visite:</h3>
        <div class="legenda-items">
            <span style="<?php echo getBadgeStyle('Prima Visita'); ?>">Prima Visita</span>
            <span style="<?php echo getBadgeStyle('Medicazione'); ?>">Medicazione</span>
            <span style="<?php echo getBadgeStyle('Rimozione Punti'); ?>">Rimozione Punti</span>
            <span style="<?php echo getBadgeStyle('Controllo'); ?>">Controllo</span>
            <span style="<?php echo getBadgeStyle('Visita Legale'); ?>">Visita Legale</span>
        </div>
    </div>

    <!-- SELETTORE DATA DIRETTO -->
    <div class="nav-date">
        <form method="GET" action="planner.php">
            <?php if (!empty($paziente_id)): ?>
                <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_id); ?>">
            <?php endif; ?>
            <?php if ($ruolo !== 'medico'): ?>
                <input type="hidden" name="medico_id" value="<?php echo htmlspecialchars($medico_selezionato_id); ?>">
            <?php endif; ?>
            <label for="data" style="display: block; font-weight: bold; margin-bottom: 5px;">Seleziona Data Specifica:</label>
            <input type="date" id="data" name="data" value="<?php echo htmlspecialchars($data_corrente); ?>" onchange="this.form.submit()">
        </form>
    </div>

    <?php if (isset($risultato_controlli['error'])): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; margin-bottom: 20px; border-radius: 8px;">
            <strong>Errore API Supabase:</strong> <?php echo htmlspecialchars(json_encode($risultato_controlli)); ?>
        </div>
    <?php endif; ?>

    <!-- LAYOUT MOBILE-FIRST A SCHEDE (PLANNER) -->
    <div class="planner-container">
        <?php 
        if (!empty($appuntamenti_per_orario)) {
            foreach ($appuntamenti_per_orario as $ora_app => $app_val) {
                if (!in_array($ora_app, $orari)) {
                    $orari[] = $ora_app;
                }
            }
            sort($orari);
        }
        ?>

        <?php if (empty($orari)): ?>
            <div style="background: #fff3cd; color: #664d03; padding: 20px; border-radius: 12px; text-align: center; border: 1px solid #ffecb5;">
                <strong>Nessun appuntamento o disponibilità oraria</strong> trovata per la data selezionata.<br>
                <a href="medici_disponibilita.php" class="btn btn-sm btn-outline-dark mt-2 text-decoration-none fw-bold" style="background: #fff; border: 1px solid #664d03; padding: 6px 12px; border-radius: 6px; display: inline-block;">Configura Orari Disponibili &rarr;</a>
            </div>
        <?php else: ?>
            <?php foreach ($orari as $orario): ?>
                <?php 
                    $occupato = isset($appuntamenti_per_orario[$orario]);
                    $appuntamento = $occupato ? $appuntamenti_per_orario[$orario] : null;
                    $paziente_prenotato = $appuntamento['pazienti'] ?? null;
                    $tipo_visita = $appuntamento['tipo_controllo'] ?? 'Controllo';
                    $stato_visita = $appuntamento['stato'] ?? 'Programmato';
                    $telefono_paziente = $paziente_prenotato['telefono'] ?? '';
                    
                    $style_card = $occupato ? getCardHighlightStyle($tipo_visita) : '';
                ?>
                <div class="slot-card <?php echo $occupato ? 'occupato' : 'libero'; ?>" style="<?php echo $style_card; ?>">
                    <div class="slot-header-row">
                        <div class="slot-time"><?php echo $orario; ?></div>
                        <?php if ($occupato): ?>
                            <div><span style="<?php echo getBadgeStatoStyle($stato_visita); ?>"><?php echo htmlspecialchars($stato_visita); ?></span></div>
                        <?php endif; ?>
                    </div>

                    <div class="patient-info">
                        <?php if ($occupato): ?>
                            <div class="patient-name">
                                <?php echo htmlspecialchars(($paziente_prenotato['cognome'] ?? 'Sconosciuto') . ' ' . ($paziente_prenotato['nome'] ?? '')); ?>
                            </div>
                            <div class="patient-details">
                                <span style="<?php echo getBadgeStyle($tipo_visita); ?>"><?php echo htmlspecialchars($tipo_visita); ?></span>
                                <?php if (!empty($telefono_paziente)): ?>
                                    <span>&bull; Tel: <a href="tel:<?php echo htmlspecialchars($telefono_paziente); ?>" class="phone-link"><?php echo htmlspecialchars($telefono_paziente); ?></a></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($appuntamento['note'])): ?>
                                <div style="font-size: 0.85em; color: #555; margin-top: 4px; font-style: italic;">
                                    Note: <?php echo htmlspecialchars($appuntamento['note']); ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="font-weight: bold; color: #198754; font-size: 0.95em;">Slot Libero</div>
                            <div style="font-size: 0.85em; color: #6c757d;">Nessun appuntamento pianificato in questo orario.</div>
                        <?php endif; ?>
                    </div>

                    <div class="slot-actions">
                        <?php if ($occupato): ?>
                            <a href="visita.php?id=<?php echo htmlspecialchars($appuntamento['id']); ?>" class="btn-gestisci">Apri Visita</a>
                            
                            <form method="POST" action="planner.php?data=<?php echo urlencode($data_corrente); ?><?php echo $url_params_totale; ?>" class="form-cambio-stato">
                                <input type="hidden" name="azione_stato" value="1">
                                <input type="hidden" name="controllo_id" value="<?php echo htmlspecialchars($appuntamento['id']); ?>">
                                <select name="nuovo_stato" aria-label="Cambia stato visita">
                                    <option value="Programmato" <?php if($stato_visita === 'Programmato') echo 'selected'; ?>>Programmato</option>
                                    <option value="Effettuato" <?php if($stato_visita === 'Effettuato') echo 'selected'; ?>>Effettuato</option>
                                    <option value="Annullato" <?php if($stato_visita === 'Annullato') echo 'selected'; ?>>Annullato</option>
                                </select>
                                <button type="submit">Aggiorna</button>
                            </form>
                        <?php else: ?>
                            <?php 
                                $url_prenotazione = "nuova_prenotazione.php?data=" . urlencode($data_corrente) . "&orario=" . urlencode($orario) . (!empty($medico_selezionato_id) ? "&medico_id=" . urlencode($medico_selezionato_id) : "") . (!empty($paziente_id) ? "&paziente_id=" . urlencode($paziente_id) : "");
                            ?>
                            <a href="<?php echo $url_prenotazione; ?>" class="btn-prenota">Prenota questo slot &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function toggleCalendar() {
            var content = document.getElementById('calendar-content');
            var icon = document.getElementById('accordion-icon');
            if (content.classList.contains('open')) {
                content.classList.remove('open');
                icon.innerHTML = '&#9660;';
            } else {
                content.classList.add('open');
                icon.innerHTML = '&#9650;';
            }
        }
    </script>
</body>
</html>