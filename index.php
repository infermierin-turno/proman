<?php
// index.php - Pagina di Login con salvataggio abbonamento in sessione
session_start();

// Se l'utente è già loggato, reindirizza alla dashboard
if (isset($_SESSION['utente'])) {
    header("Location: dashboard.php");
    exit;
}

$errore = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($email) && !empty($password)) {
        // Inclusione del file di configurazione con le funzioni cURL di Supabase
        require_once 'config.php';

        // Interrogazione della tabella medici su Supabase filtrando per email
        $risultato = supabase_request("medici?email=eq." . urlencode($email) . "&select=*");

        if (!empty($risultato) && isset($risultato[0])) {
            $utente = $risultato[0];

            // Verifica della password confrontandola con 'password_hash' presente sul DB
            if (password_verify($password, $utente['password_hash'])) {
                // Controllo opzionale sul campo 'attivo' se presente
                if (isset($utente['attivo']) && $utente['attivo'] === false) {
                    $errore = "Utente disattivato. Contattare l'amministratore.";
                } else {
                    $studio_id = $utente['studio_id'] ?? null;
                    
                    // Salvataggio della sessione utente
                    $_SESSION['utente'] = $utente;
                    $_SESSION['ruolo'] = $utente['ruolo'] ?? 'medico';
                    $_SESSION['is_admin'] = !empty($utente['is_admin']) ? true : false;
                    $_SESSION['studio_id'] = $studio_id;

                    // VERIFICA ABBONAMENTO ESEGUITA UNA SOLA VOLTA AL LOGIN E MEMORIZZATA IN SESSIONE
                    if (function_exists('verifica_abbonamento')) {
                        $_SESSION['abbonamento'] = @verifica_abbonamento($studio_id);
                    } else {
                        $_SESSION['abbonamento'] = ['stato' => 'attivo', 'giorni' => 99, 'data_scadenza' => 'N/D'];
                    }

                    if (!isset($studio_id) && empty($utente['is_admin'])) {
                        $errore = "Errore di sistema: Nessuno studio associato all'account.";
                    } else {
                        header("Location: dashboard.php");
                        exit;
                    }
                }
            } else {
                $errore = "Password errata.";
            }
        } else {
            $errore = "Utente non trovato con questa email.";
        }
    } else {
        $errore = "Compila tutti i campi.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Studio Medico</title>
    <!-- Tailwind CSS per un design moderno e mobile-first -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen">

    <div class="w-full max-w-md p-6 bg-white rounded-xl shadow-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Studio Medico</h1>
            <p class="text-sm text-gray-500">Accedi al gestionale</p>
        </div>

        <?php if (!empty($errore)): ?>
            <div class="mb-4 p-3 text-sm text-red-700 bg-red-100 rounded-lg">
                <?php echo htmlspecialchars($errore); ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" required 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="nome@ospedale.it"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="••••••••">
            </div>

            <button type="submit" 
                    class="w-full py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition duration-200">
                Accedi
            </button>
        </form>
    </div>

</body>
</html>