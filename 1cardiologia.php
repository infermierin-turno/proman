<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$studio_id_sessione = $_SESSION['studio_id'] ?? null;
$messaggio = '';
$errore = '';

// Recupero anagrafica medico
$nome_medico_stampato = 'Dr. Medico Sanitario';
$id_medico_sessione = $_SESSION['utente']['medico_id'] ?? $_SESSION['utente']['id'] ?? null;

if (!empty($id_medico_sessione)) {
    $res_medico = supabase_request('medici?id=eq.' . urlencode((string)$id_medico_sessione) . '&select=nome,cognome', 'GET');
    if (!empty($res_medico) && !isset($res_medico['error']) && is_array($res_medico)) {
        $medico_dati = $res_medico[0];
        $nome_medico_stampato = 'Dr. ' . trim(($medico_dati['nome'] ?? '') . ' ' . ($medico_dati['cognome'] ?? ''));
    }
} elseif (!empty($_SESSION['utente']['nome']) || !empty($_SESSION['utente']['cognome'])) {
    $nome_medico_stampato = 'Dr. ' . trim(($_SESSION['utente']['nome'] ?? '') . ' ' . ($_SESSION['utente']['cognome'] ?? ''));
}

// Verifica attivazione modulo Cardiologia
$modulo_attivo = false;
if (!empty($studio_id_sessione)) {
    $res_mod = supabase_request('pagamenti?studio_id=eq.' . urlencode((string)$studio_id_sessione) . '&select=modulo_cardiologia', 'GET');
    if (!empty($res_mod) && !isset($res_mod['error']) && is_array($res_mod)) {
        foreach ($res_mod as $pag) {
            if (isset($pag['modulo_cardiologia']) && ($pag['modulo_cardiologia'] === true || $pag['modulo_cardiologia'] === 'true' || $pag['modulo_cardiologia'] == 1)) {
                $modulo_attivo = true;
                break;
            }
        }
    }
}

$esame_id = $_GET['id'] ?? $_GET['esame_id'] ?? $_GET['controllo_id'] ?? '';
$paziente_id = $_GET['paziente_id'] ?? '';
$paziente_selezionato = null;
$esame_selezionato = null;

if ($modulo_attivo && !empty($esame_id)) {
    $res_esame = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id) . '&select=*', 'GET');
    if (!empty($res_esame) && !isset($res_esame['error']) && is_array($res_esame) && count($res_esame) > 0) {
        $esame_selezionato = $res_esame[0];
        if (empty($paziente_id) && !empty($esame_selezionato['paziente_id'])) {
            $paziente_id = $esame_selezionato['paziente_id'];
        }
    }
}

if ($modulo_attivo && !empty($paziente_id)) {
    $res_paz = supabase_request('pazienti?id=eq.' . urlencode($paziente_id) . '&select=*', 'GET');
    if (!empty($res_paz) && !isset($res_paz['error']) && is_array($res_paz) && count($res_paz) > 0) {
        $paziente_selezionato = $res_paz[0];
        // Se lo studio_id in sessione manca, lo ricaviamo direttamente dall'anagrafica del paziente
        if (empty($studio_id_sessione) && !empty($paziente_selezionato['studio_id'])) {
            $studio_id_sessione = $paziente_selezionato['studio_id'];
        }
    }
}

function valuta_ecocardiogramma_clinico($fe, $tapse, $paps, $siv, $pp) {
    $allarmi = [];
    if (is_numeric($fe)) {
        if ($fe < 30) $allarmi[] = "CRITICO: Frazione di Eiezione (FE) < 30% (Grave disfunzione sistolica VS).";
        elseif ($fe >= 30 && $fe < 40) $allarmi[] = "ATTENZIONE: FE moderatamente ridotta (30-39%).";
        elseif ($fe >= 40 && $fe < 50) $allarmi[] = "NOTIZIA: FE lievemente ridotta / borderline (40-49%).";
        else $allarmi[] = "REGOLARE: FE conservata (>= 50%).";
    }
    if (is_numeric($tapse)) {
        if ($tapse < 17) $allarmi[] = "CRITICO: TAPSE < 17 mm (Ridotta funzione longitudinale del Ventricolo Destro).";
        else $allarmi[] = "REGOLARE: Funzione sistolica del Ventricolo Destro conservata (TAPSE >= 17 mm).";
    }
    if (is_numeric($paps)) {
        if ($paps > 40) $allarmi[] = "ATTENZIONE: PAPS stimata > 40 mmHg (Segno di possibile Ipertensione Polmonare).";
        else $allarmi[] = "REGOLARE: PAPS nei limiti di norma (<= 40 mmHg).";
    }
    if (is_numeric($siv) && is_numeric($pp)) {
        if ($siv > 11 || $pp > 11) $allarmi[] = "NOTA: Spessori parietali aumentati (> 11 mm) - Sospetta Ipertrofia Ventricolare Sinistra.";
    }
    return $allarmi;
}

// Gestione salvataggio referto
if ($modulo_attivo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'salva_ecocardio') {
    $paziente_id_post = $_POST['paziente_id'] ?? '';
    
    // Recupero studio_id se non presente
    if (empty($studio_id_sessione) && !empty($paziente_id_post)) {
        $res_paz_chk = supabase_request('pazienti?id=eq.' . urlencode($paziente_id_post) . '&select=studio_id', 'GET');
        if (!empty($res_paz_chk) && !isset($res_paz_chk['error'])) {
            $studio_id_sessione = $res_paz_chk[0]['studio_id'] ?? null;
        }
    }

    if (empty($paziente_id_post) || empty($studio_id_sessione)) {
        $errore = "Errore critico: Impossibile determinare il Paziente o lo Studio di appartenenza per il salvataggio.";
    } else {
        $fe_val = trim($_POST['fe_ev'] ?? '');
        $tapse_val = trim($_POST['tapse'] ?? '');
        $paps_val = trim($_POST['paps'] ?? '');
        $siv_val = trim($_POST['spessore_siv'] ?? '');
        $pp_val = trim($_POST['spessore_pp'] ?? '');

        preg_match('/\d+/', $fe_val, $m_fe);
        preg_match('/\d+/', $tapse_val, $m_tapse);
        preg_match('/\d+/', $paps_val, $m_paps);
        preg_match('/\d+/', $siv_val, $m_siv);
        preg_match('/\d+/', $pp_val, $m_pp);

        $num_fe = $m_fe[0] ?? null;
        $num_tapse = $m_tapse[0] ?? null;
        $num_paps = $m_paps[0] ?? null;
        $num_siv = $m_siv[0] ?? null;
        $num_pp = $m_pp[0] ?? null;

        $note_cliniche = $_POST['note_cliniche'] ?? '';
        $report_algoritmo = valuta_ecocardiogramma_clinico($num_fe, $num_tapse, $num_paps, $num_siv, $num_pp);
        
        if (!empty($report_algoritmo)) {
            $note_cliniche .= "\n\n--- [DECISION SUPPORT ENGINE - EACVI STANDARDS] ---\n" . implode("\n", $report_algoritmo);
        }

        $dati_inserimento = [
            'paziente_id' => $paziente_id_post,
            'studio_id' => $studio_id_sessione,
            'medico_id' => !empty($id_medico_sessione) ? $id_medico_sessione : null,
            'data_esame' => $_POST['data_esame'] ?? date('Y-m-d'),
            'fe_ev' => $fe_val !== '' ? $fe_val : null,
            'diametro_telediastolico_vs' => $_POST['diametro_telediastolico_vs'] !== '' ? $_POST['diametro_telediastolico_vs'] : null,
            'diametro_telesistolico_vs' => $_POST['diametro_telesistolico_vs'] !== '' ? $_POST['diametro_telesistolico_vs'] : null,
            'spessore_siv' => $siv_val !== '' ? $siv_val : null,
            'spessore_pp' => $pp_val !== '' ? $pp_val : null,
            'atrio_sinistro' => $_POST['atrio_sinistro'] !== '' ? $_POST['atrio_sinistro'] : null,
            'radice_aortica' => $_POST['radice_aortica'] !== '' ? $_POST['radice_aortica'] : null,
            'tapse' => $tapse_val !== '' ? $tapse_val : null,
            'paps' => $paps_val !== '' ? $paps_val : null,
            'vd_morfologia' => $_POST['vd_morfologia'] !== '' ? $_POST['vd_morfologia'] : null,
            'morfologia_aortica' => $_POST['morfologia_aortica'] !== '' ? $_POST['morfologia_aortica'] : null,
            'stenosi_aortica' => $_POST['stenosi_aortica'] !== '' ? $_POST['stenosi_aortica'] : null,
            'insufficienza_aortica' => $_POST['insufficienza_aortica'] !== '' ? $_POST['insufficienza_aortica'] : null,
            'insufficienza_mitralica' => $_POST['insufficienza_mitralica'] !== '' ? $_POST['insufficienza_mitralica'] : null,
            'stenosi_mitralica' => $_POST['stenosi_mitralica'] !== '' ? $_POST['stenosi_mitralica'] : null,
            'insufficienza_tricuspidale' => $_POST['insufficienza_tricuspidale'] !== '' ? $_POST['insufficienza_tricuspidale'] : null,
            'versamento_pericardico' => $_POST['versamento_pericardico'] !== '' ? $_POST['versamento_pericardico'] : null,
            'quadro_cinesi' => $_POST['quadro_cinesi'] !== '' ? $_POST['quadro_cinesi'] : null,
            'note_cliniche' => $note_cliniche !== '' ? $note_cliniche : null,
            'conclusioni' => $_POST['conclusioni'] !== '' ? $_POST['conclusioni'] : null
        ];

        if (!empty($esame_id)) {
            $risultato_salvataggio = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id), 'PATCH', $dati_inserimento);
        } else {
            $risultato_salvataggio = supabase_request('cardiologia_ecocardio', 'POST', $dati_inserimento);
        }

        if (isset($risultato_salvataggio['error'])) {
            $errore = "Errore Supabase: " . (is_array($risultato_salvataggio['error']) ? json_encode($risultato_salvataggio['error']) : $risultato_salvataggio['error']);
        } else {
            $messaggio = "Referto ecocardiografico professionale salvato con successo!";
            if (empty($esame_id)) {
                $res_ult = supabase_request('cardiologia_ecocardio?paziente_id=eq.' . urlencode($paziente_id_post) . '&order=created_at.desc&limit=1&select=id', 'GET');
                if (!empty($res_ult) && !isset($res_ult['error']) && is_array($res_ult)) {
                    $esame_id = $res_ult[0]['id'];
                }
            }
            if (!empty($esame_id)) {
                $res_esame = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id) . '&select=*', 'GET');
                if (!empty($res_esame) && !isset($res_esame['error']) && is_array($res_esame)) {
                    $esame_selezionato = $res_esame[0];
                }
            }
        }
    }
}

$pazienti_studio = [];
$elenco_esami_paziente = [];
if ($modulo_attivo && !empty($studio_id_sessione)) {
    $pazienti_studio = supabase_request('pazienti?studio_id=eq.' . urlencode((string)$studio_id_sessione) . '&order=cognome.asc&select=id,nome,cognome,data_nascita', 'GET');
    if (!is_array($pazienti_studio)) {
        $pazienti_studio = [];
    }

    if (!empty($paziente_id)) {
        $res_lista = supabase_request('cardiologia_ecocardio?paziente_id=eq.' . urlencode($paziente_id) . '&order=data_esame.desc&select=id,data_esame,fe_ev,conclusioni', 'GET');
        if (is_array($res_lista) && !isset($res_lista['error'])) {
            $elenco_esami_paziente = $res_lista;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cardiologia & Ecocardiografia Avanzata - Proman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; }
        .app-header { background: linear-gradient(135deg, #0f172a 100%, #1e293b 0%); color: white; padding: 1.25rem 1rem; }
        .main-content { padding-bottom: 90px; }
        .card { border-radius: 1rem; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .section-title { font-size: 0.95rem; font-weight: 700; color: #334155; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.3rem; margin-top: 1.5rem; margin-bottom: 1rem; }
        .algoritmo-box { background: #f8fafc; border-left: 4px solid #0d6efd; padding: 1rem; border-radius: 0.5rem; font-size: 0.88rem; }
        .print-header, .print-footer { display: none; }

        @media print {
            body { background-color: #ffffff; color: #000; font-size: 12pt; }
            .app-header, .no-print, form .d-flex.justify-content-end, .card:has(form select), .algoritmo-box, button, input[type="submit"], input[type="button"] {
                display: none !important;
            }
            .card { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
            .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            input, select, textarea { border: none !important; background: transparent !important; padding: 0 !important; font-size: 11pt !important; box-shadow: none !important; -webkit-appearance: none; appearance: none; }
            .print-header { display: block !important; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
            .print-footer { display: block !important; margin-top: 40px; text-align: right; page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="app-header shadow-sm no-print">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="text-white me-3 text-decoration-none fs-5">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-heart-pulse me-2"></i>Cardiologia & Eco-Color-Doppler (EACVI Standard)</h5>
        </div>
        <?php if ($modulo_attivo && $paziente_selezionato): ?>
        <div>
            <button onclick="window.print()" class="btn btn-light btn-sm fw-semibold text-dark">
                <i class="fa-solid fa-print me-1"></i> Stampa Referto
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container mt-4 main-content">
    <?php if (!$modulo_attivo): ?>
        <div class="card p-5 text-center shadow-sm mt-5">
            <div class="mb-3 text-warning">
                <i class="fa-solid fa-lock fa-3x"></i>
            </div>
            <h3 class="fw-bold text-dark">Modulo non attivo</h3>
            <p class="text-muted">Il modulo di <strong>Cardiologia</strong> non risulta attivo per questo studio nella tabella pagamenti.</p>
            <div class="mt-3">
                <a href="dashboard.php" class="btn btn-primary px-4 rounded-pill">
                    <i class="fa-solid fa-house me-2"></i> Torna alla Dashboard
                </a>
            </div>
        </div>
    <?php else: ?>

        <?php if ($messaggio): ?>
            <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($errore): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                <?php echo htmlspecialchars($errore); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Selezione Paziente -->
        <div class="card p-4 mb-4 no-print">
            <?php if ($paziente_selezionato): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 mb-1 fw-semibold"><i class="fa-solid fa-user-check me-1"></i> Paziente in Esame</span>
                        <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($paziente_selezionato['cognome'] . ' ' . $paziente_selezionato['nome']); ?></h4>
                        <span class="text-muted small"><?php echo !empty($paziente_selezionato['data_nascita']) ? 'Nato/a il ' . date('d/m/Y', strtotime($paziente_selezionato['data_nascita'])) : ''; ?></span>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if (!empty($esame_id)): ?>
                            <a href="visita.php?id=<?php echo htmlspecialchars($esame_id); ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-file-medical me-1"></i> Torna alla visita</a>
                        <?php endif; ?>
                        <a href="cardiologia.php" class="btn btn-outline-secondary btn-sm fw-semibold">
                            <i class="fa-solid fa-user-gear me-1"></i> Nuovo Esame / Altro Paziente
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <form method="GET" action="cardiologia.php" class="row g-3 align-items-end">
                    <div class="col-md-9">
                        <label class="form-label fw-semibold text-secondary">Seleziona Paziente per Nuova Visita</label>
                        <select name="paziente_id" class="form-select bg-light" onchange="this.form.submit()">
                            <option value="">-- Cerca o seleziona paziente --</option>
                            <?php foreach ($pazienti_studio as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo ($paziente_id == $p['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['cognome'] . ' ' . $p['nome'] . (!empty($p['data_nascita']) ? ' (' . date('d/m/Y', strtotime($p['data_nascita'])) . ')' : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-grid">
                        <a href="pazienti.php" class="btn btn-outline-secondary"><i class="fa-solid fa-users me-1"></i> Anagrafica Pazienti</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($paziente_selezionato): ?>
            <?php 
                $form_action_url = 'cardiologia.php?paziente_id=' . urlencode($paziente_id);
                if (!empty($esame_id)) {
                    $form_action_url .= '&id=' . urlencode($esame_id);
                }
            ?>
            <form method="POST" action="<?php echo $form_action_url; ?>" id="formEcocardio">
                <input type="hidden" name="azione" value="salva_ecocardio">
                <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_id); ?>">

                <div class="card p-4 mb-4">
                    <div class="print-header">
                        <h3 class="fw-bold m-0">STUDIO MEDICO SPECIALISTICO</h3>
                        <p class="small text-muted mb-2">Ambulatorio di Cardiologia & Ecocardiografia</p>
                        <hr>
                        <h5 class="fw-bold text-dark m-0">REFERTO ECOCARDIOGRAFICO COLOR-DOPPLER</h5>
                        <p class="small text-muted mt-2 mb-0">Paziente: <strong><?php echo htmlspecialchars($paziente_selezionato['cognome'] . ' ' . $paziente_selezionato['nome']); ?></strong></p>
                        <p class="small text-muted mb-0">Data Esame: <?php echo isset($esame_selezionato['data_esame']) ? date('d/m/Y', strtotime($esame_selezionato['data_esame'])) : date('d/m/Y'); ?></p>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
                        <div>
                            <h4 class="fw-bold text-dark m-0"><i class="fa-solid fa-file-medical text-primary me-2"></i>REFERTO ECOCARDIOGRAFICO</h4>
                            <p class="text-muted small mt-1 mb-0">Compilazione parametri clinici ecocardiografici</p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Data Esame</label>
                            <input type="date" class="form-control form-control-sm bg-light" name="data_esame" value="<?php echo htmlspecialchars($esame_selezionato['data_esame'] ?? date('Y-m-d')); ?>" required>
                        </div>
                    </div>
                    <hr class="text-muted opacity-25 no-print">

                    <!-- Supporto Algoritmico -->
                    <div class="mb-4 no-print">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label fw-bold text-primary mb-1"><i class="fa-solid fa-robot me-1"></i> Controllo Clinico & Compilazione Automatica (Linee Guida EACVI)</label>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="rigeneraRefertoAutomatico()"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> Rigenera Testo Automatico</button>
                        </div>
                        <div id="algoritmoRealtime" class="algoritmo-box text-secondary mt-2">
                            Inserisci i valori biometrici per attivare la generazione automatica del referto...
                        </div>
                    </div>

                    <!-- Sezione 1 -->
                    <div class="section-title"><i class="fa-solid fa-ruler-combined me-2 text-primary"></i>1. Biometria Ventricolare Sinistra e Atriale</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">FE (Frazione di Eiezione)</label>
                            <input type="text" class="form-control bg-light" id="inputFe" name="fe_ev" placeholder="es. 55%" value="<?php echo htmlspecialchars($esame_selezionato['fe_ev'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Diametro Telediastolico (DDV)</label>
                            <input type="text" class="form-control bg-light" id="inputDdv" name="diametro_telediastolico_vs" placeholder="es. 48 mm" value="<?php echo htmlspecialchars($esame_selezionato['diametro_telediastolico_vs'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Diametro Telesistolico (DSV)</label>
                            <input type="text" class="form-control bg-light" id="inputDsv" name="diametro_telesistolico_vs" placeholder="es. 32 mm" value="<?php echo htmlspecialchars($esame_selezionato['diametro_telesistolico_vs'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Spessore SIV</label>
                            <input type="text" class="form-control bg-light" id="inputSiv" name="spessore_siv" placeholder="es. 10 mm" value="<?php echo htmlspecialchars($esame_selezionato['spessore_siv'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Spessore PP (Parete Posteriore)</label>
                            <input type="text" class="form-control bg-light" id="inputPp" name="spessore_pp" placeholder="es. 9 mm" value="<?php echo htmlspecialchars($esame_selezionato['spessore_pp'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Atrio Sinistro (Diametro AP)</label>
                            <input type="text" class="form-control bg-light" id="inputAs" name="atrio_sinistro" placeholder="es. 38 mm" value="<?php echo htmlspecialchars($esame_selezionato['atrio_sinistro'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Radice Aortica</label>
                            <input type="text" class="form-control bg-light" id="inputAo" name="radice_aortica" placeholder="es. 32 mm" value="<?php echo htmlspecialchars($esame_selezionato['radice_aortica'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                    </div>

                    <!-- Sezione 2 -->
                    <div class="section-title"><i class="fa-solid fa-arrow-trend-up me-2 text-primary"></i>2. Ventricolo Destro e Sezioni Destre</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">TAPSE (Funzione VD)</label>
                            <input type="text" class="form-control bg-light" id="inputTapse" name="tapse" placeholder="es. 22 mm" value="<?php echo htmlspecialchars($esame_selezionato['tapse'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">PAPS (Press. Art. Polmonare Sistolica)</label>
                            <input type="text" class="form-control bg-light" id="inputPaps" name="paps" placeholder="es. 28 mmHg" value="<?php echo htmlspecialchars($esame_selezionato['paps'] ?? ''); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Morfologia e Cinesi VD</label>
                            <input type="text" class="form-control bg-light" id="inputVdMorf" name="vd_morfologia" placeholder="es. Normale per dimensioni e cinesi" value="<?php echo htmlspecialchars($esame_selezionato['vd_morfologia'] ?? 'Normale per dimensioni e cinesi'); ?>" oninput="aggiornaAlgoritmo()">
                        </div>
                    </div>

                    <!-- Sezione 3 -->
                    <div class="section-title"><i class="fa-solid fa-water me-2 text-primary"></i>3. Valutazione Valvolare e Color-Doppler</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Morfologia Aortica</label>
                            <select name="morfologia_aortica" id="selectMorfAo" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Tricuspide normoiginata" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Tricuspide normoiginata') ? 'selected' : ''; ?>>Tricuspide normoiginata</option>
                                <option value="Bicuspide" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Bicuspide') ? 'selected' : ''; ?>>Bicuspide</option>
                                <option value="Altro / Sclerosata" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Altro / Sclerosata') ? 'selected' : ''; ?>>Altro / Sclerosata</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Stenosi Aortica</label>
                            <select name="stenosi_aortica" id="selectStenAo" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Aortica</label>
                            <select name="insufficienza_aortica" id="selectInsAo" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Mitralica</label>
                            <select name="insufficienza_mitralica" id="selectInsMit" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Stenosi Mitralica</label>
                            <select name="stenosi_mitralica" id="selectStenMit" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata/Severa" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Moderata/Severa') ? 'selected' : ''; ?>>Moderata / Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Tricuspidale</label>
                            <select name="insufficienza_tricuspidale" id="selectInsTric" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Versamento Pericardico</label>
                            <select name="versamento_pericardico" id="selectVersP" class="form-select bg-light" onchange="aggiornaAlgoritmo()">
                                <option value="Assente" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderato/Severo" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Moderato/Severo') ? 'selected' : ''; ?>>Moderato / Severo</option>
                            </select>
                        </div>
                    </div>

                    <!-- Sezione 4 -->
                    <div class="section-title"><i class="fa-solid fa-notes-medical me-2 text-primary"></i>4. Note Cliniche, Cinesi e Conclusioni</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Quadro Cinesi / Segmentaria</label>
                            <textarea class="form-control bg-light" name="quadro_cinesi" rows="2" placeholder="Es. Cinesi conservata, assenza di ipocinesie regionali significative."><?php echo htmlspecialchars($esame_selezionato['quadro_cinesi'] ?? 'Ventricolo sinistro di normali dimensioni e spessori parietali conservati. Cinesi globale e segmentaria conservata.'); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Note Cliniche e Descrizione Dettagliata</label>
                            <textarea class="form-control bg-light" name="note_cliniche" rows="4" placeholder="Ulteriori rilievi ecocardiografici..."><?php echo htmlspecialchars($esame_selezionato['note_cliniche'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Conclusioni Diagnostiche</label>
                            <textarea class="form-control bg-light" name="conclusioni" rows="3" placeholder="Sintesi diagnostica finale..."><?php echo htmlspecialchars($esame_selezionato['conclusioni'] ?? 'Ecocardiogramma color-doppler nei limiti della norma per flussi, morfologia e funzioni sisto-diastoliche.'); ?></textarea>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                        <a href="cardiologia.php" class="btn btn-outline-secondary px-4"><i class="fa-solid fa-arrow-left me-1"></i> Indietro</a>
                        <button type="submit" class="btn btn-primary px-5 fw-semibold"><i class="fa-solid fa-floppy-disk me-2"></i> Salva Referto Ecocardiografico</button>
                    </div>
                </div>

                <div class="print-footer">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($nome_medico_stampato); ?></strong></p>
                    <p class="small text-muted">Specialista in Cardiologia / Medico Refertante</p>
                </div>
            </form>

            <?php if (!empty($elenco_esami_paziente)): ?>
            <div class="card p-4 no-print mt-4">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-history me-2 text-primary"></i>Storico Esami Ecocardiografici del Paziente</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Data Esame</th>
                                <th>FE (%)</th>
                                <th>Conclusioni</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($elenco_esami_paziente as $es): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($es['data_esame'])); ?></td>
                                <td><span class="badge bg-primary bg-opacity-10 text-primary fw-semibold"><?php echo htmlspecialchars($es['fe_ev'] ?? '-'); ?></span></td>
                                <td><?php echo htmlspecialchars(substr($es['conclusioni'] ?? '', 0, 80)) . '...'; ?></td>
                                <td class="text-end">
                                    <a href="cardiologia.php?paziente_id=<?php echo $paziente_id; ?>&id=<?php echo $es['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-pen-to-square"></i> Apri
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function estraiNumero(testo) {
    if (!testo) return null;
    let match = testo.toString().match(/\d+/);
    return match ? parseInt(match[0], 10) : null;
}

function aggiornaAlgoritmo() {
    let feRaw = document.getElementById('inputFe').value;
    let tapseRaw = document.getElementById('inputTapse').value;
    let papsRaw = document.getElementById('inputPaps').value;
    let sivRaw = document.getElementById('inputSiv').value;
    let ppRaw = document.getElementById('inputPp').value;
    
    let fe = estraiNumero(feRaw);
    let tapse = estraiNumero(tapseRaw);
    let paps = estraiNumero(papsRaw);
    let siv = estraiNumero(sivRaw);
    let pp = estraiNumero(ppRaw);

    let box = document.getElementById('algoritmoRealtime');
    let html = '<ul class="mb-0 ps-3">';
    let count = 0;

    if (fe !== null) {
        count++;
        if (fe < 30) {
            html += '<li class="text-danger fw-bold">FE < 30%: Grave disfunzione sistolica del ventricolo sinistro.</li>';
        } else if (fe >= 30 && fe < 40) {
            html += '<li class="text-warning fw-bold">FE moderatamente ridotta (30-39%).</li>';
        } else if (fe >= 40 && fe < 50) {
            html += '<li class="text-info fw-bold">FE lievemente ridotta / borderline (40-49%).</li>';
        } else {
            html += '<li class="text-success">FE conservata (>= 50%).</li>';
        }
    }

    if (tapse !== null) {
        count++;
        if (tapse < 17) {
            html += '<li class="text-danger fw-bold">TAPSE < 17 mm: Ridotta funzione longitudinale del ventricolo destro.</li>';
        } else {
            html += '<li class="text-success">Funzione sistolica del ventricolo destro conservata (TAPSE >= 17 mm).</li>';
        }
    }

    if (paps !== null) {
        count++;
        if (paps > 40) {
            html += '<li class="text-warning fw-bold">PAPS > 40 mmHg: Segno di possibile ipertensione polmonare.</li>';
        } else {
            html += '<li class="text-success">PAPS nei limiti di norma (<= 40 mmHg).</li>';
        }
    }

    if (siv !== null && pp !== null) {
        count++;
        if (siv > 11 || pp > 11) {
            html += '<li class="text-warning fw-bold">Spessori parietali > 11 mm: Sospetta ipertrofia ventricolare sinistra.</li>';
        } else {
            html += '<li class="text-success">Spessori parietali ventricolari sinistri nella norma.</li>';
        }
    }

    if (count === 0) {
        box.innerHTML = "Inserisci i valori biometrici per attivare la generazione automatica del referto...";
    } else {
        html += '</ul>';
        box.innerHTML = html;
    }
}

function rigeneraRefertoAutomatico() {
    aggiornaAlgoritmo();
    let feRaw = document.getElementById('inputFe').value;
    let tapseRaw = document.getElementById('inputTapse').value;
    let papsRaw = document.getElementById('inputPaps').value;
    
    let fe = estraiNumero(feRaw);
    let concField = document.querySelector('textarea[name="conclusioni"]');
    
    if (fe !== null && fe < 40) {
        concField.value = "Ecocardiogramma color-doppler che documenta disfunzione sistolica ventricolare sinistra di grado moderato/severo. Si consiglia ottimizzazione terapia medica e rivalutazione specialistica.";
    } else {
        concField.value = "Ecocardiogramma color-doppler nei limiti della norma per flussi, morfologia e funzioni sisto-diastoliche.";
    }
    alert("Testo generato automaticamente in base ai parametri inseriti!");
}

document.addEventListener("DOMContentLoaded", function() {
    aggiornaAlgoritmo();
});
</script>
</body>
</html>