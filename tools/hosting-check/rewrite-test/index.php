<?php
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
$viaRewrite = (strpos($uri, 'index.php') === false);
echo "Angefragte Adresse: " . htmlspecialchars($uri, ENT_QUOTES, 'UTF-8') . "\n";
echo $viaRewrite
    ? "REWRITE OK: Die Adresse wurde ohne index.php an das Skript weitergeleitet.\n"
    : "Direkter Aufruf von index.php (kein Rewrite-Test). Rufe stattdessen .../rewrite-test/api/ping auf.\n";
echo "Diesen Ordner danach loeschen.\n";
