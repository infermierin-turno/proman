<?php
session_start();
require_once 'config.php';

function get_secret_signature($data, $secret_key) {
    return hash_hmac('sha256', $data, $secret_key);
}

$app_secret = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : 'proman_secure_booking_secret_key_2026';

$token = $_GET['token'] ?? '';
$studio_id_get = $_GET['studio_id'] ?? '';
$messaggio_errore = '';
$messaggio_successo = '';
$dati_token = null;
$studio_id = '';
$paziente_esistente = null;
$modalita_nuovo_paziente = false;

// 1. VERIFICA MODALITÀ: Token sicuro oppure Studio ID diretto
if (!empty($token)) {
    $base64_str = strtr($token, '-_', '+/');
    $padding = strlen($base64_str) % 4;
    if ($padding > 0) {
        $base64_str .= str_repeat('=', 4 - $padding);
    }
    
    $token_json = base64_decode($base64_str);
    $dati_token = json_decode($token_json, true);

    if (!$dati_token || !isset($dati_token['paziente_id'], $dati_token['studio_id'], $dati_token['scadenza'], $dati_token['firma'])) {
        $messaggio_errore = "Il link di prenotazione risulta corrotto o non valido.";
    } elseif (time() > $dati_token['scadenza']) {
        $messaggio_errore = "Questo link di prenotazione è scaduto. Si prega di contattare la segreteria.";
    } else {
        $payload_verifica = $dati_token['paziente_id'] . '|' . $dati_token['studio_id'] . '|' . $dati_token['scadenza'];
        if (!hash_equals(get_secret_signature($payload_verifica, $app_secret), $dati_token['firma'])) {
            $messaggio_errore = "Firma di sicurezza non valida. Accesso negato.";
        } else {
            $studio_id = $dati_token['studio_id'];
            $paziente_id = $dati_token['paziente_id'];
            
            // La tabella pazienti non ha studio_id
            $res_paz = supabase_request('pazienti?id=eq.' . urlencode($paziente_id), 'GET');
            if (!empty($res_paz) && is_array($res_paz) && !isset($res_paz['error'])) {
                $paziente_esistente = $res_paz[0];
            } else {
                $messaggio_errore = "Paziente non trovato nel sistema.";
            }
        }
    }
} elseif (!empty($studio_id_get)) {
    $studio_id = $studio_id_get;
    $modalita_nuovo_paziente = true;
} else {
    $messaggio_errore = "Accesso non autorizzato. Parametri mancanti.";
}

// Estrazione medici dello studio
$medici_studio = [];
if (empty($messaggio_errore) && !empty($studio_id)) {
    $res_med = supabase_request('medici?studio_id=eq.' . urlencode($studio_id) . '&select=id,nome,cognome,titolo,ruolo', 'GET');
    if (!empty($res_med) && is_array($res_med) && !isset($res_med['error'])) {
        foreach ($res_med as $m) {
            $r_m = strtolower(trim($m['ruolo'] ?? ''));
            if ($r_m !== 'segretario' && $r_m !== 'segreteria' && $r_m !== 'admin') {
                $medici_studio[] = $m;
            }
        }
        if (empty($medici_studio)) {
            $medici_studio = $res_med;
        }
    }
}

// Gestione selezione medico e data (priorità a POST, poi GET)
$medico_selezionato_id = $_POST['medico_id'] ?? ($_GET['medico_id'] ?? ($medici_studio[0]['id'] ?? ''));
$data_selezionata = $_POST['data'] ?? ($_GET['data'] ?? date('Y-m-d', strtotime('+1 day')));

// Calcolo orari disponibili
$orari_disponibili = [];
if (!empty($medico_selezionato_id) && !empty($studio_id) && !empty($data_selezionata)) {
    $timestamp_sel = strtotime($data_selezionata);
    $giorno_settimana_num = (int)date('w', $timestamp_sel); // 0=Domenica, 6=Sabato

    $url_disp = "medici_disponibilita?medico_id=eq." . urlencode($medico_selezionato_id) . "&studio_id=eq." . urlencode($studio_id) . "&giorno_settimana=eq." . $giorno_settimana_num . "&attivo=eq.true&select=*";
    $disponibilita_medico = supabase_request($url_disp, 'GET');

    if (!empty($disponibilita_medico) && is_array($disponibilita_medico) && !isset($disponibilita_medico['error'])) {
        foreach ($disponibilita_medico as $disp) {
            $inizio_fascia = strtotime($disp['ora_inizio']);
            $fine_fascia = strtotime($disp['ora_fine']);
            $durata_slot = isset($disp['durata_slot_minuti']) && (int)$disp['durata_slot_minuti'] > 0 ? (int)$disp['durata_slot_minuti'] : 30;

            while ($inizio_fascia <= $fine_fascia) {
                $ora_formattata = date('H:i', $inizio_fascia);
                if (!in_array($ora_formattata, $orari_disponibili)) {
                    $orari_disponibili[] = $ora_formattata;
                }
                $inizio_fascia = strtotime("+{$durata_slot} minutes", $inizio_fascia);
            }
        }
        sort($orari_disponibili);
    }

    // Estrazione controlli esistenti per la data selezionata (compatibile con planner interno)
    $res_occupati = supabase_request('controlli?studio_id=eq.' . urlencode($studio_id) . '&medico_id=eq.' . urlencode($medico_selezionato_id) . '&data_controllo=eq.' . urlencode($data_selezionata) . '&select=*', 'GET');

    $orari_occupati = [];
    
    if (!empty($res_occupati) && is_array($res_occupati) && !isset($res_occupati['error'])) {
        foreach ($res_occupati as $c) {
            if (!empty($c['orario'])) {
                $ora_pulita = substr(trim($c['orario']), 0, 5);
                if (preg_match('/^\d{2}:\d{2}$/', $ora_pulita)) {
                    $orari_occupati[] = $ora_pulita;
                }
            }
        }
    }
    
    $orari_occupati = array_unique($orari_occupati);
    $orari_disponibili = array_values(array_diff($orari_disponibili, $orari_occupati));
}

// POST: Conferma della prenotazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conferma_prenotazione']) && empty($messaggio_errore)) {
    $orario_scelto = trim($_POST['orario'] ?? '');
    $tipo_visita = trim($_POST['tipo_controllo'] ?? 'Prima Visita');
    $note_utente = trim($_POST['note'] ?? '');
    
    $paziente_id_da_usare = null;

    if ($modalita_nuovo_paziente) {
        $nuovo_nome = trim($_POST['nome'] ?? '');
        $nuovo_cognome = trim($_POST['cognome'] ?? '');
        $nuovo_telefono = trim($_POST['telefono'] ?? '');
        $nuova_email = trim($_POST['email'] ?? '');
        $nuova_data_nascita = trim($_POST['data_nascita'] ?? '');
        $nuovo_luogo_nascita = trim($_POST['luogo_nascita'] ?? '');

        if (empty($nuovo_nome) || empty($nuovo_cognome) || empty($nuovo_telefono) || empty($nuova_email) || empty($nuova_data_nascita) || empty($nuovo_luogo_nascita)) {
            $messaggio_errore = "Compila tutti i campi obbligatori anagrafici.";
        } else {
            $paziente_trovato = false;
            
            // Verifica se esiste già un paziente con lo stesso telefono (la tabella pazienti non ha studio_id)
            if (!empty($nuovo_telefono)) {
                $chk_tel = supabase_request('pazienti?telefono=eq.' . urlencode($nuovo_telefono) . '&select=id', 'GET');
                if (!empty($chk_tel) && is_array($chk_tel) && !isset($chk_tel['error']) && isset($chk_tel[0]['id'])) {
                    $paziente_id_da_usare = $chk_tel[0]['id'];
                    $paziente_trovato = true;
                }
            }

            if (!$paziente_trovato) {
                $dati_nuovo_paziente = [
                    'nome' => $nuovo_nome,
                    'cognome' => $nuovo_cognome,
                    'telefono' => $nuovo_telefono,
                    'email' => $nuova_email,
                    'data_nascita' => $nuova_data_nascita,
                    'luogo_nascita' => $nuovo_luogo_nascita
                ];
                
                $risultato_paziente = supabase_request('pazienti', 'POST', $dati_nuovo_paziente);
                
                // Recuperiamo l'ID del paziente appena inserito o cercato
                $chk_new = supabase_request('pazienti?telefono=eq.' . urlencode($nuovo_telefono) . '&order=created_at.desc&limit=1&select=id', 'GET');
                if (!empty($chk_new) && is_array($chk_new) && !isset($chk_new['error']) && isset($chk_new[0]['id'])) {
                    $paziente_id_da_usare = $chk_new[0]['id'];
                } elseif (!empty($risultato_paziente) && is_array($risultato_paziente) && isset($risultato_paziente[0]['id'])) {
                    $paziente_id_da_usare = $risultato_paziente[0]['id'];
                }
            }
        }
    } else {
        $paziente_id_da_usare = $paziente_esistente['id'] ?? null;
    }

    if (empty($messaggio_errore)) {
        if (empty($orario_scelto) || !preg_match('/^\d{2}:\d{2}$/', $orario_scelto) || empty($medico_selezionato_id) || empty($data_selezionata) || empty($paziente_id_da_usare)) {
            $messaggio_errore = "Dati incompleti per completare la prenotazione. Seleziona un orario valido.";
        } else {
            // Inserimento conforme alla tabella controlli (data_controllo = date, orario = text)
            $nuovo_controllo = [
                'studio_id' => $studio_id,
                'paziente_id' => $paziente_id_da_usare,
                'medico_id' => $medico_selezionato_id,
                'data_controllo' => $data_selezionata,
                'orario' => $orario_scelto,
                'tipo_controllo' => $tipo_visita,
                'stato' => 'Programmato',
                'note' => "Prenotato online dal portale esterno. " . $note_utente
            ];

            $risultato_inserimento = supabase_request('controlli', 'POST', $nuovo_controllo);

            if (!empty($risultato_inserimento) && isset($risultato_inserimento['error'])) {
                $messaggio_errore = "Errore Supabase: " . (is_array($risultato_inserimento['error']) ? json_encode($risultato_inserimento['error']) : $risultato_inserimento['error']);
            } elseif (empty($risultato_inserimento) || !isset($risultato_inserimento['error'])) {
                $messaggio_successo = "Prenotazione effettuata con successo per il giorno {$data_selezionata} alle ore {$orario_scelto}!";
            } else {
                $messaggio_errore = "Errore durante il salvataggio della prenotazione su Supabase.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prenotazione Online - Studio Medico</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; margin: 0; padding: 20px; background-color: #f0f2f5; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card-container { background: #fff; width: 100%; max-width: 600px; padding: 30px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        h1 { font-size: 1.5em; margin-top: 0; color: #1a1a1a; text-align: center; margin-bottom: 5px; }
        .subtitle { text-align: center; color: #6c757d; font-size: 0.95em; margin-bottom: 25px; }
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95em; line-height: 1.4; }
        .alert-danger { background-color: #f8d7da; color: #842029; border: 1px solid #f5c2c7; }
        .alert-success { background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; text-align: center; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 6px; font-size: 0.9em; color: #495057; }
        select, input[type="text"], input[type="email"], input[type="tel"], input[type="date"], textarea { width: 100%; padding: 12px; border: 1px solid #ced4da; border-radius: 8px; font-size: 1em; background: #fff; }
        select:focus, input:focus, textarea:focus { border-color: #0d6efd; outline: none; box-shadow: 0 0 0 3px rgba(13,110,253,0.15); }
        .slots-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px; margin-top: 10px; }
        .slot-label { display: block; cursor: pointer; }
        .slot-btn { background: #f8f9fa; border: 2px solid #dee2e6; border-radius: 8px; padding: 10px; text-align: center; font-weight: bold; font-size: 1em; color: #212529; transition: all 0.2s; }
        .slot-label input[type="radio"]:checked + .slot-btn { background: #0d6efd; color: white; border-color: #0d6efd; }
        .slot-btn:hover { background: #e9ecef; border-color: #adb5bd; }
        .btn-submit { background: #198754; color: white; border: none; width: 100%; padding: 14px; border-radius: 8px; font-size: 1.05em; font-weight: bold; cursor: pointer; transition: background 0.2s; margin-top: 15px; }
        .btn-submit:hover { background: #157347; }
        .info-box { background: #e7f1ff; border: 1px solid #b6d4fe; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95em; color: #084298; }
        .section-title { font-size: 1.1em; font-weight: bold; color: #212529; margin: 20px 0 10px 0; border-bottom: 2px solid #f1f3f5; padding-bottom: 5px; }
    </style>
</head>
<body>

<div class="card-container">
    <h1>Prenotazione Appuntamento</h1>
    <div class="subtitle">Scegli il medico, compila tutti i dati e seleziona il tuo orario</div>

    <?php if (!empty($messaggio_successo)): ?>
        <div class="alert alert-success">
            <h2 style="margin-top:0; color: #0f5132;">Prenotazione Confermata!</h2>
            <p><?php echo htmlspecialchars($messaggio_successo); ?></p>
            <p style="font-size: 0.9em; color: #6c757d; margin-bottom:0;">La segreteria ha ricevuto la richiesta. Puoi chiudere questa pagina.</p>
        </div>
    <?php else: ?>

        <?php if (!empty($messaggio_errore)): ?>
            <div class="alert alert-danger">
                <strong>Attenzione:</strong> <?php echo htmlspecialchars($messaggio_errore); ?>
            </div>
        <?php endif; ?>

        <?php if ($paziente_esistente): ?>
            <div class="info-box">
                Paziente: <strong><?php echo htmlspecialchars(($paziente_esistente['cognome'] ?? '') . ' ' . ($paziente_esistente['nome'] ?? '')); ?></strong>
            </div>
        <?php endif; ?>

        <!-- Form per il cambio di Medico o Data -->
        <form method="GET" action="prenota_esterno.php" id="form-cambio-filtri">
            <?php if (!empty($token)): ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <?php elseif (!empty($studio_id)): ?>
                <input type="hidden" name="studio_id" value="<?php echo htmlspecialchars($studio_id); ?>">
            <?php endif; ?>
            
            <div class="form-group">
                <label for="medico_id">Professionista:</label>
                <select name="medico_id" id="medico_id" onchange="document.getElementById('form-cambio-filtri').submit()">
                    <?php foreach ($medici_studio as $m): ?>
                        <?php 
                            $t_m = trim($m['titolo'] ?? 'Dr.');
                            $n_m = trim(($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? ''));
                        ?>
                        <option value="<?php echo htmlspecialchars($m['id']); ?>" <?php if ($m['id'] == $medico_selezionato_id) echo 'selected'; ?>>
                            <?php echo htmlspecialchars("$t_m $n_m"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="data">Data Appuntamento:</label>
                <input type="date" id="data" name="data" value="<?php echo htmlspecialchars($data_selezionata); ?>" min="<?php echo date('Y-m-d'); ?>" onchange="document.getElementById('form-cambio-filtri').submit()">
            </div>
        </form>

        <!-- Form di Conferma Principale (POST) -->
        <form method="POST" action="prenota_esterno.php?<?php echo !empty($token) ? 'token=' . urlencode($token) : 'studio_id=' . urlencode($studio_id); ?>">
            <input type="hidden" name="medico_id" value="<?php echo htmlspecialchars($medico_selezionato_id); ?>">
            <input type="hidden" name="data" value="<?php echo htmlspecialchars($data_selezionata); ?>">

            <?php if ($modalita_nuovo_paziente): ?>
                <div class="section-title">I tuoi Dati Anagrafici (Tutti obbligatori)</div>
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex:1;">
                        <label for="nome">Nome *</label>
                        <input type="text" id="nome" name="nome" required value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="cognome">Cognome *</label>
                        <input type="text" id="cognome" name="cognome" required value="<?php echo htmlspecialchars($_POST['cognome'] ?? ''); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex:1;">
                        <label for="data_nascita">Data di Nascita *</label>
                        <input type="date" id="data_nascita" name="data_nascita" required value="<?php echo htmlspecialchars($_POST['data_nascita'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="luogo_nascita">Luogo di Nascita *</label>
                        <input type="text" id="luogo_nascita" name="luogo_nascita" required placeholder="Città (Prov)" value="<?php echo htmlspecialchars($_POST['luogo_nascita'] ?? ''); ?>">
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex:1;">
                        <label for="telefono">Telefono *</label>
                        <input type="tel" id="telefono" name="telefono" required value="<?php echo htmlspecialchars($_POST['telefono'] ?? ''); ?>">
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-title">Dettagli Visita</div>
            <div class="form-group">
                <label for="tipo_controllo">Tipo di Visita:</label>
                <select name="tipo_controllo" id="tipo_controllo">
                    <option value="Prima Visita" <?php if(isset($_POST['tipo_controllo']) && $_POST['tipo_controllo'] === 'Prima Visita') echo 'selected'; ?>>Prima Visita</option>
                    <option value="Controllo" <?php if(isset($_POST['tipo_controllo']) && $_POST['tipo_controllo'] === 'Controllo') echo 'selected'; ?>>Controllo</option>
                    <option value="Medicazione" <?php if(isset($_POST['tipo_controllo']) && $_POST['tipo_controllo'] === 'Medicazione') echo 'selected'; ?>>Medicazione</option>
                    <option value="Rimozione Punti" <?php if(isset($_POST['tipo_controllo']) && $_POST['tipo_controllo'] === 'Rimozione Punti') echo 'selected'; ?>>Rimozione Punti</option>
                </select>
            </div>

            <div class="form-group">
                <label>Seleziona Orario Disponibile:</label>
                <?php if (empty($orari_disponibili)): ?>
                    <div class="alert alert-danger" style="margin-top: 5px; text-align: center; font-size: 0.9em;">
                        Nessun orario disponibile per il professionista nella data selezionata. Scegli un'altra data.
                    </div>
                <?php else: ?>
                    <div class="slots-grid">
                        <?php foreach ($orari_disponibili as $ora): ?>
                            <label class="slot-label">
                                <input type="radio" name="orario" value="<?php echo $ora; ?>" style="display:none;" required <?php if(isset($_POST['orario']) && $_POST['orario'] === $ora) echo 'checked'; ?>>
                                <div class="slot-btn"><?php echo $ora; ?></div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="note">Note Aggiuntive (Opzionale):</label>
                <textarea name="note" id="note" rows="2" placeholder="Motivo della visita o comunicazioni..."><?php echo htmlspecialchars($_POST['note'] ?? ''); ?></textarea>
            </div>

            <?php if (!empty($orari_disponibili)): ?>
                <button type="submit" name="conferma_prenotazione" value="1" class="btn-submit">Conferma e Invia Prenotazione</button>
            <?php endif; ?>
        </form>

    <?php endif; ?>

</div>

</body>
</html>