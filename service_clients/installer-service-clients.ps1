<#
.SYNOPSIS
    Installe ou met à jour l'outil « Service clients D8 » sur IIS et active PHP pour son dossier.

.DESCRIPTION
    À lancer sur le serveur IIS, en administrateur (le script se relance seul en administrateur) :

        powershell -NoProfile -ExecutionPolicy Bypass -File .\installer-service-clients.ps1

    ou double-cliquez sur installer-service-clients.cmd.

    Étapes (chacune est vérifiée et sautée si elle est déjà faite) :
      1. Rôles IIS nécessaires : serveur web, CGI/FastCGI, filtrage des requêtes, outils PowerShell d'IIS.
      2. PHP : réutilise le PHP déjà déclaré dans IIS (celui du planning D8), sinon un PHP existant
         (-PhpPath), une archive (-PhpZip) ou un téléchargement officiel (-InstallPhp).
      3. Extensions PHP indispensables (mbstring, openssl) et réglages FastCGI.
      4. Traitement des fichiers .php déclaré pour le seul dossier service_clients (pas pour tout le site).
      5. Copie de index.html, formulaire.html, api.php, web.config, .user.ini (config.php et les données ne sont jamais écrasés ;
         l'ancienne version est mise de côté).
      6. Dossier de données HORS de la racine web, droits d'écriture pour le pool d'applications IIS.
      7. Mot de passe super administrateur (demandé s'il n'existe pas encore) : il sert à la mise en service
         et aux opérations sensibles (restauration…). Il n'y a pas de mot de passe par défaut.
      8. Facultatif (-SauvegardePlanifiee) : tâche planifiée Windows pour les sauvegardes et la durée de
         conservation, même quand personne n'a l'outil ouvert.
      9. Tests : page, API PHP, données non accessibles depuis le web.

    Rien n'est supprimé. Relancez le script après chaque mise à jour des fichiers : il ne fait que ce qui manque.

.PARAMETER Destination
    Dossier publié par IIS. Par défaut : C:\inetpub\wwwroot\service_clients

.PARAMETER Site
    Site IIS qui contient le dossier. Par défaut : Default Web Site

.PARAMETER DataDir
    Dossier des données (comptes, dossiers clients, sauvegardes). Par défaut : C:\inetpub\service_clients_data
    (hors de wwwroot : jamais servi par IIS, même en cas d'erreur de configuration).

.PARAMETER PhpPath
    Dossier d'un PHP existant (contenant php-cgi.exe), si la détection automatique ne le trouve pas.

.PARAMETER PhpZip
    Archive officielle PHP pour Windows (« Non Thread Safe » x64) à installer si PHP est absent.

.PARAMETER InstallPhp
    Télécharge et installe PHP (version -PhpVersion) depuis windows.php.net si PHP est absent.

.PARAMETER PhpVersion
    Version de PHP à télécharger avec -InstallPhp. Par défaut : 8.3

.PARAMETER CorrigerPhpIni
    Active dans php.ini les extensions manquantes (mbstring, openssl, fileinfo) d'un PHP déjà installé.
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
$Source = $PSScriptRoot
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$Fichiers = @('index.html', 'formulaire.html', 'api.php', 'web.config', '.user.ini')
$AppFolder = Split-Path -Leaf $Destination
$Avertissements = New-Object System.Collections.ArrayList

function Etape([string]$t) { Write-Host ''; Write-Host "== $t" -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "   [OK] $t" -ForegroundColor Green }
function Info([string]$t) { Write-Host "   $t" }
function Alerte([string]$t) { Write-Host "   [!] $t" -ForegroundColor Yellow; [void]$Avertissements.Add($t) }
function Echec([string]$t) { Write-Host ''; Write-Host "[ÉCHEC] $t" -ForegroundColor Red; throw $t }
function Ecrire-Utf8([string]$Chemin, [string]$Texte) { [System.IO.File]::WriteAllText($Chemin, $Texte, $Utf8NoBom) }

# --- relance en administrateur -------------------------------------------------------------------
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Droits administrateur nécessaires : relance du script en administrateur…' -ForegroundColor Yellow
    $relance = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$PSCommandPath`"")
    foreach ($p in $PSBoundParameters.GetEnumerator()) {
        if ($p.Value -is [System.Management.Automation.SwitchParameter]) { if ($p.Value.IsPresent) { $relance += "-$($p.Key)" } }
        else { $relance += "-$($p.Key)"; $relance += "`"$($p.Value)`"" }
    }
    Start-Process -FilePath 'powershell.exe' -ArgumentList $relance -Verb RunAs
    return
}

$journal = Join-Path $env:TEMP ("installation-service-clients-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))
try { Start-Transcript -Path $journal -ErrorAction SilentlyContinue | Out-Null } catch { }
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

try {
    Write-Host 'D8 · Service clients — installation / mise à jour sur IIS' -ForegroundColor White
    Info "Source : $Source"
    Info "Destination : $Destination  (site « $Site »)"
    Info "Données : $DataDir"
    foreach ($f in @('index.html', 'api.php', 'web.config')) {
        if (-not (Test-Path -LiteralPath (Join-Path $Source $f))) { Echec "Fichier $f introuvable à côté du script ($Source). Copiez tout le dossier service_clients avant de lancer le script." }
    }

    # =============================================================================================
    Etape '1. Rôles IIS'
    $estServeur = [bool](Get-Command -Name Install-WindowsFeature -ErrorAction SilentlyContinue)
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
    $racine = [Environment]::ExpandEnvironmentVariables((Get-Item "IIS:\Sites\$Site").physicalPath)
    Ok "IIS prêt (site « $Site », racine $racine)"

    # =============================================================================================
    Etape '2. PHP'
    $phpCgi = $null
    if ($PhpPath) {
        $phpCgi = Join-Path $PhpPath 'php-cgi.exe'
        if (-not (Test-Path -LiteralPath $phpCgi)) { Echec "php-cgi.exe introuvable dans $PhpPath" }
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
        # Visual C++ 2015-2022 (x64) : indispensable aux versions officielles de PHP 8
        $vc = Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64' -ErrorAction SilentlyContinue
        if (-not $vc -or $vc.Installed -ne 1 -or $vc.Major -lt 14) {
            if ($InstallPhp) {
                Info 'Installation de Visual C++ 2015-2022 (x64)…'
                $vcExe = Join-Path $env:TEMP 'vc_redist.x64.exe'
                Invoke-WebRequest -UseBasicParsing -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vcExe
                $p = Start-Process -FilePath $vcExe -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
                if ($p.ExitCode -notin 0, 1638, 3010) { Echec "Installation de Visual C++ échouée (code $($p.ExitCode))." }
            } else { Alerte 'Visual C++ 2015-2022 (x64) semble absent : PHP risque de ne pas démarrer (https://aka.ms/vs/17/release/vc_redist.x64.exe).' }
        }
        $zip = $PhpZip
        if (-not $zip) {
            Info "Recherche de la dernière version PHP $PhpVersion (Non Thread Safe, x64) sur windows.php.net…"
            $rel = Invoke-RestMethod -UseBasicParsing -Uri 'https://windows.php.net/downloads/releases/releases.json'
            $branche = $rel.$PhpVersion
            if (-not $branche) { Echec "Version PHP $PhpVersion introuvable sur windows.php.net." }
            $build = $branche.PSObject.Properties | Where-Object { $_.Name -match '^nts-vs\d+-x64$' } | Select-Object -First 1
            if (-not $build) { Echec "Aucune version Non Thread Safe x64 pour PHP $PhpVersion." }
            $zip = Join-Path $env:TEMP $build.Value.zip.path
            Invoke-WebRequest -UseBasicParsing -Uri ("https://windows.php.net/downloads/releases/" + $build.Value.zip.path) -OutFile $zip
            $hash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash
            if ($build.Value.zip.sha256 -and $hash -ne $build.Value.zip.sha256.ToUpper()) { Echec 'Empreinte SHA-256 de l''archive PHP incorrecte : téléchargement corrompu.' }
            Ok "Téléchargé : $($build.Value.zip.path) (PHP $($branche.version))"
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
    $phpIni = Join-Path $phpDir 'php.ini'
    if (-not (Test-Path -LiteralPath $phpIni)) {
        $modele = Join-Path $phpDir 'php.ini-production'
        if (-not (Test-Path -LiteralPath $modele)) { Echec "php.ini et php.ini-production absents de $phpDir" }
        Copy-Item -LiteralPath $modele -Destination $phpIni
        $phpNeuf = $true
        Info 'php.ini créé à partir de php.ini-production'
    }
    $version = (& $phpExe -n -r 'echo PHP_VERSION;' 2>$null)
    if (-not $version) { Echec "PHP ne démarre pas ($phpExe). Installez Visual C++ 2015-2022 x64, ou relancez avec -InstallPhp." }
    if ([version]($version -replace '[^\d.].*$', '') -lt [version]'7.4') { Echec "PHP $version trop ancien : 7.4 minimum (8.3 conseillé)." }
    Ok "PHP $version ($phpDir)"

    # =============================================================================================
    Etape '3. Extensions et réglages PHP'
    $ini = [IO.File]::ReadAllText($phpIni)
    $iniAvant = $ini
    $modules = (& $phpExe -m 2>$null) -join "`n"
    $requis = @('mbstring', 'openssl', 'session', 'json')
    $absents = @($requis | Where-Object { $modules -notmatch "(?im)^$_$" })
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
        # PHP neuf : réglages conseillés pour IIS
        $reglages = [ordered]@{ 'extension_dir' = '"ext"'; 'cgi.force_redirect' = '0'; 'cgi.fix_pathinfo' = '1'; 'fastcgi.impersonate' = '1'; 'date.timezone' = 'Europe/Paris'; 'display_errors' = 'Off'; 'log_errors' = 'On' }
        foreach ($k in $reglages.Keys) {
            $motif = '(?im)^\s*;?\s*' + [regex]::Escape($k) + '\s*=.*$'
            if ($ini -match $motif) { $ini = [regex]::Replace($ini, $motif, "$k = $($reglages[$k])", 1) } else { $ini += "`r`n$k = $($reglages[$k])" }
        }
    }
    if ($ini -ne $iniAvant) {
        Copy-Item -LiteralPath $phpIni -Destination ("$phpIni.{0:yyyyMMdd-HHmmss}.bak" -f (Get-Date))
        [IO.File]::WriteAllText($phpIni, $ini, (New-Object System.Text.UTF8Encoding($false)))
        Ok "php.ini mis à jour (copie de l'ancien : $phpIni.*.bak)"
        $modules = (& $phpExe -m 2>$null) -join "`n"
    }
    $toujours = @($requis | Where-Object { $modules -notmatch "(?im)^$_$" })
    if (-not $toujours.Count) { Ok 'Extensions mbstring, openssl, session, json présentes' }
    elseif ($phpNeuf -or $CorrigerPhpIni) { Alerte ('Extensions toujours absentes après correction de php.ini : ' + ($toujours -join ', ') + " (vérifiez extension_dir et le dossier ext de $phpDir)") }

    # =============================================================================================
    Etape '4. FastCGI et traitement des .php pour le dossier'
    $fcgi = Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "/system.webServer/fastCgi/application[@fullPath='$phpCgi']"
    if (-not $fcgi) {
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter '/system.webServer/fastCgi' -Value @{ fullPath = $phpCgi; activityTimeout = 600; requestTimeout = 600; instanceMaxRequests = 10000 }
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "/system.webServer/fastCgi/application[@fullPath='$phpCgi']/environmentVariables" -Value @{ name = 'PHP_FCGI_MAX_REQUESTS'; value = '10000' }
        Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "/system.webServer/fastCgi/application[@fullPath='$phpCgi']/environmentVariables" -Value @{ name = 'PHPRC'; value = $phpDir }
        Ok "Application FastCGI déclarée : $phpCgi"
    } else { Ok 'Application FastCGI déjà déclarée' }

    if (-not (Test-Path -LiteralPath $Destination)) { New-Item -ItemType Directory -Path $Destination | Out-Null }
    $emplacement = "$Site/$AppFolder"
    $gestionnaires = @(Get-WebConfiguration -PSPath "IIS:\Sites\$Site\$AppFolder" -Filter '/system.webServer/handlers/add' | Where-Object { $_.path -eq '*.php' })
    $actif = $gestionnaires | Where-Object { $_.modules -eq 'FastCgiModule' -and $_.scriptProcessor -match 'php-cgi\.exe' } | Select-Object -First 1
    if (-not $actif) {
        Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $emplacement -Filter '/system.webServer/handlers' -Name '.' -Value @{
            name = 'PHP_service_clients'; path = '*.php'; verb = 'GET,HEAD,POST'; modules = 'FastCgiModule'; scriptProcessor = $phpCgi; resourceType = 'Either'; requireAccess = 'Script' }
        Ok "Fichiers .php confiés à PHP pour « $emplacement » seulement"
    } else {
        Ok "PHP déjà actif pour ce dossier ($($actif.scriptProcessor))"
        if ($actif.scriptProcessor -ne $phpCgi) { Info "(le dossier utilise $($actif.scriptProcessor))" }
    }

    # =============================================================================================
    Etape '5. Fichiers de l''application'
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

    # =============================================================================================
    Etape '6. Dossier de données et droits'
    if (-not (Test-Path -LiteralPath $DataDir)) { New-Item -ItemType Directory -Path $DataDir | Out-Null; Ok "Créé : $DataDir" }
    $config = Join-Path $Destination 'config.php'
    $dataPhp = $DataDir.Replace('\', '\\').Replace("'", "\'")
    if (-not (Test-Path -LiteralPath $config)) {
        Ecrire-Utf8 $config ("<?php`n// Réglages propres à ce serveur (créé par installer-service-clients.ps1, jamais remplacé lors d'une mise à jour).`n" +
                             "`$DATA_DIR = '$dataPhp';`n")
        Ok "config.php créé (données : $DataDir)"
    } else {
        $contenu = [IO.File]::ReadAllText($config)
        if ($contenu.Length -and [int][char]$contenu[0] -eq 0xFEFF) { Ecrire-Utf8 $config $contenu.TrimStart([char]0xFEFF); Alerte 'config.php commençait par un BOM UTF-8 : retiré (il cassait les réponses de l''API).' }
        if ($contenu -notmatch [regex]::Escape($dataPhp)) { Alerte "config.php existe déjà et ne désigne pas $DataDir : il est conservé tel quel." } else { Ok 'config.php conservé' }
    }
    $pool = (Get-Item "IIS:\Sites\$Site").applicationPool
    $comptes = @('IIS_IUSRS', 'IUSR', "IIS AppPool\$pool")
    foreach ($c in $comptes) {
        & icacls.exe $DataDir /grant "${c}:(OI)(CI)M" /T /Q | Out-Null
        if ($LASTEXITCODE -ne 0) { Alerte "Droit d'écriture non accordé à « $c » sur $DataDir" }
    }
    # le dossier publié : lecture seule pour IIS (les données vivent ailleurs)
    & icacls.exe $Destination /grant "IIS_IUSRS:(OI)(CI)RX" /Q | Out-Null
    Ok "Écriture autorisée sur $DataDir pour : $($comptes -join ', ')"

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
        # en UTF-8 comme les navigateurs (Windows PowerShell envoie de l'ASCII par défaut : les accents seraient perdus)
        $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
        $hash = ($t1 | & $phpExe -n -r '$p = rtrim((string)fgets(STDIN), "\r\n"); echo password_hash($p, PASSWORD_DEFAULT);')
        $t1 = $null; $t2 = $null
        if ($hash -notmatch '^\$2y\$') { Echec 'Calcul de l''empreinte du mot de passe impossible avec PHP.' }
        $json = '{"hash":"' + $hash + '","changed":"' + (Get-Date -Format 'yyyy-MM-ddTHH:mm:sszzz') + '","by":"script d''installation"}'
        Ecrire-Utf8 $saFichier $json
        Ok 'Mot de passe super administrateur enregistré (empreinte bcrypt seulement)'
    } else { Ok 'Mot de passe super administrateur déjà défini (relancez avec -MotDePasseSuperAdmin pour le changer)' }

    # =============================================================================================
    Etape '8. Tests'
    $url = "http://localhost/$AppFolder"
    if ($Site -ne 'Default Web Site') {
        $liaison = (Get-WebBinding -Name $Site | Where-Object { $_.protocol -eq 'http' } | Select-Object -First 1)
        if ($liaison) { $port = ($liaison.bindingInformation -split ':')[1]; $url = "http://localhost:$port/$AppFolder" }
    }
    function Tester([string]$u) {
        try { $r = Invoke-WebRequest -UseBasicParsing -Uri $u -TimeoutSec 30; return @{ code = [int]$r.StatusCode; body = [string]$r.Content } }
        catch { $resp = $_.Exception.Response; if ($resp) { $code = [int]$resp.StatusCode; $txt = ''; try { $txt = (New-Object IO.StreamReader($resp.GetResponseStream())).ReadToEnd() } catch { }; return @{ code = $code; body = $txt } }; return @{ code = 0; body = $_.Exception.Message } }
    }
    $page = Tester "$url/"
    if ($page.code -eq 200 -and $page.body -match 'Service clients') { Ok "Page : $url/" } else { Alerte "Page $url/ : réponse $($page.code). Vérifiez le document par défaut et les droits du dossier." }
    $api = Tester "$url/api.php?a=ping"
    if ($api.code -eq 200 -and $api.body -match '"ok":true') { Ok 'API PHP : répond' }
    elseif ($api.code -eq 404 -or $api.body -match '404\.3') { Alerte 'API : IIS ne transmet pas les .php à PHP (erreur 404.3) : vérifiez l''étape 4.' }
    elseif ($api.body -match 'Accès refusé pour l') { Alerte 'API : adresse refusée par $ALLOWED_NETS (config.php).' }
    elseif ($api.body -match 'données') { Alerte ("API : " + $api.body) }
    else { Alerte "API : réponse $($api.code) — $($api.body.Substring(0, [Math]::Min(300, $api.body.Length)))" }
    foreach ($interdit in @('config.php', '.user.ini', 'installer-service-clients.ps1', 'data/users.json')) {
        $t = Tester "$url/$interdit"
        if ($t.code -eq 200 -and $t.body.Length -gt 0) { Alerte "$interdit est accessible depuis le web : vérifiez web.config." }
    }
    Ok 'Fichiers sensibles non servis par IIS'

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
        Register-ScheduledTask -TaskName 'D8 Service clients - sauvegardes' -Action $action -Trigger $declencheur -User 'SYSTEM' -RunLevel Highest -Force `
            -Description 'Sauvegardes complètes programmées et durée de conservation des dossiers (outil Service clients D8).' | Out-Null
        Ok 'Tâche « D8 Service clients - sauvegardes » créée (toutes les 30 minutes)'
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
