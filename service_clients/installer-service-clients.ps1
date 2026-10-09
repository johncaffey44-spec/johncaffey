<#
.SYNOPSIS
    Installe ou met à jour l'outil « Service clients D8 » sur IIS et active PHP pour son dossier.

.DESCRIPTION
    À lancer sur le serveur IIS, en administrateur (le script se relance seul en administrateur) :

        powershell -NoProfile -ExecutionPolicy Bypass -File .\installer-service-clients.ps1

    ou double-cliquez sur installer-service-clients.cmd.

    Étapes (chacune est vérifiée et sautée si elle est déjà faite) :
      1. Rôles IIS nécessaires : serveur web, CGI/FastCGI, filtrage des requêtes, outils PowerShell d'IIS.
         Le dossier service_clients devient une application IIS avec son propre pool (« D8-ServiceClients ») :
         le planning et les autres applications du serveur n'ont aucun accès à ses données.
      2. PHP : réutilise le PHP qui traite déjà les .php du site ou déclaré dans IIS (celui du planning D8),
         sinon un PHP existant (-PhpPath), une archive (-PhpZip) ou un téléchargement officiel (-InstallPhp).
      3. Extensions PHP indispensables (mbstring, openssl). Le php.ini d'un PHP déjà utilisé n'est modifié
         qu'avec -CorrigerPhpIni (il est partagé avec le planning).
      4. Traitement des fichiers .php déclaré pour le seul dossier service_clients (pas pour tout le site).
      5. Copie de index.html, formulaire.html, api.php, web.config, .user.ini (config.php et les données ne sont
         jamais écrasés ; l'ancienne version est mise de côté).
      6. Dossier de données HORS de la racine web, accessible au seul pool de l'outil (et aux administrateurs).
      7. Mot de passe super administrateur (demandé s'il n'existe pas encore) : il sert à la mise en service
         et aux opérations sensibles (restauration…). Il n'y a pas de mot de passe par défaut.
      8. Tests : page, API PHP, données non accessibles depuis le web.
      9. Facultatif (-SauvegardePlanifiee) : tâche planifiée Windows pour les sauvegardes et la durée de
         conservation, même quand personne n'a l'outil ouvert.

    Rien n'est supprimé. Relancez le script après chaque mise à jour des fichiers : il ne fait que ce qui manque.

.PARAMETER Destination
    Dossier publié par IIS. Par défaut : C:\inetpub\wwwroot\service_clients (sous la racine du site).

.PARAMETER Site
    Site IIS qui contient le dossier. Par défaut : Default Web Site

.PARAMETER DataDir
    Dossier des données (comptes, dossiers clients, sauvegardes). Par défaut : C:\inetpub\service_clients_data
    (hors de wwwroot : jamais servi par IIS, même en cas d'erreur de configuration).
    Si config.php existe déjà, c'est le dossier qu'il désigne qui est utilisé.

.PARAMETER PhpPath
    Dossier d'un PHP existant (contenant php-cgi.exe), si la détection automatique ne le trouve pas.

.PARAMETER PhpZip
    Archive officielle PHP pour Windows (« Non Thread Safe » x64) à installer si PHP est absent.

.PARAMETER InstallPhp
    Télécharge et installe PHP (version -PhpVersion) depuis windows.php.net si PHP est absent.

.PARAMETER PhpVersion
    Version de PHP à télécharger avec -InstallPhp. Par défaut : 8.3

.PARAMETER CorrigerPhpIni
    Active dans le php.ini d'un PHP déjà installé les extensions manquantes (mbstring, openssl).
    Sans ce paramètre, le script signale seulement ce qui manque (php.ini est partagé avec le planning).

.PARAMETER MotDePasseSuperAdmin
    Redéfinit le mot de passe super administrateur même s'il existe déjà.

.PARAMETER SauvegardePlanifiee
    Crée la tâche planifiée « D8 Service clients - sauvegardes » (toutes les 30 minutes).

.EXAMPLE
    .\installer-service-clients.ps1
    Installation ou mise à jour standard, PHP du planning réutilisé.

.EXAMPLE
    .\installer-service-clients.ps1 -InstallPhp -SauvegardePlanifiee
    Installe PHP s'il est absent et crée la tâche planifiée des sauvegardes.
#>
[CmdletBinding()]
param(
    [string]$Destination = 'C:\inetpub\wwwroot\service_clients',
    [string]$Site = 'Default Web Site',
    [string]$DataDir = 'C:\inetpub\service_clients_data',
    [string]$PhpPath = '',
    [string]$PhpZip = '',
    [switch]$InstallPhp,
    [string]$PhpVersion = '8.3',
    [switch]$CorrigerPhpIni,
    [switch]$MotDePasseSuperAdmin,
    [switch]$SauvegardePlanifiee
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'          # la barre de progression de Windows PowerShell ralentit fortement les téléchargements
$Source = $PSScriptRoot
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$Fichiers = @('index.html', 'formulaire.html', 'api.php', 'web.config', '.user.ini')
$Destination = $Destination.TrimEnd('\')
$AppFolder = Split-Path -Leaf $Destination
$PoolDedie = 'D8-ServiceClients'
$Avertissements = New-Object System.Collections.ArrayList

function Etape([string]$t) { Write-Host ''; Write-Host "== $t" -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "   [OK] $t" -ForegroundColor Green }
function Info([string]$t) { Write-Host "   $t" }
function Alerte([string]$t) { Write-Host "   [!] $t" -ForegroundColor Yellow; [void]$Avertissements.Add($t) }
function Echec([string]$t) { Write-Host ''; Write-Host "[ÉCHEC] $t" -ForegroundColor Red; throw $t }
function Ecrire-Utf8([string]$Chemin, [string]$Texte) { [System.IO.File]::WriteAllText($Chemin, $Texte, $Utf8NoBom) }
# php.exe : avec le php.ini qu'utilise IIS ; ses avertissements (sortie d'erreur) n'arrêtent pas le script.
# Attention (Windows PowerShell 5.1) : pas de guillemets doubles dans le code passé à -r, ils seraient perdus.
function Php {
    $ErrorActionPreference = 'Continue'
    $ini = @(); if ($script:phpIni) { $ini = @('-c', $script:phpIni) }
    $input | & $script:phpExe @ini @args 2>$null          # $input : ce qui est envoyé par le pipeline (mot de passe)
}

# --- relance en administrateur, en PowerShell 64 bits -------------------------------------------
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Droits administrateur nécessaires : relance du script en administrateur…' -ForegroundColor Yellow
    # -NoExit : la fenêtre administrateur reste ouverte pour lire le résultat
    $relance = @('-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    foreach ($p in $PSBoundParameters.GetEnumerator()) {
        if ($p.Value -is [System.Management.Automation.SwitchParameter]) { if ($p.Value.IsPresent) { $relance += "-$($p.Key)" } }
        else { $relance += "-$($p.Key)"; $relance += "`"$(([string]$p.Value).TrimEnd('\'))`"" }
    }
    Start-Process -FilePath 'powershell.exe' -ArgumentList $relance -Verb RunAs
    return
}
if ([Environment]::Is64BitOperatingSystem -and -not [Environment]::Is64BitProcess) {
    Write-Host '[ÉCHEC] Lancez le script avec PowerShell 64 bits (et non « Windows PowerShell (x86) ») : les outils IIS ne fonctionnent pas en 32 bits.' -ForegroundColor Red
    return
}

$journal = Join-Path $env:TEMP ("installation-service-clients-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))
try { Start-Transcript -Path $journal -ErrorAction SilentlyContinue | Out-Null } catch { }
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

try {
    Write-Host 'D8 · Service clients — installation / mise à jour sur IIS' -ForegroundColor White
    Info "Source : $Source"
    Info "Destination : $Destination  (site « $Site »)"
    foreach ($f in @('index.html', 'api.php', 'web.config')) {
        if (-not (Test-Path -LiteralPath (Join-Path $Source $f))) { Echec "Fichier $f introuvable à côté du script ($Source). Copiez tout le dossier service_clients avant de lancer le script." }
    }

    # =============================================================================================
    Etape '1. Rôles IIS et application'
    $estServeur = (Get-CimInstance -ClassName Win32_OperatingSystem).ProductType -ne 1      # 1 = Windows 10/11
    if ($estServeur) {
        $roles = @('Web-Server', 'Web-Default-Doc', 'Web-Static-Content', 'Web-Http-Errors', 'Web-Filtering', 'Web-CGI', 'Web-Scripting-Tools')
        $manquants = @(Get-WindowsFeature -Name $roles | Where-Object { -not $_.Installed } | ForEach-Object { $_.Name })
        if ($manquants.Count) {
            Info ("Installation : " + ($manquants -join ', '))
            $r = Install-WindowsFeature -Name $manquants
            if (-not $r.Success) { Echec "Installation des rôles IIS impossible : $($manquants -join ', ')" }
            if ($r.RestartNeeded -eq 'Yes') { Alerte 'Windows demande un redémarrage pour terminer l''installation des rôles IIS.' }
        }
    } else {
        $roles = @('IIS-WebServerRole', 'IIS-WebServer', 'IIS-DefaultDocument', 'IIS-StaticContent', 'IIS-HttpErrors', 'IIS-RequestFiltering', 'IIS-CGI', 'IIS-ManagementScriptingTools')
        foreach ($nom in $roles) {
            $f = Get-WindowsOptionalFeature -Online -FeatureName $nom
            if ($f.State -ne 'Enabled') { Info "Activation : $nom"; Enable-WindowsOptionalFeature -Online -FeatureName $nom -All -NoRestart | Out-Null }
        }
    }
    Import-Module WebAdministration
    if (-not (Test-Path "IIS:\Sites\$Site")) { Echec "Site IIS « $Site » introuvable. Sites existants : $((Get-ChildItem IIS:\Sites | ForEach-Object { $_.Name }) -join ', ')" }
    $racine = [Environment]::ExpandEnvironmentVariables((Get-Item "IIS:\Sites\$Site").physicalPath).TrimEnd('\')
    $attendu = Join-Path $racine $AppFolder
    if ($Destination -ne $attendu) { Echec "Le dossier $Destination n'est pas dans la racine du site « $Site » ($racine). Relancez avec -Destination `"$attendu`" (ou -Site <nom du site>)." }
    if (-not (Test-Path -LiteralPath $Destination)) { New-Item -ItemType Directory -Path $Destination | Out-Null }
    $emplacement = "$Site/$AppFolder"
    # application IIS avec son propre pool : identité distincte de celle du planning
    $app = Get-WebApplication -Site $Site -Name $AppFolder
    if (-not $app) {
        if (-not (Test-Path "IIS:\AppPools\$PoolDedie")) {
            New-WebAppPool -Name $PoolDedie | Out-Null
            Set-ItemProperty "IIS:\AppPools\$PoolDedie" -Name managedRuntimeVersion -Value ''
            Set-ItemProperty "IIS:\AppPools\$PoolDedie" -Name processModel.identityType -Value 'ApplicationPoolIdentity'
            Ok "Pool d'applications « $PoolDedie » créé"
        }
        New-WebApplication -Site $Site -Name $AppFolder -PhysicalPath $Destination -ApplicationPool $PoolDedie | Out-Null
        Ok "Application IIS « $emplacement » créée (pool « $PoolDedie »)"
    }
    $pool = (Get-WebApplication -Site $Site -Name $AppFolder).applicationPool
    if ($pool -eq (Get-Item "IIS:\Sites\$Site").applicationPool) { Alerte "L'application utilise le même pool que le site (« $pool ») : les autres applications de ce pool peuvent lire ses données." }
    # compte Windows sous lequel tourne le pool (c'est lui qui écrit les données)
    $pm = (Get-Item "IIS:\AppPools\$pool").processModel
    switch ([string]$pm.identityType) {
        'LocalSystem' { $comptePool = '*S-1-5-18' }
        'LocalService' { $comptePool = '*S-1-5-19' }
        'NetworkService' { $comptePool = '*S-1-5-20' }
        'SpecificUser' { $comptePool = [string]$pm.userName }
        default { $comptePool = "IIS AppPool\$pool" }
    }
    # requêtes anonymes exécutées sous l'identité du pool (et non IUSR) ; l'outil gère lui-même la connexion
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $emplacement -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name enabled -Value $true
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $emplacement -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name userName -Value ''
    Ok "IIS prêt (site « $Site », application « $emplacement », pool « $pool »)"

    # =============================================================================================
    Etape '2. PHP'
    $phpCgi = $null
    if ($PhpPath) {
        $phpCgi = Join-Path $PhpPath.TrimEnd('\') 'php-cgi.exe'
        if (-not (Test-Path -LiteralPath $phpCgi)) { Echec "php-cgi.exe introuvable dans $PhpPath" }
    }
    if (-not $phpCgi) {
        # PHP qui traite déjà les .php à cet endroit (déclaré pour tout le serveur ou le site) : c'est lui qu'IIS utilisera
        $herite = $null
        try {
            $herite = @(Get-WebConfiguration -PSPath "MACHINE/WEBROOT/APPHOST/$emplacement" -Filter '/system.webServer/handlers/add' |
                Where-Object { $_.path -eq '*.php' -and $_.modules -eq 'FastCgiModule' }) | Select-Object -First 1
        } catch { }
        if ($herite) {
            $cand = [Environment]::ExpandEnvironmentVariables(([string]$herite.scriptProcessor -split '\|')[0])
            if (Test-Path -LiteralPath $cand) { $phpCgi = $cand; Info "PHP qui traite déjà les .php de ce dossier : $phpCgi" }
        }
    }
    if (-not $phpCgi) {
        # PHP déjà déclaré dans IIS (planning D8…) : on prend le plus récent
        $declares = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter '/system.webServer/fastCgi/application' |
            ForEach-Object { [Environment]::ExpandEnvironmentVariables($_.fullPath) } |
            Where-Object { $_ -match 'php-cgi\.exe$' -and (Test-Path -LiteralPath $_) })
        if ($declares.Count) {
            $phpCgi = $declares | Sort-Object { try { [version]((Get-Item -LiteralPath $_).VersionInfo.ProductVersion -replace '[^\d.].*$', '') } catch { [version]'0.0' } } -Descending | Select-Object -First 1
            Info "PHP déjà déclaré dans IIS : $phpCgi"
        }
    }
    if (-not $phpCgi) {
        $candidats = @('C:\PHP', 'C:\Program Files\PHP', 'C:\Program Files (x86)\PHP', 'C:\tools')
        foreach ($c in $candidats) {
            if (Test-Path -LiteralPath $c) {
                $trouve = Get-ChildItem -LiteralPath $c -Filter 'php-cgi.exe' -Recurse -Depth 2 -ErrorAction SilentlyContinue | Select-Object -First 1
                if ($trouve) { $phpCgi = $trouve.FullName; Info "PHP trouvé : $phpCgi"; break }
            }
        }
    }
    $phpNeuf = $false
    if (-not $phpCgi -and ($PhpZip -or $InstallPhp)) {
        # Visual C++ 2015-2022 (x64), 14.29 minimum pour PHP 8 : avec -InstallPhp, toujours (re)lancé (code 1638 = déjà plus récent)
        $vc = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
        if ($InstallPhp) {
            Info 'Installation ou vérification de Visual C++ 2015-2022 (x64)…'
            $vcExe = Join-Path $env:TEMP 'vc_redist.x64.exe'
            Invoke-WebRequest -UseBasicParsing -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vcExe
            $p = Start-Process -FilePath $vcExe -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
            if ($p.ExitCode -notin 0, 1638, 3010) { Echec "Installation de Visual C++ échouée (code $($p.ExitCode))." }
            if ($p.ExitCode -eq 3010) { Alerte 'Visual C++ installé : un redémarrage de Windows est conseillé.' }
        } elseif (-not $vc -or $vc.Installed -ne 1 -or $vc.Major -lt 14 -or ($vc.Major -eq 14 -and $vc.Minor -lt 29)) {
            Alerte 'Visual C++ 2015-2022 (x64, 14.29 ou plus) semble absent : PHP risque de ne pas démarrer (https://aka.ms/vs/17/release/vc_redist.x64.exe).'
        }
        $zip = $PhpZip
        if (-not $zip) {
            Info "Recherche de la dernière version PHP $PhpVersion (Non Thread Safe, x64) sur windows.php.net…"
            $rel = Invoke-RestMethod -UseBasicParsing -Uri 'https://windows.php.net/downloads/releases/releases.json'
            $branche = $rel.$PhpVersion
            if (-not $branche) { Echec "Version PHP $PhpVersion introuvable sur windows.php.net." }
            $build = $branche.PSObject.Properties | Where-Object { $_.Name -match '^nts-vs\d+-x64$' } | Select-Object -First 1
            if (-not $build) { Echec "Aucune version Non Thread Safe x64 pour PHP $PhpVersion." }
            if (-not $build.Value.zip.sha256) { Echec 'Empreinte SHA-256 absente de releases.json : téléchargement non vérifiable, abandon.' }
            $zip = Join-Path $env:TEMP $build.Value.zip.path
            Invoke-WebRequest -UseBasicParsing -Uri ("https://windows.php.net/downloads/releases/" + $build.Value.zip.path) -OutFile $zip
            $hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
            if ($hash -ne $build.Value.zip.sha256.ToUpper()) { Echec 'Empreinte SHA-256 de l''archive PHP incorrecte : téléchargement corrompu.' }
            Ok "Téléchargé : $($build.Value.zip.path) (PHP $($branche.version), SHA-256 vérifié)"
        }
        if (-not (Test-Path -LiteralPath $zip)) { Echec "Archive PHP introuvable : $zip" }
        $dossierPhp = Join-Path 'C:\PHP' ([IO.Path]::GetFileNameWithoutExtension($zip))
        if (-not (Test-Path -LiteralPath $dossierPhp)) { Expand-Archive -LiteralPath $zip -DestinationPath $dossierPhp }
        $phpCgi = Join-Path $dossierPhp 'php-cgi.exe'
        if (-not (Test-Path -LiteralPath $phpCgi)) { Echec "php-cgi.exe absent de l'archive ($zip) : prenez la version « Non Thread Safe » x64." }
        $phpNeuf = $true
    }
    if (-not $phpCgi) {
        Echec ("PHP introuvable. Relancez avec -InstallPhp (téléchargement depuis windows.php.net), -PhpZip <archive NTS x64> " +
               "ou -PhpPath <dossier de PHP>. Si le planning D8 fonctionne sur ce serveur, indiquez son dossier PHP avec -PhpPath.")
    }
    $phpDir = Split-Path -Parent $phpCgi
    $phpExe = Join-Path $phpDir 'php.exe'
    if (-not (Test-Path -LiteralPath $phpExe)) { Echec "php.exe absent de $phpDir" }
    # entrée FastCGI de ce PHP (identifiée par son chemin et ses arguments)
    $entree = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter '/system.webServer/fastCgi/application' |
        Where-Object { [Environment]::ExpandEnvironmentVariables($_.fullPath) -eq $phpCgi }) | Select-Object -First 1
    # php.ini : celui qu'utilise IIS (PHPRC de l'entrée FastCGI), sinon celui que PHP charge ; créé seulement pour un PHP installé ici
    $phpIni = $null
    if ($phpNeuf) {
        $phpIni = Join-Path $phpDir 'php.ini'
        if (-not (Test-Path -LiteralPath $phpIni)) {
            $modele = Join-Path $phpDir 'php.ini-production'
            if (-not (Test-Path -LiteralPath $modele)) { Echec "php.ini-production absent de $phpDir" }
            Copy-Item -LiteralPath $modele -Destination $phpIni
            Info 'php.ini créé à partir de php.ini-production'
        }
    } else {
        if ($entree) {
            $rc = @($entree.environmentVariables.Collection | Where-Object { $_.name -eq 'PHPRC' }) | Select-Object -First 1
            if ($rc) { $c = [Environment]::ExpandEnvironmentVariables([string]$rc.value); if (Test-Path -LiteralPath $c -PathType Container) { $c = Join-Path $c 'php.ini' }; if (Test-Path -LiteralPath $c) { $phpIni = $c } }
            if ($entree.arguments -match '-c\s+"?([^"]+?)"?(\s|$)') { $c = $Matches[1]; if (Test-Path -LiteralPath $c -PathType Container) { $c = Join-Path $c 'php.ini' }; if (Test-Path -LiteralPath $c) { $phpIni = $c } }
        }
        if (-not $phpIni) { $l = (Php -r 'echo php_ini_loaded_file();') -join ''; if ($l -and (Test-Path -LiteralPath $l)) { $phpIni = $l } }
        if (-not $phpIni) { Alerte "Ce PHP ne charge aucun php.ini (réglages par défaut) : le script n'en crée pas, pour ne rien changer au planning. Extensions à vérifier à la main." }
    }
    $version = (Php -n -r 'echo PHP_VERSION;') -join ''
    if (-not $version) { Echec "PHP ne démarre pas ($phpExe). Installez Visual C++ 2015-2022 x64, ou relancez avec -InstallPhp." }
    if ([version]($version -replace '[^\d.].*$', '') -lt [version]'7.4') { Echec "PHP $version trop ancien : 7.4 minimum (8.3 conseillé)." }
    Ok "PHP $version ($phpDir)$(if ($phpIni) { ", php.ini : $phpIni" })"

    # =============================================================================================
    Etape '3. Extensions et réglages PHP'
    $requis = @('mbstring', 'openssl', 'session', 'json')
    $modules = (Php -m) -join "`n"
    $absents = @($requis | Where-Object { $modules -notmatch "(?im)^$_$" })
    if ($phpIni) {
        $ini = [IO.File]::ReadAllText($phpIni)
        $iniAvant = $ini
        if ($absents.Count) {
            if ($phpNeuf -or $CorrigerPhpIni) {
                foreach ($e in $absents) {
                    if ($ini -match "(?im)^\s*;\s*extension\s*=\s*(php_)?$e(\.dll)?\s*$") { $ini = [regex]::Replace($ini, "(?im)^\s*;\s*(extension\s*=\s*(php_)?$e(\.dll)?)\s*$", '$1') }
                    elseif ($e -notin @('session', 'json')) { $ini += "`r`nextension=$e" }
                }
            } else {
                Alerte ("Extensions PHP absentes : " + ($absents -join ', ') + ". Relancez avec -CorrigerPhpIni pour les activer dans $phpIni (fichier partagé avec les autres applications PHP).")
            }
        }
        if ($phpNeuf) {
            # PHP installé par ce script : réglages conseillés pour IIS
            $reglages = [ordered]@{ 'extension_dir' = '"ext"'; 'cgi.force_redirect' = '0'; 'cgi.fix_pathinfo' = '1'; 'fastcgi.impersonate' = '1'; 'date.timezone' = 'Europe/Paris'; 'display_errors' = 'Off'; 'log_errors' = 'On' }
            foreach ($k in $reglages.Keys) {
                $motif = '(?im)^\s*;?\s*' + [regex]::Escape($k) + '\s*=.*$'
                if ($ini -match $motif) { $ini = [regex]::Replace($ini, $motif, "$k = $($reglages[$k])", 1) } else { $ini += "`r`n$k = $($reglages[$k])" }
            }
        }
        if ($ini -ne $iniAvant) {
            Copy-Item -LiteralPath $phpIni -Destination ("$phpIni.{0:yyyyMMdd-HHmmss}.bak" -f (Get-Date))
            [IO.File]::WriteAllText($phpIni, $ini, $Utf8NoBom)
            Ok "php.ini mis à jour (copie de l'ancien : $phpIni.*.bak)"
            if (-not $phpNeuf) { Alerte 'php.ini modifié : les processus PHP déjà lancés le relisent au prochain recyclage des pools (ou après « iisreset »).' }
            $modules = (Php -m) -join "`n"
        }
    }
    $toujours = @($requis | Where-Object { $modules -notmatch "(?im)^$_$" })
    if (-not $toujours.Count) { Ok 'Extensions mbstring, openssl, session, json présentes' }
    elseif ($phpNeuf -or $CorrigerPhpIni) { Alerte ('Extensions toujours absentes : ' + ($toujours -join ', ') + " (vérifiez extension_dir et le dossier ext de $phpDir)") }

    # =============================================================================================
    Etape '4. FastCGI et traitement des .php pour le dossier'
    if (-not $entree) {
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter '/system.webServer/fastCgi' -Value @{ fullPath = $phpCgi; activityTimeout = 600; requestTimeout = 600; instanceMaxRequests = 10000 }
        $chemin = "/system.webServer/fastCgi/application[@fullPath='$phpCgi']"
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "$chemin/environmentVariables" -Value @{ name = 'PHP_FCGI_MAX_REQUESTS'; value = '10000' }
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "$chemin/environmentVariables" -Value @{ name = 'PHPRC'; value = $phpDir }
        if ($phpIni) { Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter $chemin -Name monitorChangesTo -Value $phpIni }
        $processeur = $phpCgi
        Ok "Application FastCGI déclarée : $phpCgi"
    } else {
        # le gestionnaire doit désigner exactement la même entrée : chemin, et arguments s'il y en a
        $processeur = [string]$entree.fullPath + $(if ($entree.arguments) { '|' + $entree.arguments } else { '' })
        Ok "Application FastCGI déjà déclarée ($processeur)"
    }
    $gestionnaires = @(Get-WebConfiguration -PSPath "MACHINE/WEBROOT/APPHOST/$emplacement" -Filter '/system.webServer/handlers/add' | Where-Object { $_.path -eq '*.php' })
    $actif = $gestionnaires | Where-Object { $_.modules -eq 'FastCgiModule' } | Select-Object -First 1
    if (-not $actif) {
        Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $emplacement -Filter '/system.webServer/handlers' -Name '.' -Value @{
            name = 'PHP_service_clients'; path = '*.php'; verb = 'GET,HEAD,POST'; modules = 'FastCgiModule'; scriptProcessor = $processeur; resourceType = 'Either'; requireAccess = 'Script' }
        Ok "Fichiers .php confiés à PHP pour « $emplacement » seulement"
    } else {
        Ok "PHP déjà actif pour ce dossier ($($actif.scriptProcessor))"
        $actifExe = [Environment]::ExpandEnvironmentVariables(([string]$actif.scriptProcessor -split '\|')[0])
        if ($actifExe -ne $phpCgi) { Alerte "Le dossier est traité par $actifExe, et non par le PHP vérifié ci-dessus ($phpCgi) : relancez avec -PhpPath `"$(Split-Path -Parent $actifExe)`"." }
    }

    # =============================================================================================
    Etape '5. Dossier de données'
    $config = Join-Path $Destination 'config.php'
    $ancien = Join-Path $Destination 'data'
    if (Test-Path -LiteralPath $config) {
        # config.php commençant par un BOM UTF-8 : il casse les réponses de l'API
        $octets = [IO.File]::ReadAllBytes($config)
        if ($octets.Length -ge 3 -and $octets[0] -eq 0xEF -and $octets[1] -eq 0xBB -and $octets[2] -eq 0xBF) {
            [IO.File]::WriteAllBytes($config, [byte[]]($octets[3..($octets.Length - 1)]))
            Alerte 'config.php commençait par un BOM UTF-8 : retiré (il cassait les réponses de l''API).'
        }
        # dossier réellement utilisé par l'outil : celui de config.php
        $env:SC_CONFIG = $config
        $lu = ((Php -n -r '$DATA_DIR = dirname(getenv(''SC_CONFIG'')) . ''/data''; require getenv(''SC_CONFIG''); echo $DATA_DIR;') -join '').Trim()
        Remove-Item Env:\SC_CONFIG -ErrorAction SilentlyContinue
        if (-not $lu) { Echec "config.php illisible par PHP ($config) : corrigez-le ou renommez-le, puis relancez." }
        $lu = $lu.Replace('/', '\')
        if ($lu.TrimEnd('\') -ne $DataDir.TrimEnd('\')) { Info "config.php désigne le dossier de données $lu : c'est lui qui est utilisé." }
        $DataDir = $lu
        Ok 'config.php conservé'
    } elseif (Test-Path -LiteralPath (Join-Path $ancien 'users.json')) {
        # ancienne installation sans config.php : données dans service_clients\data ; on les recopie hors de wwwroot
        if (Test-Path -LiteralPath (Join-Path $DataDir 'users.json')) { Echec "Des données existent à la fois dans $ancien et dans $DataDir : choisissez le bon dossier avec -DataDir." }
        & robocopy.exe $ancien $DataDir /E /COPY:DAT /R:1 /W:1 /NFL /NDL /NJH /NJS | Out-Null
        if ($LASTEXITCODE -ge 8) { Echec "Copie des données de $ancien vers $DataDir impossible (robocopy $LASTEXITCODE)." }
        Alerte "Données recopiées de $ancien vers $DataDir (l'original est conservé : supprimez-le après vérification)."
    }
    Info "Données : $DataDir"
    if (-not (Test-Path -LiteralPath $DataDir)) { New-Item -ItemType Directory -Path $DataDir | Out-Null; Ok "Créé : $DataDir" }
    # accès réservé : SYSTEM et administrateurs (contrôle total), pool de l'outil (modification) ; héritage coupé
    # (sinon « Utilisateurs », IIS_IUSRS et les autres pools, dont celui du planning, liraient comptes et dossiers clients)
    & icacls.exe $DataDir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "${comptePool}:(OI)(CI)M" /T /Q | Out-Null
    $codeAcl = $LASTEXITCODE
    & icacls.exe $DataDir /remove:g 'IIS_IUSRS' 'IUSR' '*S-1-5-32-545' /T /Q | Out-Null        # anciens droits trop larges éventuels
    if ($codeAcl -ne 0) { Alerte "Droits du dossier $DataDir non appliqués (icacls $codeAcl) : donnez « Modifier » à $comptePool et retirez « Utilisateurs »." }
    else { Ok "Dossier des données réservé au pool « $pool » ($comptePool) et aux administrateurs" }
    if (-not (Test-Path -LiteralPath $config)) {
        $dataPhp = $DataDir.Replace('\', '\\').Replace("'", "\'")
        Ecrire-Utf8 $config ("<?php`n// Réglages propres à ce serveur (créé par installer-service-clients.ps1, jamais remplacé lors d'une mise à jour).`n" +
                             "`$DATA_DIR = '$dataPhp';`n")
        Ok "config.php créé (données : $DataDir)"
    }

    # =============================================================================================
    Etape '6. Fichiers de l''application'
    $memeDossier = (Resolve-Path -LiteralPath $Source).Path.TrimEnd('\') -eq (Resolve-Path -LiteralPath $Destination).Path.TrimEnd('\')
    if ($memeDossier) { Ok 'Le script est lancé depuis le dossier publié : aucune copie nécessaire' }
    else {
        $existants = @($Fichiers | Where-Object { Test-Path -LiteralPath (Join-Path $Destination $_) })
        if ($existants.Count) {
            $archive = Join-Path $DataDir ("versions\{0:yyyyMMdd-HHmmss}" -f (Get-Date))
            New-Item -ItemType Directory -Path $archive -Force | Out-Null
            foreach ($f in $existants) { Copy-Item -LiteralPath (Join-Path $Destination $f) -Destination $archive }
            Info "Ancienne version mise de côté : $archive"
        }
        foreach ($f in $Fichiers) {
            $src = Join-Path $Source $f
            if (Test-Path -LiteralPath $src) { Copy-Item -LiteralPath $src -Destination (Join-Path $Destination $f) -Force }
        }
        Ok ("Copiés : " + ($Fichiers -join ', '))
    }
    Get-ChildItem -LiteralPath $Destination -File | Unblock-File -ErrorAction SilentlyContinue
    # le dossier publié : lecture seule pour IIS (les données vivent ailleurs)
    & icacls.exe $Destination /grant "${comptePool}:(OI)(CI)RX" /Q | Out-Null

    # =============================================================================================
    Etape '7. Mot de passe super administrateur'
    $saFichier = Join-Path $DataDir 'superadmin.json'
    if (-not (Test-Path -LiteralPath $saFichier) -or $MotDePasseSuperAdmin) {
        Info 'Ce mot de passe sert à la mise en service (création du premier accès super administrateur),'
        Info 'à la restauration des sauvegardes et aux opérations sensibles. 12 caractères minimum. Notez-le en lieu sûr.'
        while ($true) {
            $p1 = Read-Host -AsSecureString 'Mot de passe super administrateur'
            $p2 = Read-Host -AsSecureString 'Confirmation'
            $b1 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p1); $b2 = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p2)
            try { $t1 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b1); $t2 = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($b2) }
            finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b1); [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b2) }
            if ($t1 -ne $t2) { Write-Host '   Les deux saisies diffèrent.' -ForegroundColor Yellow; continue }
            if ($t1.Length -lt 12) { Write-Host '   12 caractères minimum.' -ForegroundColor Yellow; continue }
            break
        }
        # empreinte bcrypt calculée par PHP ; le mot de passe passe par l'entrée standard (jamais sur la ligne de commande),
        # en UTF-8 comme les navigateurs (Windows PowerShell envoie de l'ASCII par défaut : les accents seraient perdus).
        # Pas de guillemets doubles dans le code PHP : Windows PowerShell 5.1 les supprimerait (chr(13).chr(10) = fin de ligne).
        $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        $hash = (($t1 | Php -n -r '$p = rtrim((string)fgets(STDIN), chr(13).chr(10)); echo password_hash($p, PASSWORD_DEFAULT);') -join '').Trim()
        $t1 = $null; $t2 = $null
        if ($hash -notmatch '^\$2y\$') { Echec 'Calcul de l''empreinte du mot de passe impossible avec PHP.' }
        $json = '{"hash":"' + $hash + '","changed":"' + (Get-Date -Format 'yyyy-MM-ddTHH:mm:sszzz') + '","by":"script d''installation"}'
        Ecrire-Utf8 $saFichier $json
        Ok 'Mot de passe super administrateur enregistré (empreinte bcrypt seulement)'
    } else { Ok 'Mot de passe super administrateur déjà défini (relancez avec -MotDePasseSuperAdmin pour le changer)' }

    # =============================================================================================
    Etape '8. Tests'
    # adresse du site d'après ses liaisons (http de préférence, sinon https ; port et nom d'hôte compris)
    $url = "http://localhost/$AppFolder"
    $liaisons = @(Get-WebBinding -Name $Site)
    $liaison = $liaisons | Where-Object { $_.protocol -eq 'http' } | Select-Object -First 1
    if (-not $liaison) { $liaison = $liaisons | Where-Object { $_.protocol -eq 'https' } | Select-Object -First 1 }
    if ($liaison) {
        $morceaux = [string]$liaison.bindingInformation -split ':'          # adresse:port:nom d'hôte (« [::]:80: » compris)
        $port = $morceaux[-2]; $hote = $morceaux[-1]
        if (-not $hote) { $ipL = ($morceaux[0..($morceaux.Count - 3)] -join ':'); $hote = if ($ipL -and $ipL -ne '*' -and $ipL -notmatch '^\[?::\]?$') { $ipL } else { 'localhost' } }
        $proto = [string]$liaison.protocol
        $defaut = ($proto -eq 'http' -and $port -eq '80') -or ($proto -eq 'https' -and $port -eq '443')
        $url = "${proto}://$hote$(if (-not $defaut) { ':' + $port })/$AppFolder"
    }
    Info "Adresse testée : $url/"
    function Tester([string]$u) {
        try { $r = Invoke-WebRequest -UseBasicParsing -Uri $u -TimeoutSec 30; return @{ code = [int]$r.StatusCode; body = [string]$r.Content } }
        catch { $resp = $_.Exception.Response; if ($resp) { $code = [int]$resp.StatusCode; $txt = ''; try { $txt = (New-Object IO.StreamReader($resp.GetResponseStream())).ReadToEnd() } catch { }; return @{ code = $code; body = $txt } }; return @{ code = 0; body = $_.Exception.Message } }
    }
    $page = Tester "$url/"
    if ($page.code -eq 200 -and $page.body -match 'Service clients') { Ok "Page : $url/" } else { Alerte "Page $url/ : réponse $($page.code). Vérifiez le document par défaut et les droits du dossier." }
    $api = Tester "$url/api.php?a=ping"
    if ($api.code -eq 200 -and $api.body -match '"ok":true') { Ok 'API PHP : répond' }
    elseif ($api.code -eq 404 -or $api.body -match '404\.3') { Alerte 'API : IIS ne transmet pas les .php à PHP (erreur 404.3) : vérifiez l''étape 4.' }
    elseif ($api.body -match 'Accès refusé pour l|proxy non déclaré') { Alerte ('API : ' + $api.body + ' ($ALLOWED_NETS / $TRUSTED_PROXIES dans config.php)') }
    elseif ($api.body -match 'données') { Alerte ("API : " + $api.body) }
    else { Alerte "API : réponse $($api.code) — $($api.body.Substring(0, [Math]::Min(300, $api.body.Length)))" }
    $fuites = 0
    foreach ($interdit in @('config.php', '.user.ini', 'installer-service-clients.ps1', 'LISEZMOI.md', 'data/users.json')) {
        $t = Tester "$url/$interdit"
        if ($t.code -eq 200 -and $t.body.Length -gt 0) { Alerte "$interdit est accessible depuis le web : vérifiez web.config."; $fuites++ }
    }
    if (-not $fuites) { Ok 'Fichiers sensibles non servis par IIS' }

    # =============================================================================================
    if ($SauvegardePlanifiee) {
        Etape '9. Tâche planifiée des sauvegardes'
        $secFichier = Join-Path $DataDir 'security.json'
        $sec = @{}
        if (Test-Path -LiteralPath $secFichier) { $o = Get-Content -LiteralPath $secFichier -Raw -Encoding UTF8 | ConvertFrom-Json; foreach ($p in $o.PSObject.Properties) { $sec[$p.Name] = $p.Value } }
        if (-not $sec['cronKey'] -or ([string]$sec['cronKey']).Length -lt 32) {
            $octets = New-Object byte[] 20; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($octets)
            $sec['cronKey'] = -join ($octets | ForEach-Object { $_.ToString('x2') })
            Ecrire-Utf8 $secFichier ($sec | ConvertTo-Json -Depth 6)
        }
        $cible = "$url/api.php?a=cron&key=$($sec['cronKey'])"
        $commande = "try { Invoke-WebRequest -UseBasicParsing -Uri '$cible' -TimeoutSec 300 | Out-Null } catch { exit 1 }"
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command `"$commande`""
        $declencheur = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650)
        $options = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10)
        Register-ScheduledTask -TaskName 'D8 Service clients - sauvegardes' -Action $action -Trigger $declencheur -Settings $options -User 'SYSTEM' -RunLevel Highest -Force `
            -Description 'Sauvegardes complètes programmées et durée de conservation des dossiers (outil Service clients D8). Si la clé est régénérée dans l''outil, relancez le script avec -SauvegardePlanifiee.' | Out-Null
        Ok "Tâche « D8 Service clients - sauvegardes » créée (toutes les 30 minutes, $url)"
    }

    # =============================================================================================
    Write-Host ''
    Write-Host '========================================================================' -ForegroundColor White
    if ($Avertissements.Count) { Write-Host "Terminé avec $($Avertissements.Count) point(s) à vérifier :" -ForegroundColor Yellow; $Avertissements | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow } }
    else { Write-Host 'Installation terminée sans erreur.' -ForegroundColor Green }
    $nom = [System.Net.Dns]::GetHostName()
    Write-Host ''
    Write-Host "Adresse pour les postes : http://$nom/$AppFolder/   (HTTPS conseillé : liaison https sur le site IIS)"
    Write-Host 'Première ouverture : choisissez un super administrateur, saisissez le mot de passe super administrateur,'
    Write-Host 'puis votre propre mot de passe. Invitez ensuite les autres personnes (Administration › Utilisateurs).'
    Write-Host "Données : $DataDir   ·   Journal de l'installation : $journal"
}
finally {
    try { Stop-Transcript | Out-Null } catch { }
}
