<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Inclusione centralizzata del file di configurazione e delle funzioni API (Regola tassativa)
require_once 'config.php';

// chirurgia_della_mano.php - Scheda Specialistica e Algoritmi di Valutazione per Chirurgia della Mano
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// Recupero robusto dello studio_id dalla sessione in tutte le varianti possibili
$session_studio_id = $_SESSION['studio_id'] ?? ($_SESSION['azienda_id'] ?? ($_SESSION['studio'] ?? ''));

$studio_id = $session_studio_id;
if ($is_admin && isset($_GET['studio_id']) && trim($_GET['studio_id']) !== '') {
    $studio_id = trim($_GET['studio_id']);
}

$paziente_id = $_GET['paziente_id'] ?? '';
$controllo_id = $_GET['controllo_id'] ?? null;

$messaggio = '';
$tipo_alert = '';

// Gestione sicura del nome del medico / utente in sessione (evita array o valori non stringa)
$raw_medico = $_SESSION['nome_medico'] ?? ($_SESSION['nome_utente'] ?? ($_SESSION['utente'] ?? 'Dr. Specialista'));
if (is_array($raw_medico)) {
    $nome_medico = $raw_medico['nome'] ?? ($raw_medico['username'] ?? 'Dr. Specialista');
} else {
    $nome_medico = trim((string)$raw_medico);
}
if ($nome_medico === '') {
    $nome_medico = 'Dr. Specialista';
}

// GESTIONE SALVATAGGIO (POST) - Inserimento o Aggiornamento basato sul controllo_id o paziente_id
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_paziente_id = trim($_POST['paziente_id'] ?? $paziente_id);
    
    // Recupero sicuro e prioritario dello studio_id dal POST o dalla sessione
    $post_studio_id = trim($_POST['studio_id'] ?? '');
    if (empty($post_studio_id)) {
        $post_studio_id = $session_studio_id;
    }
    
    $post_controllo_id = !empty($_POST['controllo_id']) ? trim($_POST['controllo_id']) : null;
    
    $data_valutazione = date('c'); // Formato ISO 8601 per timestamptz
    
    $lato = trim($_POST['lato'] ?? '');
    $arto_dominante = trim($_POST['arto_dominante'] ?? '');
    $tipo_lesione = trim($_POST['tipo_lesione'] ?? '');
    $anamnesi_mirata = trim($_POST['anamnesi_mirata'] ?? '') !== '' ? trim($_POST['anamnesi_mirata']) : null;
    
    $scala_vas = intval($_POST['scala_vas'] ?? 0);
    $scala_moberg = trim($_POST['scala_moberg'] ?? '') !== '' ? trim($_POST['scala_moberg']) : null;
    
    $punteggio_dash = null;
    if (isset($_POST['dash_score_manual']) && $_POST['dash_score_manual'] !== '') {
        $punteggio_dash = floatval($_POST['dash_score_manual']);
    }

    $rom_dita_lesionato = trim($_POST['rom_dita_lesionato'] ?? '') !== '' ? trim($_POST['rom_dita_lesionato']) : null;
    $rom_dita_sano = trim($_POST['rom_dita_sano'] ?? '') !== '' ? trim($_POST['rom_dita_sano']) : null;
    
    $forza_presa_lesionato = !empty($_POST['forza_presa_lesionato']) ? floatval($_POST['forza_presa_lesionato']) : null;
    $forza_presa_sano = !empty($_POST['forza_presa_sano']) ? floatval($_POST['forza_presa_sano']) : null;

    $phalen = trim($_POST['phalen'] ?? '') !== '' ? trim($_POST['phalen']) : null;
    $tinel = trim($_POST['tinel'] ?? '') !== '' ? trim($_POST['tinel']) : null;
    $finkelstein = trim($_POST['finkelstein'] ?? '') !== '' ? trim($_POST['finkelstein']) : null;
    
    $staging_dupuytren = trim($_POST['staging_dupuytren'] ?? '') !== '' ? trim($_POST['staging_dupuytren']) : null;
    $sintesi_emg = trim($_POST['sintesi_emg'] ?? '') !== '' ? trim($_POST['sintesi_emg']) : null;
    $dettagli_trauma_acuto = trim($_POST['dettagli_trauma_acuto'] ?? '') !== '' ? trim($_POST['dettagli_trauma_acuto']) : null;
    
    $trattamento_proposto = trim($_POST['trattamento_proposto'] ?? '') !== '' ? trim($_POST['trattamento_proposto']) : null;
    $protocollo_riabilitativo = trim($_POST['protocollo_riabilitativo'] ?? '') !== '' ? trim($_POST['protocollo_riabilitativo']) : null;
    $data_rimozione_tutore = !empty($_POST['data_rimozione_tutore']) ? $_POST['data_rimozione_tutore'] : null;
    
    if (empty($data_rimozione_tutore) && !empty($trattamento_proposto)) {
        $giorni_aggiuntivi = 21; 
        if (strpos(strtolower($trattamento_proposto), 'carpale') !== false) {
            $giorni_aggiuntivi = 12; 
        }
        $data_rimozione_tutore = date('Y-m-d', strtotime("+$giorni_aggiuntivi days"));
    }

    $diario_operatorio = trim($_POST['diario_operatorio'] ?? '') !== '' ? trim($_POST['diario_operatorio']) : null;
    $note_fotografiche = trim($_POST['note_fotografiche'] ?? '') !== '' ? trim($_POST['note_fotografiche']) : null;
    
    // Gestione Upload File nella cartella uploads/
    $foto_clinica_path = trim($_POST['foto_clinica_esistente'] ?? '') !== '' ? trim($_POST['foto_clinica_esistente']) : null;
    if (isset($_FILES['foto_clinica_file']) && $_FILES['foto_clinica_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileTmpPath = $_FILES['foto_clinica_file']['tmp_name'];
        $fileName = $_FILES['foto_clinica_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName = 'mano_' . $post_paziente_id . '_' . time() . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $foto_clinica_path = $destPath;
            } else {
                $messaggio = "Errore durante il salvataggio del file caricato sul server.";
                $tipo_alert = "danger";
            }
        } else {
            $messaggio = "Formato file non consentito. Usa JPG, PNG, WEBP o PDF.";
            $tipo_alert = "danger";
        }
    }

    if (!empty($post_paziente_id) && empty($messaggio)) {
        $datiChirurgia = [
            'paziente_id' => $post_paziente_id,
            'studio_id' => !empty($post_studio_id) ? $post_studio_id : null,
            'controllo_id' => $post_controllo_id,
            'data_valutazione' => $data_valutazione,
            'lato' => !empty($lato) ? $lato : null,
            'arto_dominante' => !empty($arto_dominante) ? $arto_dominante : null,
            'tipo_lesione' => !empty($tipo_lesione) ? $tipo_lesione : null,
            'anamnesi_mirata' => $anamnesi_mirata,
            'punteggio_dash' => $punteggio_dash,
            'scala_vas' => $scala_vas,
            'scala_moberg' => $scala_moberg,
            'rom_dita_lesionato' => $rom_dita_lesionato,
            'rom_dita_sano' => $rom_dita_sano,
            'forza_presa_lesionato' => $forza_presa_lesionato,
            'forza_presa_sano' => $forza_presa_sano,
            'phalen' => $phalen,
            'tinel' => $tinel,
            'finkelstein' => $finkelstein,
            'staging_dupuytren' => $staging_dupuytren,
            'sintesi_emg' => $sintesi_emg,
            'dettagli_trauma_acuto' => $dettagli_trauma_acuto,
            'trattamento_proposto' => $trattamento_proposto,
            'protocollo_riabilitativo' => $protocollo_riabilitativo,
            'data_rimozione_tutore' => $data_rimozione_tutore,
            'diario_operatorio' => $diario_operatorio,
            'foto_clinica_path' => $foto_clinica_path,
            'note_fotografiche' => $note_fotografiche
        ];

        // Verifica se esiste già un record legato a questo controllo_id (o paziente se controllo_id è vuoto)
        $esistente = null;
        if (!empty($post_controllo_id)) {
            $checkRes = supabase_request('chirurgia_mano?controllo_id=eq.' . $post_controllo_id . '&select=id');
            if (is_array($checkRes) && count($checkRes) > 0 && !isset($checkRes['error'])) {
                $esistente = $checkRes[0]['id'];
            }
        }

        if ($esistente) {
            $resIns = supabase_request('chirurgia_mano?id=eq.' . $esistente, 'PATCH', $datiChirurgia);
        } else {
            $resIns = supabase_request('chirurgia_mano', 'POST', $datiChirurgia);
        }

        if (!is_array($resIns) || isset($resIns['error'])) {
            $err_msg = $resIns['error']['message'] ?? ($resIns['error'] ?? 'Errore sconosciuto');
            $messaggio = "Errore durante il salvataggio della scheda di chirurgia della mano: " . $err_msg;
            $tipo_alert = "danger";
        } else {
            $messaggio = "Scheda di Chirurgia della Mano salvata con successo!";
            $tipo_alert = "success";
        }
    } elseif (empty($post_paziente_id)) {
        $messaggio = "ID paziente mancante. Impossibile salvare la scheda.";
        $tipo_alert = "danger";
    }
}

// Caricamento dati per visualizzazione
$listaPazienti = [];
$resPaz = supabase_request('pazienti?select=id,nome,cognome&order=cognome.asc');
if (is_array($resPaz) && !isset($resPaz['error'])) {
    $listaPazienti = $resPaz;
}

$nome_paziente_selezionato = '';
foreach ($listaPazienti as $p) {
    if ($p['id'] === $paziente_id) {
        $nome_paziente_selezionato = $p['cognome'] . ' ' . $p['nome'];
        break;
    }
}

$listaStudi = [];
if ($is_admin) {
    $resStudi = supabase_request('studi?select=id,nome_studio&order=nome_studio.asc');
    if (is_array($resStudi) && !isset($resStudi['error'])) {
        $listaStudi = $resStudi;
    }
}

// Caricamento della scheda specifica legata al controllo_id o all'ultima del paziente
$schedaCorrente = [];
if (!empty($controllo_id)) {
    $resScheda = supabase_request('chirurgia_mano?controllo_id=eq.' . $controllo_id . '&limit=1');
    if (is_array($resScheda) && count($resScheda) > 0 && !isset($resScheda['error'])) {
        $schedaCorrente = $resScheda[0];
    }
} elseif (!empty($paziente_id)) {
    $resScheda = supabase_request('chirurgia_mano?paziente_id=eq.' . $paziente_id . '&order=created_at.desc&limit=1');
    if (is_array($resScheda) && count($resScheda) > 0 && !isset($resScheda['error'])) {
        $schedaCorrente = $resScheda[0];
    }
}

$storicoValutazioni = [];
if (!empty($paziente_id)) {
    $resStorico = supabase_request('chirurgia_mano?paziente_id=eq.' . $paziente_id . '&order=created_at.desc');
    if (is_array($resStorico) && !isset($resStorico['error'])) {
        $storicoValutazioni = $resStorico;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chirurgia della Mano - Proman 2.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --accent-color: #3498db;
            --border-color: #cbd5e1;
            --bg-soft: #f8fafc;
        }
        body {
            background-color: #f1f5f9;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #334155;
        }
        .card-custom {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            background: #fff;
            margin-bottom: 0.5rem;
        }
        .card-header-custom {
            background-color: var(--bg-soft);
            color: var(--primary-color);
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            padding: 0.35rem 0.6rem;
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
        }
        .form-label {
            font-size: 0.73rem;
            font-weight: 600;
            margin-bottom: 0.1rem;
            color: #475569;
        }
        .form-control, .form-select {
            font-size: 0.78rem;
            padding: 0.2rem 0.4rem;
            height: calc(1.4em + 0.4rem + 2px);
        }
        textarea.form-control {
            height: auto;
            min-height: 40px;
            font-size: 0.78rem;
        }
        
        /* Ottimizzazione Stampa A4 Unica Pagina compatta */
        @media print {
            @page {
                size: A4 portrait;
                margin: 5mm;
            }
            body {
                background: #fff !important;
                color: #000 !important;
                font-size: 8pt !important;
                line-height: 1.15;
            }
            .no-print, .navbar, .btn, form button, .alert, .storico-section {
                display: none !important;
            }
            .container {
                max-width: 100% !important;
                width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .card-custom {
                border: 1px solid #94a3b8 !important;
                box-shadow: none !important;
                margin-bottom: 0.3rem !important;
                page-break-inside: avoid;
            }
            .card-header-custom {
                background-color: #e2e8f0 !important;
                color: #0f172a !important;
                border-bottom: 1px solid #94a3b8 !important;
                padding: 2px 5px !important;
                font-size: 7.5pt !important;
            }
            .card-body {
                padding: 4px 6px !important;
            }
            input, select, textarea {
                border: none !important;
                border-bottom: 1px dotted #64748b !important;
                background: transparent !important;
                padding: 1px 2px !important;
                font-size: 8pt !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            select {
                -webkit-appearance: none;
                -moz-appearance: none;
                appearance: none;
                background: transparent !important;
            }
            .print-footer {
                display: block !important;
                position: fixed;
                bottom: 3mm;
                right: 5mm;
                font-size: 8.5pt;
                font-weight: bold;
                text-align: right;
                color: #0f172a;
            }
        }
        .print-footer {
            display: none;
        }
    </style>
</head>
<body>

    <!-- Navbar con menu responsive per mobile -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-2 no-print py-1">
        <div class="container-fluid">
            <a class="navbar-brand fs-6 fw-bold" href="dashboard.php"><i class="bi bi-hand-index-thumb me-1"></i> Proman 2.0</a>
            <button class="navbar-toggler py-1 px-2 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="width: 1.2em; height: 1.2em;"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto small">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner</a></li>
                    <?php if (!empty($controllo_id)): ?>
                        <li class="nav-item"><a class="nav-link text-warning fw-bold" href="visita.php?id=<?php echo htmlspecialchars($controllo_id); ?>"><i class="bi bi-arrow-left-circle me-1"></i> Torna a Visita</a></li>
                    <?php elseif (!empty($paziente_id)): ?>
                        <li class="nav-item"><a class="nav-link text-warning fw-bold" href="visita.php?id=<?php echo htmlspecialchars($paziente_id); ?>"><i class="bi bi-arrow-left-circle me-1"></i> Torna a Visita</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mb-3">
        <!-- Intestazione Pagina -->
        <div class="row mb-2 align-items-center no-print">
            <div class="col-sm-8">
                <h4 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-file-medical me-2 text-primary"></i> Chirurgia della Mano &mdash; Scheda Clinica Specialistica</h4>
            </div>
            <div class="col-sm-4 text-sm-end mt-1 mt-sm-0">
                <button type="button" class="btn btn-outline-primary btn-sm px-3 shadow-sm w-100 w-sm-auto" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i> Stampa Pagina A4
                </button>
            </div>
        </div>

        <?php if (!empty($messaggio)): ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> alert-dismissible fade show py-2 small shadow-sm no-print" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="chirurgia_della_mano.php?paziente_id=<?php echo htmlspecialchars($paziente_id); ?><?php echo !empty($controllo_id) ? '&controllo_id=' . htmlspecialchars($controllo_id) : ''; ?>" enctype="multipart/form-data">
            
            <div class="row g-2">
                
                <!-- Colonna Sinistra -->
                <div class="col-lg-4">
                    
                    <!-- Dati Paziente & Anagrafica -->
                    <div class="card card-custom mb-2">
                        <div class="card-header-custom"><i class="bi bi-person me-1"></i> Anagrafica &amp; Lato</div>
                        <div class="card-body">
                            <div class="mb-2">
                                <label class="form-label">Paziente</label>
                                <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($nome_paziente_selezionato ?: 'Nessun paziente selezionato'); ?>" disabled>
                                <input type="hidden" name="paziente_id" value="<?php echo htmlspecialchars($paziente_id); ?>">
                            </div>

                            <input type="hidden" name="studio_id" value="<?php echo htmlspecialchars($studio_id); ?>">
                            
                            <?php if ($is_admin): ?>
                                <div class="mb-2 no-print">
                                    <label class="form-label">Studio (Admin)</label>
                                    <select class="form-select" onchange="document.querySelector('input[name=\'studio_id\']').value=this.value;">
                                        <?php foreach ($listaStudi as $st): ?>
                                            <option value="<?php echo htmlspecialchars($st['id']); ?>" <?php echo ($studio_id === $st['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($st['nome_studio']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <input type="hidden" name="controllo_id" value="<?php echo htmlspecialchars($controllo_id ?? ''); ?>">

                            <div class="row g-1 mb-2">
                                <div class="col-6">
                                    <label for="lato" class="form-label">Lato</label>
                                    <select class="form-select" id="lato" name="lato">
                                        <option value="Destra" <?php echo (($schedaCorrente['lato'] ?? '') === 'Destra') ? 'selected' : ''; ?>>Destra</option>
                                        <option value="Sinistra" <?php echo (($schedaCorrente['lato'] ?? '') === 'Sinistra') ? 'selected' : ''; ?>>Sinistra</option>
                                        <option value="Bilaterale" <?php echo (($schedaCorrente['lato'] ?? '') === 'Bilaterale') ? 'selected' : ''; ?>>Bilaterale</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label for="arto_dominante" class="form-label">Dominanza</label>
                                    <select class="form-select" id="arto_dominante" name="arto_dominante">
                                        <option value="Destra" <?php echo (($schedaCorrente['arto_dominante'] ?? '') === 'Destra') ? 'selected' : ''; ?>>Destra</option>
                                        <option value="Sinistra" <?php echo (($schedaCorrente['arto_dominante'] ?? '') === 'Sinistra') ? 'selected' : ''; ?>>Sinistra</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label for="tipo_lesione" class="form-label">Tipo Lesione / Patologia</label>
                                <select class="form-select" id="tipo_lesione" name="tipo_lesione">
                                    <option value="Traumatica" <?php echo (($schedaCorrente['tipo_lesione'] ?? '') === 'Traumatica') ? 'selected' : ''; ?>>Traumatica / Acuta</option>
                                    <option value="Degenerativa" <?php echo (($schedaCorrente['tipo_lesione'] ?? '') === 'Degenerativa') ? 'selected' : ''; ?>>Degenerativa / Cronica</option>
                                    <option value="Infiammatoria" <?php echo (($schedaCorrente['tipo_lesione'] ?? '') === 'Infiammatoria') ? 'selected' : ''; ?>>Infiammatoria</option>
                                    <option value="Compressiva" <?php echo (($schedaCorrente['tipo_lesione'] ?? '') === 'Compressiva') ? 'selected' : ''; ?>>Compressiva Nervosa</option>
                                </select>
                            </div>

                            <div class="mb-1">
                                <label for="anamnesi_mirata" class="form-label">Anamnesi Mirata</label>
                                <textarea class="form-control" id="anamnesi_mirata" name="anamnesi_mirata" rows="2"><?php echo htmlspecialchars($schedaCorrente['anamnesi_mirata'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Staging e Specifiche -->
                    <div class="card card-custom">
                        <div class="card-header-custom"><i class="bi bi-diagram-3 me-1"></i> Staging &amp; Strumentali</div>
                        <div class="card-body">
                            <div class="row g-1 mb-1">
                                <div class="col-6">
                                    <label for="staging_dupuytren" class="form-label">Dupuytren</label>
                                    <input type="text" class="form-control" id="staging_dupuytren" name="staging_dupuytren" value="<?php echo htmlspecialchars($schedaCorrente['staging_dupuytren'] ?? ''); ?>">
                                </div>
                                <div class="col-6">
                                    <label for="sintesi_emg" class="form-label">Sintesi EMG</label>
                                    <input type="text" class="form-control" id="sintesi_emg" name="sintesi_emg" value="<?php echo htmlspecialchars($schedaCorrente['sintesi_emg'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="mb-1">
                                <label for="dettagli_trauma_acuto" class="form-label">Trauma Acuto / Urgenze</label>
                                <input type="text" class="form-control" id="dettagli_trauma_acuto" name="dettagli_trauma_acuto" value="<?php echo htmlspecialchars($schedaCorrente['dettagli_trauma_acuto'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Colonna Destra -->
                <div class="col-lg-8">
                    
                    <!-- Scale di Valutazione -->
                    <div class="card card-custom mb-2">
                        <div class="card-header-custom"><i class="bi bi-speedometer2 me-1"></i> Scale di Valutazione &amp; Test Funzionali</div>
                        <div class="card-body">
                            <div class="row g-2 align-items-center mb-2">
                                <div class="col-md-4">
                                    <?php $vas_val = intval($schedaCorrente['scala_vas'] ?? 0); ?>
                                    <label for="scala_vas" class="form-label text-danger mb-0">VAS Dolore (0-10): <span id="valVas" class="fw-bold"><?php echo $vas_val; ?></span></label>
                                    <input type="range" class="form-range no-print" min="0" max="10" step="1" id="scala_vas" name="scala_vas" value="<?php echo $vas_val; ?>" oninput="document.getElementById('valVas').innerText=this.value">
                                </div>
                                <div class="col-md-4">
                                    <label for="scala_moberg" class="form-label">Test di Moberg</label>
                                    <input type="text" class="form-control" id="scala_moberg" name="scala_moberg" value="<?php echo htmlspecialchars($schedaCorrente['scala_moberg'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="dash_score_manual" class="form-label">Punteggio DASH (0-100)</label>
                                    <input type="number" step="0.1" min="0" max="100" class="form-control" id="dash_score_manual" name="dash_score_manual" value="<?php echo htmlspecialchars($schedaCorrente['punteggio_dash'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row g-2 align-items-center bg-light p-1 rounded">
                                <div class="col-md-6">
                                    <label class="form-label">Forza di Presa (Dinamometro - Kg)</label>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <input type="number" step="0.1" class="form-control" id="forza_presa_lesionato" name="forza_presa_lesionato" value="<?php echo htmlspecialchars($schedaCorrente['forza_presa_lesionato'] ?? ''); ?>" placeholder="Leso" oninput="calcolaDeficitForza()">
                                        </div>
                                        <div class="col-6">
                                            <input type="number" step="0.1" class="form-control" id="forza_presa_sano" name="forza_presa_sano" value="<?php echo htmlspecialchars($schedaCorrente['forza_presa_sano'] ?? ''); ?>" placeholder="Sano" oninput="calcolaDeficitForza()">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <span class="small fw-bold text-success" id="boxDeficitRisultato">Deficit calcolato: -- %</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Esame Obiettivo e Test Specifici -->
                    <div class="card card-custom mb-2">
                        <div class="card-header-custom"><i class="bi bi-clipboard2-pulse me-1"></i> Esame Obiettivo &amp; Test Clinici</div>
                        <div class="card-body">
                            <div class="row g-1 mb-2">
                                <div class="col-6">
                                    <label for="rom_dita_lesionato" class="form-label">ROM (Arto Lesionato)</label>
                                    <input type="text" class="form-control" id="rom_dita_lesionato" name="rom_dita_lesionato" value="<?php echo htmlspecialchars($schedaCorrente['rom_dita_lesionato'] ?? ''); ?>">
                                </div>
                                <div class="col-6">
                                    <label for="rom_dita_sano" class="form-label">ROM (Arto Sano)</label>
                                    <input type="text" class="form-control" id="rom_dita_sano" name="rom_dita_sano" value="<?php echo htmlspecialchars($schedaCorrente['rom_dita_sano'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row g-1">
                                <div class="col-4">
                                    <label for="phalen" class="form-label">Test di Phalen</label>
                                    <select class="form-select" id="phalen" name="phalen">
                                        <option value="">Non eseguito</option>
                                        <option value="Positivo" <?php echo (($schedaCorrente['phalen'] ?? '') === 'Positivo') ? 'selected' : ''; ?>>Positivo</option>
                                        <option value="Negativo" <?php echo (($schedaCorrente['phalen'] ?? '') === 'Negativo') ? 'selected' : ''; ?>>Negativo</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label for="tinel" class="form-label">Segno di Tinel</label>
                                    <select class="form-select" id="tinel" name="tinel">
                                        <option value="">Non eseguito</option>
                                        <option value="Positivo" <?php echo (($schedaCorrente['tinel'] ?? '') === 'Positivo') ? 'selected' : ''; ?>>Positivo</option>
                                        <option value="Negativo" <?php echo (($schedaCorrente['tinel'] ?? '') === 'Negativo') ? 'selected' : ''; ?>>Negativo</option>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <label for="finkelstein" class="form-label">Finkelstein</label>
                                    <select class="form-select" id="finkelstein" name="finkelstein">
                                        <option value="">Non eseguito</option>
                                        <option value="Positivo" <?php echo (($schedaCorrente['finkelstein'] ?? '') === 'Positivo') ? 'selected' : ''; ?>>Positivo</option>
                                        <option value="Negativo" <?php echo (($schedaCorrente['finkelstein'] ?? '') === 'Negativo') ? 'selected' : ''; ?>>Negativo</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trattamento e Riabilitazione -->
                    <div class="card card-custom mb-2">
                        <div class="card-header-custom"><i class="bi bi-shield-check me-1"></i> Trattamento &amp; Note Cliniche</div>
                        <div class="card-body">
                            <div class="row g-1 mb-2">
                                <div class="col-6">
                                    <label for="trattamento_proposto" class="form-label">Trattamento</label>
                                    <select class="form-select" id="trattamento_proposto" name="trattamento_proposto">
                                        <option value="Conservativo" <?php echo (($schedaCorrente['trattamento_proposto'] ?? '') === 'Conservativo') ? 'selected' : ''; ?>>Conservativo</option>
                                        <option value="Infiltrazione" <?php echo (($schedaCorrente['trattamento_proposto'] ?? '') === 'Infiltrazione') ? 'selected' : ''; ?>>Infiltrazione</option>
                                        <option value="Chirurgico" <?php echo (($schedaCorrente['trattamento_proposto'] ?? '') === 'Chirurgico') ? 'selected' : ''; ?>>Chirurgico</option>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label for="data_rimozione_tutore" class="form-label">Rimozione Tutore</label>
                                    <input type="date" class="form-control" id="data_rimozione_tutore" name="data_rimozione_tutore" value="<?php echo htmlspecialchars($schedaCorrente['data_rimozione_tutore'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row g-1 mb-1">
                                <div class="col-md-6">
                                    <label for="protocollo_riabilitativo" class="form-label">Protocollo Riabilitativo</label>
                                    <textarea class="form-control" id="protocollo_riabilitativo" name="protocollo_riabilitativo" rows="1"><?php echo htmlspecialchars($schedaCorrente['protocollo_riabilitativo'] ?? ''); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label for="diario_operatorio" class="form-label">Note / Diario Operatorie</label>
                                    <textarea class="form-control" id="diario_operatorio" name="diario_operatorio" rows="1"><?php echo htmlspecialchars($schedaCorrente['diario_operatorio'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Bottone Salva -->
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end no-print">
                        <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold shadow-sm">
                            <i class="bi bi-save me-1"></i> Salva Scheda
                        </button>
                    </div>

                </div>

            </div>
        </form>

        <!-- Firma del medico in fondo per la stampa A4 -->
        <div class="print-footer">
            Il Medico Specialista<br>
            <span style="font-weight: normal; font-size: 8pt;"><?php echo htmlspecialchars($nome_medico); ?></span>
        </div>

        <?php if (!empty($storicoValutazioni)): ?>
            <div class="row mt-2 no-print storico-section">
                <div class="col-12">
                    <div class="card card-custom">
                        <div class="card-header-custom"><i class="bi bi-clock-history me-1"></i> Storico Valutazioni</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0 align-middle small">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Data</th>
                                            <th>Lato</th>
                                            <th>Lesione</th>
                                            <th>VAS</th>
                                            <th>DASH</th>
                                            <th>Trattamento</th>
                                            <th class="text-end">Azione</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($storicoValutazioni as $item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($item['created_at'] ?? 'now'))); ?></td>
                                                <td><?php echo htmlspecialchars($item['lato'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['tipo_lesione'] ?? '-'); ?></td>
                                                <td><span class="badge bg-danger"><?php echo htmlspecialchars($item['scala_vas'] ?? '0'); ?></span></td>
                                                <td><?php echo htmlspecialchars($item['punteggio_dash'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($item['trattamento_proposto'] ?? '-'); ?></td>
                                                <td class="text-end">
                                                    <a href="chirurgia_della_mano.php?paziente_id=<?php echo htmlspecialchars($item['paziente_id']); ?>&controllo_id=<?php echo htmlspecialchars($item['controllo_id'] ?? ''); ?>" class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size: 0.75rem;">
                                                        Carica
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calcolaDeficitForza() {
            const les = parseFloat(document.getElementById('forza_presa_lesionato').value);
            const sano = parseFloat(document.getElementById('forza_presa_sano').value);
            const box = document.getElementById('boxDeficitRisultato');

            if (!isNaN(les) && !isNaN(sano) && sano > 0) {
                let deficit = ((sano - les) / sano) * 100;
                if (deficit < 0) deficit = 0;
                box.innerText = "Deficit calcolato: " + deficit.toFixed(1) + " %";
            } else {
                box.innerText = "Deficit calcolato: -- %";
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            calcolaDeficitForza();
        });
    </script>
</body>
</html>