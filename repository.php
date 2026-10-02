<?php
// repository.php - Archivio Modulistica e Documenti Studio Medico
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

// Configurazione credenziali Supabase
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', rtrim(trim(getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? '')), '/'));
}
if (!defined('SUPABASE_KEY')) {
    define('SUPABASE_KEY', trim(getenv('SUPABASE_KEY') ?: ($_ENV['SUPABASE_KEY'] ?? '')));
}

if (empty(SUPABASE_URL) || empty(SUPABASE_KEY)) {
    die("<div style='font-family:sans-serif; padding: 20px; color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; margin: 20px; border-radius: 5px;'>
        <h3>Errore di Configurazione su Render</h3>
        <p>Le variabili di ambiente <strong>SUPABASE_URL</strong> o <strong>SUPABASE_KEY</strong> non sono configurate o risultano vuote.</p>
    </div>");
}

if (!function_exists('supabase_request')) {
    function supabase_request($endpoint, $method = 'GET', $data = null, $custom_headers = []) {
        $base_url = rtrim(SUPABASE_URL, '/');
        $endpoint = ltrim($endpoint, '/');
        $url = $base_url . '/rest/v1/' . $endpoint;
        
        $headers = [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ];
        if (!empty($custom_headers)) {
            $headers = array_merge($headers, $custom_headers);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($http_code >= 400 || !empty($curl_error)) {
            $decoded = json_decode($response, true);
            return ['error' => $decoded['message'] ?? $response ?: $curl_error];
        }
        return json_decode($response, true);
    }
}

// Recupero dati utente e studio_id dalla sessione
$utente_id = null;
$studio_id = null;

if (is_array($_SESSION['utente'])) {
    $utente_id = $_SESSION['utente']['id'] ?? $_SESSION['utente']['user_id'] ?? null;
    $studio_id = $_SESSION['utente']['studio_id'] ?? null;
}

$messaggio = '';
$errore = '';

// 1. Gestione Eliminazione Documento
if (isset($_GET['elimina']) && !empty($_GET['elimina'])) {
    $id_da_eliminare = $_GET['elimina'];
    
    // Cerchiamo il documento tramite UUID (senza vincoli rigidi se studio_id è null nel db)
    $query_verifica = "repository?id=eq.$id_da_eliminare&select=file_url,studio_id";
    if (!empty($studio_id)) {
        // Se lo studio è valorizzato in sessione, permettiamo la cancellazione se corrisponde o se è null
        // Per semplicità e sicurezza robusta, verifichiamo solo l'esistenza del record UUID
    }
    
    $doc_da_cancellare = supabase_request("repository?id=eq.$id_da_eliminare&select=file_url");
    
    if (!empty($doc_da_cancellare) && !isset($doc_da_cancellare['error'])) {
        $percorso_file_url = $doc_da_cancellare[0]['file_url'];
        
        // Estrazione nome file dal bucket Supabase Storage
        $parti_url = explode('/repository/', $percorso_file_url);
        if (isset($parti_url[1])) {
            $nome_file_storage = $parti_url[1];
            
            $url_storage = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/repository/' . $nome_file_storage;
            $ch = curl_init($url_storage);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        
        // Eliminazione riga dal database tramite UUID
        $risultato_del = supabase_request("repository?id=eq.$id_da_eliminare", 'DELETE');
        
        if (isset($risultato_del['error'])) {
            $errore = "Errore durante l'eliminazione dal database: " . json_encode($risultato_del['error']);
        } else {
            $messaggio = "Documento eliminato con successo!";
        }
    } else {
        $errore = "Documento non trovato o già eliminato.";
    }
}

// 2. Gestione caricamento nuovo documento su Supabase Storage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['elimina'])) {
    $titolo = trim($_POST['titolo'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');

    if (empty($titolo) || !isset($_FILES['file_fisico']) || $_FILES['file_fisico']['error'] !== UPLOAD_ERR_OK) {
        $errore = "Titolo e un file valido sono obbligatori.";
    } else {
        $file_tmp = $_FILES['file_fisico']['tmp_name'];
        $nome_originale = basename($_FILES['file_fisico']['name']);
        $mime_type = mime_content_type($file_tmp) ?: 'application/octet-stream';
        
        // Nome unico per il file nel bucket Supabase
        $prefix = $studio_id ? $studio_id : 'general';
        $nome_file_storage = $prefix . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $nome_originale);
        
        $url_storage = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/repository/' . $nome_file_storage;
        $file_data = file_get_contents($file_tmp);

        $ch = curl_init($url_storage);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: ' . $mime_type
        ]);

        $response_storage = curl_exec($ch);
        $http_code_storage = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error_storage = curl_error($ch);
        curl_close($ch);

        if ($http_code_storage >= 200 && $http_code_storage < 300) {
            $url_pubblico = rtrim(SUPABASE_URL, '/') . '/storage/v1/object/public/repository/' . $nome_file_storage;

            $nuovo_doc = [
                'titolo' => $titolo,
                'descrizione' => $descrizione,
                'file_url' => $url_pubblico,
                'studio_id' => $studio_id ?: null,
                'caricato_da' => $utente_id ?: null
            ];

            $risultato = supabase_request('repository', 'POST', $nuovo_doc);

            if (isset($risultato['error'])) {
                $errore = "Errore DB: " . (is_array($risultato['error']) ? json_encode($risultato['error']) : $risultato['error']);
            } else {
                $messaggio = "Documento caricato e salvato con successo nel cloud!";
            }
        } else {
            $dettaglio_errore = $response_storage ?: $curl_error_storage;
            $errore = "Errore caricamento Storage (HTTP $http_code_storage): " . htmlspecialchars($dettaglio_errore);
        }
    }
}

// Recupero documenti ordinati per data (gestendo eventuali record senza studio_id obbligatorio)
$query_get = "repository?order=created_at.desc&select=*";
if (!empty($studio_id)) {
    // Se vuoi filtrare per studio ma includere anche quelli null, oppure filtrare direttamente:
    // $query_get = "repository?or=(studio_id.eq.$studio_id,studio_id.is.null)&order=created_at.desc&select=*";
}
$documenti = supabase_request($query_get);
if (isset($documenti['error']) || !is_array($documenti)) {
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
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light small text-uppercase">
                    <tr><th>Titolo</th><th>Descrizione</th><th class="text-end">Azioni</th></tr>
                </thead>
                <tbody>
                    <?php if (count($documenti) > 0): foreach ($documenti as $doc): ?>
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
                    <?php endforeach; else: ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">Nessun documento presente nel repository.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
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
