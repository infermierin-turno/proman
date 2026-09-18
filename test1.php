<?php
require_once 'config.php';
$test = supabase_request('pazienti?limit=1');
echo "<pre>";
print_r($test);
echo "</pre>";
?>