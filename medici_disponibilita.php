<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$messaggio = "";
$errore = "";

// Recuperiamo i dati dell'utente loggato
$utente_loggato = $_SESSION['utente'];
$medico_id_sessione = $utente_loggato['id'] ?? null;
$id_studio_corrente = $utente_loggato['studio_id'] ?? ($_SESSION['studio_id'] ?? null);

// Verifichiamo il ruolo dell'utente
$ruolo_utente = strtolower(trim($utente_loggato['ruolo'] ?? ''));

// CORRETTO: L'admin o amministratore è SEMPRE super admin globale (può scegliere gli studi liberamente)
// I segretari, coordinatori o altri ruoli restano vincolati al proprio studio.
$is_super_admin = ($ruolo_utente === 'admin' || $ruolo_utente === 'amministratore');
$is_segreteria = in_array($ruolo_utente, ['segreteria', 'segretario', 'coordinatore']);
$is_medico = !$is_super_admin && !$is_segreteria;

if (!$medico_id_sessione) {
    $errore = "Errore: ID utente non trovato nella sessione.";
}

// 1. GESTIONE DELLO STUDIO SELEZIONATO
// Se è super admin, può sceglierlo tramite GET/POST/Session. Se è segreteria, è bloccato fisso sul proprio studio_id.
$studio_selezionato_id = $is_super_admin ? null : $id_studio_corrente;

if ($is_super_admin) {
    if (isset($_POST['studio_selezionato_id'])) {
        $studio_selezionato_id = $_POST['studio_selezionato_id'];
        $_SESSION['admin_studio_selezionato'] = $studio_selezionato_id;
    } elseif (isset($_GET['studio_selezionato_id'])) {
        $studio_selezionato_id = $_GET['studio_selezionato_id'];
        $_SESSION['admin_studio_selezionato'] = $studio_selezionato_id;
    } elseif (isset($_SESSION['admin_studio_selezionato'])) {
        $studio_selezionato_id = $_SESSION['admin_studio_selezionato'];
    }
}

// Recuperiamo la lista di tutti gli studi (indispensabile per il super admin)
$studi_list = [];
if (function_exists('supabase_request')) {
    $studi_list = @supabase_request('studi?select=*', 'GET');
}

if ($is_super_admin && empty($studio_selezionato_id) && !empty($studi_list) && is_array($studi_list)) {
    $studio_selezionato_id = $studi_list[0]['id'] ?? null;
    $_SESSION['admin_studio_selezionato'] = $studio_selezionato_id;
}

// 2. GESTIONE DEI MEDICI VISIBILI
$medici_list = [];
if (function_exists('supabase_request')) {
    if ($is_super_admin && !empty($studio_selezionato_id)) {
        // Il super admin vede i medici dello studio scelto nel menu
        $url_medici = "medici?studio_id=eq." . urlencode($studio_selezionato_id) . "&select=id,nome,cognome,titolo,ruolo,studio_id";
        $medici_list = @supabase_request($url_medici, 'GET');
    } elseif ($is_segreteria && !empty($id_studio_corrente)) {
        // Il segretario vede SOLO i medici del proprio studio associato
        $url_medici = "medici?studio_id=eq." . urlencode($id_studio_corrente) . "&select=id,nome,cognome,titolo,ruolo,studio_id";
        $medici_list = @supabase_request($url_medici, 'GET');
    }
}

// 3. DETERMINAZIONE DEL MEDICO TARGET DA GESTIRE
$medico_selezionato_id = $medico_id_sessione; // Default per i medici
if ($is_super_admin || $is_segreteria) {
    if (isset($_POST['medico_id'])) {
        $medico_selezionato_id = $_POST['medico_id'];
    } elseif (isset($_GET['medico_id'])) {
        $medico_selezionato_id = $_GET['medico_id'];
    } else {
        // Se non specificato, prendiamo il primo medico della lista disponibile
        if (!empty($medici_list) && is_array($medici_list)) {
            $medico_selezionato_id = $medici_list[0]['id'] ?? $medico_id_sessione;
        }
    }
}

// Gestione dell'invio del form per aggiungere una nuova disponibilità
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiungi_disponibilita'])) {
    $target_studio_id = $is_super_admin ? $studio_selezionato_id : $id_studio_corrente;
    $target_medico_id = ($is_super_admin || $is_segreteria) && !empty($_POST['medico_id']) ? $_POST['medico_id'] : $medico_id_sessione;
    $giorno_settimana = $_POST['giorno_settimana'] ?? '';
    $ora_inizio = $_POST['ora_inizio'] ?? '';
    $ora_fine = $_POST['ora_fine'] ?? '';
    $durata_slot_minuti = $_POST['durata_slot_minuti'] ?? 30;

    if (empty($target_studio_id) || empty($target_medico_id) || $giorno_settimana === '' || empty($ora_inizio) || empty($ora_fine)) {
        $errore = "Tutti i campi obbligatori devono essere compilati.";
    } else {
        $payload = [
            'medico_id' => $target_medico_id,
            'studio_id' => $target_studio_id,
            'giorno_settimana' => (int)$giorno_settimana,
            'ora_inizio' => $ora_inizio,
            'ora_fine' => $ora_fine,
            'durata_slot_minuti' => (int)$durata_slot_minuti,
            'attivo' => true
        ];

        if (function_exists('supabase_request')) {
            $risultato = @supabase_request('medici_disponibilita', 'POST', $payload);
            if ($risultato !== false && !isset($risultato['error'])) {
                $messaggio = "Disponibilità aggiunta con successo!";
            } else {
                $errore = "Errore durante il salvataggio su Supabase.";
            }
        } else {
            $errore = "Funzione supabase_request non disponibile in config.php.";
        }
    }
}

// Gestione disattivazione disponibilità
if (isset($_GET['disattiva']) && function_exists('supabase_request')) {
    $id_disponibilita = trim($_GET['disattiva']);
    $payload = ['attivo' => false];
    @supabase_request('medici_disponibilita?id=eq.' . urlencode($id_disponibilita), 'PATCH', $payload);
    
    $redirect_url = "medici_disponibilita.php?medico_id=" . urlencode($medico_selezionato_id);
    if ($is_super_admin && !empty($studio_selezionato_id)) {
        $redirect_url .= "&studio_selezionato_id=" . urlencode($studio_selezionato_id);
    }
    header("Location: " . $redirect_url);
    exit;
}

// Recupero disponibilità del medico selezionato
$disponibilita_list = [];
if (function_exists('supabase_request') && !empty($medico_selezionato_id)) {
    $url_disp = "medici_disponibilita?medico_id=eq." . urlencode($medico_selezionato_id) . "&attivo=eq.true&select=*";
    $disponibilita_list = @supabase_request($url_disp, 'GET');
}

// Troviamo il nome leggibile dello studio corrente
$nome_studio_corrente = "Studio Principale";
$studio_attivo_id = $is_super_admin ? $studio_selezionato_id : $id_studio_corrente;

if (!empty($studi_list) && is_array($studi_list)) {
    foreach ($studi_list as $s) {
        if ($s['id'] == $studio_attivo_id) {
            $nome_studio_corrente = $s['nome_studio'] ?? ('Studio ' . $s['id']);
            break;
        }
    }
}

$giorni_settimana_nomi = [
    0 => 'Domenica',
    1 => 'Lunedì',
    2 => 'Martedì',
    3 => 'Mercoledì',
    4 => 'Giovedì',
    5 => 'Venerdì',
    6 => 'Sabato'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proman 2.0 - Gestione Disponibilità Orarie</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --card-bg: #ffffff; 
            --body-bg: #f8fafc; 
            --primary-color: #0f172a;
        }
        body { 
            background-color: var(--body-bg); 
            font-family: 'Inter', sans-serif; 
            color: #1e293b; 
        }
        .app-header { 
            background: linear-gradient(135deg, #0f172a 100%, #1e293b 0%); 
            color: white; 
            padding: 1.5rem 1rem 2rem 1rem; 
            border-bottom-left-radius: 28px; 
            border-bottom-right-radius: 28px; 
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2); 
            margin-bottom: 1.5rem; 
        }
        .card { border: none; border-radius: 20px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03); }
    </style>
</head>
<body>

    <div class="app-header">
        <div class="container d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <div class="bg-white bg-opacity-10 text-info p-3 rounded-4 me-3 fs-4 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div>
                    <span class="d-block text-white-50 small text-uppercase fw-bold" style="font-size: 0.7rem;">Configurazione Orari</span>
                    <h5 class="mb-0 fw-bold text-white">Disponibilità Visite</h5>
                </div>
            </div>
            <div>
                <a href="dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 border-0 bg-white bg-opacity-10 shadow-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="container my-4">
        <div class="row">
            <div class="col-12">
                <?php if (!empty($messaggio)): ?>
                    <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4" role="alert"><i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($messaggio); ?></div>
                <?php endif; ?>
                
                <?php if (!empty($errore)): ?>
                    <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4" role="alert"><i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($errore); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_super_admin): ?>
            <!-- FILTRI SUPER ADMIN: Studio + Medico (Libero) -->
            <div class="card p-4 bg-white mb-4">
                <form method="GET" action="medici_disponibilita.php" class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="studio_selezionato_id" class="form-label fw-bold small text-muted"><i class="fa-solid fa-building text-primary me-1"></i> Seleziona Studio:</label>
                        <select name="studio_selezionato_id" id="studio_selezionato_id" class="form-select rounded-pill" onchange="this.form.submit()">
                            <?php foreach ($studi_list as $st): ?>
                                <option value="<?php echo htmlspecialchars($st['id']); ?>" <?php if ($st['id'] == $studio_selezionato_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($st['nome_studio'] ?? ('Studio ' . $st['id'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label for="medico_id" class="form-label fw-bold small text-muted"><i class="fa-solid fa-user-doctor text-primary me-1"></i> Seleziona Medico (dello Studio Selezionato):</label>
                        <select name="medico_id" id="medico_id" class="form-select rounded-pill" onchange="this.form.submit()">
                            <?php if (!empty($medici_list) && is_array($medici_list)): ?>
                                <?php foreach ($medici_list as $med): ?>
                                    <option value="<?php echo htmlspecialchars($med['id']); ?>" <?php if ($med['id'] == $medico_selezionato_id) echo 'selected'; ?>>
                                        <?php echo htmlspecialchars(trim(($med['titolo'] ?? 'Dr.') . ' ' . ($med['cognome'] ?? '') . ' ' . ($med['nome'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Nessun medico in questo studio</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </form>
            </div>
        <?php elseif ($is_segreteria && !empty($medici_list) && is_array($medici_list)): ?>
            <!-- FILTRO SEGRETERIA: Solo i medici del PROPRIO studio (senza scelta studio) -->
            <div class="card p-3 bg-white mb-4">
                <form method="GET" action="medici_disponibilita.php" class="row align-items-center g-3">
                    <div class="col-md-auto">
                        <label for="medico_id" class="form-label fw-bold small text-muted mb-0"><i class="fa-solid fa-user-doctor text-primary me-1"></i> Seleziona Medico (del tuo studio):</label>
                    </div>
                    <div class="col-md-6">
                        <select name="medico_id" id="medico_id" class="form-select rounded-pill" onchange="this.form.submit()">
                            <?php foreach ($medici_list as $med): ?>
                                <option value="<?php echo htmlspecialchars($med['id']); ?>" <?php if ($med['id'] == $medico_selezionato_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars(trim(($med['titolo'] ?? 'Dr.') . ' ' . ($med['cognome'] ?? '') . ' ' . ($med['nome'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Form di Inserimento -->
            <div class="col-md-5">
                <div class="card p-4 bg-white">
                    <h4 class="mb-3 fs-5 fw-bold text-dark"><i class="fa-solid fa-plus-circle text-primary me-2"></i>Nuova Fascia Oraria</h4>
                    <form method="POST" action="medici_disponibilita.php<?php echo ($is_super_admin || $is_segreteria) ? '?medico_id=' . urlencode($medico_selezionato_id) . ($is_super_admin ? '&studio_selezionato_id=' . urlencode($studio_selezionato_id) : '') : ''; ?>">
                        
                        <?php if ($is_super_admin || $is_segreteria): ?>
                            <input type="hidden" name="medico_id" value="<?php echo htmlspecialchars($medico_selezionato_id); ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label for="studio_nome_display" class="form-label small fw-bold text-muted">Studio / Ambito</label>
                            <input type="text" class="form-control rounded-3 bg-light text-dark fw-semibold" id="studio_nome_display" value="<?php echo htmlspecialchars($nome_studio_corrente); ?>" disabled>
                        </div>

                        <div class="mb-3">
                            <label for="giorno_settimana" class="form-label small fw-bold text-muted">Giorno della Settimana</label>
                            <select class="form-select rounded-3" id="giorno_settimana" name="giorno_settimana" required>
                                <option value="">Seleziona giorno...</option>
                                <?php foreach ($giorni_settimana_nomi as $num => $nome): ?>
                                    <option value="<?php echo $num; ?>"><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label for="ora_inizio" class="form-label small fw-bold text-muted">Ora Inizio</label>
                                <input type="time" class="form-control rounded-3" id="ora_inizio" name="ora_inizio" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label for="ora_fine" class="form-label small fw-bold text-muted">Ora Fine</label>
                                <input type="time" class="form-control rounded-3" id="ora_fine" name="ora_fine" required>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="durata_slot_minuti" class="form-label small fw-bold text-muted">Durata Visita</label>
                            <select class="form-select rounded-3" id="durata_slot_minuti" name="durata_slot_minuti">
                                <option value="15">15 minuti</option>
                                <option value="20">20 minuti</option>
                                <option value="30" selected>30 minuti</option>
                                <option value="45">45 minuti</option>
                                <option value="60">60 minuti</option>
                            </select>
                        </div>

                        <button type="submit" name="aggiungi_disponibilita" class="btn btn-primary w-100 rounded-pill fw-bold py-2 shadow-sm">Salva Disponibilità</button>
                    </form>
                </div>
            </div>

            <!-- Tabella delle Disponibilità Attive -->
            <div class="col-md-7">
                <div class="card p-4 bg-white">
                    <h4 class="mb-3 fs-5 fw-bold text-dark"><i class="fa-solid fa-list-check text-success me-2"></i>Fasce Orarie Attive</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="rounded-start">Giorno</th>
                                    <th>Orario</th>
                                    <th>Slot</th>
                                    <th class="text-end rounded-end">Azione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($disponibilita_list) && is_array($disponibilita_list)): ?>
                                    <?php foreach ($disponibilita_list as $disp): ?>
                                        <tr>
                                            <td><strong><?php echo $giorni_settimana_nomi[$disp['giorno_settimana']] ?? 'N/D'; ?></strong></td>
                                            <td><?php echo substr($disp['ora_inizio'], 0, 5); ?> - <?php echo substr($disp['ora_fine'], 0, 5); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo $disp['durata_slot_minuti']; ?> min</span></td>
                                            <td class="text-end">
                                                <?php 
                                                    $del_url = "medici_disponibilita.php?disattiva=" . $disp['id'] . "&medico_id=" . urlencode($medico_selezionato_id);
                                                    if ($is_super_admin) {
                                                        $del_url .= "&studio_selezionato_id=" . urlencode($studio_selezionato_id);
                                                    }
                                                ?>
                                                <a href="<?php echo $del_url; ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="return confirm('Confermi la rimozione di questa disponibilità?');">Elimina</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Nessuna disponibilità oraria impostata per questo medico.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>