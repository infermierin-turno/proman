<?php
// medici.php - Gestione e Inserimento Medici (Mobile First)
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$messaggio = '';
$errore = '';

// Gestione inserimento nuovo medico
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'nuovo_medico') {
    $titolo = trim($_POST['titolo'] ?? 'Dr.');
    $cognome = trim($_POST['cognome'] ?? '');
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $attivo = isset($_POST['attivo']) ? true : false;

    if (!empty($cognome) && !empty($nome) && !empty($email) && !empty($password)) {
        // Generazione dell'hash sicuro della password per il database
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $nuovo_record = [
            'titolo' => $titolo,
            'cognome' => $cognome,
            'nome' => $nome,
            'email' => $email,
            'password_hash' => $password_hash,
            'attivo' => $attivo
        ];

        $risultato = supabase_request('medici', 'POST', $nuovo_record);

        if (!isset($risultato['error'])) {
            $messaggio = "Medico registrato con successo!";
        } else {
            $errore = "Errore durante il salvataggio su Supabase: " . ($risultato['message'] ?? 'Errore sconosciuto');
        }
    } else {
        $errore = "Tutti i campi obbligatori (Cognome, Nome, Email e Password) devono essere compilati.";
    }
}

// Recupero elenco medici da Supabase ordinati per cognome
$medici = supabase_request('medici?select=*&order=cognome.asc');
if (isset($medici['error'])) {
    $medici = [];
    $errore = "Impossibile recuperare l'elenco dei medici.";
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Medici - Proman</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome per le icone -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --body-bg: #f4f7f6;
            --card-bg: #ffffff;
        }

        body {
            background-color: var(--body-bg);
            font-family: 'Inter', sans-serif;
            color: #333333;
            -webkit-font-smoothing: antialiased;
        }

        .app-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 1.25rem 1rem;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .doctor-card {
            background: var(--card-bg);
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            transition: transform 0.15s ease;
            margin-bottom: 0.75rem;
        }

        .mobile-nav {
            background: #ffffff;
            border-top: 1px solid #eaeaea;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 0.5rem 0;
            box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
        }

        .mobile-nav-item {
            color: #6c757d;
            text-align: center;
            text-decoration: none;
            font-size: 0.75rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            transition: color 0.2s;
        }

        .mobile-nav-item i {
            font-size: 1.25rem;
            margin-bottom: 2px;
        }

        .mobile-nav-item.active, .mobile-nav-item:hover {
            color: #0d6efd;
        }

        .main-content {
            padding-bottom: 90px;
        }

        @media (min-width: 768px) {
            .mobile-nav {
                display: none !important;
            }
            .app-header {
                border-radius: 0;
                margin-bottom: 2rem;
            }
            .main-content {
                padding-bottom: 2rem;
            }
        }
    </style>
</head>
<body>

<!-- Header Top -->
<div class="app-header">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="text-white me-3 fs-5"><i class="fa-solid fa-arrow-left"></i></a>
            <div>
                <span class="d-block text-white-50 small">Configurazione</span>
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-user-doctor me-2"></i>Gestione Medici</h5>
            </div>
        </div>
        <div>
            <button class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuovoMedico">
                <i class="fa-solid fa-user-plus me-1"></i> Nuovo
            </button>
        </div>
    </div>
</div>

<!-- Contenuto Principale -->
<div class="container main-content">

    <?php if (!empty($messaggio)): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?php echo htmlspecialchars($messaggio); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($errore)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($errore); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Barra di Ricerca Rapida -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="input-group shadow-sm rounded-4 overflow-hidden">
                <span class="input-group-text bg-white border-0 ps-3 text-muted"><i class="fa-solid fa-search"></i></span>
                <input type="text" id="cercaMedico" class="form-control border-0 py-2.5" placeholder="Cerca medico per nome, cognome o email...">
            </div>
        </div>
    </div>

    <!-- Elenco Medici -->
    <div class="row" id="listaMediciContainer">
        <?php if (empty($medici)): ?>
            <div class="col-12 text-center py-5">
                <div class="text-muted mb-2"><i class="fa-solid id-badge fa-2x"></i></div>
                <p class="text-muted">Nessun medico registrato nel database.</p>
            </div>
        <?php else: ?>
            <?php foreach ($medici as $m): ?>
                <div class="col-12 col-md-6 col-lg-4 medico-item" data-search="<?php echo strtolower(htmlspecialchars(($m['titolo'] ?? '') . ' ' . $m['cognome'] . ' ' . $m['nome'] . ' ' . $m['email'])); ?>">
                    <div class="card doctor-card p-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge <?php echo (!isset($m['attivo']) || $m['attivo'] == true || $m['attivo'] === 'TRUE') ? 'bg-success' : 'bg-secondary'; ?> bg-opacity-10 text-<?php echo (!isset($m['attivo']) || $m['attivo'] == true || $m['attivo'] === 'TRUE') ? 'success' : 'secondary'; ?> mb-1">
                                    <?php echo (!isset($m['attivo']) || $m['attivo'] == true || $m['attivo'] === 'TRUE') ? 'Attivo' > 0 ? 'Attivo' : 'Attivo' : 'Disattivato'; ?>
                                </span>
                                <h5 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars(($m['titolo'] ?? 'Dr.') . ' ' . $m['cognome'] . ' ' . $m['nome']); ?></h5>
                                <p class="text-muted small mb-1">
                                    <i class="fa-solid fa-envelope me-1"></i> <?php echo htmlspecialchars($m['email']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<!-- Modal Inserimento Nuovo Medico -->
<div class="modal fade" id="modalNuovoMedico" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2 text-primary"></i>Nuovo Medico</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="azione" value="nuovo_medico">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="titolo" class="form-label small fw-semibold">Titolo</label>
                        <select class="form-select rounded-3" id="titolo" name="titolo">
                            <option value="Dr.">Dr.</option>
                            <option value="Dott.ssa">Dott.ssa</option>
                            <option value="Prof.">Prof.</option>
                            <option value="Prof.ssa">Prof.ssa</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label for="cognome" class="form-label small fw-semibold">Cognome *</label>
                            <input type="text" class="form-control rounded-3" id="cognome" name="cognome" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label for="nome" class="form-label small fw-semibold">Nome *</label>
                            <input type="text" class="form-control rounded-3" id="nome" name="nome" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label small fw-semibold">Email (Username accesso) *</label>
                        <input type="email" class="form-control rounded-3" id="email" name="email" required>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label small fw-semibold">Password provvisoria *</label>
                        <input type="password" class="form-control rounded-3" id="password" name="password" required>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="attivo" name="attivo" value="1" checked>
                        <label class="form-check-label small fw-semibold" for="attivo">Profilo Attivo</label>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Salva Medico</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bottom Navigation Bar (Mobile) -->
<nav class="mobile-nav d-md-none">
    <div class="container d-flex justify-content-around">
        <a href="dashboard.php" class="mobile-nav-item">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="pazienti.php" class="mobile-nav-item">
            <i class="fa-solid fa-users"></i>
            <span>Pazienti</span>
        </a>
        <a href="medici.php" class="mobile-nav-item active">
            <i class="fa-solid fa-user-doctor"></i>
            <span>Medici</span>
        </a>
        <a href="prenotazioni.php" class="mobile-nav-item">
            <i class="fa-solid fa-calendar-check"></i>
            <span>Prenotazioni</span>
        </a>
    </div>
</nav>

<!-- Script per ricerca dinamica -->
<script>
document.getElementById('cercaMedico').addEventListener('input', function() {
    let filtro = this.value.toLowerCase();
    let elementi = document.querySelectorAll('.medico-item');
    
    elementi.forEach(function(item) {
        let testo = item.getAttribute('data-search');
        if (testo.includes(filtro)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
});
</script>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>