<?php
// repository.php - Archivio Modulistica e Documenti Studio Medico
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

// Recupero studio_id dalla sessione (assicurati che sia presente nel tuo sistema di login)
$studio_id = $_SESSION['utente']['studio_id'] ?? null;

if (!$studio_id) {
    die("Errore: Studio non identificato. Contattare l'amministratore.");
}

$messaggio = '';
$errore = '';

// 1. Gestione Eliminazione Documento
if (isset($_GET['elimina']) && !empty($_GET['elimina'])) {
    $id_da_eliminare = $_GET['elimina'];
    
    // Recupero record verificando l'appartenenza allo studio per sicurezza
    $doc_da_cancellare = supabase_request("repository?id=eq.$id_da_eliminare&studio_id=eq.$studio_id&select=file_url");
    
    if (!empty($doc_da_cancellare) && !isset($doc_da_cancellare['error'])) {
        $percorso_file = $doc_da_cancellare[0]['file_url'];
        
        // Elimina file dal server
        if (file_exists($percorso_file)) {
            unlink($percorso_file);
        }
        
        // Elimina riga dal database
        $risultato_del = supabase_request("repository?id=eq.$id_da_eliminare&studio_id=eq.$studio_id", 'DELETE');
        
        if (isset($risultato_del['error'])) {
            $errore = "Errore durante l'eliminazione dal database.";
        } else {
            $messaggio = "Documento eliminato con successo!";
        }
    } else {
        $errore = "Documento non trovato o non autorizzato.";
    }
}

// 2. Gestione caricamento nuovo documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['elimina'])) {
    $titolo = trim($_POST['titolo'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');

    if (empty($titolo) || !isset($_FILES['file_fisico']) || $_FILES['file_fisico']['error'] !== UPLOAD_ERR_OK) {
        $errore = "Titolo e un file valido sono obbligatori.";
    } else {
        $cartella_upload = 'uploads/';
        if (!is_dir($cartella_upload)) mkdir($cartella_upload, 0755, true);

        // Aggiunto studio_id nel nome del file per maggiore pulizia
        $nome_file = $studio_id . '_' . time() . '_' . basename($_FILES['file_fisico']['name']);
        $percorso_destinazione = $cartella_upload . $nome_file;

        if (move_uploaded_file($_FILES['file_fisico']['tmp_name'], $percorso_destinazione)) {
            $nuovo_doc = [
                'titolo' => $titolo,
                'descrizione' => $descrizione,
                'file_url' => $percorso_destinazione,
                'studio_id' => $studio_id // Inserimento multitenant
            ];

            $risultato = supabase_request('repository', 'POST', $nuovo_doc);

            if (isset($risultato['error'])) {
                $errore = "Errore DB: " . (is_array($risultato['error']) ? json_encode($risultato['error']) : $risultato['error']);
            } else {
                $messaggio = "Documento caricato e salvato con successo!";
            }
        } else {
            $errore = "Errore durante il caricamento del file sul server.";
        }
    }
}

// Recupero documenti filtrati per studio_id
$documenti = supabase_request("repository?studio_id=eq.$studio_id&order=created_at.desc&select=*");
if (isset($documenti['error'])) {
    $documenti = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repository Documenti - Studio Medico</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; }
        .app-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 1.25rem 1rem; margin-bottom: 1.5rem; }
        .mobile-nav { background: #ffffff; border-top: 1px solid #eaeaea; position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000; padding: 0.5rem 0; box-shadow: 0 -4px 15px rgba(0,0,0,0.05); }
        .mobile-nav-item { color: #6c757d; text-align: center; text-decoration: none; font-size: 0.75rem; display: flex; flex-direction: column; align-items: center; }
        .mobile-nav-item.active { color: #0d6efd; }
        .main-content { padding-bottom: 80px; }
        @media (min-width: 768px) { .mobile-nav { display: none !important; } }
    </style>
</head>
<body>

<div class="app-header shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="text-white me-3 fs-5"><i class="fa-solid fa-arrow-left"></i></a>
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-folder-open me-2"></i>Repository</h5>
        </div>
        <button class="btn btn-warning btn-sm rounded-pill px-3 fw-semibold text-dark" data-bs-toggle="modal" data-bs-target="#nuovoDocModal">
            <i class="fa-solid fa-upload me-1"></i> Carica
        </button>
    </div>
</div>

<div class="container main-content">
    <?php if (!empty($messaggio)): ?><div class="alert alert-success rounded-4"><?php echo htmlspecialchars($messaggio); ?></div><?php endif; ?>
    <?php if (!empty($errore)): ?><div class="alert alert-danger rounded-4"><?php echo htmlspecialchars($errore); ?></div><?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 p-4">
        <table class="table table-hover align-middle">
            <thead class="table-light small text-uppercase">
                <tr><th>Titolo</th><th>Descrizione</th><th class="text-end">Azioni</th></tr>
            </thead>
            <tbody>
                <?php foreach ($documenti as $doc): ?>
                <tr>
                    <td><div class="fw-bold text-dark"><i class="fa-solid fa-file-pdf text-danger me-2"></i><?php echo htmlspecialchars($doc['titolo']); ?></div></td>
                    <td><span class="text-muted small"><?php echo htmlspecialchars($doc['descrizione'] ?? '-'); ?></span></td>
                    <td class="text-end">
                        <a href="<?php echo htmlspecialchars($doc['file_url']); ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-pill me-1"><i class="fa-solid fa-download"></i></a>
                        <a href="?elimina=<?php echo $doc['id']; ?>" class="btn btn-outline-danger btn-sm rounded-pill" onclick="return confirm('Sei sicuro di voler eliminare questo documento?')">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modale -->
<div class="modal fade" id="nuovoDocModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-header border-0"><h5 class="modal-title fw-bold">Carica Documento</h5></div>
                <div class="modal-body">
                    <div class="mb-3"><input type="text" name="titolo" class="form-control" placeholder="Titolo" required></div>
                    <div class="mb-3"><textarea name="descrizione" class="form-control" placeholder="Descrizione"></textarea></div>
                    <div class="mb-3"><input type="file" name="file_fisico" class="form-control" required></div>
                </div>
                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-warning rounded-pill px-4">Carica</button>
                </div>
            </form>
        </div>
    </div>
</div>

<nav class="mobile-nav d-md-none">
    <div class="container d-flex justify-content-around">
        <a href="dashboard.php" class="mobile-nav-item"><i class="fa-solid fa-house"></i><span>Home</span></a>
        <a href="planner.php" class="mobile-nav-item"><i class="fa-solid fa-calendar-days"></i><span>Planner</span></a>
        <a href="repository.php" class="mobile-nav-item active"><i class="fa-solid fa-folder-open"></i><span>File</span></a>
        <a href="cambia_password.php" class="mobile-nav-item"><i class="fa-solid fa-key"></i><span>Password</span></a>
    </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>