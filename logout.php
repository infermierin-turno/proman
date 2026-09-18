<?php
// logout.php - Disconnessione e chiusura sessione utente
session_start();

// Svuota tutte le variabili di sessione
$_SESSION = array();

// Distrugge la sessione sul server
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

// Reindirizza alla pagina di login
header("Location: index.php");
exit;
?>