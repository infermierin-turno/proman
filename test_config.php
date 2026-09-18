<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "1. Inizio test<br>";
$time_start = microtime(true);

require_once 'config.php';
echo "2. config.php caricato in " . round(microtime(true) - $time_start, 4) . " secondi<br>";

if (function_exists('verifica_abbonamento')) {
    echo "3. Eseguo verifica_abbonamento...<br>";
    $res = verifica_abbonamento(null);
    echo "4. Risultato verifica: <pre>";
    print_r($res);
    echo "</pre>";
} else {
    echo "3. La funzione verifica_abbonamento non esiste.<br>";
}