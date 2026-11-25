<?php
/*
 * Sentinelle XDR — configuration « figée » (facultative).
 * Copiez ce fichier en config.php pour modifier ces valeurs. Elles touchent à la sécurité :
 * elles ne se changent pas depuis l'interface web, exprès.
 * Les autres réglages (Freebox, détection, notifications, AdGuard…) se font dans Paramètres.
 */
return [
    // Dossier des données (base SQLite, clé d'accès Freebox, sessions). Idéalement HORS de la
    // racine du site, par exemple : 'C:/ProgramData/SentinelleXDR'. Donnez alors aux comptes
    // IIS_IUSRS et IUSR le droit « Modification » sur ce dossier.
    // 'data_dir' => 'C:/ProgramData/SentinelleXDR',

    // Réseaux autorisés à ouvrir l'interface. Par défaut : réseau local seulement.
    // 'allowed_networks' => ['127.0.0.0/8', '::1/128', '192.168.1.0/24', 'fe80::/10'],

    // IPv6 : accepter aussi les appareils du même préfixe /64 que ce serveur (votre réseau local).
    // 'allow_same_ipv6_prefix' => true,

    // Déconnexion après N minutes d'inactivité, et au plus tard après N heures.
    // 'session_idle_minutes' => 30,
    // 'session_max_hours' => 12,

    // 'timezone' => 'Europe/Paris',
];
