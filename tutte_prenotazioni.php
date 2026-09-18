<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
if (!$is_admin) {
    header("Location: dashboard.php");
    exit;
}

$filtro_data = $_GET['data'] ?? '';
$filtro_stato = $_GET['stato'] ?? '';
$filtro_medico = $_GET['medico_id'] ?? '';
$filtro_studio = $_GET['studio_id'] ?? '';
$ricerca_testo = trim($_GET['q'] ?? '');

$studi_map = [];
$res_studi = supabase_request('studi?select=id,nome');
if (is_array($res_studi) && !isset($res_studi['error'])) {
    foreach ($res_studi as $s) {
        $studi_map[$s['id']] = $s['nome'] ?? 'Studio #' . substr($s['id'], 0, 8);
    }
}

$medici_map = [];
$res_medici = supabase_request('medici?select=id,nome,cognome');
if (is_array($res_medici) && !isset($res_medici['error'])) {
    foreach ($res_medici as $m) {
        $nome_completo = trim(($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? ''));
        $medici_map[$m['id']] = $nome_completo !== '' ? $nome_completo : ('Medico #' . substr($m['id'], 0, 8));
    }
}

$endpoint = 'prenotazioni_web?select=*&order=data_ora_appuntamento.desc';
$supabase_error = null;
$prenotazioni = [];

$res = supabase_request($endpoint);

if (is_array($res)) {
    if (isset($res['error'])) {
        $supabase_error = is_array($res['error']) ? json_encode($res['error']) : $res['error'];
    } elseif (isset($res['message'])) {
        $supabase_error = $res['message'] . (isset($res['details']) ? ' - ' . $res['details'] : '');
    } else {
        $prenotazioni = $res;
    }
} else {
    $supabase_error = "Risposta non valida o vuota ricevuta da Supabase.";
}

if (!empty($prenotazioni) && empty($supabase_error)) {
    $prenotazioni = array_filter($prenotazioni, function($item) use ($filtro_medico, $filtro_studio, $filtro_data, $filtro_stato, $ricerca_testo, $medici_map, $studi_map) {
        
        if ($filtro_stato !== '' && strtolower(trim($item['stato'] ?? '')) !== strtolower(trim($filtro_stato))) {
            return false;
        }
        if ($filtro_medico !== '' && ($item['medico_id'] ?? '') !== $filtro_medico) {
            return false;
        }
        if ($filtro_studio !== '' && ($item['studio_id'] ?? '') !== $filtro_studio) {
            return false;
        }
        if ($filtro_data !== '') {
            $data_item = isset($item['data_ora_appuntamento']) ? substr($item['data_ora_appuntamento'], 0, 10) : '';
            if ($data_item !== $filtro_data) {
                return false;
            }
        }
        if ($ricerca_testo !== '') {
            $medico_nome = $medici_map[$item['medico_id']] ?? '';
            $studio_nome = $studi_map[$item['studio_id']] ?? '';
            $searchable = ($item['paziente_nome'] ?? '') . ' ' . 
                         ($item['paziente_cognome'] ?? '') . ' ' . 
                         ($item['paziente_email'] ?? '') . ' ' . 
                         ($item['paziente_telefono'] ?? '') . ' ' . 
                         ($item['motivo'] ?? '') . ' ' .
                         $medico_nome . ' ' .
                         $studio_nome;
            if (stripos($searchable, $ricerca_testo) === false) {
                return false;
            }
        }
        return true;
    });
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutte le Prenotazioni Web (Admin) - Proman 2.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2c3e50;
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
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            background: #fff;
        }
        .card-header-custom {
            background-color: var(--bg-soft);
            color: var(--primary-color);
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border-color);
            padding: 0.5rem 0.8rem;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        .table-custom th {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background-color: #f1f5f9;
            color: #475569;
        }
        .table-custom td {
            font-size: 0.82rem;
            vertical-align: middle;
        }
        .form-label-sm {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.2rem;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3 py-1">
        <div class="container-fluid">
            <a class="navbar-brand fs-6 fw-bold" href="dashboard.php"><i class="bi bi-globe me-1"></i> Proman 2.0</a>
            <button class="navbar-toggler py-1 px-2 border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon" style="width: 1.2em; height: 1.2em;"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto small">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner</a></li>
                    <li class="nav-item"><a class="nav-link active text-warning fw-bold" href="tutte_prenotazioni.php"><i class="bi bi-shield-lock me-1"></i> Prenotazioni Web</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-3 mb-4">
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h4 class="fw-bold text-dark mb-0 fs-5"><i class="bi bi-calendar2-check me-2 text-primary"></i> Registro Generale Prenotazioni Web</h4>
                <small class="text-muted">Elenco completo di tutte le prenotazioni presenti nel database</small>
            </div>
            <div>
                <a href="planner.php" class="btn btn-sm btn-outline-secondary shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Torna al Planner
                </a>
            </div>
        </div>

        <?php if (!empty($supabase_error)): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 small" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Errore Supabase:</strong> <?php echo htmlspecialchars($supabase_error); ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card card-custom mb-3">
            <div class="card-header-custom"><i class="bi bi-funnel me-1"></i> Filtri di Ricerca</div>
            <div class="card-body p-2">
                <form method="GET" action="tutte_prenotazioni.php" class="row g-2 align-items-end">
                    <div class="col-md-3 col-6">
                        <label class="form-label-sm">Cerca Testo</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Paziente, email, motivo..." value="<?php echo htmlspecialchars($ricerca_testo); ?>">
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label-sm">Medico</label>
                        <select name="medico_id" class="form-select form-select-sm">
                            <option value="">Tutti i medici</option>
                            <?php foreach ($medici_map as $mid => $mnome): ?>
                                <option value="<?php echo htmlspecialchars($mid); ?>" <?php echo ($filtro_medico === $mid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mnome); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label-sm">Studio</label>
                        <select name="studio_id" class="form-select form-select-sm">
                            <option value="">Tutti gli studi</option>
                            <?php foreach ($studi_map as $sid => $snome): ?>
                                <option value="<?php echo htmlspecialchars($sid); ?>" <?php echo ($filtro_studio === $sid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($snome); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-6">
                        <label class="form-label-sm">Data Appuntamento</label>
                        <input type="date" name="data" class="form-control form-control-sm" value="<?php echo htmlspecialchars($filtro_data); ?>">
                    </div>
                    <div class="col-md-1 col-6">
                        <label class="form-label-sm">Stato</label>
                        <select name="stato" class="form-select form-select-sm">
                            <option value="">Tutti</option>
                            <option value="in_attesa" <?php echo ($filtro_stato === 'in_attesa') ? 'selected' : ''; ?>>In attesa</option>
                            <option value="confermata" <?php echo ($filtro_stato === 'confermata') ? 'selected' : ''; ?>>Confermata</option>
                            <option value="cancellata" <?php echo ($filtro_stato === 'cancellata') ? 'selected' : ''; ?>>Cancellata</option>
                            <option value="completata" <?php echo ($filtro_stato === 'completata') ? 'selected' : ''; ?>>Completata</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex gap-1 mt-md-0 mt-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-search me-1"></i> Filtra
                        </button>
                        <a href="tutte_prenotazioni.php" class="btn btn-sm btn-outline-secondary" title="Azzera filtri">
                            <i class="bi bi-x-circle"></i> Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card card-custom">
            <div class="card-header-custom d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-1"></i> Risultati (<?php echo count($prenotazioni); ?>)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-striped table-custom mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Data e Ora Appuntamento</th>
                                <th>Medico</th>
                                <th>Studio</th>
                                <th>Paziente</th>
                                <th>Contatti</th>
                                <th>Motivo</th>
                                <th>Stato</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($prenotazioni)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="bi bi-inbox fs-3 d-block mb-1"></i>
                                        Nessuna prenotazione trovata nel database (o nessuna corrisponde ai filtri impostati).
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($prenotazioni as $p): ?>
                                    <?php 
                                        $data_ora_raw = $p['data_ora_appuntamento'] ?? '';
                                        $data_formatted = !empty($data_ora_raw) ? date('d/m/Y H:i', strtotime($data_ora_raw)) : '-';
                                        
                                        $medico_nome = $medici_map[$p['medico_id']] ?? 'Medico non specificato';
                                        $studio_nome = $studi_map[$p['studio_id']] ?? 'Studio non specificato';
                                        
                                        $badge_class = 'bg-secondary';
                                        $st = strtolower($p['stato'] ?? 'in_attesa');
                                        if ($st === 'confermata') { $badge_class = 'bg-success'; }
                                        elseif ($st === 'in_attesa') { $badge_class = 'bg-warning text-dark'; }
                                        elseif ($st === 'cancellata') { $badge_class = 'bg-danger'; }
                                        elseif ($st === 'completata') { $badge_class = 'bg-info text-dark'; }
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="fw-bold text-dark"><i class="bi bi-calendar-event me-1 text-primary"></i><?php echo htmlspecialchars($data_formatted); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><i class="bi bi-person-badge me-1 text-secondary"></i><?php echo htmlspecialchars($medico_nome); ?></div>
                                        </td>
                                        <td>
                                            <div><i class="bi bi-building me-1 text-secondary"></i><?php echo htmlspecialchars($studio_nome); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark">
                                                <?php echo htmlspecialchars(($p['paziente_cognome'] ?? '') . ' ' . ($p['paziente_nome'] ?? '')); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div><i class="bi bi-telephone me-1 text-muted"></i><?php echo htmlspecialchars($p['paziente_telefono'] ?? '-'); ?></div>
                                            <div><small class="text-muted"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($p['paziente_email'] ?? '-'); ?></small></div>
                                        </td>
                                        <td>
                                            <small class="text-muted text-truncate d-inline-block" style="max-width: 150px;" title="<?php echo htmlspecialchars($p['motivo'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($p['motivo'] ?? '-'); ?>
                                            </small>
                                        </td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($p['stato'] ?? 'in_attesa'); ?></span></td>
                                        <td class="text-end">
                                            <?php if (!empty($p['controllo_id'])): ?>
                                                <a href="visita.php?id=<?php echo htmlspecialchars($p['controllo_id']); ?>" class="btn btn-xs btn-outline-primary py-0 px-2" title="Apri Controllo/Visita" style="font-size: 0.75rem;">
                                                    <i class="bi bi-folder2-open me-1"></i> Visita
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>