<?php
// dashboard.php - Dashboard principale Studio Medico (Mobile First, con filtro per medico o studio)
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$utente = $_SESSION['utente'];
$nome_completo = htmlspecialchars($utente['nome'] . ' ' . $utente['cognome']);
$titolo = htmlspecialchars($utente['titolo']);
$ruolo = strtolower(trim($utente['ruolo'] ?? ''));
$email_utente = strtolower(trim($utente['email'] ?? ''));
$medico_loggato_id = $utente['id'] ?? null;
$id_studio_corrente = $utente['studio_id'] ?? ($_SESSION['studio_id'] ?? null);

// Definizione permessi basati sul ruolo
$puo_gestire_clinica = ($ruolo === 'medico' || $ruolo === 'admin' || $ruolo === 'amministratore');

// Controllo esatto e sicuro per l'amministratore
$is_admin = ($ruolo === 'admin');

// SEZIONE SUPER ADMIN / BYPASS BLOCCO:
$is_super_admin = ($email_utente === 'gianden71@gmail.com' || $ruolo === 'superadmin');

// LEPROTTINO DI VELOCITÀ: Leggiamo l'abbonamento direttamente dalla sessione
$info_abbonamento = $_SESSION['abbonamento'] ?? ['stato' => 'attivo', 'giorni' => 99, 'data_scadenza' => 'N/D'];

if ($is_super_admin) {
    $is_abbonamento_scaduto = false;
    $info_abbonamento['stato'] = 'attivo';
} else {
    $is_abbonamento_scaduto = ($info_abbonamento['stato'] === 'scaduto');
}

// RECUPERO CONTROLLI ODIERNI DA SUPABASE CON FILTRO MIRATO (MEDICO VS SEGRETARIO/ADMIN)
$oggi = date('Y-m-d');
$totale_controlli_oggi = 0;
$lista_stringhe_tipi = [];

if (!$is_abbonamento_scaduto && function_exists('supabase_request') && $id_studio_corrente) {
    // Costruiamo la query di base per lo studio e la data odierna
    $query_url = "controlli?studio_id=eq." . urlencode($id_studio_corrente) . "&data_controllo=eq." . $oggi . "&select=tipo_controllo,medico_id";
    
    // SE È UN MEDICO, FILTRIAMO SOLO I SUOI APPUNTAMENTI PERSONALI
    if ($ruolo === 'medico' && !empty($medico_loggato_id)) {
        $query_url .= "&medico_id=eq." . urlencode($medico_loggato_id);
    }
    
    $risultato_controlli = @supabase_request($query_url);
    
    if (is_array($risultato_controlli)) {
        $totale_controlli_oggi = count($risultato_controlli);
        $tipi_count = [];
        foreach ($risultato_controlli as $c) {
            $tipo = trim($c['tipo_controllo'] ?? 'Generico');
            if (!empty($tipo)) {
                $tipi_count[$tipo] = ($tipi_count[$tipo] ?? 0) + 1;
            }
        }
        foreach ($tipi_count as $tipo => $conteggio) {
            $lista_stringhe_tipi[] = "$conteggio $tipo";
        }
    }
}
$riassunto_tipi = !empty($lista_stringhe_tipi) ? implode(', ', $lista_stringhe_tipi) : 'Nessun appuntamento';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Proman 2.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --card-bg: #ffffff; 
            --body-bg: #f8fafc; 
            --primary-color: #0f172a;
            --accent-blue: #0ea5e9;
        }
        body { 
            background-color: var(--body-bg); 
            font-family: 'Inter', sans-serif; 
            color: #1e293b; 
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Header moderno in stile app nativa */
        .app-header { 
            background: linear-gradient(135deg, #0f172a 100%, #1e293b 0%); 
            color: white; 
            padding: 1.5rem 1rem 2rem 1rem; 
            border-bottom-left-radius: 28px; 
            border-bottom-right-radius: 28px; 
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.2); 
            margin-bottom: 1.5rem; 
        }
        .user-avatar { 
            width: 50px; 
            height: 50px; 
            background: rgba(255, 255, 255, 0.1); 
            border: 1px solid rgba(255, 255, 255, 0.2);
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border-radius: 16px; 
            font-size: 1.25rem;
            color: #38bdf8;
        }

        /* Card interattive modernissime */
        .dashboard-card { 
            background: var(--card-bg); 
            border: 1px solid #f1f5f9; 
            border-radius: 20px; 
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.03); 
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); 
            overflow: hidden; 
            position: relative; 
        }
        .dashboard-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px -5px rgba(0, 0, 0, 0.07);
            border-color: #e2e8f0;
        }
        .dashboard-card:active { 
            transform: scale(0.97); 
        }
        
        .card-icon-wrapper { 
            width: 54px; 
            height: 54px; 
            border-radius: 16px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 1.35rem; 
            margin-bottom: 0.85rem; 
            transition: transform 0.2s;
        }
        .dashboard-card:hover .card-icon-wrapper {
            transform: scale(1.05);
        }

        .disabled-card { 
            opacity: 0.5; 
            filter: grayscale(1); 
            cursor: not-allowed !important; 
            pointer-events: none !important; 
        }

        /* Temi colore icone */
        .card-planner .card-icon-wrapper { background: #eff6ff; color: #2563eb; }
        .card-pazienti .card-icon-wrapper { background: #f0fdf4; color: #16a34a; }
        .card-repository .card-icon-wrapper { background: #fefce8; color: #ca8a04; }
        .card-controlli .card-icon-wrapper { background: #fdf4ff; color: #c084fc; }
        .card-disponibilita .card-icon-wrapper { background: #e0f2fe; color: #0284c7; }
        .card-admin .card-icon-wrapper { background: #fef2f2; color: #dc2626; }

        .main-content { padding-bottom: 3rem; }
        
        .badge-ruolo {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        /* Banner giornaliero elegante stile card sfumata */
        .agenda-banner {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-left: 4px solid #2563eb;
            border-radius: 20px;
            box-shadow: 0 4px 15px -3px rgba(0, 0, 0, 0.03);
        }
    </style>
</head>
<body>

<div class="app-header">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <div class="user-avatar me-3 shadow-sm"><i class="fa-solid fa-hand-holding-medical"></i></div>
            <div>
                <span class="d-block text-white-50 small text-uppercase fw-bold tracking-wider" style="font-size: 0.7rem; letter-spacing: 0.05em;">Dashboard — <?php echo ucfirst($ruolo); ?></span>
                <h5 class="mb-0 fw-bold text-white"><?php echo $titolo . ' ' . $nome_completo; ?></h5>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="cambia_password.php" class="btn btn-outline-light btn-sm rounded-pill px-3 border-0 bg-white bg-opacity-10 shadow-sm" title="Cambia Password">
                <i class="fa-solid fa-key"></i>
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3 border-0 bg-white bg-opacity-10 shadow-sm" title="Esci">
                <i class="fa-solid fa-power-off"></i>
            </a>
        </div>
    </div>
</div>

<div class="container main-content">

    <?php if ($info_abbonamento['stato'] === 'scaduto' && !$is_super_admin): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-danger border-0 shadow-sm rounded-4 p-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #fef2f2; color: #991b1b;">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-circle-exclamation fs-3 me-3 text-danger"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Abbonamento Scaduto</h6>
                            <p class="mb-0 small">Il tuo abbonamento è scaduto il <?php echo ($info_abbonamento['data_scadenza'] && $info_abbonamento['data_scadenza'] !== 'N/D') ? date('d/m/Y', strtotime($info_abbonamento['data_scadenza'])) : 'recentemente'; ?>.</p>
                        </div>
                    </div>
                    <a href="mailto:gianden71@gmail.com?subject=Rinnovo%20Abbonamento" class="btn btn-danger btn-sm px-3 rounded-pill fw-bold shadow-sm">Rinnova Ora</a>
                </div>
            </div>
        </div>
    <?php elseif ($info_abbonamento['stato'] === 'in_scadenza' && !$is_super_admin): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning border-0 shadow-sm rounded-4 p-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background-color: #fffbeb; color: #92400e;">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-triangle-exclamation fs-3 me-3 text-warning"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Scadenza Imminente</h6>
                            <p class="mb-0 small">Il tuo abbonamento scadrà tra <strong><?php echo $info_abbonamento['giorni']; ?> giorni</strong>.</p>
                        </div>
                    </div>
                    <a href="mailto:gianden71@gmail.com?subject=Rinnovo%20Abbonamento" class="btn btn-warning btn-sm px-3 rounded-pill fw-bold text-dark shadow-sm">Rinnova Ora</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Brand Card / Info Studio -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="p-3 bg-white rounded-4 shadow-sm border border-light d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4 me-3 fs-5">
                        <i class="fa-solid fa-hand-holding-medical"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-dark">PROMAN 2.0</h6>
                        <p class="text-muted small mb-0">Gestione integrata ambulatorio</p>
                    </div>
                </div>
                <span class="badge badge-ruolo px-3 py-2 rounded-pill fw-bold text-uppercase small shadow-none"><?php echo $ruolo; ?></span>
            </div>
        </div>
    </div>

    <!-- BANNER ELEGANTE: Riepilogo Appuntamenti Odierni (Filtrato per Medico o Studio) -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="agenda-banner p-3 p-md-4 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4 me-3 fs-4 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                        <i class="fa-solid fa-calendar-day"></i>
                    </div>
                    <div>
                        <span class="d-block text-muted text-uppercase fw-bold" style="font-size: 0.65rem; letter-spacing: 0.08em;">
                            <?php echo ($ruolo === 'medico') ? 'I tuoi impegni di oggi' : 'Agenda totale studio'; ?> — <?php echo date('d/m/Y'); ?>
                        </span>
                        <h6 class="fw-bold mb-0 text-dark fs-6 mt-1">
                            <?php if ($totale_controlli_oggi > 0): ?>
                                Oggi ci sono: <span class="text-primary fw-bold"><?php echo htmlspecialchars($riassunto_tipi); ?></span> 
                                <span class="text-muted fw-normal small ms-1">(Tot: <?php echo $totale_controlli_oggi; ?>)</span>
                            <?php else: ?>
                                <span class="text-muted fw-normal">Nessun appuntamento in programma per oggi.</span>
                            <?php endif; ?>
                        </h6>
                    </div>
                </div>
                <div class="d-none d-sm-block text-end">
                    <span class="badge bg-light text-secondary border px-3 py-2 rounded-pill fw-medium" style="font-size: 0.75rem;">
                        <i class="fa-solid fa-circle-check text-success me-1"></i> Studio Attivo
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Griglia Principale Funzioni -->
    <div class="row g-3 mb-4">
        <!-- Planner -->
        <div class="col-6 col-md-3">
            <a href="<?php echo $is_abbonamento_scaduto ? '#' : 'planner.php'; ?>" class="text-decoration-none <?php echo $is_abbonamento_scaduto ? 'disabled-card' : ''; ?>">
                <div class="card dashboard-card card-planner h-100 p-3">
                    <div class="card-icon-wrapper"><i class="fa-solid fa-calendar-days"></i></div>
                    <h6 class="fw-bold text-dark mb-1">Planner</h6>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Agenda e prenotazioni prime visite</p>
                </div>
            </a>
        </div>
        <!-- Pazienti -->
        <div class="col-6 col-md-3">
            <a href="<?php echo $is_abbonamento_scaduto ? '#' : 'pazienti.php'; ?>" class="text-decoration-none <?php echo $is_abbonamento_scaduto ? 'disabled-card' : ''; ?>">
                <div class="card dashboard-card card-pazienti h-100 p-3">
                    <div class="card-icon-wrapper"><i class="fa-solid fa-users"></i></div>
                    <h6 class="fw-bold text-dark mb-1">Pazienti</h6>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Anagrafica clinica e prenotazione prosieguo cure</p>
                </div>
            </a>
        </div>
        <!-- Controlli -->
        <div class="col-6 col-md-3">
            <?php 
                $link_controlli = ($puo_gestire_clinica && !$is_abbonamento_scaduto) ? 'controlli.php' : '#';
                $classe_controlli = (!$puo_gestire_clinica || $is_abbonamento_scaduto) ? 'disabled-card' : '';
            ?>
            <a href="<?php echo $link_controlli; ?>" class="text-decoration-none <?php echo $classe_controlli; ?>">
                <div class="card dashboard-card card-controlli h-100 p-3">
                    <div class="card-icon-wrapper"><i class="fa-solid fa-stethoscope"></i></div>
                    <h6 class="fw-bold text-dark mb-1">Controlli</h6>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Follow-up medico-chirurgici</p>
                </div>
            </a>
        </div>
        <!-- Repository -->
        <div class="col-6 col-md-3">
            <a href="<?php echo $is_abbonamento_scaduto ? '#' : 'repository.php'; ?>" class="text-decoration-none <?php echo $is_abbonamento_scaduto ? 'disabled-card' : ''; ?>">
                <div class="card dashboard-card card-repository h-100 p-3">
                    <div class="card-icon-wrapper"><i class="fa-solid fa-folder-open"></i></div>
                    <h6 class="fw-bold text-dark mb-1">Repository</h6>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Documenti e file</p>
                </div>
            </a>
        </div>
        <!-- Disponibilità Orarie -->
        <div class="col-6 col-md-3">
            <a href="<?php echo $is_abbonamento_scaduto ? '#' : 'medici_disponibilita.php'; ?>" class="text-decoration-none <?php echo $is_abbonamento_scaduto ? 'disabled-card' : ''; ?>">
                <div class="card dashboard-card card-disponibilita h-100 p-3">
                    <div class="card-icon-wrapper"><i class="fa-solid fa-clock"></i></div>
                    <h6 class="fw-bold text-dark mb-1">Disponibilità</h6>
                    <p class="text-muted small mb-0" style="font-size: 0.75rem;">Gestione fasce orarie e slot visite</p>
                </div>
            </a>
        </div>
    </div>

    <!-- SEZIONE AMMINISTRAZIONE (Visibile solo se is_admin è true) -->
    <?php if ($is_admin): ?>
        <div class="d-flex align-items-center mb-3 mt-4">
            <h6 class="text-uppercase text-muted fw-bold small tracking-wider mb-0" style="letter-spacing: 0.08em;"><i class="fa-solid fa-shield-halved text-danger me-2"></i>Area Amministrazione</h6>
        </div>
        <div class="row g-3 mb-4">
            <!-- Nuovo Utente -->
            <div class="col-6 col-md-3">
                <a href="nuovo_utente.php" class="text-decoration-none">
                    <div class="card dashboard-card card-admin h-100 p-3">
                        <div class="card-icon-wrapper"><i class="fa-solid fa-user-plus"></i></div>
                        <h6 class="fw-bold text-dark mb-1">Nuovo Utente</h6>
                        <p class="text-muted small mb-0" style="font-size: 0.75rem;">Abilita personale</p>
                    </div>
                </a>
            </div>
            <!-- Pagamenti -->
            <div class="col-6 col-md-3">
                <a href="pagamenti.php" class="text-decoration-none">
                    <div class="card dashboard-card card-admin h-100 p-3">
                        <div class="card-icon-wrapper"><i class="fa-solid fa-euro-sign"></i></div>
                        <h6 class="fw-bold text-dark mb-1">Pagamenti</h6>
                        <p class="text-muted small mb-0" style="font-size: 0.75rem;">Gestione abbonamenti</p>
                    </div>
                </a>
            </div>
            <!-- Prenotazioni Web (Admin) -->
            <div class="col-6 col-md-3">
                <a href="tutte_prenotazioni.php" class="text-decoration-none">
                    <div class="card dashboard-card card-admin h-100 p-3">
                        <div class="card-icon-wrapper"><i class="fa-solid fa-globe"></i></div>
                        <h6 class="fw-bold text-dark mb-1">Prenotazioni Web</h6>
                        <p class="text-muted small mb-0" style="font-size: 0.75rem;">Registro prenotazioni online</p>
                    </div>
                </a>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>