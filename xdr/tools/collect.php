<?php
/*
 * Sentinelle XDR — collecte en ligne de commande (alternative à l'appel HTTP de la tâche planifiée).
 *   php tools\collect.php
 * Si vous l'utilisez dans une tâche planifiée, le compte de la tâche doit pouvoir écrire dans data\.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    ensure_data_dir();
    $r = Collector::run(Network::make(), 'cron');
    fwrite(STDOUT, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
