<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

// RECUPERO FORZATO STUDIO_ID CON PULIZIA E CONTROLLO TIPO RIGOROSO (tabella medici)
$studio_id_sessione = null;
$medico_loggato_id = null;
$ruolo_utente = 'medico';

if (isset($_SESSION['studio_id'])) {
    $val = $_SESSION['studio_id'];
    if (is_array($val)) {
        $studio_id_sessione = $val['id'] ?? ($val['studio_id'] ?? ($val[0] ?? null));
    } else {
        $studio_id_sessione = $val;
    }
}

$utente_info = $_SESSION['utente'];
$email_cerca = is_array($utente_info) ? ($utente_info['email'] ?? '') : $utente_info;

if (!empty($email_cerca) && is_string($email_cerca)) {
    $user_data = supabase_request('medici?email=eq.' . urlencode($email_cerca) . '&select=*', 'GET');
    
    if (!empty($user_data) && !isset($user_data['error'])) {
        $medico_record = $user_data[0];
        $medico_loggato_id = $medico_record['id'] ?? null;
        $ruolo_utente = $medico_record['ruolo'] ?? 'medico';
        
        if (empty($studio_id_sessione) && isset($medico_record['studio_id'])) {
            $val_studio = $medico_record['studio_id'];
            if (is_array($val_studio)) {
                $studio_id_sessione = $val_studio['id'] ?? ($val_studio['studio_id'] ?? ($val_studio[0] ?? null));
            } else {
                $studio_id_sessione = $val_studio;
            }
            $_SESSION['studio_id'] = $studio_id_sessione;
        }
    }
}

if (is_array($studio_id_sessione)) {
    $studio_id_sessione = null;
}

if (!empty($studio_id_sessione)) {
    $abbonamento_check = supabase_request('studi?id=eq.' . urlencode((string)$studio_id_sessione) . '&select=stato_abbonamento,scadenza_abbonamento', 'GET');
    if (!empty($abbonamento_check) && !isset($abbonamento_check['error'])) {
        $studio_info = $abbonamento_check[0];
        $stato_abb = $studio_info['stato_abbonamento'] ?? 'attivo';
        $scadenza_abb = $studio_info['scadenza_abbonamento'] ?? null;
        
        $abbonamento_scaduto = false;
        if ($stato_abb !== 'attivo') {
            $abbonamento_scaduto = true;
        } elseif (!empty($scadenza_abb) && strtotime($scadenza_abb) < time()) {
            $abbonamento_scaduto = true;
        }

        if ($abbonamento_scaduto) {
            header("Location: abbonamento_scaduto.php");
            exit;
        }
    }
}

$medici_studio = [];
if (!empty($studio_id_sessione)) {
    $res_medici = supabase_request('medici?studio_id=eq.' . urlencode((string)$studio_id_sessione) . '&ruolo=eq.medico&select=id,nome,cognome,ruolo', 'GET');
    if (!empty($res_medici) && !isset($res_medici['error'])) {
        $medici_studio = $res_medici;
    }
}

$data_selezionata = isset($_GET['data']) ? $_GET['data'] : (isset($_POST['data']) ? $_POST['data'] : date('Y-m-d'));
$orario_selezionato = isset($_GET['orario']) ? $_GET['orario'] : (isset($_POST['orario']) ? $_POST['orario'] : '15:00');
if (strlen($orario_selezionato) === 5) {
    $orario_selezionato .= ':00'; 
}

$paziente_id_url = isset($_GET['paziente_id']) ? $_GET['paziente_id'] : (isset($_POST['paziente_id']) ? $_POST['paziente_id'] : null);

$messaggio = '';
$debug_info = '';
$paziente_precompilato = null;

if (!empty($paziente_id_url)) {
    // La tabella pazienti è ora globale e pulita
    $url_paziente = 'pazienti?id=eq.' . urlencode((string)$paziente_id_url);
    $ris_paziente = supabase_request($url_paziente, 'GET');
    if (!empty($ris_paziente) && !isset($ris_paziente['error'])) {
        $paziente_precompilato = $ris_paziente[0];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($studio_id_sessione)) {
        $debug_info = "Errore Critico: Impossibile determinare lo studio_id. Riprovare il login.";
    } else {
        $paziente_id = !empty($_POST['paziente_id']) ? $_POST['paziente_id'] : null;

        if ($ruolo_utente === 'segreteria' || strtolower($ruolo_utente) === 'segretario' || strtolower($ruolo_utente) === 'operatore') {
            $medico_id_destinazione = !empty($_POST['medico_id']) ? $_POST['medico_id'] : null;
        } else {
            $medico_id_destinazione = $medico_loggato_id;
        }

        $cognome = trim($_POST['cognome'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $data_nascita = !empty($_POST['data_nascita']) ? $_POST['data_nascita'] : null;
        $luogo_nascita = trim($_POST['luogo_nascita'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        
        // Dati clinici e di visita (salvati nei controlli)
        $diagnosi_controllo = trim($_POST['diagnosi'] ?? '');
        $tipologia_intervento = trim($_POST['tipologia_intervento'] ?? '');
        $data_intervento = !empty($_POST['data_intervento']) ? $_POST['data_intervento'] : null;
        $tipo_controllo = trim($_POST['tipo_controllo'] ?? 'Controllo');
        $note_controllo = trim($_POST['note'] ?? '');
        $terapia = trim($_POST['terapia'] ?? '');

        if (empty($paziente_id)) {
            if (!empty($cognome) && !empty($nome) && !empty($data_nascita) && !empty($telefono)) {
                
                $nome_norm = mb_strtolower($nome, 'UTF-8');
                $cognome_norm = mb_strtolower($cognome, 'UTF-8');
                $telefono_norm = preg_replace('/[^0-9+]/', '', $telefono);

                // Ricerca anagrafica globale
                $url_query_pazienti = 'pazienti?select=*';
                $pazienti_db = supabase_request($url_query_pazienti, 'GET');
                
                $paziente_esistente = null;
                if (!isset($pazienti_db['error']) && is_array($pazienti_db)) {
                    foreach ($pazienti_db as $p) {
                        $p_nome = mb_strtolower(trim($p['nome'] ?? ''), 'UTF-8');
                        $p_cognome = mb_strtolower(trim($p['cognome'] ?? ''), 'UTF-8');
                        $p_data_nascita = trim($p['data_nascita'] ?? '');
                        $p_telefono_db = preg_replace('/[^0-9+]/', '', trim($p['telefono'] ?? ''));

                        if (
                            $p_nome === $nome_norm &&
                            $p_cognome === $cognome_norm &&
                            $p_data_nascita === $data_nascita &&
                            $p_telefono_db === $telefono_norm
                        ) {
                            $paziente_esistente = $p;
                            break;
                        }
                    }
                }

                if ($paziente_esistente) {
                    $paziente_id = $paziente_esistente['id'] ?? null;
                    
                    // Aggiorniamo campi anagrafici opzionali se mancanti
                    $dati_aggiornamento = [];
                    if (empty($paziente_esistente['luogo_nascita']) && !empty($luogo_nascita)) {
                        $dati_aggiornamento['luogo_nascita'] = $luogo_nascita;
                    }
                    if (empty($paziente_esistente['email']) && !empty($email)) {
                        $dati_aggiornamento['email'] = $email;
                    }
                    if (!empty($dati_aggiornamento)) {
                        supabase_request('pazienti?id=eq.' . urlencode((string)$paziente_id), 'PATCH', $dati_aggiornamento);
                    }
                } else {
                    // Creazione anagrafica pura (senza studio, medico o diagnosi)
                    $dati_paziente = [
                        'cognome' => $cognome,
                        'nome' => $nome,
                        'data_nascita' => $data_nascita,
                        'telefono' => $telefono,
                        'luogo_nascita' => $luogo_nascita,
                        'email' => $email
                    ];

                    $nuovo_paziente = supabase_request('pazienti', 'POST', $dati_paziente);
                    
                    if (isset($nuovo_paziente['error'])) {
                        $debug_info = "Errore Inserimento Paziente: " . json_encode($nuovo_paziente);
                    } else {
                        $paziente_id = $nuovo_paziente[0]['id'] ?? ($nuovo_paziente['id'] ?? null);
                    }
                }

            } else {
                $messaggio = "Compilare tutti i campi obbligatori per l'anagrafica (Cognome, Nome, Data di Nascita e Telefono).";
            }
        }

        // Inserimento del controllo con tutti i dati clinici, studio e medico
        if ($paziente_id && empty($messaggio) && empty($debug_info)) {
            if (empty($studio_id_sessione)) {
                $debug_info = "Errore Critico: Impossibile determinare lo studio_id per il controllo.";
            } else {
                $orario_pulito = substr($orario_selezionato, 0, 5);
                $note_complete = "Orario: " . $orario_pulito . (!empty($note_controllo) ? " - " . $note_controllo : "");
                
                $dati_controllo = [
                    'paziente_id' => $paziente_id,
                    'data_controllo' => $data_selezionata,
                    'orario' => $orario_pulito,
                    'tipo_controllo' => $tipo_controllo,
                    'diagnosi' => $diagnosi_controllo,
                    'tipologia_intervento' => $tipologia_intervento,
                    'data_intervento' => $data_intervento,
                    'note' => trim($note_complete),
                    'terapia' => $terapia,
                    'studio_id' => $studio_id_sessione,
                    'medico_id' => $medico_id_destinazione
                ];
                if (empty($data_intervento)) {
                    unset($dati_controllo['data_intervento']);
                }
                
                $inserimento_controllo = supabase_request('controlli', 'POST', $dati_controllo);

                if (isset($inserimento_controllo['error'])) {
                    unset($dati_controllo['orario']);
                    $inserimento_controllo = supabase_request('controlli', 'POST', $dati_controllo);
                }

                if (isset($inserimento_controllo['error'])) {
                    $debug_info = "Errore Inserimento Controllo: " . json_encode($inserimento_controllo);
                } else {
                    $redirect_url = "planner.php?data=" . $data_selezionata;
                    if (!empty($medico_id_destinazione)) {
                        $redirect_url .= "&medico_id=" . $medico_id_destinazione;
                    }
                    header("Location: " . $redirect_url);
                    exit;
                }
            }
        }
    }
}

$orario_form_display = substr($orario_selezionato, 0, 5);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Controllo / Prenotazione - Proman</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 10px; background-color: #f8f9fa; color: #333; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { font-size: 1.4em; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.95em; }
        input[type="text"], input[type="date"], input[type="email"], select, textarea { 
            width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; background-color: #fff;
        }
        input:disabled, input[readonly] { background-color: #e9ecef; color: #495057; }
        fieldset { border: 1px solid #ced4da; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        legend { font-weight: bold; color: #007bff; padding: 0 5px; font-size: 1em; }
        button { background-color: #007bff; color: white; padding: 14px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        button:hover { background-color: #0056b3; }
        .error { background-color: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9em; }
        .info-slot { background-color: #e2f0d9; padding: 12px; margin-bottom: 20px; border-radius: 4px; font-weight: bold; color: #385723; font-size: 0.95em; text-align: center; }
        .banner-paziente-scelto { background-color: #d1ecf1; color: #0c5460; padding: 12px; margin-bottom: 20px; border-radius: 4px; font-size: 0.95em; border: 1px solid #bee5eb; }
        a { color: #007bff; text-decoration: none; display: inline-block; margin-top: 15px; font-size: 0.95em; }
        a:hover { text-decoration: underline; }
        .required-mark { color: red; }
    </style>
</head>
<body>
<div class="container">
    <h2>Nuovo Appuntamento / Controllo</h2>
    
    <div class="info-slot">
        Slot: <?php echo htmlspecialchars($data_selezionata); ?> ore <?php echo htmlspecialchars($orario_form_display); ?>
    </div>

    <?php if ($paziente_precompilato): ?>
        <div class="banner-paziente-scelto">
            Paziente associato dall'anagrafica: <strong><?php echo htmlspecialchars(($paziente_precompilato['cognome'] ?? '') . ' ' . ($paziente_precompilato['nome'] ?? '')); ?></strong>
        </div>
    <?php endif; ?>

    <?php if (!empty($messaggio)): ?>
        <div class="error"><?php echo htmlspecialchars($messaggio); ?></div>
    <?php endif; ?>

    <?php if (!empty($debug_info)): ?>
        <div class="error"><strong>DEBUG ERROR:</strong><br><pre><?php echo htmlspecialchars($debug_info); ?></pre></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="data" value="<?php echo htmlspecialchars($data_selezionata); ?>">
        <input type="hidden" name="orario" value="<?php echo htmlspecialchars($orario_form_display); ?>">
        
        <?php if ($paziente_precompilato): ?>
            <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_precompilato['id'] ?? ''); ?>">
        <?php endif; ?>
        
        <?php if ($ruolo_utente === 'segreteria' || strtolower($ruolo_utente) === 'segretario' || strtolower($ruolo_utente) === 'operatore'): ?>
        <fieldset>
            <legend>Assegnazione Medico</legend>
            <div class="form-group">
                <label>Seleziona Medico per l'appuntamento: <span class="required-mark">*</span></label>
                <select name="medico_id" required>
                    <option value="">-- Seleziona Medico --</option>
                    <?php foreach ($medici_studio as $med): ?>
                        <?php if ($medico_loggato_id && $med['id'] == $medico_loggato_id) { continue; } ?>
                        <option value="<?php echo htmlspecialchars($med['id']); ?>">
                            Dr. <?php echo htmlspecialchars(($med['cognome'] ?? '') . ' ' . ($med['nome'] ?? '')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>
        <?php endif; ?>

        <fieldset>
            <legend>Anagrafica Paziente</legend>
            <div class="form-group">
                <label>Cognome: <span class="required-mark">*</span></label>
                <input type="text" name="cognome" value="<?php echo htmlspecialchars($paziente_precompilato['cognome'] ?? ''); ?>" <?php echo $paziente_precompilato ? 'readonly' : 'required'; ?>>
            </div>
            <div class="form-group">
                <label>Nome: <span class="required-mark">*</span></label>
                <input type="text" name="nome" value="<?php echo htmlspecialchars($paziente_precompilato['nome'] ?? ''); ?>" <?php echo $paziente_precompilato ? 'readonly' : 'required'; ?>>
            </div>
            <div class="form-group">
                <label>Data di Nascita: <span class="required-mark">*</span></label>
                <input type="date" name="data_nascita" value="<?php echo htmlspecialchars($paziente_precompilato['data_nascita'] ?? ''); ?>" <?php echo $paziente_precompilato ? 'readonly' : 'required'; ?>>
            </div>
            <div class="form-group">
                <label>Telefono: <span class="required-mark">*</span></label>
                <input type="text" name="telefono" value="<?php echo htmlspecialchars($paziente_precompilato['telefono'] ?? ''); ?>" <?php echo $paziente_precompilato ? 'readonly' : 'required'; ?>>
            </div>
            <div class="form-group">
                <label>Luogo di Nascita:</label>
                <input type="text" name="luogo_nascita" value="<?php echo htmlspecialchars($paziente_precompilato['luogo_nascita'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Email:</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($paziente_precompilato['email'] ?? ''); ?>">
            </div>
        </fieldset>

        <fieldset>
            <legend>Dettagli Clinici e Visita</legend>
            <div class="form-group">
                <label>Tipo Controllo</label>
                <select name="tipo_controllo" required>
                    <option value="Prima Visita" selected>Prima Visita</option>
                    <option value="Controllo">Controllo</option>
                    <option value="Controllo Post-operatorio">Controllo Post-operatorio</option>
                    <option value="Medicazione">Medicazione</option>
                    <option value="Rimozione Punti">Rimozione Punti</option>
                    <option value="Visita legale">Visita legale</option>
                </select>
            </div>
            <div class="form-group">
                <label>Diagnosi:</label>
                <input type="text" name="diagnosi" placeholder="Es. Sindrome del tunnel carpale">
            </div>
            <div class="form-group">
                <label>Tipologia Intervento:</label>
                <input type="text" name="tipologia_intervento" placeholder="Es. Decompressione chirurgica">
            </div>
            <div class="form-group">
                <label>Data Intervento:</label>
                <input type="date" name="data_intervento">
            </div>
            <div class="form-group">
                <label>Terapia:</label>
                <input type="text" name="terapia" placeholder="Eventuale terapia prescritta">
            </div>
            <div class="form-group">
                <label>Note / Anamnesi:</label>
                <textarea name="note" rows="3"></textarea>
            </div>
        </fieldset>
        
        <button type="submit">Salva Appuntamento</button>
    </form>
    
    <p><a href="planner.php?data=<?php echo htmlspecialchars($data_selezionata); ?>">&larr; Torna al Planner</a></p>
</div>
</body>
</html>