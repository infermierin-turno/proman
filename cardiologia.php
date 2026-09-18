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

$esame_id = $_GET['id'] ?? $_GET['esame_id'] ?? '';
$paziente_id = $_GET['paziente_id'] ?? '';
$controllo_id = $_GET['controllo_id'] ?? '';
$paziente_selezionato = null;
$esame_selezionato = null;

if ($modulo_attivo && !empty($esame_id)) {
    $res_esame = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id) . '&select=*', 'GET');
    if (!empty($res_esame) && !isset($res_esame['error']) && is_array($res_esame) && count($res_esame) > 0) {
        $esame_selezionato = $res_esame[0];
        if (empty($paziente_id) && !empty($esame_selezionato['paziente_id'])) {
            $paziente_id = $esame_selezionato['paziente_id'];
        }
        if (empty($controllo_id) && !empty($esame_selezionato['controllo_id'])) {
            $controllo_id = $esame_selezionato['controllo_id'];
        }
    }
}

if ($modulo_attivo && !empty($paziente_id)) {
    $res_paz = supabase_request('pazienti?id=eq.' . urlencode($paziente_id) . '&select=*', 'GET');
    if (!empty($res_paz) && !isset($res_paz['error']) && is_array($res_paz) && count($res_paz) > 0) {
        $paziente_selezionato = $res_paz[0];
        if (empty($studio_id_sessione) && !empty($paziente_selezionato['studio_id'])) {
            $studio_id_sessione = $paziente_selezionato['studio_id'];
        }
    }
}

// Gestione salvataggio referto
if ($modulo_attivo && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'salva_ecocardio') {
    $paziente_id_post = $_POST['paziente_id'] ?? '';
    $esame_id_post = trim($_POST['esame_id'] ?? '');
    $controllo_id_post = trim($_POST['controllo_id'] ?? '') !== '' ? trim($_POST['controllo_id']) : null;
    
    if (empty($studio_id_sessione) && !empty($paziente_id_post)) {
        $res_paz_chk = supabase_request('pazienti?id=eq.' . urlencode($paziente_id_post) . '&select=studio_id', 'GET');
        if (!empty($res_paz_chk) && !isset($res_paz_chk['error'])) {
            $studio_id_sessione = $res_paz_chk[0]['studio_id'] ?? null;
        }
    }

    if (empty($paziente_id_post) || empty($studio_id_sessione)) {
        $errore = "Errore critico: Impossibile determinare il Paziente o lo Studio di appartenenza per il salvataggio.";
    } else {
        $data_esame_input = $_POST['data_esame'] ?? date('Y-m-d');
        
        $dati_inserimento = [
            'paziente_id' => $paziente_id_post,
            'studio_id' => $studio_id_sessione,
            'controllo_id' => $controllo_id_post,
            'data_esame' => $data_esame_input,
            'fe_ev' => trim($_POST['fe_ev'] ?? '') !== '' ? trim($_POST['fe_ev']) : null,
            'diametro_telediastolico_vs' => trim($_POST['diametro_telediastolico_vs'] ?? '') !== '' ? trim($_POST['diametro_telediastolico_vs']) : null,
            'diametro_telesistolico_vs' => trim($_POST['diametro_telesistolico_vs'] ?? '') !== '' ? trim($_POST['diametro_telesistolico_vs']) : null,
            'spessore_siv' => trim($_POST['spessore_siv'] ?? '') !== '' ? trim($_POST['spessore_siv']) : null,
            'spessore_pp' => trim($_POST['spessore_pp'] ?? '') !== '' ? trim($_POST['spessore_pp']) : null,
            'atrio_sinistro' => trim($_POST['atrio_sinistro'] ?? '') !== '' ? trim($_POST['atrio_sinistro']) : null,
            'radice_aortica' => trim($_POST['radice_aortica'] ?? '') !== '' ? trim($_POST['radice_aortica']) : null,
            'tapse' => trim($_POST['tapse'] ?? '') !== '' ? trim($_POST['tapse']) : null,
            'paps' => trim($_POST['paps'] ?? '') !== '' ? trim($_POST['paps']) : null,
            'vd_morfologia' => trim($_POST['vd_morfologia'] ?? '') !== '' ? trim($_POST['vd_morfologia']) : null,
            'morfologia_aortica' => trim($_POST['morfologia_aortica'] ?? '') !== '' ? trim($_POST['morfologia_aortica']) : null,
            'stenosi_aortica' => trim($_POST['stenosi_aortica'] ?? '') !== '' ? trim($_POST['stenosi_aortica']) : null,
            'insufficienza_aortica' => trim($_POST['insufficienza_aortica'] ?? '') !== '' ? trim($_POST['insufficienza_aortica']) : null,
            'insufficienza_mitralica' => trim($_POST['insufficienza_mitralica'] ?? '') !== '' ? trim($_POST['insufficienza_mitralica']) : null,
            'stenosi_mitralica' => trim($_POST['stenosi_mitralica'] ?? '') !== '' ? trim($_POST['stenosi_mitralica']) : null,
            'insufficienza_tricuspidale' => trim($_POST['insufficienza_tricuspidale'] ?? '') !== '' ? trim($_POST['insufficienza_tricuspidale']) : null,
            'versamento_pericardico' => trim($_POST['versamento_pericardico'] ?? '') !== '' ? trim($_POST['versamento_pericardico']) : null,
            'quadro_cinesi' => trim($_POST['quadro_cinesi'] ?? '') !== '' ? trim($_POST['quadro_cinesi']) : null,
            'note_cliniche' => trim($_POST['note_cliniche'] ?? '') !== '' ? trim($_POST['note_cliniche']) : null,
            'conclusioni' => trim($_POST['conclusioni'] ?? '') !== '' ? trim($_POST['conclusioni']) : null
        ];

        if (!empty($esame_id_post)) {
            $risultato_salvataggio = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id_post), 'PATCH', $dati_inserimento);
            $esame_id = $esame_id_post;
        } else {
            $risultato_salvataggio = supabase_request('cardiologia_ecocardio', 'POST', $dati_inserimento);
            
            if (!isset($risultato_salvataggio['error'])) {
                $res_ult = supabase_request('cardiologia_ecocardio?paziente_id=eq.' . urlencode($paziente_id_post) . '&order=created_at.desc&limit=1&select=id', 'GET');
                if (!empty($res_ult) && !isset($res_ult['error']) && is_array($res_ult) && count($res_ult) > 0) {
                    $esame_id = $res_ult[0]['id'];
                }
            }
        }

        if (isset($risultato_salvataggio['error']) || !empty($risultato_salvataggio['error'])) {
            $errore = "Errore durante il salvataggio su Supabase.";
            if (is_array($risultato_salvataggio['error'])) {
                $errore .= " " . json_encode($risultato_salvataggio['error']);
            }
        } else {
            $messaggio = "Referto ecocardiografico salvato con successo!";
            if (!empty($esame_id)) {
                $res_esame = supabase_request('cardiologia_ecocardio?id=eq.' . urlencode($esame_id) . '&select=*', 'GET');
                if (!empty($res_esame) && !isset($res_esame['error']) && is_array($res_esame) && count($res_esame) > 0) {
                    $esame_selezionato = $res_esame[0];
                    if (!empty($esame_selezionato['controllo_id'])) {
                        $controllo_id = $esame_selezionato['controllo_id'];
                    }
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
    <title>Cardiologia & Ecocardiografia - Proman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; }
        .app-header { background: linear-gradient(135deg, #0f172a 100%, #1e293b 0%); color: white; padding: 1.25rem 1rem; }
        .main-content { padding-bottom: 90px; }
        .card { border-radius: 1rem; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.03); }
        .section-title { font-size: 0.95rem; font-weight: 700; color: #334155; border-bottom: 2px solid #e2e8f0; padding-bottom: 0.3rem; margin-top: 1.5rem; margin-bottom: 1rem; }
        .print-header, .print-footer { display: none; }

        @media print {
            body { background-color: #ffffff; color: #000; font-size: 12pt; }
            .app-header, .no-print, form .d-flex.justify-content-end, .card:has(form select), button, input[type="submit"], input[type="button"] {
                display: none !important;
            }
            .card { box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
            .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            input, select, textarea { border: none !important; background: transparent !important; padding: 0 !important; font-size: 11pt !important; box-shadow: none !important; }
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
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-heart-pulse me-2"></i>Cardiologia & Eco-Color-Doppler</h5>
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
            <p class="text-muted">Il modulo di <strong>Cardiologia</strong> non risulta attivo per questo studio.</p>
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
                <strong><?php echo htmlspecialchars($errore); ?></strong>
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
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <?php if (!empty($controllo_id)): ?>
                            <a href="visita.php?id=<?php echo urlencode($controllo_id); ?>" class="btn btn-info btn-sm fw-semibold text-white">
                                <i class="fa-solid fa-file-medical me-1"></i> Apri Visita / Controllo Collegato
                            </a>
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
                if (!empty($controllo_id)) {
                    $form_action_url .= '&controllo_id=' . urlencode($controllo_id);
                }
            ?>
            <form method="POST" action="<?php echo $form_action_url; ?>" id="formEcocardio">
                <input type="hidden" name="azione" value="salva_ecocardio">
                <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_id); ?>">
                <input type="hidden" name="esame_id" value="<?php echo htmlspecialchars($esame_id); ?>">
                <input type="hidden" name="controllo_id" value="<?php echo htmlspecialchars($controllo_id); ?>">

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
                            <p class="text-muted small mt-1 mb-0">Compilazione parametri clinici e generazione automatica referto</p>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Data Esame</label>
                            <input type="date" class="form-control form-control-sm bg-light" name="data_esame" value="<?php echo htmlspecialchars(isset($esame_selezionato['data_esame']) ? substr($esame_selezionato['data_esame'], 0, 10) : date('Y-m-d')); ?>" required>
                        </div>
                    </div>
                    <hr class="text-muted opacity-25 no-print">

                    <!-- Sezione 1 -->
                    <div class="section-title"><i class="fa-solid fa-ruler-combined me-2 text-primary"></i>1. Biometria Ventricolare Sinistra e Atriale</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">FE (Frazione di Eiezione)</label>
                            <input type="text" class="form-control bg-light" id="inputFe" name="fe_ev" placeholder="es. 55%" value="<?php echo htmlspecialchars($esame_selezionato['fe_ev'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Diametro Telediastolico (DDV)</label>
                            <input type="text" class="form-control bg-light" id="inputDdv" name="diametro_telediastolico_vs" placeholder="es. 48 mm" value="<?php echo htmlspecialchars($esame_selezionato['diametro_telediastolico_vs'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Diametro Telesistolico (DSV)</label>
                            <input type="text" class="form-control bg-light" id="inputDsv" name="diametro_telesistolico_vs" placeholder="es. 32 mm" value="<?php echo htmlspecialchars($esame_selezionato['diametro_telesistolico_vs'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Spessore SIV</label>
                            <input type="text" class="form-control bg-light" id="inputSiv" name="spessore_siv" placeholder="es. 10 mm" value="<?php echo htmlspecialchars($esame_selezionato['spessore_siv'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Spessore PP (Parete Posteriore)</label>
                            <input type="text" class="form-control bg-light" id="inputPp" name="spessore_pp" placeholder="es. 9 mm" value="<?php echo htmlspecialchars($esame_selezionato['spessore_pp'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Atrio Sinistro (Diametro AP)</label>
                            <input type="text" class="form-control bg-light" id="inputAs" name="atrio_sinistro" placeholder="es. 38 mm" value="<?php echo htmlspecialchars($esame_selezionato['atrio_sinistro'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Radice Aortica</label>
                            <input type="text" class="form-control bg-light" id="inputAo" name="radice_aortica" placeholder="es. 32 mm" value="<?php echo htmlspecialchars($esame_selezionato['radice_aortica'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                    </div>

                    <!-- Sezione 2 -->
                    <div class="section-title"><i class="fa-solid fa-arrow-trend-up me-2 text-primary"></i>2. Ventricolo Destro e Sezioni Destre</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">TAPSE (Funzione VD)</label>
                            <input type="text" class="form-control bg-light" id="inputTapse" name="tapse" placeholder="es. 22 mm" value="<?php echo htmlspecialchars($esame_selezionato['tapse'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">PAPS (Press. Art. Polmonare Sistolica)</label>
                            <input type="text" class="form-control bg-light" id="inputPaps" name="paps" placeholder="es. 28 mmHg" value="<?php echo htmlspecialchars($esame_selezionato['paps'] ?? ''); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Morfologia e Cinesi VD</label>
                            <input type="text" class="form-control bg-light" id="inputVdMorf" name="vd_morfologia" placeholder="es. Normale per dimensioni e cinesi" value="<?php echo htmlspecialchars($esame_selezionato['vd_morfologia'] ?? 'Normale per dimensioni e cinesi'); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                    </div>

                    <!-- Sezione 3 -->
                    <div class="section-title"><i class="fa-solid fa-water me-2 text-primary"></i>3. Valutazione Valvolare e Color-Doppler</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Morfologia Aortica</label>
                            <select name="morfologia_aortica" id="selectMorfAo" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Tricuspide normoiginata" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Tricuspide normoiginata') ? 'selected' : ''; ?>>Tricuspide normoiginata</option>
                                <option value="Bicuspide" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Bicuspide') ? 'selected' : ''; ?>>Bicuspide</option>
                                <option value="Altro / Sclerosata" <?php echo (($esame_selezionato['morfologia_aortica'] ?? '') === 'Altro / Sclerosata') ? 'selected' : ''; ?>>Altro / Sclerosata</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Stenosi Aortica</label>
                            <select name="stenosi_aortica" id="selectStenAo" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['stenosi_aortica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Aortica</label>
                            <select name="insufficienza_aortica" id="selectInsAo" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_aortica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Mitralica</label>
                            <select name="insufficienza_mitralica" id="selectInsMit" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_mitralica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Stenosi Mitralica</label>
                            <select name="stenosi_mitralica" id="selectStenMit" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['stenosi_mitralica'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Insufficienza Tricuspidale</label>
                            <select name="insufficienza_tricuspidale" id="selectInsTric" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente/Tracce" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Assente/Tracce') ? 'selected' : ''; ?>>Assente / Tracce</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderata" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Moderata') ? 'selected' : ''; ?>>Moderata</option>
                                <option value="Severa" <?php echo (($esame_selezionato['insufficienza_tricuspidale'] ?? '') === 'Severa') ? 'selected' : ''; ?>>Severa</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Versamento Pericardico</label>
                            <select name="versamento_pericardico" id="selectVersPeric" class="form-select bg-light" onchange="rigeneraRefertoAutomatico()">
                                <option value="Assente" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Assente') ? 'selected' : ''; ?>>Assente</option>
                                <option value="Lieve" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Lieve') ? 'selected' : ''; ?>>Lieve</option>
                                <option value="Moderato" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Moderato') ? 'selected' : ''; ?>>Moderato</option>
                                <option value="Abbondante" <?php echo (($esame_selezionato['versamento_pericardico'] ?? '') === 'Abbondante') ? 'selected' : ''; ?>>Abbondante</option>
                            </select>
                        </div>
                    </div>

                    <!-- Sezione 4 -->
                    <div class="section-title"><i class="fa-solid fa-stethoscope me-2 text-primary"></i>4. Cinesi, Note Cliniche e Conclusioni (Auto-generate e Modificabili)</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Quadro Cinesi Segmentaria</label>
                            <input type="text" class="form-control bg-light" id="inputCinesi" name="quadro_cinesi" placeholder="es. Normocinesia globale e segmentaria ventricolare sinistra." value="<?php echo htmlspecialchars($esame_selezionato['quadro_cinesi'] ?? 'Normocinesia globale e segmentaria ventricolare sinistra.'); ?>" oninput="rigeneraRefertoAutomatico()">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Note Cliniche / Anamnesi / Descrizione Ecografica</label>
                            <textarea class="form-control bg-light" id="inputNote" name="note_cliniche" rows="4" placeholder="Descrizione dettagliata o note cliniche..."><?php echo htmlspecialchars($esame_selezionato['note_cliniche'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-semibold">Conclusioni Diagnostiche</label>
                            <textarea class="form-control bg-light fw-bold text-dark" id="inputConclusioni" name="conclusioni" rows="3" placeholder="Conclusioni diagnostiche finali..."><?php echo htmlspecialchars($esame_selezionato['conclusioni'] ?? 'Ventricolo sinistro di normali dimensioni e spessori parietali, con conservata funzione di eiezione globale. Valvole aortica e mitralica morfologicamente e funzionalmente nella norma. Assenza di versamento pericardico significativo.'); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top no-print">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="compilaValoriNormali()">
                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Precompila Normale
                            </button>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                                <i class="fa-solid fa-floppy-disk me-2"></i> Salva Referto
                            </button>
                        </div>
                    </div>

                    <div class="print-footer">
                        <p class="mb-1">Il Medico Specialista</p>
                        <p class="fw-bold mt-4 mb-0"><?php echo htmlspecialchars($nome_medico_stampato); ?></p>
                    </div>
                </div>
            </form>

            <?php if (!empty($elenco_esami_paziente)): ?>
                <div class="card p-4 mb-4 no-print">
                    <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-history me-2 text-secondary"></i>Storico Esami Ecocardiografici del Paziente</h5>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Data Esame</th>
                                    <th>FE / Frazione Eiezione</th>
                                    <th>Conclusioni</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($elenco_esami_paziente as $es): ?>
                                    <tr class="<?php echo ($esame_id == $es['id']) ? 'table-primary' : ''; ?>">
                                        <td><?php echo date('d/m/Y', strtotime($es['data_esame'])); ?></td>
                                        <td><?php echo htmlspecialchars($es['fe_ev'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($es['conclusioni'] ?? '', 0, 70)) . (strlen($es['conclusioni'] ?? '') > 70 ? '...' : ''); ?></td>
                                        <td class="text-end">
                                            <a href="cardiologia.php?paziente_id=<?php echo $paziente_id; ?>&id=<?php echo $es['id']; ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                                <i class="fa-solid fa-folder-open me-1"></i> Apri / Stampa
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
function compilaValoriNormali() {
    document.getElementById('inputFe').value = '55%';
    document.getElementById('inputDdv').value = '48 mm';
    document.getElementById('inputDsv').value = '32 mm';
    document.getElementById('inputSiv').value = '10 mm';
    document.getElementById('inputPp').value = '9 mm';
    document.getElementById('inputAs').value = '36 mm';
    document.getElementById('inputAo').value = '32 mm';
    document.getElementById('inputTapse').value = '22 mm';
    document.getElementById('inputPaps').value = '25 mmHg';
    document.getElementById('inputVdMorf').value = 'Normale per dimensioni e cinesi';
    
    document.getElementById('selectMorfAo').value = 'Tricuspide normoiginata';
    document.getElementById('selectStenAo').value = 'Assente';
    document.getElementById('selectInsAo').value = 'Assente/Tracce';
    document.getElementById('selectInsMit').value = 'Assente/Tracce';
    document.getElementById('selectStenMit').value = 'Assente';
    document.getElementById('selectInsTric').value = 'Assente/Tracce';
    document.getElementById('selectVersPeric').value = 'Assente';
    
    document.getElementById('inputCinesi').value = 'Normocinesia globale e segmentaria ventricolare sinistra.';
    document.getElementById('inputConclusioni').value = 'Ventricolo sinistro di normali dimensioni e spessori parietali, con conservata funzione di eiezione globale. Valvole aortica e mitralica morfologicamente e funzionalmente nella norma. Assenza di versamento pericardico significativo.';
    
    rigeneraRefertoAutomatico();
}

function rigeneraRefertoAutomatico() {
    let fe = document.getElementById('inputFe').value || 'conservata';
    let ddv = document.getElementById('inputDdv').value;
    let dsiv = document.getElementById('inputSiv').value;
    let dpp = document.getElementById('inputPp').value;
    let das = document.getElementById('inputAs').value;
    let tapse = document.getElementById('inputTapse').value;
    let paps = document.getElementById('inputPaps').value;
    
    let morfAo = document.getElementById('selectMorfAo').value;
    let stenAo = document.getElementById('selectStenAo').value;
    let insAo = document.getElementById('selectInsAo').value;
    let insMit = document.getElementById('selectInsMit').value;
    let stenMit = document.getElementById('selectStenMit').value;
    let insTric = document.getElementById('selectInsTric').value;
    let versPeric = document.getElementById('selectVersPeric').value;
    let cinesi = document.getElementById('inputCinesi').value;

    let notaDesc = "Esame ecocardiografico color-doppler eseguito con sonda settoriale.\n\n" +
                   "BIOMETRIA E MORFOLOGIA:\n" +
                   "- Radice aortica e aorta ascendente di calibro regolare. Valvola aortica " + morfAo.toLowerCase() + ", con apertura regolare (stenosi: " + stenAo.toLowerCase() + ", insufficienza: " + insAo.toLowerCase() + ").\n" +
                   "- Atrio sinistro di dimensioni" + (das ? " (diametro AP " + das + ")" : " nella norma") + ".\n" +
                   "- Ventricolo sinistro" + (ddv ? " (DDV " + ddv + ")" : "") + " di normali dimensioni cavitarie e spessori parietali" + (dsiv || dpp ? " (SIV " + (dsiv||'-') + ", PP " + (dpp||'-') + ")" : "") + ". " + cinesi + "\n" +
                   "- Frazione di eiezione globale del ventricolo sinistro (FE) stimata intorno al " + fe + ".\n" +
                   "- Sezioni destre: Ventricolo destro normodimensionato e normocinetico" + (tapse ? " (TAPSE " + tapse + ")" : "") + ". Sezioni destre non dilatate. PAPS stimata a " + (paps ? paps : "limiti di norma") + ".\n" +
                   "- Valvola mitralica con lembi sottili e mobili, insufficienza " + insMit.toLowerCase() + " (stenosi: " + stenMit.toLowerCase() + ").\n" +
                   "- Valvola tricuspide con insufficienza " + insTric.toLowerCase() + ".\n" +
                   "- Pericardio: Versamento pericardico " + versPeric.toLowerCase() + ".";

    let campoNote = document.getElementById('inputNote');
    if (campoNote && (!campoNote.value || campoNote.dataset.auto === '1')) {
        campoNote.value = notaDesc;
        campoNote.dataset.auto = '1';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    let campoNote = document.getElementById('inputNote');
    if (campoNote) {
        campoNote.addEventListener('input', function() {
            this.dataset.auto = '0';
        });
    }
});
</script>
</body>
</html>