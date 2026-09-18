<?php
// aggiorna_password.php - Script temporaneo per impostare l'hash corretto
require_once 'config.php';

$email = 'medico@test.it';
$password_chiara = '123456';

// Genera l'hash nativo sicuro usando il motore PHP del server
$nuovo_hash = password_hash($password_chiara, PASSWORD_DEFAULT);

echo "Nuovo hash generato da PHP: <code>$nuovo_hash</code><br><br>";

// Prepara i dati per l'aggiornamento via API Supabase (PATCH)
$dati_aggiornamento = array(
    'password_hash' => $nuovo_hash
);

// Effettua la richiesta PATCH a Supabase filtrando per email
$risultato = supabase_request('medici?email=eq.' . urlencode($email), 'PATCH', $dati_aggiornamento);

if (isset($risultato['error'])) {
    echo "<p style='color:red;'>Errore durante l'aggiornamento su Supabase:</p>";
    echo "<pre>" . htmlspecialchars($risultato['message']) . "</pre>";
} else {
    echo "<p style='color:green; font-size:18px;'><strong>PASSWORD AGGIORNATA CON SUCCESSO SU SUPABASE!</strong></p>";
    echo "<p>Ora puoi eliminare questo file e testare il login su <a href='index.php'>index.php</a> con:</p>";
    echo "<ul><li>Email: <strong>medico@test.it</strong></li><li>Password: <strong>123456</strong></li></ul>";
}
?>