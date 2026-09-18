<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

function genera_link_prenotazione_whatsapp($paziente_id, $studio_id) {
    $app_secret = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : 'proman_secure_booking_secret_key_2026';
    $scadenza = time() + (7 * 24 * 60 * 60);
    
    $payload = $paziente_id . '|' . $studio_id . '|' . $scadenza;
    $firma = hash_hmac('sha256', $payload, $app_secret);
    
    $dati_token = [
        'paziente_id' => $paziente_id,
        'studio_id' => $studio_id,
        'scadenza' => $scadenza,
        'firma' => $firma
    ];
    
    $token_string = rtrim(strtr(base64_encode(json_encode($dati_token)), '+/', '-_'), '=');
    
    $protocollo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $percorso_base = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    
    return "{$protocollo}://{$host}{$percorso_base}/prenota_esterno.php?token=" . $token_string;
}

$studio_id_sessione = null;
$medico_loggato_id = null;
$ruolo_utente = 'medico';

if (isset($_SESSION['studio_id'])) {
    $val = $_SESSION['studio_id'];
    $studio_id_sessione = is_array($val) ? ($val['id'] ?? ($val['studio_id'] ?? null)) : $val;
}

$utente_info = $_SESSION['utente'];
$email_cerca = is_array($utente_info) ? ($utente_info['email'] ?? '') : $utente_info;

if (!empty($email_cerca)) {
    $medico_data = supabase_request('medici?email=eq.' . urlencode($email_cerca) . '&select=*', 'GET');
    if (!empty($medico_data) && !isset($medico_data['error'])) {
        $medico_record = $medico_data[0];
        $medico_loggato_id = $medico_record['id'] ?? null;
        $ruolo_utente = $medico_record['ruolo'] ?? 'medico';
        
        if (empty($studio_id_sessione)) {
            $val_studio = $medico_record['studio_id'] ?? null;
            $studio_id_sessione = is_array($val_studio) ? ($val_studio['id'] ?? null) : $val_studio;
            $_SESSION['studio_id'] = $studio_id_sessione;
        }
    }
}

$messaggio = '';
$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'aggiorna_stato') {
    $prenotazione_id = $_POST['prenotazione_id'] ?? '';
    $nuovo_stato = $_POST['nuovo_stato'] ?? '';

    if (!empty($prenotazione_id) && !empty($nuovo_stato)) {
        $update_data = ['stato' => $nuovo_stato];
        $risultato_aggiornamento = supabase_request('prenotazioni?id=eq.' . urlencode($prenotazione_id), 'PATCH', $update_data);
        
        if (isset($risultato_aggiornamento['error'])) {
            $errore = "Errore durante l'aggiornamento dello stato della visita.";
        } else {
            $messaggio = "Stato della visita aggiornato con successo!";
        }
    } else {
        $errore = "Dati incompleti per l'aggiornamento dello stato.";
    }
}

$ricerca = trim($_GET['q'] ?? '');
$pazienti = [];

if (!empty($ricerca)) {
    $endpoint = "pazienti?order=cognome.asc";
    $valore = '*' . $ricerca . '*';
    $valore_encoded = rawurlencode($valore);
    $endpoint .= "&or=(cognome.ilike." . $valore_encoded . ",nome.ilike." . $valore_encoded . ")";

    $pazienti = supabase_request($endpoint, 'GET');

    if (!is_array($pazienti)) {
        $pazienti = [];
        $errore = "Impossibile recuperare i dati dei pazienti.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anagrafica Pazienti - Proman</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f4f7f6; font-family: 'Inter', sans-serif; }
        .app-header { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: white; padding: 1.25rem 1rem; }
        .mobile-nav { background: #ffffff; border-top: 1px solid #eaeaea; position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000; padding: 0.5rem 0; box-shadow: 0 -4px 15px rgba(0,0,0,0.05); }
        .mobile-nav-item { color: #6c757d; text-align: center; text-decoration: none; font-size: 0.75rem; display: flex; flex-direction: column; align-items: center; }
        .mobile-nav-item.active { color: #0d6efd; }
        .main-content { padding-bottom: 90px; }
    </style>
</head>
<body>

<div class="app-header shadow-sm">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="text-white me-3 text-decoration-none fs-5">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <h5 class="mb-0 fw-bold"><i class="fa-solid fa-users me-2"></i>Pazienti</h5>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($studio_id_sessione)): ?>
                <?php 
                    $protocollo_pub = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host_pub = $_SERVER['HTTP_HOST'];
                    $percorso_pub = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                    $medico_id_pub = $_GET['medico_filtro'] ?? ($medico_loggato_id ?? '');
                    
                    $link_nuovo_paziente_esterno = "{$protocollo_pub}://{$host_pub}{$percorso_pub}/prenota_esterno.php?studio_id=" . urlencode($studio_id_sessione);
                    if (!empty($medico_id_pub)) {
                        $link_nuovo_paziente_esterno .= "&medico_id=" . urlencode($medico_id_pub);
                    }
                ?>
                <button type="button" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold text-nowrap" onclick="navigator.clipboard.writeText('<?php echo $link_nuovo_paziente_esterno; ?>'); alert('Link per nuovi pazienti copiato negli appunti!');" title="Copia link pubblico per nuovi pazienti">
                    <i class="fa-solid fa-link me-1"></i> Link Nuovo Paziente
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container mt-4 main-content">
    <?php if ($messaggio): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($messaggio); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($errore): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($errore); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Barra di ricerca -->
    <div class="card border-0 shadow-sm rounded-4 p-3 mb-4">
        <form method="GET" action="pazienti.php" class="row g-2">
            <div class="col-10">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="fa-solid fa-search"></i></span>
                    <input type="text" class="form-control border-start-0 bg-light" name="q" value="<?php echo htmlspecialchars($ricerca); ?>" placeholder="Cerca nome o cognome paziente...">
                </div>
            </div>
            <div class="col-2 d-grid">
                <button type="submit" class="btn btn-primary rounded-3"><i class="fa-solid fa-arrow-right"></i></button>
            </div>
        </form>
    </div>

    <!-- Tabella Risultati -->
    <div class="card border-0 shadow-sm rounded-4 p-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="text-secondary fs-7">
                        <th>Nominativo</th>
                        <th>Data di Nascita</th>
                        <th>Luogo di Nascita</th>
                        <th>Contatti</th>
                        <th class="text-center">Gestione Prenotazioni (Interna / Esterna)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ricerca)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Usa la barra di ricerca in alto per cercare un paziente.</td></tr>
                    <?php else: ?>
                        <?php if (empty($pazienti)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Nessun paziente trovato.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pazienti as $pat): ?>
                                <?php 
                                    $paziente_id = $pat['id'] ?? '';
                                    $nome_paz = trim($pat['nome'] ?? '');
                                    $cognome_paz = trim($pat['cognome'] ?? '');
                                    $nome_completo_paziente = trim($cognome_paz . ' ' . $nome_paz);
                                    if (empty($nome_completo_paziente)) {
                                        $nome_completo_paziente = 'Paziente Sconosciuto';
                                    }
                                    
                                    $ultima_prenotazione = null;
                                    $ha_gia_prenotato_esterno = false;

                                    if (!empty($paziente_id)) {
                                        $pren_data = supabase_request('prenotazioni?paziente_id=eq.' . urlencode($paziente_id) . '&order=data.desc,ora.desc&limit=1', 'GET');
                                        if (!empty($pren_data) && is_array($pren_data) && !isset($pren_data['error'])) {
                                            $ultima_prenotazione = $pren_data[0];
                                        }

                                        $prenotazioni_esistenti = supabase_request('prenotazioni?paziente_id=eq.' . urlencode($paziente_id) . '&select=id,stato', 'GET');
                                        if (!empty($prenotazioni_esistenti) && is_array($prenotazioni_esistenti)) {
                                            foreach ($prenotazioni_esistenti as $p_esistente) {
                                                $st = $p_esistente['stato'] ?? '';
                                                if (in_array($st, ['programmato', 'confermato', 'completato'])) {
                                                    $ha_gia_prenotato_esterno = true;
                                                    break;
                                                }
                                            }
                                        }
                                    }

                                    $link_whatsapp_esterna = '';
                                    $telefono_paziente = trim($pat['telefono'] ?? '');
                                    if (!empty($paziente_id) && !empty($studio_id_sessione) && !$ha_gia_prenotato_esterno) {
                                        $link_sicuro = genera_link_prenotazione_whatsapp($paziente_id, $studio_id_sessione);
                                        $tel_pulito = preg_replace('/[^0-9+]/', '', $telefono_paziente);
                                        if (substr($tel_pulito, 0, 2) !== '+3' && strlen($tel_pulito) == 10) {
                                            $tel_pulito = '+39' . $tel_pulito;
                                        }
                                        $testo_wa = "Buongiorno " . $nome_paz . ", può prenotare il suo appuntamento cliccando su questo link sicuro: " . $link_sicuro;
                                        $link_whatsapp_esterna = "https://wa.me/" . ltrim($tel_pulito, '+') . "?text=" . urlencode($testo_wa);
                                    }
                                ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?php echo htmlspecialchars($nome_completo_paziente); ?></td>
                                    <td class="text-muted small">
                                        <?php 
                                            if (!empty($pat['data_nascita'])) {
                                                echo htmlspecialchars(date('d/m/Y', strtotime($pat['data_nascita'])));
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                    <td class="text-muted small"><?php echo htmlspecialchars($pat['luogo_nascita'] ?? '-'); ?></td>
                                    <td class="text-muted small">
                                        <div><i class="fa-solid fa-phone me-1 text-secondary"></i> <?php echo htmlspecialchars($telefono_paziente ?: '-'); ?></div>
                                        <?php if (!empty($pat['email'])): ?>
                                            <div><i class="fa-solid fa-envelope me-1 text-secondary"></i> <?php echo htmlspecialchars($pat['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center align-items-center gap-2 flex-wrap">
                                            
                                            <!-- Passaggio completo di tutte le varianti di parametri per compatibilità con il planner -->
                                            <a href="planner.php?id_paziente=<?php echo urlencode($paziente_id); ?>&paziente_id=<?php echo urlencode($paziente_id); ?>&nome_paziente=<?php echo urlencode($nome_completo_paziente); ?>&nome=<?php echo urlencode($nome_completo_paziente); ?>&cognome=<?php echo urlencode($cognome_paz); ?>" class="btn btn-sm btn-primary rounded-pill px-3" title="Prenota direttamente dal planner">
                                                <i class="fa-solid fa-calendar-plus me-1"></i> Prenota Interno
                                            </a>

                                            <?php if ($ha_gia_prenotato_esterno): ?>
                                                <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 text-muted" disabled title="Il paziente ha già una prenotazione attiva/completata">
                                                    <i class="fa-brands fa-whatsapp me-1"></i> Già prenotato
                                                </button>
                                            <?php elseif (empty($telefono_paziente) || $telefono_paziente === '0000000000'): ?>
                                                <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 text-muted" disabled title="Telefono mancante o non valido">
                                                    <i class="fa-brands fa-whatsapp me-1"></i> Nessun tel.
                                                </button>
                                            <?php else: ?>
                                                <a href="<?php echo $link_whatsapp_esterna; ?>" target="_blank" class="btn btn-sm btn-success rounded-pill px-3 text-white" title="Invia link WhatsApp">
                                                    <i class="fa-brands fa-whatsapp me-1"></i> Invia Link WhatsApp se presente nella tua rubrica
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($ultima_prenotazione): ?>
                                                <form method="POST" action="pazienti.php" class="d-inline-block m-0">
                                                    <input type="hidden" name="azione" value="aggiorna_stato">
                                                    <input type="hidden" name="prenotazione_id" value="<?php echo htmlspecialchars($ultima_prenotazione['id']); ?>">
                                                    <select name="nuovo_stato" class="form-select form-select-sm rounded-pill px-2 fs-7 bg-light border-secondary text-secondary fw-semibold" onchange="this.form.submit()">
                                                        <?php $stato_corrente = $ultima_prenotazione['stato'] ?? 'programmato'; ?>
                                                        <option value="programmato" <?php echo ($stato_corrente === 'programmato') ? 'selected' : ''; ?>>Programmato</option>
                                                        <option value="confermato" <?php echo ($stato_corrente === 'confermato') ? 'selected' : ''; ?>>Confermato</option>
                                                        <option value="completato" <?php echo ($stato_corrente === 'completato') ? 'selected' : ''; ?>>Completato</option>
                                                        <option value="annullato" <?php echo ($stato_corrente === 'annullato') ? 'selected' : ''; ?>>Annullato</option>
                                                    </select>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<nav class="mobile-nav d-md-none">
    <div class="container d-flex justify-content-around">
        <a href="dashboard.php" class="mobile-nav-item">
            <i class="fa-solid fa-house mb-1 fs-5"><span>Home</span></i>
        </a>
        <a href="pazienti.php" class="mobile-nav-item active">
            <i class="fa-solid fa-users mb-1 fs-5"><span>Pazienti</span></i>
        </a>
    </div>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>