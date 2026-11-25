<?php
/*
 * Sentinelle XDR — outil en ligne de commande.
 *
 *   php tools\setup.php --check        vérifie l'installation (extensions, dossier de données)
 *   php tools\setup.php --password     crée ou remplace le mot de passe administrateur
 *   php tools\setup.php --reset-2fa    désactive la double authentification (téléphone perdu)
 *   php tools\setup.php --cron-key     affiche la clé de la tâche planifiée (la crée si besoin)
 *   php tools\setup.php --demo-reset   remet la démonstration à zéro
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

function out(string $s = ''): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function ask(string $question, bool $secret = false): string
{
    fwrite(STDOUT, $question);
    $unix = $secret && PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');
    if ($unix) @shell_exec('stty -echo');
    $line = fgets(STDIN);
    if ($unix) {
        @shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim((string)$line, "\r\n");
}

$args = array_slice($argv, 1);
try {
    ensure_data_dir();
    if (in_array('--cron-key', $args, true)) {
        if (Db::kv('cron_key') === null) Db::setKv('cron_key', bin2hex(random_bytes(16)));
        out((string)Db::kv('cron_key'));
        exit(0);
    }
    if (in_array('--check', $args, true) || $args === []) {
        out('Sentinelle XDR ' . XDR_VERSION . ' — PHP ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ')');
        foreach (['curl', 'pdo_sqlite', 'openssl', 'mbstring', 'simplexml'] as $ext) {
            out(sprintf('  %-12s %s', $ext, extension_loaded($ext) ? 'OK' : 'MANQUANTE — php.ini : extension=' . $ext));
        }
        out('  données      ' . data_dir() . (is_writable(data_dir()) ? ' (écriture OK)' : ' (PAS accessible en écriture)'));
        out('  administrateur ' . (Auth::needsSetup() ? 'pas encore créé' : 'créé'));
        if ($args !== []) exit(0);
    }
    if (in_array('--password', $args, true) || ($args === [] && Auth::needsSetup())) {
        out();
        out('Choix du mot de passe administrateur (10 caractères minimum).');
        if (PHP_OS_FAMILY === 'Windows') out('Attention : sous Windows, la saisie reste visible à l\'écran.');
        $a = ask('Mot de passe : ', true);
        $b = ask('Confirmation : ', true);
        if ($a !== $b) throw new RuntimeException('Les deux saisies diffèrent.');
        Auth::checkPolicy($a);
        $admin = Auth::admin() ?? ['created' => time(), 'totp' => null];
        $admin['hash'] = password_hash($a, PASSWORD_DEFAULT);
        $admin['pw_changed'] = time();
        write_json(data_path('admin.json'), $admin);
        Db::audit('password_change', '', true, 'depuis la console du serveur');
        out('Mot de passe enregistré. Les sessions ouvertes sont déconnectées.');
    }
    if (in_array('--reset-2fa', $args, true)) {
        $admin = Auth::admin();
        if ($admin === null) throw new RuntimeException('Aucun administrateur.');
        $admin['totp'] = null;
        write_json(data_path('admin.json'), $admin);
        Db::audit('totp_disable', '', true, 'depuis la console du serveur');
        out('Double authentification désactivée. Réactivez-la depuis Paramètres › Sécurité.');
    }
    if (in_array('--demo-reset', $args, true)) {
        DemoBox::reset();
        foreach (['devices', 'alerts', 'events', 'traffic', 'dev_traffic', 'schedules'] as $t) Db::q("DELETE FROM $t");
        Db::setKv('collect_last', '0');
        out('Démonstration remise à zéro.');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
