<?php
/**
 * Boussole — coffre-fort de documents (bulletins de paie, justificatifs, factures…).
 *
 * Chaque fichier est CHIFFRÉ sur le disque (libsodium, ou AES-256-GCM avec OpenSSL) avec une clé
 * créée à l'installation (data/secret.key). Cela protège contre une fuite du dossier des fichiers
 * (sauvegarde égarée, dossier exposé par erreur), pas contre quelqu'un qui contrôle le serveur.
 * Sauvegardez data/secret.key avec le reste : sans elle, les documents sont illisibles.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

/** id => [libellé, icône, durée de conservation (texte), années de conservation ou null, validité pour les démarches (mois) ou null, champs] */
function doc_types(): array {
    return [
        'paie' => ['Bulletin de paie', '💶', 'Jusqu’à la liquidation de la retraite', null, null, ['periode', 'net', 'net_imposable', 'brut', 'employeur']],
        'contrat_travail' => ['Contrat de travail / attestation employeur', '📝', 'Jusqu’à la liquidation de la retraite', null, null, []],
        'domicile' => ['Justificatif de domicile', '🏠', '5 ans s’il s’agit d’une facture ; valable 3 mois pour les démarches', 5, 3, []],
        'facture_energie' => ['Facture d’électricité, de gaz ou d’eau', '⚡', '5 ans', 5, 3, []],
        'facture_telecom' => ['Facture de téléphone ou d’internet', '📶', '5 ans', 5, 3, []],
        'quittance' => ['Quittance de loyer', '🔑', 'Durée de la location + 3 ans', null, 3, []],
        'bail' => ['Bail / état des lieux', '📄', 'Durée de la location + 3 ans', null, null, []],
        'impots' => ['Avis d’imposition', '🧾', 'Jusqu’au 31 décembre de la 2e année suivant la mise en recouvrement (gardez-le plus longtemps)', null, null, ['annee_revenus', 'revenu_fiscal']],
        'releve' => ['Relevé bancaire', '🏦', '5 ans', 5, null, []],
        'facture_achat' => ['Facture d’achat / garantie', '🛍️', '5 ans, et jusqu’à la fin de la garantie', 5, null, []],
        'assurance' => ['Contrat ou attestation d’assurance', '🛡️', '2 ans après la fin du contrat', null, 12, []],
        'sante' => ['Santé (décomptes, ordonnances)', '🩺', 'Décomptes de l’Assurance maladie : 2 ans', 2, null, []],
        'caf' => ['Attestation CAF, France Travail, Sécurité sociale', '🏛️', '5 ans', 5, 3, []],
        'pret' => ['Contrat de prêt', '🏦', '5 ans après la fin du prêt', null, null, []],
        'identite' => ['Pièce d’identité / titre de séjour', '🪪', 'Tant qu’elle est valide', null, null, ['expiration']],
        'vehicule' => ['Véhicule (carte grise, contrôle technique)', '🚗', 'Tant que vous avez le véhicule', null, null, ['expiration']],
        'dons' => ['Reçu fiscal de don', '🤝', 'Jusqu’au 31 décembre de la 3e année suivant le don (contrôle fiscal)', null, null, []],
        'autre' => ['Autre document', '📁', 'Selon le document', null, null, []],
    ];
}

/** Date jusqu'à laquelle conserver le document (null = longue durée / dépend de la situation). */
function doc_keep_until(string $type, ?string $docDate): ?string {
    $t = doc_types()[$type] ?? null;
    if ($t === null || $docDate === null || $docDate === '') return null;
    $y = (int)substr($docDate, 0, 4);
    if ($type === 'impots') return ($y + 2) . '-12-31';
    if ($type === 'dons') return ($y + 3) . '-12-31';
    if ($t[3] === null) return null;
    return date('Y-m-d', strtotime($docDate . ' +' . (int)$t[3] . ' years'));
}

/**
 * Tous les formats sont acceptés (PDF, photos, tableurs, archives, vidéos…), SAUF les programmes et scripts :
 * un double-clic sur le fichier téléchargé les exécuterait. Rien n'est jamais exécuté par le serveur : les fichiers
 * sont chiffrés, rangés hors du site web et renvoyés en téléchargement.
 */
const DOC_BLOCKED = ['exe', 'com', 'scr', 'pif', 'cpl', 'msi', 'msp', 'mst', 'bat', 'cmd', 'ps1', 'psm1', 'psd1', 'vbs', 'vbe', 'js', 'jse', 'mjs',
    'wsf', 'wsh', 'wsc', 'hta', 'jar', 'lnk', 'url', 'reg', 'dll', 'sys', 'drv', 'ocx', 'inf', 'scf', 'application', 'appref-ms', 'gadget', 'msc',
    'chm', 'apk', 'xll', 'iqy', 'slk', 'sh', 'bash', 'command', 'csh', 'ksh', 'run', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
    'pht', 'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'cgi', 'pl', 'htaccess', 'library-ms', 'settingcontent-ms', 'search-ms'];

/** Seuls ces formats s'affichent dans le navigateur, et seulement si le contenu correspond bien à l'extension. */
const DOC_INLINE = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'txt' => 'text/plain', 'csv' => 'text/plain', 'log' => 'text/plain'];

/** Type MIME d'après l'extension (utile quand l'extension PHP fileinfo n'est pas activée, fréquent sous Windows). */
function doc_mime_ext(string $ext): string {
    static $m = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'heic' => 'image/heic', 'heif' => 'image/heif', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'log' => 'text/plain', 'md' => 'text/markdown', 'rtf' => 'application/rtf',
        'htm' => 'text/html', 'html' => 'text/html', 'xml' => 'application/xml', 'json' => 'application/json', 'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook',
        'ofx' => 'application/x-ofx', 'qfx' => 'application/x-ofx', 'qif' => 'application/qif', 'sta' => 'text/plain', '940' => 'text/plain',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docm' => 'application/vnd.ms-word.document.macroEnabled.12',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'zip' => 'application/zip', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar', 'gz' => 'application/gzip', 'tar' => 'application/x-tar',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska', 'webm' => 'video/webm', 'epub' => 'application/epub+zip', 'pages' => 'application/vnd.apple.pages', 'numbers' => 'application/vnd.apple.numbers',
    ];
    return $m[$ext] ?? 'application/octet-stream';
}

/** Le contenu correspond-il vraiment au format annoncé ? (sinon : pas d'affichage dans le navigateur) */
function doc_magic_ok(string $ext, string $head): bool {
    switch ($ext) {
        case 'pdf': return strpos(substr($head, 0, 1024), '%PDF') !== false;
        case 'jpg': case 'jpeg': return starts($head, "\xFF\xD8\xFF");
        case 'png': return starts($head, "\x89PNG\r\n\x1A\n");
        case 'gif': return starts($head, 'GIF87a') || starts($head, 'GIF89a');
        case 'webp': return starts($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
        case 'txt': case 'csv': case 'log': return strpos(substr($head, 0, 4096), "\0") === false;
    }
    return false;
}

/** Vérifie un fichier envoyé. Rend [mime, ext] ou lance une exception avec un message clair. */
function doc_check_upload(string $tmp, string $name, int $size, int $max): array {
    if ($size <= 0) throw new RuntimeException('Fichier vide.');
    if ($size > $max) throw new RuntimeException('Fichier trop lourd (' . round($size / 1048576, 1) . ' Mo, maximum ' . round($max / 1048576) . ' Mo).');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, DOC_BLOCKED, true)) throw new RuntimeException('Les programmes et scripts (.' . $ext . ') ne sont pas acceptés : téléchargés puis ouverts d\'un double-clic, ils s\'exécuteraient.');
    $head = (string)file_get_contents($tmp, false, null, 0, 4096);
    if (starts($head, 'MZ') || starts($head, "\x7FELF") || starts($head, "\xCF\xFA\xED\xFE") || starts($head, "\xCA\xFE\xBA\xBE")) {
        throw new RuntimeException('Ce fichier est un programme déguisé (il porte l\'extension .' . $ext . ') : refusé par sécurité.');
    }
    if ($ext === 'pdf' && !doc_magic_ok('pdf', $head)) throw new RuntimeException('Ce fichier ne ressemble pas à un PDF (il est peut-être abîmé).');
    $mime = doc_mime_ext($ext);
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $m = $fi ? finfo_file($fi, $tmp) : false;          // pas de finfo_close() : inutile depuis PHP 8.1, déprécié en PHP 8.5
        if (is_string($m) && $m !== '' && $m !== 'application/octet-stream' && ($mime === 'application/octet-stream' || $m === $mime)) $mime = $m;
    }
    return [$mime, $ext];
}

/**
 * Comment renvoyer un document : [Content-Type, affichable dans le navigateur ?].
 * HTML, SVG, XML… partent toujours en téléchargement (ils pourraient contenir du code exécuté par la page).
 */
function doc_serve_type(string $fname, string $raw): array {
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    if (isset(DOC_INLINE[$ext]) && doc_magic_ok($ext, substr($raw, 0, 4096))) {
        $t = DOC_INLINE[$ext];
        return [$t . ($t === 'text/plain' ? '; charset=utf-8' : ''), true];
    }
    $mime = doc_mime_ext($ext);
    if (preg_match('#^(text/html|image/svg|application/xml|application/xhtml|text/xml|application/javascript|text/javascript)#', $mime)) $mime = 'application/octet-stream';
    return [$mime, false];
}
function doc_inline_ext(string $fname): bool { return isset(DOC_INLINE[strtolower(pathinfo($fname, PATHINFO_EXTENSION))]); }

/* ========================================================================== */
/* Chiffrement                                                                 */
/* ========================================================================== */

function vault_key(string $dataDir): string {
    $f = $dataDir . '/secret.key';
    if (!is_file($f)) {
        if (@file_put_contents($f, base64_encode(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('Impossible de créer la clé de chiffrement dans le dossier data.');
        @chmod($f, 0600);
    }
    $k = base64_decode(trim((string)file_get_contents($f)), true);
    if (!is_string($k) || strlen($k) !== 32) throw new RuntimeException('Clé de chiffrement illisible (data/secret.key).');
    return $k;
}
function vault_cipher(): string {
    if (function_exists('sodium_crypto_secretbox')) return 'libsodium (XSalsa20-Poly1305)';
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) return 'OpenSSL AES-256-GCM';
    return '';
}
function vault_encrypt(string $plain, string $key): string {
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'BSL1S' . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
    }
    if (function_exists('openssl_encrypt')) {
        $iv = random_bytes(12); $tag = '';
        $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($c === false) throw new RuntimeException('Échec du chiffrement.');
        return 'BSL1O' . $iv . $tag . $c;
    }
    throw new RuntimeException('Ni libsodium ni OpenSSL ne sont disponibles dans PHP : chiffrement impossible.');
}
function vault_decrypt(string $blob, string $key): string {
    $h = substr($blob, 0, 5);
    if ($h === 'BSL1S') {
        $nonce = substr($blob, 5, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $p = sodium_crypto_secretbox_open(substr($blob, 5 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($p === false) throw new RuntimeException('Document illisible (clé différente ou fichier abîmé).');
        return $p;
    }
    if ($h === 'BSL1O') {
        $p = openssl_decrypt(substr($blob, 33), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 5, 12), substr($blob, 17, 16));
        if ($p === false) throw new RuntimeException('Document illisible (clé différente ou fichier abîmé).');
        return $p;
    }
    throw new RuntimeException('Format de document inconnu.');
}

/* ========================================================================== */
/* Dossiers prêts à envoyer                                                    */
/* ========================================================================== */

/** id => [titre, explication, exigences [[types séparés par |, nombre (0 = facultatif), libellé, fraîcheur en jours ou null]], remarques] */
function dossiers(): array {
    return [
        'location' => ['Dossier de location', 'Les pièces qu’un bailleur peut demander (liste limitative du décret n° 2015-1437).', [
            ['identite', 1, 'Pièce d’identité en cours de validité', null],
            ['quittance|domicile|facture_energie|facture_telecom', 1, 'Justificatif de domicile (dernières quittances ou facture récente)', 92],
            ['contrat_travail', 1, 'Justificatif d’activité (contrat de travail ou attestation employeur)', null],
            ['paie', 3, 'Les 3 derniers bulletins de paie', 120],
            ['impots', 1, 'Dernier avis d’imposition', 550],
            ['caf', 0, 'Justificatif de prestations sociales (si vous en percevez)', 120],
        ], ['Un bailleur ne peut PAS exiger vos relevés de compte, une attestation de bonne tenue de compte, une autorisation de prélèvement, une attestation d’absence de crédit, un chèque de réservation ni votre carte Vitale (art. 22-2 de la loi du 6 juillet 1989).', 'Le service gratuit DossierFacile (dossierfacile.logement.gouv.fr) certifie votre dossier.']],
        'credit' => ['Demande de prêt', 'Ce que les banques demandent le plus souvent pour étudier un crédit.', [
            ['identite', 1, 'Pièce d’identité', null],
            ['quittance|domicile|facture_energie|facture_telecom', 1, 'Justificatif de domicile de moins de 3 mois', 92],
            ['contrat_travail', 1, 'Contrat de travail ou attestation employeur', null],
            ['paie', 3, 'Les 3 derniers bulletins de paie', 120],
            ['impots', 2, 'Les 2 derniers avis d’imposition', 900],
            ['releve', 3, 'Les 3 derniers relevés de compte', 120],
            ['pret', 0, 'Contrats des prêts en cours (s’il y en a)', null],
        ], ['Avant de déposer : 3 mois de relevés sans incident ni jeux d’argent pèsent plus lourd qu’un bon salaire.', 'Comparez le TAEG (coût total), pas seulement le taux nominal.']],
        'caf' => ['Demande d’aide (CAF, prime d’activité, aides au logement)', 'Pièces utiles pour une demande ou une mise à jour.', [
            ['identite', 1, 'Pièce d’identité ou titre de séjour', null],
            ['paie', 3, 'Les 3 derniers bulletins de paie', 120],
            ['impots', 1, 'Dernier avis d’imposition', 550],
            ['bail|quittance', 1, 'Bail ou quittance de loyer (aides au logement)', null],
        ], ['Simulez d’abord vos droits sur mesdroitssociaux.gouv.fr : beaucoup de foyers ne demandent pas des aides auxquelles ils ont droit.']],
        'impots' => ['Déclaration de revenus', 'Pour vérifier la déclaration pré-remplie au printemps.', [
            ['paie', 1, 'Bulletin de décembre (cumul net imposable de l’année)', 200],
            ['impots', 1, 'Avis d’imposition de l’an dernier', 550],
            ['dons', 0, 'Reçus fiscaux de dons', 500],
        ], ['Comparez le net imposable de décembre au montant pré-rempli : une erreur de l’employeur se corrige vous-même.', 'Dons, frais de garde, emploi à domicile : ce sont des réductions ou crédits d’impôt à déclarer.']],
    ];
}

/** État d'un dossier : pour chaque exigence, les documents retenus et leur statut (ok / ancien / manquant / facultatif). */
function dossier_status(string $id, array $docs, string $today): ?array {
    $D = dossiers()[$id] ?? null;
    if ($D === null) return null;
    $types = doc_types();
    $req = [];
    $ready = true;
    foreach ($D[2] as [$tlist, $n, $label, $fresh]) {
        $ok = explode('|', $tlist);
        $cands = array_values(array_filter($docs, function ($d) use ($ok) { return in_array($d['type'], $ok, true); }));
        usort($cands, function ($a, $b) { return strcmp((string)($b['period'] ?: $b['doc_date']), (string)($a['period'] ?: $a['doc_date'])); });
        $want = max(1, $n);
        $picked = array_slice($cands, 0, $want);
        $status = 'ok'; $note = '';
        if (count($picked) < $n) { $status = 'manquant'; $note = count($picked) . ' sur ' . $n; }
        foreach ($picked as $d) {
            $ref = (string)($d['period'] ? $d['period'] . '-28' : $d['doc_date']);
            if ($fresh !== null && $ref !== '' && days_between($ref, $today) > $fresh && $status === 'ok') { $status = 'ancien'; $note = 'document de plus de ' . round($fresh / 30) . ' mois'; }
            if (!empty($d['expiry']) && $d['expiry'] < $today) { $status = 'ancien'; $note = 'pièce expirée'; }
        }
        if ($n === 0 && !$picked) $status = 'facultatif';
        if ($status === 'manquant' || $status === 'ancien') $ready = false;
        $req[] = ['types' => $ok, 'libelle' => $label, 'nombre' => $n, 'statut' => $status, 'note' => $note,
            'docs' => array_map(function ($d) use ($types) { return ['id' => (int)$d['id'], 'titre' => $d['title'], 'date' => $d['period'] ?: $d['doc_date'], 'type' => $types[$d['type']][0] ?? $d['type']]; }, $picked)];
    }
    return ['id' => $id, 'titre' => $D[0], 'explication' => $D[1], 'exigences' => $req, 'remarques' => $D[3], 'complet' => $ready];
}
