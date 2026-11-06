<?php
/**
 * Boussole — envoi des e-mails (alertes, lien « mot de passe oublié », sécurité).
 *
 * Deux façons d'envoyer :
 *   - « php »  : la fonction mail() de l'hébergeur (OVH, o2switch, IONOS… la proposent d'origine) ;
 *   - « smtp » : un serveur SMTP (celui de l'hébergeur, Gmail avec mot de passe d'application, Brevo…).
 * Les adresses Outlook.com / Hotmail / Live peuvent RECEVOIR les alertes, mais plus les ENVOYER par
 * mot de passe : Microsoft impose OAuth pour ces comptes depuis le 16 septembre 2024.
 *
 * Le mot de passe SMTP est chiffré avec la clé du coffre (data/secret.key).
 * Les messages ne contiennent jamais de lien de connexion automatique ni de mot de passe.
 */
if (!defined('BOUSSOLE')) { http_response_code(403); exit; }

/** Préréglages proposés dans l'interface. */
function mail_presets(): array {
    return [
        'php' => ['Fonction mail() de l’hébergeur', ['mode' => 'php']],
        'hebergeur' => ['SMTP de l’hébergeur (OVH, o2switch, IONOS…)', ['mode' => 'smtp', 'host' => '', 'port' => 465, 'secure' => 'ssl']],
        'gmail' => ['Gmail (mot de passe d’application)', ['mode' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'secure' => 'tls']],
        'brevo' => ['Brevo (relais gratuit)', ['mode' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'secure' => 'tls']],
        'autre' => ['Autre serveur SMTP', ['mode' => 'smtp']],
    ];
}

function clean_header(string $s): string { return trim((string)preg_replace('/[\r\n\0]+/', ' ', $s)); }
function valid_email(string $e): bool { return strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e); }
function mime_word(string $s): string { return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?='; }

/** Construit un message MIME (texte + HTML). Rend [en-têtes sans To/Subject, corps, message complet pour SMTP]. */
function mail_build(string $from, string $fromName, string $to, string $subject, string $text, ?string $html): array {
    $from = clean_header($from); $to = clean_header($to);
    $domain = substr(strrchr($from, '@') ?: '@boussole.local', 1);
    $b = 'bsl-' . bin2hex(random_bytes(12));
    $h = [
        'From: ' . mime_word(clean_header($fromName)) . ' <' . $from . '>',
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . preg_replace('/[^A-Za-z0-9.-]/', '', $domain) . '>',
        'MIME-Version: 1.0',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
        'X-Mailer: Boussole',
    ];
    $part = function (string $type, string $body): string {
        return "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . rtrim(chunk_split(base64_encode($body), 76, "\r\n")) . "\r\n";
    };
    if ($html !== null) {
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
        $body = "--$b\r\n" . $part('text/plain', $text) . "--$b\r\n" . $part('text/html', $html) . "--$b--\r\n";
    } else {
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $body = rtrim(chunk_split(base64_encode($text), 76, "\r\n")) . "\r\n";
    }
    $full = implode("\r\n", array_merge(['To: <' . $to . '>', 'Subject: ' . mime_word(clean_header($subject))], $h)) . "\r\n\r\n" . $body;
    return [implode("\r\n", $h), $body, $full];
}

/**
 * Envoie un message. $cfg : mode, from, from_name, host, port, secure (tls|ssl|none), user, pass (en clair, déjà déchiffré).
 * Rend null si l'envoi est accepté, sinon un message d'erreur compréhensible.
 */
function mail_send(array $cfg, string $to, string $subject, string $text, ?string $html = null): ?string {
    $from = (string)($cfg['from'] ?? '');
    if (!valid_email($to)) return 'Adresse du destinataire invalide.';
    if (!valid_email($from)) return 'Adresse d’expédition invalide : renseignez-la dans Réglages › Envoi des e-mails.';
    [$headers, $body, $full] = mail_build($from, (string)($cfg['from_name'] ?? 'Boussole') ?: 'Boussole', $to, $subject, $text, $html);
    if (($cfg['mode'] ?? '') === 'php') {
        if (!function_exists('mail')) return 'La fonction mail() est désactivée sur ce serveur : utilisez un serveur SMTP.';
        $ok = @mail($to, mime_word(clean_header($subject)), $body, $headers, '-f' . $from);
        if (!$ok) $ok = @mail($to, mime_word(clean_header($subject)), $body, $headers);
        return $ok ? null : 'Le serveur a refusé l’envoi par mail(). Votre hébergeur ne le permet peut-être pas : utilisez son serveur SMTP.';
    }
    if (($cfg['mode'] ?? '') === 'smtp') return smtp_send($cfg, $from, $to, $full);
    return 'L’envoi des e-mails n’est pas configuré.';
}

function smtp_send(array $cfg, string $from, string $to, string $data): ?string {
    $host = clean_header((string)($cfg['host'] ?? ''));
    $port = (int)($cfg['port'] ?? 587);
    $secure = (string)($cfg['secure'] ?? 'tls');
    if ($host === '' || $port <= 0) return 'Serveur SMTP non renseigné.';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return smtp_hint('Connexion impossible à ' . $host . ':' . $port . ' (' . ($errstr ?: 'délai dépassé') . ')', $cfg);
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $r = '';
        while (($l = fgets($fp, 2048)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function (?string $line, array $ok) use ($fp, $read): string {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException(trim($r) !== '' ? trim($r) : 'pas de réponse du serveur');
        return $r;
    };
    try {
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        $cmd(null, [220]);
        $ehlo = $cmd('EHLO ' . $me, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $method)) throw new RuntimeException('échec du chiffrement TLS (certificat refusé ?)');
            $ehlo = $cmd('EHLO ' . $me, [250]);
        }
        $user = (string)($cfg['user'] ?? ''); $pass = (string)($cfg['pass'] ?? '');
        if ($user !== '') {
            if (stripos($ehlo, 'LOGIN') !== false) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334]);
                $cmd(base64_encode($pass), [235]);
            } else {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), [235]);
            }
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = (string)preg_replace('/^\./m', '..', $data);
        fwrite($fp, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
        $cmd(null, [250]);
        try { $cmd('QUIT', [221]); } catch (RuntimeException $e) { /* le message est déjà accepté */ }
    } catch (RuntimeException $e) {
        @fclose($fp);
        return smtp_hint($e->getMessage(), $cfg);
    }
    fclose($fp);
    return null;
}

/** Traduit les refus SMTP courants en conseils concrets. */
function smtp_hint(string $r, array $cfg): string {
    $host = strtolower((string)($cfg['host'] ?? ''));
    $tip = '';
    if (preg_match('/outlook|hotmail|live\.|office365/', $host) || preg_match('/basic auth|BasicAuth|5\.7\.139/i', $r)) {
        $tip = ' Microsoft n’accepte plus l’envoi par mot de passe pour les adresses Outlook, Hotmail et Live (OAuth obligatoire depuis septembre 2024) : envoyez depuis l’adresse de votre hébergeur, Gmail ou Brevo. Votre adresse Live peut toujours RECEVOIR les alertes.';
    } elseif (preg_match('/^53[45]|authentication|auth/i', $r)) {
        $tip = strpos($host, 'gmail') !== false
            ? ' Gmail exige un « mot de passe d’application » (compte Google › Sécurité › Validation en deux étapes › Mots de passe des applications), pas votre mot de passe habituel.'
            : ' Identifiant ou mot de passe refusé : vérifiez-les, ou le nom d’utilisateur exact attendu (souvent l’adresse complète).';
    } elseif (preg_match('/Connexion impossible|timed out|délai/i', $r)) {
        $tip = ' Vérifiez l’adresse et le port (587 avec STARTTLS, ou 465 avec SSL). Certains hébergeurs bloquent les connexions vers d’autres serveurs SMTP : utilisez alors le leur, ou la fonction mail().';
    } elseif (preg_match('/certificat|certificate|TLS/i', $r)) {
        $tip = ' Le certificat du serveur ne correspond pas à son nom : utilisez le nom exact fourni par l’hébergeur.';
    }
    return 'Envoi refusé : ' . cut($r, 220) . '.' . $tip;
}

/* ========================================================================== */
/* Modèles de messages                                                         */
/* ========================================================================== */

function mail_html(string $title, string $intro, string $inner, ?string $button = null, ?string $url = null): string {
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $btn = ($button !== null && $url) ? '<p style="margin:24px 0 8px"><a href="' . $e($url) . '" style="display:inline-block;background:#c8102e;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:10px">' . $e($button) . '</a></p>' : '';
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . $e($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0b0b0b">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e4e4e7">'
        . '<tr><td style="background:#120f12;background-image:linear-gradient(135deg,#0b0b0d 0%,#3b0a12 60%,#8e0a18 100%);padding:20px 24px;color:#ffffff;font-size:18px;font-weight:700">Boussole <span style="font-weight:400;color:#f3c4ca;font-size:14px">· finances du foyer</span></td></tr>'
        . '<tr><td style="padding:24px"><h1 style="font-size:19px;margin:0 0 10px">' . $e($title) . '</h1><p style="margin:0 0 14px;color:#52514e;line-height:1.5">' . $intro . '</p>' . $inner . $btn
        . '<p style="margin:22px 0 0;font-size:12px;color:#898781;line-height:1.5">Message automatique, merci de ne pas y répondre. Boussole ne vous demandera jamais votre mot de passe ni vos codes par e-mail.</p></td></tr></table>'
        . '</td></tr></table></body></html>';
}

/** Récapitulatif des nouvelles alertes. $contenu : 'titres' ou 'discret' (aucun détail financier dans le message). */
function mail_alerts(string $prenom, array $alerts, string $url, string $contenu): array {
    $nCrit = count(array_filter($alerts, function ($a) { return $a['niveau'] === 'critique'; }));
    $n = count($alerts);
    $subject = 'Boussole · ' . ($n > 1 ? $n . ' nouvelles alertes' : 'nouvelle alerte') . ($nCrit ? ' dont ' . $nCrit . ' critique' . ($nCrit > 1 ? 's' : '') : '');
    $lab = ['critique' => 'Critique', 'attention' => 'À vérifier', 'info' => 'Conseil', 'bravo' => 'Bravo'];
    $col = ['critique' => '#d03b3b', 'attention' => '#e39a00', 'info' => '#2a78d6', 'bravo' => '#0ca30c'];
    $intro = 'Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8') . ', ' . ($contenu === 'discret' ? 'de nouvelles alertes vous attendent dans Boussole.' : 'voici ce que Boussole a repéré :');
    $text = 'Bonjour ' . $prenom . ",\n\n";
    $inner = '';
    if ($contenu === 'discret') {
        $text .= $n . ' nouvelle(s) alerte(s)' . ($nCrit ? ', dont ' . $nCrit . ' critique(s)' : '') . ". Connectez-vous pour les consulter.\n";
        $inner = '<p style="margin:0;font-size:15px"><b>' . $n . '</b> nouvelle(s) alerte(s)' . ($nCrit ? ', dont <b style="color:#d03b3b">' . $nCrit . ' critique(s)</b>' : '') . '.</p>';
    } else {
        foreach ($alerts as $a) {
            $text .= '• [' . ($lab[$a['niveau']] ?? $a['niveau']) . '] ' . $a['titre'] . "\n";
            $inner .= '<div style="border-left:4px solid ' . ($col[$a['niveau']] ?? '#898781') . ';background:#f8f8f9;border-radius:8px;padding:10px 12px;margin:0 0 8px">'
                . '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:' . ($col[$a['niveau']] ?? '#898781') . '">' . htmlspecialchars($lab[$a['niveau']] ?? $a['niveau'], ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-weight:600;margin-top:2px">' . htmlspecialchars($a['titre'], ENT_QUOTES, 'UTF-8') . '</div></div>';
        }
    }
    $text .= "\nOuvrir Boussole : " . $url . "\n\nPour modifier ces envois : Réglages › Alertes par e-mail.\n";
    return [$subject, $text, mail_html($n > 1 ? 'Nouvelles alertes' : 'Nouvelle alerte', $intro, $inner, 'Ouvrir Boussole', $url)];
}

function mail_reset(string $prenom, string $url): array {
    $p = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $text = "Bonjour $prenom,\n\nVous avez demandé à choisir un nouveau mot de passe pour Boussole. Ce lien est valable 30 minutes et ne sert qu'une fois :\n$url\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n";
    return ['Boussole · choisir un nouveau mot de passe', $text, mail_html('Choisir un nouveau mot de passe', "Bonjour $p, vous avez demandé à choisir un nouveau mot de passe. Ce lien est valable <b>30 minutes</b> et ne sert qu’une fois.", '<p style="margin:0;color:#52514e;font-size:14px">Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.</p>', 'Choisir mon mot de passe', $url)];
}
function mail_test(string $prenom, string $url): array {
    $p = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $text = "Bonjour $prenom,\n\nCet e-mail de test confirme que Boussole peut vous écrire : les alertes arriveront à cette adresse.\nAstuce : ajoutez l'expéditeur à vos contacts pour qu'elles n'atterrissent pas dans les courriers indésirables.\n\n$url\n";
    return ['Boussole · e-mail de test', $text, mail_html('Les alertes arriveront ici', "Bonjour $p, cet e-mail de test confirme que Boussole peut vous écrire.", '<p style="margin:0;color:#52514e;font-size:14px">Astuce : ajoutez l’expéditeur à vos contacts pour que les alertes n’atterrissent pas dans les courriers indésirables.</p>', 'Ouvrir Boussole', $url)];
}
function mail_lock(string $prenom, int $minutes, string $ip, string $url): array {
    $p = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $text = "Bonjour $prenom,\n\nPlusieurs mots de passe erronés ont été saisis pour votre compte Boussole (adresse IP : $ip). Par sécurité, il est bloqué pendant $minutes minutes.\n\nSi ce n'était pas vous, changez votre mot de passe dès votre prochaine connexion (Réglages › Sécurité) et vérifiez le journal de votre compte.\n\n$url\n";
    return ['Boussole · compte bloqué ' . $minutes . ' minutes', $text, mail_html('Compte bloqué par sécurité', "Bonjour $p, plusieurs mots de passe erronés ont été saisis pour votre compte (adresse IP : " . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') . "). Il est bloqué pendant <b>$minutes minutes</b>.", '<p style="margin:0;color:#52514e;font-size:14px">Si ce n’était pas vous, changez votre mot de passe dès votre prochaine connexion (Réglages › Sécurité) et vérifiez le journal de votre compte.</p>', 'Ouvrir Boussole', $url)];
}
function mail_changed(string $prenom, string $newEmail, string $url): array {
    $p = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $text = "Bonjour $prenom,\n\nL'adresse e-mail de votre compte Boussole vient d'être remplacée par : $newEmail\nSi vous n'êtes pas à l'origine de ce changement, connectez-vous et changez votre mot de passe sans attendre.\n\n$url\n";
    return ['Boussole · adresse e-mail modifiée', $text, mail_html('Adresse e-mail modifiée', "Bonjour $p, l’adresse e-mail de votre compte vient d’être remplacée par <b>" . htmlspecialchars($newEmail, ENT_QUOTES, 'UTF-8') . '</b>.', '<p style="margin:0;color:#52514e;font-size:14px">Si vous n’êtes pas à l’origine de ce changement, connectez-vous et changez votre mot de passe sans attendre.</p>', 'Ouvrir Boussole', $url)];
}

/** Invitation d'une nouvelle personne du foyer : le lien contient le code (après le #, il ne passe jamais par le serveur). */
function mail_invite(string $prenom, string $par, string $foyer, string $code, string $url): array {
    $p = htmlspecialchars($prenom, ENT_QUOTES, 'UTF-8');
    $b = htmlspecialchars($par, ENT_QUOTES, 'UTF-8');
    $f = htmlspecialchars($foyer, ENT_QUOTES, 'UTF-8');
    $text = "Bonjour $prenom,\n\n$par vous invite à rejoindre « $foyer » sur Boussole, l'outil de suivi des finances du foyer.\n\n"
        . "Ouvrez ce lien depuis la maison, puis choisissez VOTRE mot de passe (personne d'autre ne le connaîtra) :\n$url\n\n"
        . "Code d'invitation (valable 30 jours, à usage unique) : $code\n\n"
        . "Vos opérations restent privées tant que vous ne choisissez pas de les partager.\n";
    return ['Boussole · invitation de ' . $par, $text, mail_html('Rejoindre « ' . $foyer . ' »',
        "Bonjour $p, <b>$b</b> vous invite à rejoindre <b>$f</b> sur Boussole. Vous choisirez vous-même votre mot de passe ; vos opérations restent privées tant que vous ne décidez pas de les partager.",
        '<p style="margin:0 0 6px;color:#52514e;font-size:14px">Code d’invitation, valable 30 jours :</p><p style="margin:0;font:600 20px/1.3 ui-monospace,Consolas,monospace;letter-spacing:.06em">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>',
        'Activer mon accès', $url)];
}
