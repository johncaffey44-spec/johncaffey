<?php
/*
 * D8 · Service clients — réglages propres à votre serveur.
 * Copiez ce fichier en « config.php » (le script d'installation le fait pour vous).
 * config.php n'est jamais remplacé lors d'une mise à jour d'api.php.
 *
 * ATTENTION : enregistrez ce fichier en UTF-8 SANS BOM et sans aucun caractère
 * avant « <?php », sinon l'API renvoie des réponses invalides.
 */

// dossier des données (comptes, dossiers clients, journal, sauvegardes) : de préférence hors de C:\inetpub\wwwroot
$DATA_DIR = 'C:\\inetpub\\service_clients_data';

// plages d'adresses autorisées à utiliser l'outil (réseau interne)
// $ALLOWED_NETS = ['127.0.0.0/8', '::1/128', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

// sauvegardes complètes automatiques (modifiables aussi dans Administration › Sauvegardes)
// $AUTO_BACKUP_TIMES = ['12:00', '17:00'];
