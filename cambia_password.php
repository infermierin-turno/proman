<?php
// cambia_password.php - Modifica Password Account (Mobile First)
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$utente = $_SESSION['utente'];
$messaggio = '';
$errore = '';

// Gestione cambio password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'cambia_password') {
    $vecchia_password = $_POST['vecchia_password'] ?? '';
    $nuova_password = $_POST['nuova_password'] ?? '';
    $conferma_password = $_POST['conferma_password'] ?? '';

    if (!empty($vecchia_password) && !empty($nuova_password) && !empty($conferma_password)) {
        if ($nuova_password === $conferma_password) {
            // Recupero i dati del medico/utente corrente tramite email o id memorizzato in sessione
            $email_utente = $utente['email'] ?? '';
            
            if (!empty($email_utente)) {
                $medici = supabase_request('medici?email=eq.' . urlencode($email_utente) . '&select=*');
                
                if (!empty($medici) && !isset($medici['error'])) {
                    $medico_db = $medici[0];
                    
                    // Verifica della vecchia password
                    if (password_verify($vecchia_password, $medico_db['password_hash'])) {
                        // Generazione del nuovo hash della password
                        $nuovo_hash = password_hash($nuova_password, PASSWORD_DEFAULT);
                        
                        $dati_aggiornamento = [
                            'password_hash' => $nuovo_hash
                        ];
                        
                        $risultato = supabase_request('medici?id=eq.' . $medico_db['id'], 'PATCH', $dati_aggiornamento);
                        
                        if (!isset($risultato['error'])) {
                            $messaggio = "Password modificata con successo!";
                        } else {
                            $errore = "Errore durante l'aggiornamento su Supabase: " . ($risultato['message'] ?? 'Errore sconosciuto');
                        }
                    } else {
                        $errore = "La vecchia password inserita non è corretta.";
                    }
                } else {
                    $errore = "Impossibile trovare il profilo utente nel database.";
                }
            } else {
                $errore = "Sessione utente non valida.";
            }
        } else {
            $errore = "La nuova password e la conferma non coincidono.";
        }
    } else {
        $errore = "Tutti i campi sono obbligatori.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambia Password - Proman</title>
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

        .password-card {
            background: var(--card-bg);
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
            padding: 1.5rem;
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
                <span class="d-block text-white-50 small">Sicurezza Account</span>
                <h5 class="mb-0 fw-bold"><i class="fa-solid fa-key me-2"></i>Cambia Password</h5>
            </div>
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

    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="card password-card">
                <form method="POST" action="">
                    <input type="hidden" name="azione" value="cambia_password">
                    
                    <div class="mb-3">
                        <label for="vecchia_password" class="form-label small fw-semibold">Vecchia Password *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0 text-muted"><i class="fa-solid fa-lock"></i></span>
                            <input type="password" class="form-control border-0 bg-light py-2" id="vecchia_password" name="vecchia_password" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="nuova_password" class="form-label small fw-semibold">Nuova Password *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0 text-muted"><i class="fa-solid fa-key"></i></span>
                            <input type="password" class="form-control border-0 bg-light py-2" id="nuova_password" name="nuova_password" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="conferma_password" class="form-label small fw-semibold">Conferma Nuova Password *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light border-0 text-muted"><i class="fa-solid fa-key"></i></span>
                            <input type="password" class="form-control border-0 bg-light py-2" id="conferma_password" name="conferma_password" required>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary rounded-pill py-2.5 fw-semibold shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Aggiorna Password
                        </button>
                    </div>
                </form>
            </div>
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
        <a href="medici.php" class="mobile-nav-item">
            <i class="fa-solid fa-user-doctor"></i>
            <span>Medici</span>
        </a>
        <a href="cambia_password.php" class="mobile-nav-item active">
            <i class="fa-solid fa-key"></i>
            <span>Password</span>
        </a>
    </div>
</nav>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>