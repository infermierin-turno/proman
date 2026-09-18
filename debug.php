<?php
require_once 'config.php';

// Testiamo un endpoint semplice
$risposta = supabase_request('pazienti?limit=1');

echo "<h1>Diagnostica Supabase</h1>";
if (isset($risposta['error'])) {
    echo "<h2 style='color:red;'>ERRORE RILEVATO:</h2>";
    echo "<pre>" . print_r($risposta, true) . "</pre>";
    echo "<p><strong>Cosa significa:</strong> Il server Tophost raggiunge Supabase, ma Supabase rifiuta la richiesta.</p>";
    echo "<p><strong>Possibile causa:</strong> Tabella errata o RLS (Policies) attive.</p>";
} else {
    echo "<h2 style='color:green;'>CONNESSIONE OK!</h2>";
    echo "<pre>" . print_r($risposta, true) . "</pre>";
}
?>