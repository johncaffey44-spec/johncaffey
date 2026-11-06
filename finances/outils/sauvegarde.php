<?php
/**
 * Boussole — sauvegarde complète : base de données, clé de chiffrement et documents (déjà chiffrés).
 * -----------------------------------------------------------------------------
 * En ligne de commande uniquement (tâche planifiée Windows ou cron) :
 *
 *   php outils/sauvegarde.php --vers="D:\Sauvegardes\Boussole" [--garder=30] [--donnees="C:\BoussoleDonnees"] [--sans-cle]
 *
 * Chaque exécution crée un dossier « boussole-AAAAMMJJ-HHMMSS » contenant :
 *   boussole.sqlite   copie cohérente de la base, même si quelqu'un utilise l'application au même moment
 *   secret.key        la clé qui déchiffre les documents (sauf avec --sans-cle : gardez-la alors ailleurs)
 *   vault\            les documents, toujours chiffrés
 *   LISEZ-MOI-RESTAURATION.txt
 * puis supprime les plus anciennes pour n'en garder que --garder (30 par défaut).
 *
 * Les documents identiques à ceux de la sauvegarde précédente ne sont pas recopiés : ils sont partagés par
 * « lien physique » (NTFS, ext4…) et n'occupent la place qu'une fois. Sur une clé USB en FAT32/exFAT, ils sont copiés.
 * Code de sortie : 0 = réussite, 1 = échec, 2 = mauvaise utilisation.
 * -----------------------------------------------------------------------------
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const MARQUE = 'LISEZ-MOI-RESTAURATION.txt';   // écrit en dernier : sa présence signifie « sauvegarde complète »

function dire(string $m): void { fwrite(STDOUT, $m . PHP_EOL); }
function arreter(string $m, int $code = 1): void { fwrite(STDERR, 'ERREUR : ' . $m . PHP_EOL); exit($code); }
function taille_lisible(float $o): string {
    foreach (['octets', 'Ko', 'Mo', 'Go'] as $i => $u) if ($o < 1024 || $i === 3) return ($i ? number_format($o, 1, ',', ' ') : (string)(int)$o) . ' ' . $u; else $o /= 1024;
    return '';
}
/** Supprime un dossier de sauvegarde (et seulement s'il se trouve bien dans $racine). */
function supprimer_dossier(string $dir, string $racine): void {
    $real = realpath($dir); $root = realpath($racine);
    if ($real === false || $root === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        if ($f->isLink() || !$f->isDir()) @unlink($p); else @rmdir($p);
    }
    @rmdir($real);
}

/* ------------------------------------------------------------ paramètres */
$opts = ['vers' => '', 'garder' => '30', 'donnees' => '', 'sans-cle' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) || !array_key_exists($m[1], $opts)) arreter('option inconnue « ' . $arg . ' ». Usage : php outils/sauvegarde.php --vers=DOSSIER [--garder=30] [--donnees=DOSSIER] [--sans-cle]', 2);
    $opts[$m[1]] = isset($m[2]) ? trim($m[2], " \"'") : true;
}
$APP = dirname(__DIR__);
$DATA_DIR = $APP . '/data';
if (is_file($APP . '/config.local.php')) require $APP . '/config.local.php';   // $DATA_DIR choisi à l'installation
if ($opts['donnees'] !== '') $DATA_DIR = (string)$opts['donnees'];
$DATA_DIR = rtrim(str_replace('\\', '/', (string)$DATA_DIR), '/');
$racine = rtrim(str_replace('\\', '/', (string)$opts['vers']), '/');
$garder = (int)$opts['garder'];
if ($racine === '') arreter('indiquez le dossier de destination : --vers="D:\\Sauvegardes\\Boussole"', 2);
if ($garder < 1 || $garder > 3650) arreter('--garder doit être un nombre entre 1 et 3650.', 2);
$base = $DATA_DIR . '/boussole.sqlite';
if (!is_file($base)) arreter('base introuvable : ' . $base . ' (l’application a-t-elle déjà été ouverte une première fois ? sinon précisez --donnees=…)');
if (!extension_loaded('pdo_sqlite')) arreter('l’extension PHP pdo_sqlite n’est pas activée pour la ligne de commande (php.ini).');
$creee = !is_dir($racine);
if ($creee && !@mkdir($racine, 0700, true)) arreter('impossible de créer le dossier ' . $racine);
$racineReelle = (string)realpath($racine);
$donneesReelles = (string)realpath($DATA_DIR);
if (strpos(strtolower($racineReelle . DIRECTORY_SEPARATOR), strtolower($donneesReelles . DIRECTORY_SEPARATOR)) === 0) {
    if ($creee) @rmdir($racine);
    arreter('la destination ne peut pas se trouver DANS le dossier des données.', 2);
}
@date_default_timezone_set(isset($TIMEZONE) ? (string)$TIMEZONE : 'Europe/Paris') or date_default_timezone_set('Europe/Paris');

/* ------------------------------------------------------------ sauvegarde */
$debut = microtime(true);
$nom = 'boussole-' . date('Ymd-His');
$dest = $racine . '/' . $nom;
for ($i = 2; file_exists($dest); $i++) $dest = $racine . '/' . $nom . '-' . $i;
$journal = $racine . '/sauvegardes.log';
try {
    if (!@mkdir($dest . '/vault', 0700, true)) throw new RuntimeException('impossible de créer ' . $dest);

    // 1. base : VACUUM INTO donne une copie cohérente et compacte ; repli : copie du fichier sous verrou d'écriture
    $db = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 20000');
    $copie = $dest . '/boussole.sqlite';
    try {
        $db->prepare('VACUUM INTO ?')->execute([$copie]);
        $methode = 'VACUUM INTO';
    } catch (PDOException $e) {
        @unlink($copie);
        $db->exec('BEGIN IMMEDIATE');
        $ok = @copy($base, $copie);
        $db->exec('COMMIT');
        if (!$ok) throw new RuntimeException('copie de la base impossible');
        $methode = 'copie sous verrou';
    }
    $chk = new PDO('sqlite:' . $copie, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $integ = (string)$chk->query('PRAGMA integrity_check')->fetchColumn();
    if ($integ !== 'ok') throw new RuntimeException('la copie de la base est abîmée (' . $integ . ')');
    $compte = function (string $t) use ($chk): int { try { return (int)$chk->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn(); } catch (PDOException $e) { return 0; } };
    $nUsers = $compte('users'); $nTx = $compte('tx'); $nDocs = $compte('docs');
    $chk = null;

    // 2. clé de chiffrement
    $cle = $DATA_DIR . '/secret.key';
    $avecCle = !$opts['sans-cle'] && is_file($cle);
    if ($avecCle && !@copy($cle, $dest . '/secret.key')) throw new RuntimeException('copie de secret.key impossible');

    // 3. documents chiffrés (fichiers jamais modifiés : on réutilise ceux de la sauvegarde précédente quand ils sont identiques)
    $precedente = null;
    $dirs = glob($racine . '/boussole-*', GLOB_ONLYDIR) ?: [];
    rsort($dirs);
    foreach ($dirs as $d) if ($d !== $dest && is_file($d . '/' . MARQUE)) { $precedente = $d; break; }
    $nFich = 0; $nLiens = 0; $octets = 0;
    foreach (glob($DATA_DIR . '/vault/*.bin') ?: [] as $f) {
        $b = basename($f);
        $cible = $dest . '/vault/' . $b;
        $taille = (int)filesize($f);
        $octets += $taille;
        $nFich++;
        $ancien = $precedente !== null ? $precedente . '/vault/' . $b : null;
        if ($ancien !== null && is_file($ancien) && filesize($ancien) === $taille && hash_file('sha256', $ancien) === hash_file('sha256', $f) && @link($ancien, $cible)) { $nLiens++; continue; }
        if (!@copy($f, $cible)) throw new RuntimeException('copie impossible : ' . $b);
        if (filesize($cible) !== $taille) throw new RuntimeException('copie incomplète : ' . $b);
    }

    // 4. notice de restauration, écrite en dernier
    $sansCle = $avecCle ? '' : "\r\nATTENTION : cette sauvegarde ne contient PAS secret.key (option --sans-cle ou clé absente).\r\nSans cette clé, les documents ne peuvent pas être déchiffrés : gardez-en une copie à part.\r\n";
    $affiche = str_replace('/', DIRECTORY_SEPARATOR, $DATA_DIR);
    $notice = "BOUSSOLE — sauvegarde du " . date('d/m/Y à H:i') . "\r\n"
        . "Données d'origine : " . $affiche . "\r\n"
        . "Contenu : " . $nUsers . " compte(s), " . $nTx . " opération(s), " . $nDocs . " document(s) (" . taille_lisible($octets) . "), base copiée par " . $methode . ".\r\n"
        . $sansCle
        . "\r\nCE DOSSIER CONTIENT TOUTES VOS DONNÉES FINANCIÈRES : rangez-le sur un disque chiffré (BitLocker / BitLocker To Go).\r\n"
        . "\r\nRESTAURER (après une panne, une réinstallation ou une erreur) :\r\n"
        . "  1. Arrêtez le site : dans une invite de commandes administrateur, tapez  iisreset /stop\r\n"
        . "  2. Copiez boussole.sqlite, secret.key et le dossier vault de cette sauvegarde dans le dossier des données\r\n"
        . "     (" . $affiche . "), en remplaçant les fichiers existants.\r\n"
        . "  3. Redémarrez le site :  iisreset /start  puis ouvrez Boussole et connectez-vous.\r\n"
        . "  4. Si les documents ne s'ouvrent pas (« Document illisible »), c'est que secret.key ne correspond pas : reprenez celle de la même sauvegarde.\r\n";
    if (file_put_contents($dest . '/' . MARQUE, "\xEF\xBB\xBF" . $notice) === false) throw new RuntimeException('écriture de la notice impossible');
} catch (Throwable $e) {
    supprimer_dossier($dest, $racine);                  // jamais de sauvegarde à moitié faite
    @file_put_contents($journal, date('Y-m-d H:i:s') . ' ÉCHEC ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    try { $w = new PDO('sqlite:' . $base); $w->exec('PRAGMA busy_timeout = 20000'); $w->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('sauvegarde_echec', ?)")->execute([json_encode(['t' => time(), 'erreur' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e2) { /* base inaccessible : le journal suffit */ }
    arreter($e->getMessage());
}

/* ------------------------------------------------------------ rotation : on ne garde que les N plus récentes */
$completes = [];
foreach (glob($racine . '/boussole-*', GLOB_ONLYDIR) ?: [] as $d) {
    if (!preg_match('/^boussole-\d{8}-\d{6}(-\d+)?$/', basename($d))) continue;
    if (is_file($d . '/' . MARQUE)) $completes[] = $d;
    elseif ($d !== $dest && filemtime($d) < time() - 86400) supprimer_dossier($d, $racine);   // restes d'une coupure de courant
}
rsort($completes);
$supprimees = 0;
foreach (array_slice($completes, $garder) as $d) { supprimer_dossier($d, $racine); $supprimees++; }

$duree = round(microtime(true) - $debut, 1);
$resume = $nom . ' : ' . $nUsers . ' compte(s), ' . $nTx . ' opération(s), ' . $nDocs . ' document(s) dont ' . $nFich . ' fichier(s) chiffré(s) ('
    . taille_lisible($octets) . ($nLiens ? ', ' . $nLiens . ' repris de la sauvegarde précédente sans recopie' : '') . ')' . ($avecCle ? '' : ', SANS la clé') . ' en ' . $duree . ' s';
@file_put_contents($journal, date('Y-m-d H:i:s') . ' OK ' . $resume . ($supprimees ? ' ; ' . $supprimees . ' ancienne(s) supprimée(s)' : '') . PHP_EOL, FILE_APPEND);
try {
    $w = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $w->exec('PRAGMA busy_timeout = 20000');
    $w->prepare("INSERT OR REPLACE INTO meta (k, v) VALUES ('sauvegarde', ?)")->execute([json_encode(['t' => time(), 'dossier' => $dest, 'docs' => $nDocs, 'octets' => $octets, 'cle' => $avecCle, 'garder' => $garder, 'nb' => min(count($completes), $garder)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $w->exec("DELETE FROM meta WHERE k = 'sauvegarde_echec'");
} catch (Throwable $e) { dire('(note : date de sauvegarde non enregistrée dans la base : ' . $e->getMessage() . ')'); }
dire('Sauvegarde réussie : ' . $resume . '.');
dire('Dossier : ' . str_replace('/', DIRECTORY_SEPARATOR, $dest));
if ($supprimees) dire($supprimees . ' sauvegarde(s) plus ancienne(s) supprimée(s) (on en garde ' . $garder . ').');
exit(0);
