<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

// RECUPERO STUDIO_ID (Logica multi-tenant)
$studio_id_sessione = null;
if (isset($_SESSION['studio_id'])) {
    $val = $_SESSION['studio_id'];
    $studio_id_sessione = is_array($val) ? ($val['id'] ?? ($val['studio_id'] ?? null)) : $val;
}

if (empty($studio_id_sessione)) {
    $utente_info = $_SESSION['utente'];
    $email_cerca = is_array($utente_info) ? ($utente_info['email'] ?? '') : $utente_info;
    if (!empty($email_cerca)) {
        $medico_data = supabase_request('medici?email=eq.' . urlencode($email_cerca) . '&select=studio_id', 'GET');
        if (!empty($medico_data) && !isset($medico_data['error'])) {
            $val_studio = $medico_data[0]['studio_id'] ?? null;
            $studio_id_sessione = is_array($val_studio) ? ($val_studio['id'] ?? null) : $val_studio;
            $_SESSION['studio_id'] = $studio_id_sessione;
        }
    }
}

$tabella_db = 'controlli'; 
$messaggio = '';
$errore = '';

// Gestione inserimento di un nuovo controllo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'nuovo_controllo') {
    if (empty($studio_id_sessione)) {
        $errore = "Errore: Studio non identificato.";
    } else {
        $paziente_id = trim($_POST['paziente_id'] ?? '');
        $data_controllo = trim($_POST['data_controllo'] ?? '');
        $tipo_controllo = trim($_POST['tipo_controllo'] ?? 'Controllo');
        $note = trim($_POST['note'] ?? '');
        $terapia = trim($_POST['terapia'] ?? '');

        if (empty($paziente_id) || empty($data_controllo)) {
            $errore = "Seleziona un paziente e inserisci la data del controllo.";
        } else {
            // Salviamo il controllo associandolo allo studio corrente e al paziente (anche se censito originariamente altrove)
            $nuovo_controllo = [
                'paziente_id' => $paziente_id,
                'data_controllo' => $data_controllo,
                'tipo_controllo' => $tipo_controllo,
                'note' => $note,
                'terapia' => $terapia,
                'studio_id' => $studio_id_sessione,
                'stato' => 'Programmato'
            ];

            $risultato = supabase_request($tabella_db, 'POST', $nuovo_controllo);

            if (isset($risultato['error'])) {
                $errore = "Errore durante il salvataggio: " . json_encode($risultato['error']);
            } else {
                $messaggio = "Controllo registrato con successo!";
                header("Location: controlli.php?paziente_id=" . urlencode($paziente_id));
                exit;
            }
        }
    }
}

// Acquisiamo la lista globale dei pazienti per permettere di trovare il paziente anche se registrato in un altro studio
$pazienti = supabase_request("pazienti?order=cognome.asc&select=*", 'GET');
if (!is_array($pazienti) || isset($pazienti['error'])) {
    $pazienti = [];
}

// Paziente selezionato tramite GET
$paziente_selezionato_id = trim($_GET['paziente_id'] ?? '');
$paziente_corrente = null;
$visite = [];

if (!empty($paziente_selezionato_id)) {
    // Recupera i dati anagrafici del paziente
    $res_paz = supabase_request("pazienti?id=eq.{$paziente_selezionato_id}&select=*", 'GET');
    if (is_array($res_paz) && count($res_paz) > 0 && !isset($res_paz['error'])) {
        $paziente_corrente = $res_paz[0];
    }

    // SICUREZZA / PRIVACY: Recupera i controlli associati a questo paziente MA filtrati rigorosamente per lo studio corrente della sessione
    $endpoint = "{$tabella_db}?studio_id=eq." . urlencode((string)$studio_id_sessione) . "&paziente_id=eq.{$paziente_selezionato_id}&order=data_controllo.desc&select=*";
    $visite = supabase_request($endpoint, 'GET');
    
    if (!is_array($visite) || isset($visite['error'])) {
        $visite = [];
    }
}

// Prepariamo il nome visualizzato nel campo di ricerca se c'è un paziente selezionato
$valore_ricerca_iniziale = '';
if ($paziente_corrente) {
    $valore_ricerca_iniziale = $paziente_corrente['cognome'] . ' ' . $paziente_corrente['nome'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Controlli e Visite - Proman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; color: #333333; }
        .app-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 1.25rem 1rem; margin-bottom: 1.5rem; }
        .mobile-nav { background: #ffffff; border-top: 1px solid #eaeaea; position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000; padding: 0.5rem 0; box-shadow: 0 -4px 15px rgba(0,0,0,0.05); }
        .mobile-nav-item { color: #6c757d; text-align: center; text-decoration: none; font-size: 0.75rem; display: flex; flex-direction: column; align-items: center; }
        .mobile-nav-item i { font-size: 1.25rem; margin-bottom: 2px; }
        .mobile-nav-item.active { color: #0d6efd; }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .mobile-nav { display: none !important; } .main-content { padding-bottom: 2rem; } }
    </style>
</head>
<body>

<div class="app-header shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="text-white me-3 fs-5"><i class="fa-solid fa-arrow-left"></i></a>
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-stethoscope me-2"></i>Controlli e Visite</h5>
        </div>
        <div>
            <button class="btn btn-success btn-sm rounded-pill px-3 fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#nuovoControlloModal">
                <i class="fa-solid fa-plus me-1"></i> Nuovo Controllo
            </button>
        </div>
    </div>
</div>

<div class="container main-content">
    <?php if (!empty($messaggio)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i> <?php echo htmlspecialchars($messaggio); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errore)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($errore); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Card di ricerca paziente -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
        <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-magnifying-glass text-primary me-2"></i>Seleziona Paziente</h6>
        <form method="GET" action="controlli.php" class="row g-2 align-items-center" id="formFiltroPaziente">
            <input type="hidden" name="paziente_id" id="paziente_id_hidden" value="<?php echo htmlspecialchars($paziente_selezionato_id); ?>">
            
            <div class="col-12 col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-light text-secondary"><i class="fa-solid fa-search"></i></span>
                    <input type="text" class="form-control bg-light" id="ricercaPazienteInput" 
                           placeholder="Digita il cognome o nome del paziente..." 
                           value="<?php echo htmlspecialchars($valore_ricerca_iniziale); ?>" 
                           list="listaPazientiDatalist" autocomplete="off" required>
                    <datalist id="listaPazientiDatalist">
                        <?php foreach ($pazienti as $p): ?>
                            <?php $nome_completo = $p['cognome'] . ' ' . $p['nome']; ?>
                            <option data-id="<?php echo $p['id']; ?>" value="<?php echo htmlspecialchars($nome_completo); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-primary fw-semibold">Cerca</button>
            </div>
        </form>
        <?php if (!empty($paziente_selezionato_id)): ?>
            <div class="mt-2 text-end">
                <a href="controlli.php" class="text-danger small text-decoration-none"><i class="fa-solid fa-xmark me-1"></i>Cambia paziente</a>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$paziente_corrente): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center text-muted">
            <i class="fa-solid fa-hand-pointer fa-3x mb-3 text-primary opacity-50"></i>
            <h5 class="fw-bold text-dark">Nessun paziente selezionato</h5>
            <p class="mb-0">Usa il campo di ricerca sopra per cercare e selezionare un paziente di cui visualizzare le visite di questo studio.</p>
        </div>
    <?php else: ?>
        <!-- Scheda riepilogo paziente selezionato -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white border-start border-primary border-4">
            <h4 class="fw-bold text-dark mb-2">
                <i class="fa-solid fa-user-injured text-primary me-2"></i>
                <?php echo htmlspecialchars($paziente_corrente['cognome'] . ' ' . $paziente_corrente['nome']); ?>
            </h4>
            <div class="row text-muted small g-2">
                <?php if (!empty($paziente_corrente['telefono'])): ?>
                    <div class="col-auto">
                        <i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($paziente_corrente['telefono']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($paziente_corrente['data_intervento'])): ?>
                    <div class="col-auto">
                        <i class="fa-solid fa-calendar-day me-1"></i>Data Intervento: <strong><?php echo htmlspecialchars($paziente_corrente['data_intervento']); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($paziente_corrente['tipologia_intervento'])): ?>
                    <div class="col-auto">
                        <i class="fa-solid fa-scalpel me-1"></i>Tipologia Intervento: <strong><?php echo htmlspecialchars($paziente_corrente['tipologia_intervento']); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($paziente_corrente['diagnosi'])): ?>
                    <div class="col-12">
                        <i class="fa-solid fa-notes-medical me-1"></i>Diagnosi: <strong><?php echo htmlspecialchars($paziente_corrente['diagnosi']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Elenco visite del paziente filtrate per lo studio corrente -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-list-check text-primary me-2"></i>Visite e Controlli in questo Studio
                </h5>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill"><?php echo count($visite); ?> trovate</span>
            </div>

            <?php if (empty($visite)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa-solid fa-folder-open fa-2x mb-3 text-secondary opacity-50"></i>
                    <p class="mb-0">Nessuna visita registrata per questo paziente in questo specifico studio.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light small text-uppercase">
                            <tr>
                                <th>Data Controllo</th>
                                <th>Tipo</th>
                                <th>Note / Esito</th>
                                <th>Link Visita Esatto</th>
                                <th class="text-center">Azione</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visite as $v): ?>
                                <?php 
                                    $controllo_id = $v['id'] ?? null; 
                                    $link_visita = "visita.php?id=" . $controllo_id;
                                ?>
                                <tr>
                                    <td class="small text-secondary fw-semibold">
                                        <?php echo !empty($v['data_controllo']) ? htmlspecialchars($v['data_controllo']) : '-'; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info text-dark px-2 py-1"><?php echo htmlspecialchars($v['tipo_controllo'] ?? 'Controllo'); ?></span>
                                    </td>
                                    <td class="small text-muted">
                                        <?php echo htmlspecialchars($v['note'] ?? $v['esito'] ?? '-'); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($controllo_id)): ?>
                                            <code class="small text-primary user-select-all"><?php echo htmlspecialchars($link_visita); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($controllo_id)): ?>
                                            <a href="visita.php?id=<?php echo urlencode($controllo_id); ?>" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold shadow-sm">
                                                <i class="fa-solid fa-file-medical me-1"></i> Apri Visita
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">ID non disponibile</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="nuovoControlloModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <form method="POST" action="controlli.php<?php echo !empty($paziente_selezionato_id) ? '?paziente_id=' . $paziente_selezionato_id : ''; ?>">
                <input type="hidden" name="azione" value="nuovo_controllo">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Aggiungi Nuovo Controllo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Paziente *</label>
                        <input type="text" class="form-control bg-light" id="modalRicercaPaziente" 
                               placeholder="Digita nome o cognome..." 
                               value="<?php echo htmlspecialchars($valore_ricerca_iniziale); ?>"
                               list="listaPazientiModalDatalist" autocomplete="off" required>
                        <input type="hidden" name="paziente_id" id="modal_paziente_id_hidden" value="<?php echo htmlspecialchars($paziente_selezionato_id); ?>">
                        <datalist id="listaPazientiModalDatalist">
                            <?php foreach ($pazienti as $p): ?>
                                <?php $nome_completo = $p['cognome'] . ' ' . $p['nome']; ?>
                                <option data-id="<?php echo $p['id']; ?>" value="<?php echo htmlspecialchars($nome_completo); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Data e Ora Controllo *</label>
                        <input type="text" class="form-control bg-light" name="data_controllo" placeholder="Es. 2026-08-19" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Tipo Controllo</label>
                        <input type="text" class="form-control bg-light" name="tipo_controllo" value="Controllo" placeholder="Es. Controllo, Prima visita, Medicazione...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Note</label>
                        <textarea class="form-control bg-light" name="note" rows="3" placeholder="Dettagli sul controllo..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-secondary">Terapia</label>
                        <input type="text" class="form-control bg-light" name="terapia" placeholder="Eventuale terapia...">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-success btn-sm rounded-pill px-4 fw-semibold">Salva Controllo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<nav class="mobile-nav d-md-none">
    <div class="container d-flex justify-content-around">
        <a href="dashboard.php" class="mobile-nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="planner.php" class="mobile-nav-item"><i class="fa-solid fa-calendar-days"></i><span>Planner</span></a>
        <a href="pazienti.php" class="mobile-nav-item"><i class="fa-solid fa-users"></i><span>Pazienti</span></a>
        <a href="controlli.php" class="mobile-nav-item active"><i class="fa-solid fa-stethoscope"></i><span>Controlli</span></a>
    </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const inputFiltro = document.getElementById('ricercaPazienteInput');
    const hiddenFiltro = document.getElementById('paziente_id_hidden');
    const datalistFiltro = document.getElementById('listaPazientiDatalist');

    if (inputFiltro) {
        inputFiltro.addEventListener('input', function() {
            let val = this.value;
            let options = datalistFiltro.options;
            let foundId = '';
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === val) {
                    foundId = options[i].getAttribute('data-id');
                    break;
                }
            }
            hiddenFiltro.value = foundId;
        });
        
        inputFiltro.addEventListener('change', function() {
            if (!this.value) {
                hiddenFiltro.value = '';
            }
        });
    }

    const inputModal = document.getElementById('modalRicercaPaziente');
    const hiddenModal = document.getElementById('modal_paziente_id_hidden');
    const datalistModal = document.getElementById('listaPazientiModalDatalist');

    if (inputModal) {
        inputModal.addEventListener('input', function() {
            let val = this.value;
            let options = datalistModal.options;
            let foundId = '';
            for (let i = 0; i < options.length; i++) {
                if (options[i].value === val) {
                    foundId = options[i].getAttribute('data-id');
                    break;
                }
            }
            hiddenModal.value = foundId;
        });

        inputModal.addEventListener('change', function() {
            if (!this.value) {
                hiddenModal.value = '';
            }
        });
    }
});
</script>
</body>
</html>