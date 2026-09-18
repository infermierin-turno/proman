<?php session_start(); if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; } ?>
<?php
require_once 'config.php';

// Estrazione sicura dell'utente dalla sessione
$utente_loggato = $_SESSION['utente'];
$utente_id = is_array($utente_loggato) ? ($utente_loggato['id'] ?? ($utente_loggato['utente_id'] ?? null)) : null;
$utente_email = is_array($utente_loggato) ? ($utente_loggato['email'] ?? null) : (is_string($utente_loggato) ? $utente_loggato : null);
$utente_username = is_array($utente_loggato) ? ($utente_loggato['username'] ?? null) : null;
$studio_id = is_array($utente_loggato) ? ($utente_loggato['studio_id'] ?? null) : null;

$titolo_medico = 'Dr.';
$nome_medico = '';
$cognome_medico = '';
$matricola_medico = 'N/D';
$specializzazione_medico = 'Specialista in Ortopedia e Traumatologia';
$ambulatorio_medico = 'Studio / Ambulatorio Medico Specialistico';

// Interroghiamo la tabella 'medici' per recuperare i dati completi e l'id se mancante
$dati_medico_trovati = null;

if ($utente_id) {
    $res = supabase_request('medici?id=eq.' . urlencode($utente_id), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if (!$dati_medico_trovati && $utente_email) {
    $res = supabase_request('medici?email=eq.' . urlencode($utente_email), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if (!$dati_medico_trovati && $utente_username) {
    $res = supabase_request('medici?username=eq.' . urlencode($utente_username), 'GET');
    if (!empty($res) && !isset($res['error'])) {
        $dati_medico_trovati = $res[0];
    }
}

if ($dati_medico_trovati) {
    $utente_id = $dati_medico_trovati['id'] ?? $utente_id;
    $titolo_medico = $dati_medico_trovati['titolo'] ?? 'Dr.';
    $nome_medico = $dati_medico_trovati['nome'] ?? '';
    $cognome_medico = $dati_medico_trovati['cognome'] ?? '';
    $matricola_medico = $dati_medico_trovati['matricola'] ?? 'N/D';
    
    if (!empty($dati_medico_trovati['specializzazione'])) {
        $specializzazione_medico = $dati_medico_trovati['specializzazione'];
    }
    if (!empty($dati_medico_trovati['ambulatorio'])) {
        $ambulatorio_medico = $dati_medico_trovati['ambulatorio'];
    }
    if (!$studio_id && !empty($dati_medico_trovati['studio_id'])) {
        $studio_id = $dati_medico_trovati['studio_id'];
    }
}

$nome_utente_completo = trim($nome_medico . ' ' . $cognome_medico);
if ($nome_utente_completo === '') {
    $nome_utente_completo = is_array($utente_loggato) ? ($utente_loggato['username'] ?? ($utente_loggato['email'] ?? 'Utente')) : $utente_loggato;
}

$messaggio_successo = '';
$messaggio_errore = '';

// Gestione del salvataggio del pagamento legato allo Studio
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studio_selezionato_id = trim($_POST['studio_id'] ?? '');
    $medico_selezionato_id = trim($_POST['medico_id'] ?? '');
    
    // Fallback di sicurezza: se il medico non è selezionato ma il DB lo richiede, usiamo l'utente loggato
    if (empty($medico_selezionato_id) && !empty($utente_id)) {
        $medico_selezionato_id = $utente_id;
    }

    $importo = trim($_POST['importo'] ?? '');
    $note = trim($_POST['note'] ?? '');
    $data_pagamento = trim($_POST['data_pagamento'] ?? date('Y-m-d'));
    $durata_mesi = intval($_POST['durata_mesi'] ?? 1);
    $servizio_selezionato = trim($_POST['servizio'] ?? '');

    if (empty($importo)) {
        $messaggio_errore = "L'importo è obbligatorio.";
    } elseif (empty($studio_selezionato_id)) {
        $messaggio_errore = "È obbligatorio selezionare lo studio a cui associare il pagamento.";
    } elseif (empty($medico_selezionato_id)) {
        $messaggio_errore = "È obbligatorio associare un medico (il vincolo del database richiede un medico di riferimento).";
    } else {
        // Dati da inviare a Supabase legati allo Studio principale
        $dati_pagamento = [
            'studio_id' => $studio_selezionato_id,
            'medico_id' => $medico_selezionato_id,
            'importo' => floatval(str_replace(',', '.', $importo)),
            'note' => $note !== '' ? $note : null,
            'data_pagamento' => $data_pagamento,
            'durata_mesi' => $durata_mesi,
            'modulo_chirurgia_mano' => false,
            'modulo_cardiologia' => false
        ];

        // Attivazione dinamica del flag booleano in base al servizio scelto
        if ($servizio_selezionato === 'chirurgia_mano') {
            $dati_pagamento['modulo_chirurgia_mano'] = true;
        } elseif ($servizio_selezionato === 'cardiologia') {
            $dati_pagamento['modulo_cardiologia'] = true;
        }

        $pagamento_id = $_GET['id'] ?? null;

        if (!empty($pagamento_id)) {
            $risultato = supabase_request('pagamenti?id=eq.' . urlencode($pagamento_id), 'PATCH', $dati_pagamento);
            $azione_testo = "aggiornato";
        } else {
            $risultato = supabase_request('pagamenti', 'POST', $dati_pagamento);
            $azione_testo = "salvato";
        }

        if (empty($risultato) || !isset($risultato['error'])) {
            $messaggio_successo = "Pagamento $azione_testo con successo!";
        } else {
            $messaggio_errore = "Errore durante il salvataggio del pagamento: " . json_encode($risultato);
        }
    }
}

// Recupero di tutti i pagamenti esistenti ordinati per data discendente
$lista_pagamenti = supabase_request("pagamenti?order=data_pagamento.desc", 'GET');
if (isset($lista_pagamenti['error'])) {
    $lista_pagamenti = [];
}

// Recupero degli studi per il select e per la mappa dei nomi
$lista_studi = supabase_request("studi?order=nome_studio.asc", 'GET');
if (isset($lista_studi['error'])) {
    $lista_studi = [];
}
$mappa_studi = [];
foreach ($lista_studi as $s) {
    if (!empty($s['id'])) {
        $mappa_studi[$s['id']] = $s['nome_studio'] ?? ('Studio #' . $s['id']);
    }
}

// Recupero dei medici per il select e per la mappa dei nomi
$lista_medici = supabase_request("medici?order=cognome.asc", 'GET');
if (isset($lista_medici['error'])) {
    $lista_medici = [];
}
$mappa_medici = [];
foreach ($lista_medici as $m) {
    if (!empty($m['id'])) {
        $mappa_medici[$m['id']] = trim(($m['titolo'] ?? 'Dr.') . ' ' . ($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? ''));
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Pagamenti e Servizi - Proman</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f8f9fa; color: #333; }
        header { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        header h1 { font-size: 1.4em; margin: 0; color: #007bff; }
        .header-actions { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; font-size: 0.9em; }
        .nav-links { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h2 { margin-top: 0; font-size: 1.2em; color: #007bff; border-bottom: 2px solid #f1f3f5; padding-bottom: 8px; margin-bottom: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.9em; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1em; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .btn-salva { background-color: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 4px; font-size: 1em; font-weight: bold; cursor: pointer; }
        .btn-salva:hover { background-color: #218838; }
        .btn-dashboard { background-color: #007bff; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        .btn-dashboard:hover { background-color: #0056b3; }
        .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.9em; }
        th, td { border: 1px solid #dee2e6; padding: 10px; text-align: left; }
        th { background-color: #f1f3f5; color: #495057; }
        @media (min-width: 768px) {
            header { flex-direction: row; justify-content: space-between; align-items: center; }
            header h1 { font-size: 1.8em; }
        }
    </style>
</head>
<body>

    <header>
        <h1>Gestione Pagamenti e Servizi</h1>
        <div class="header-actions">
            <div class="nav-links">
                <a href="dashboard.php" class="btn-dashboard">🏠 Dashboard</a>
            </div>
            <div>
                <span>Utente: <strong><?php echo htmlspecialchars($titolo_medico . ' ' . $nome_utente_completo); ?></strong> | </span>
                <a href="logout.php" style="color: #dc3545; text-decoration: none;">Logout</a>
            </div>
        </div>
    </header>

    <?php if ($messaggio_successo): ?>
        <div class="alert-success"><?php echo htmlspecialchars($messaggio_successo); ?></div>
    <?php endif; ?>

    <?php if ($messaggio_errore): ?>
        <div class="alert-danger"><?php echo htmlspecialchars($messaggio_errore); ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>Registra Nuovo Pagamento / Attivazione Servizio</h2>
        <form method="POST" action="pagamenti.php">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px;">
                <div class="form-group">
                    <label for="studio_id">Studio (Obbligatorio):</label>
                    <select id="studio_id" name="studio_id" required>
                        <option value="">-- Seleziona Studio --</option>
                        <?php foreach ($lista_studi as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id']); ?>">
                                <?php echo htmlspecialchars($s['nome_studio'] ?? ('Studio #' . $s['id'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="medico_id">Medico di Riferimento:</label>
                    <select id="medico_id" name="medico_id">
                        <option value="">-- Seleziona Medico (o usa utente loggato) --</option>
                        <?php foreach ($lista_medici as $m): ?>
                            <?php 
                                $m_id = $m['id'] ?? '';
                                $m_nome = trim(($m['titolo'] ?? 'Dr.') . ' ' . ($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? ''));
                                if ($m_nome === '') $m_nome = 'Medico #' . $m_id;
                            ?>
                            <option value="<?php echo htmlspecialchars($m_id); ?>" <?php echo ($m_id === $utente_id) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($m_nome); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="servizio">Servizio / Modulo:</label>
                    <select id="servizio" name="servizio">
                        <option value="">-- Nessun modulo specifico --</option>
                        <option value="chirurgia_mano">Chirurgia della Mano</option>
                        <option value="cardiologia">Cardiologia</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="importo">Importo (€):</label>
                    <input type="text" id="importo" name="importo" placeholder="0.00" required>
                </div>

                <div class="form-group">
                    <label for="durata_mesi">Durata (Mesi):</label>
                    <input type="number" id="durata_mesi" name="durata_mesi" value="1" min="1" required>
                </div>

                <div class="form-group">
                    <label for="data_pagamento">Data Pagamento:</label>
                    <input type="date" id="data_pagamento" name="data_pagamento" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label for="note">Note:</label>
                <textarea id="note" name="note" placeholder="Dettagli aggiuntivi sul pagamento..."></textarea>
            </div>

            <button type="submit" class="btn-salva">Registra Pagamento</button>
        </form>
    </div>

    <div class="card">
        <h2>Storico Pagamenti Registrati</h2>
        <?php if (!empty($lista_pagamenti)): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Studio</th>
                            <th>Medico</th>
                            <th>Importo</th>
                            <th>Durata</th>
                            <th>Chirurgia Mano</th>
                            <th>Cardiologia</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_pagamenti as $pag): ?>
                            <?php 
                                $id_stu = $pag['studio_id'] ?? '';
                                $nome_stu_visualizzato = $id_stu ? ($mappa_studi[$id_stu] ?? ('Studio ' . substr($id_stu, 0, 8) . '...')) : 'N/D';

                                $id_med = $pag['medico_id'] ?? '';
                                $nome_med_visualizzato = $id_med ? ($mappa_medici[$id_med] ?? ('Medico ' . substr($id_med, 0, 8) . '...')) : '-';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pag['data_pagamento'] ?? ''); ?></td>
                                <td><strong><?php echo htmlspecialchars($nome_stu_visualizzato); ?></strong></td>
                                <td><?php echo htmlspecialchars($nome_med_visualizzato); ?></td>
                                <td>€ <?php echo number_format((float)($pag['importo'] ?? 0), 2, ',', '.'); ?></td>
                                <td><?php echo htmlspecialchars($pag['durata_mesi'] ?? 1); ?> mesi</td>
                                <td><?php echo !empty($pag['modulo_chirurgia_mano']) ? '✅ Attivo' : '❌'; ?></td>
                                <td><?php echo !empty($pag['modulo_cardiologia']) ? '✅ Attivo' : '❌'; ?></td>
                                <td><?php echo htmlspecialchars($pag['note'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color: #6c757d; font-style: italic; margin: 0;">Nessun pagamento registrato.</p>
        <?php endif; ?>
    </div>

</body>
</html>