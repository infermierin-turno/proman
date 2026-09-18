<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$paziente_id = $_GET['id'] ?? null;
if (empty($paziente_id)) {
    header("Location: pazienti.php");
    exit;
}

// Recupera i dati del paziente
$risultato_paziente = supabase_request('pazienti?id=eq.' . urlencode($paziente_id), 'GET');
if (empty($risultato_paziente) || isset($risultato_paziente['error'])) {
    header("Location: pazienti.php");
    exit;
}
$paziente = $risultato_paziente[0];

// Recupera lo storico di tutti i controlli associati a questo paziente (ordinati per data decrescente o crescente)
$risultato_controlli = supabase_request('controlli?paziente_id=eq.' . urlencode($paziente_id) . '&order=data_controllo.desc', 'GET');
$controlli = (!isset($risultato_controlli['error'])) ? $risultato_controlli : [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheda Paziente - Proman</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #f4f7f6;
            font-family: 'Inter', sans-serif;
            color: #333333;
        }
        .app-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 1.25rem 1rem;
            margin-bottom: 1.5rem;
        }
        .main-content {
            padding-bottom: 3rem;
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="app-header shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="pazienti.php" class="text-white me-3 fs-5"><i class="fa-solid fa-arrow-left"></i></a>
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-user-injured me-2"></i>Scheda Clinica Paziente</h5>
        </div>
        <div>
            <a href="planner.php?paziente_id=<?php echo $paziente['id']; ?>" class="btn btn-success btn-sm rounded-pill px-3 fw-semibold shadow-sm">
                <i class="fa-solid fa-calendar-plus me-1"></i> Prenota Visita
            </a>
        </div>
    </div>
</div>

<div class="container main-content">

    <!-- Card Dati Anagrafici e Clinici -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
        <div class="row align-items-center mb-3">
            <div class="col">
                <h3 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($paziente['cognome'] . ' ' . $paziente['nome']); ?></h3>
                <span class="text-muted small">
                    <i class="fa-solid fa-cake-candles me-1"></i> Nascita: <?php echo !empty($paziente['data_nascita']) ? date('d/m/Y', strtotime($paziente['data_nascita'])) : '-'; ?> 
                    <?php if (!empty($paziente['luogo_nascita'])): ?> (<?php echo htmlspecialchars($paziente['luogo_nascita']); ?>)<?php endif; ?>
                </span>
            </div>
            <div class="col-auto text-end">
                <?php if (!empty($paziente['telefono'])): ?>
                    <div class="fw-semibold text-primary"><i class="fa-solid fa-phone me-1"></i><?php echo htmlspecialchars($paziente['telefono']); ?></div>
                <?php endif; ?>
                <?php if (!empty($paziente['email'])): ?>
                    <div class="small text-muted"><i class="fa-solid fa-envelope me-1"></i><?php echo htmlspecialchars($paziente['email']); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <hr class="text-muted opacity-25">

        <div class="row g-3 mt-1">
            <div class="col-md-4">
                <div class="p-3 bg-light rounded-3">
                    <span class="d-block small text-uppercase fw-bold text-secondary mb-1">Diagnosi Principale</span>
                    <span class="text-dark fw-semibold"><?php echo !empty($paziente['diagnosi']) ? htmlspecialchars($paziente['diagnosi']) : '<span class="text-muted fw-normal fst-italic">Non specificata</span>'; ?></span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded-3">
                    <span class="d-block small text-uppercase fw-bold text-secondary mb-1">Tipologia Intervento</span>
                    <span class="text-dark fw-semibold"><?php echo !empty($paziente['tipologia_intervento']) ? htmlspecialchars($paziente['tipologia_intervento']) : '<span class="text-muted fw-normal fst-italic">Non specificato</span>'; ?></span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3 bg-light rounded-3">
                    <span class="d-block small text-uppercase fw-bold text-secondary mb-1">Data Intervento</span>
                    <span class="text-dark fw-semibold"><?php echo !empty($paziente['data_intervento']) ? date('d/m/Y', strtotime($paziente['data_intervento'])) : '<span class="text-muted fw-normal fst-italic">Non specificata</span>'; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Storico Visite e Controlli -->
    <div class="card border-0 shadow-sm rounded-4 p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-clock-rotate-left text-primary me-2"></i>Storico Visite e Controlli</h5>
            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill"><?php echo count($controlli); ?> registrati</span>
        </div>

        <?php if (empty($controlli)): ?>
            <div class="text-center text-muted py-4 fst-italic">Nessuna visita o controllo registrato per questo paziente.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light small text-uppercase">
                        <tr>
                            <th>Data e Ora</th>
                            <th>Tipo Visita</th>
                            <th>Note / Anamnesi</th>
                            <th>Terapia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($controlli as $c): ?>
                            <tr>
                                <td class="fw-bold text-dark">
                                    <?php echo date('d/m/Y', strtotime($c['data_controllo'])); ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($c['tipo_controllo'] ?? 'Controllo'); ?></span>
                                </td>
                                <td class="small text-secondary">
                                    <?php echo !empty($c['note']) ? htmlspecialchars($c['note']) : '-'; ?>
                                </td>
                                <td class="small text-muted">
                                    <?php echo !empty($c['terapia']) ? htmlspecialchars($c['terapia']) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>