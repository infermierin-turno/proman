<?php
// nuovo_utente.php - Registrazione Nuovo Utente / Medico
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}

require_once 'config.php';

$lista_studi = supabase_request('studi', 'GET'); 

if (!is_array($lista_studi)) {
    $lista_studi = [];
}

$messaggio = '';
$debug_info = '';
$successo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titolo = trim($_POST['titolo'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password_chiara = $_POST['password'] ?? '';
    $attivo = isset($_POST['attivo']) ? true : false;
    $is_admin = isset($_POST['is_admin']) ? true : false; 
    $ruolo = trim($_POST['ruolo'] ?? 'medico');
    $matricola = trim($_POST['matricola'] ?? '');
    $studio_id = $_POST['studio_id'] ?? null;

    if (empty($cognome) || empty($nome) || empty($email) || empty($password_chiara) || empty($studio_id)) {
        $messaggio = "Compilare tutti i campi obbligatori (Cognome, Nome, Email, Password e Studio).";
    } else {
        $password_hash = password_hash($password_chiara, PASSWORD_DEFAULT);

        $dati_utente = [
            'studio_id' => $studio_id,
            'titolo' => $titolo,
            'cognome' => $cognome,
            'nome' => $nome,
            'email' => $email,
            'password_hash' => $password_hash,
            'attivo' => $attivo,
            'is_admin' => $is_admin,
            'ruolo' => $ruolo,
            'matricola' => $matricola
        ];

        $risultato = supabase_request('medici', 'POST', $dati_utente);

        if (isset($risultato['error'])) {
            $debug_info = "Errore Inserimento Utente: " . json_encode($risultato);
        } else {
            $successo = "Utente inserito con successo!";
            $_POST = [];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuovo Utente - Proman</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 10px; background-color: #f8f9fa; color: #333; }
        .container { max-width: 700px; margin: 0 auto; background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { font-size: 1.4em; margin-top: 0; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; font-size: 0.95em; }
        input[type="text"], input[type="email"], input[type="password"], select { 
            width: 100%; 
            padding: 10px; 
            border: 1px solid #ccc; 
            border-radius: 4px; 
            font-size: 16px; 
            background-color: #fff;
        }
        .checkbox-group { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .checkbox-group input { width: 20px; height: 20px; }
        fieldset { border: 1px solid #ced4da; padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        legend { font-weight: bold; color: #007bff; padding: 0 5px; font-size: 1em; }
        button { background-color: #007bff; color: white; padding: 14px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; font-weight: bold; }
        button:hover { background-color: #0056b3; }
        .error { background-color: #f8d7da; color: #721c24; padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9em; }
        .success { background-color: #d4edda; color: #155724; padding: 10px; margin-bottom: 15px; border-radius: 4px; font-size: 0.9em; }
        a { color: #007bff; text-decoration: none; display: inline-block; margin-top: 15px; font-size: 0.95em; }
        a:hover { text-decoration: underline; }
        .required-mark { color: red; }
    </style>
</head>
<body>
<div class="container">
    <h2>Registrazione Nuovo Utente</h2>
    <?php if (!empty($messaggio)): ?><div class="error"><?php echo htmlspecialchars($messaggio); ?></div><?php endif; ?>
    <?php if (!empty($successo)): ?><div class="success"><?php echo htmlspecialchars($successo); ?></div><?php endif; ?>
    <?php if (!empty($debug_info)): ?><div class="error"><strong>DEBUG:</strong><pre><?php echo htmlspecialchars($debug_info); ?></pre></div><?php endif; ?>

    <form method="POST">
        <fieldset>
            <legend>Dati Studio</legend>
            <div class="form-group">
                <label>Seleziona Studio: <span class="required-mark">*</span></label>
                <select name="studio_id" required>
                    <option value="">-- Seleziona Studio --</option>
                    <?php foreach ($lista_studi as $studio): ?>
                        <option value="<?php echo htmlspecialchars($studio['id'] ?? ''); ?>">
                            <?php echo htmlspecialchars($studio['nome_studio'] ?? ''); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </fieldset>

        <fieldset>
            <legend>Dati Utente</legend>
            
            <div class="form-group">
                <label>Titolo:</label>
                <select name="titolo">
                    <option value="Dr.">Dr.</option>
                    <option value="Dott.ssa">Dott.ssa</option>
                    <option value="Prof.">Prof.</option>
                    <option value="Signora.">Sig.ra</option>
                    <option value="Prof.ssa">Prof.ssa</option>
                    <option value="Signore">Sig.re</option>
                    <option value="Infermiere">Infermiere</option>
                </select>
            </div>

            <div class="form-group"><label>Cognome: <span class="required-mark">*</span></label><input type="text" name="cognome" value="<?php echo htmlspecialchars($_POST['cognome'] ?? ''); ?>" required></div>
            <div class="form-group"><label>Nome: <span class="required-mark">*</span></label><input type="text" name="nome" value="<?php echo htmlspecialchars($_POST['nome'] ?? ''); ?>" required></div>
            <div class="form-group"><label>Email: <span class="required-mark">*</span></label><input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></div>
            <div class="form-group"><label>Password: <span class="required-mark">*</span></label><input type="password" name="password" required></div>
            <div class="form-group"><label>Ruolo:</label>
                <select name="ruolo">
                    <option value="medico">Medico</option>
                    <option value="segretario">Segretario</option>
                </select>
            </div>
            <div class="checkbox-group"><input type="checkbox" name="is_admin" value="1"> <label>Privilegi Admin</label></div>
        </fieldset>
        <button type="submit">Registra</button>
    </form>
    <p><a href="dashboard.php">&larr; Dashboard</a></p>
</div>
</body>
</html>