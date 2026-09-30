<#
.SYNOPSIS
    Installe (ou met à jour) D8 Support sur Windows avec IIS, en un seul lancement.

.DESCRIPTION
    Le plus simple : double-cliquer sur INSTALLER.cmd, qui lance ce script.

    Le script fait tout, dans l'ordre :
      1. active IIS et FastCGI (fonctionnalités Windows) ;
      2. installe le runtime Visual C++ dont PHP a besoin ;
      3. installe PHP (x64, Non Thread Safe) dans C:\PHP avec un php.ini prêt à
         l'emploi ;
      4. copie index.php et web.config dans C:\inetpub\wwwroot\ticketing et
         donne à IIS le droit d'écrire dans data\ (et nulle part ailleurs) ;
      5. crée l'application IIS /ticketing avec son propre pool et y branche PHP ;
      6. ouvre le port HTTP dans le pare-feu Windows ;
      7. vérifie que tout répond et affiche le code d'installation ;
      8. programme une sauvegarde quotidienne.

    Relancer le script met à jour : nouvel index.php, correctifs de PHP.
    Le dossier data\ (base, pièces jointes) n'est jamais écrasé.

    Sans accès Internet : déposer à côté de ce script le zip de PHP
    (php-8.x.y-nts-Win32-vs17-x64.zip, https://windows.php.net/download/) et
    vc_redist.x64.exe ; ils seront utilisés à la place du téléchargement.

.EXAMPLE
    .\installer-iis.ps1
    Installation standard : http://<serveur>/ticketing/

.EXAMPLE
    .\installer-iis.ps1 -NomApplication support -HeureSauvegarde 12:30
    Application publiée sur http://<serveur>/support/, sauvegarde à 12 h 30.
#>
[CmdletBinding()]
param(
    # Site IIS qui accueille l'application.
    [string]$Site = 'Default Web Site',
    # Nom dans l'adresse : http://<serveur>/<NomApplication>/
    [string]$NomApplication = 'ticketing',
    # Dossier de l'application. Défaut : C:\inetpub\wwwroot\<NomApplication>
    [string]$Dossier = '',
    # Dossier de PHP. Défaut : C:\PHP (ou C:\PHP-D8Support si C:\PHP contient
    # déjà un PHP installé à la main, qui n'est alors pas touché).
    [string]$DossierPHP = '',
    # Branche de PHP à installer (la dernière version corrective est prise).
    [string]$VersionPHP = '8.4',
    # Dossier des sauvegardes quotidiennes. Défaut : C:\Sauvegardes\D8Support
    [string]$DossierSauvegardes = '',
    [string]$HeureSauvegarde = '22:00',
    [int]$SauvegardesAGarder = 14,
    [switch]$SansSauvegarde,
    # Ne pas attendre « Entrée » à la fin (installation automatisée).
    [switch]$SansPause
)

Set-StrictMode -Version 1
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# ------------------------------------------------------------------------------
#  Droits administrateur (et PowerShell 64 bits) : on se relance si besoin.
# ------------------------------------------------------------------------------
$identite = [Security.Principal.WindowsIdentity]::GetCurrent()
$estAdmin = (New-Object Security.Principal.WindowsPrincipal $identite).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $estAdmin -or -not [Environment]::Is64BitProcess) {
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    $natif = Join-Path $env:SystemRoot 'Sysnative\WindowsPowerShell\v1.0\powershell.exe'
    if (-not [Environment]::Is64BitProcess -and (Test-Path $natif)) { $powershell = $natif }

    # Un lecteur réseau (Z:) n'existe pas dans la session administrateur : on passe par \\serveur\partage.
    $cheminScript = $PSCommandPath
    try {
        $lecteur = Get-CimInstance -ClassName Win32_LogicalDisk -Filter ("DeviceID='{0}'" -f $cheminScript.Substring(0, 2))
        if ($lecteur -and $lecteur.DriveType -eq 4 -and $lecteur.ProviderName) {
            $cheminScript = $lecteur.ProviderName + $cheminScript.Substring(2)
        }
    } catch { }

    $relance = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $cheminScript))
    foreach ($p in $PSBoundParameters.GetEnumerator()) {
        if ($p.Value -is [System.Management.Automation.SwitchParameter]) {
            if ($p.Value.IsPresent) { $relance += '-' + $p.Key }
        } else {
            $relance += '-' + $p.Key
            $relance += '"{0}"' -f ([string]$p.Value).TrimEnd('\')
        }
    }
    try {
        Start-Process -FilePath $powershell -ArgumentList $relance -Verb RunAs
    } catch {
        Write-Host "Les droits administrateur sont nécessaires : installation annulée." -ForegroundColor Red
        exit 1
    }
    exit 0
}

# ------------------------------------------------------------------------------
#  Réglages et constantes
# ------------------------------------------------------------------------------
foreach ($nom in 'Dossier', 'DossierPHP', 'DossierSauvegardes') {
    Set-Variable -Name $nom -Value ((Get-Variable -Name $nom -ValueOnly).TrimEnd('\'))
}
if (-not $Dossier) { $Dossier = Join-Path $env:SystemDrive "inetpub\wwwroot\$NomApplication" }
if (-not $DossierSauvegardes) { $DossierSauvegardes = Join-Path $env:SystemDrive 'Sauvegardes\D8Support' }

$NomPool         = 'D8Support'
$NomGestionnaire = 'PHP-D8Support'
$NomTache        = 'D8 Support - sauvegarde quotidienne'
$Marqueur        = 'installe-par-d8support.txt'
$appcmd          = Join-Path $env:SystemRoot 'System32\inetsrv\appcmd.exe'
$data            = Join-Path $Dossier 'data'
$cheminIIS       = "$Site/$NomApplication"

# Comptes désignés par leur SID : les noms sont traduits sur un Windows français.
$ADMINS    = '*S-1-5-32-544'
$SYSTEME   = '*S-1-5-18'
$USERS     = '*S-1-5-32-545'
$IUSR      = '*S-1-5-17'       # compte anonyme d'IIS (celui qu'emprunte PHP)
$IIS_IUSRS = '*S-1-5-32-568'   # groupe des pools d'applications IIS

$redemarrage = $false

# ------------------------------------------------------------------------------
#  Outils
# ------------------------------------------------------------------------------
function Etape([string]$Texte) { Write-Host ''; Write-Host "==> $Texte" -ForegroundColor Cyan }
function Ok([string]$Texte) { Write-Host "    [OK] $Texte" -ForegroundColor Green }
function Info([string]$Texte) { Write-Host "    $Texte" }
function Attention([string]$Texte) { Write-Host "    [!] $Texte" -ForegroundColor Yellow }

# Lance un programme, renvoie son code de sortie et sa sortie (stdout + stderr).
function Lancer {
    param(
        [Parameter(Mandatory = $true)][string]$Programme,
        [string[]]$Arguments = @(),
        [switch]$SansErreur,
        [switch]$Utf8
    )
    $ancienEAP = $ErrorActionPreference
    $ancienEncodage = $null
    $ErrorActionPreference = 'Continue'
    if ($Utf8) {
        try {
            $ancienEncodage = [Console]::OutputEncoding
            [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding $false
        } catch { $ancienEncodage = $null }
    }
    try {
        $sortie = & $Programme @Arguments 2>&1 | ForEach-Object { "$_" }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $ancienEAP
        if ($ancienEncodage) { try { [Console]::OutputEncoding = $ancienEncodage } catch { } }
    }
    $texte = (@($sortie) -join "`n").Trim()
    if (-not $SansErreur -and $code -ne 0) {
        throw ("Échec (code {0}) de : {1} {2}`n{3}" -f $code, [IO.Path]::GetFileName($Programme), ($Arguments -join ' '), $texte)
    }
    return [pscustomobject]@{ Code = $code; Sortie = $texte }
}

function AppCmd {
    param([string[]]$Arguments, [switch]$SansErreur)
    return Lancer -Programme $appcmd -Arguments $Arguments -SansErreur:$SansErreur
}

function Definir-Droits([string]$Chemin, [string[]]$Droits) {
    Lancer -Programme 'icacls.exe' -Arguments (@($Chemin) + $Droits + '/Q') | Out-Null
}

# Dossier réservé : administrateurs et système, plus les comptes IIS en écriture.
function Proteger-Dossier([string]$Chemin, [switch]$EcritureIIS) {
    $droits = @('/inheritance:r', '/grant:r', "${ADMINS}:(OI)(CI)F", "${SYSTEME}:(OI)(CI)F")
    if ($EcritureIIS) { $droits += "${IUSR}:(OI)(CI)M", "${IIS_IUSRS}:(OI)(CI)M" }
    Definir-Droits $Chemin $droits
}

function Nouveau-ClientWeb {
    $client = New-Object System.Net.WebClient
    $client.Headers['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) D8Support-installateur'
    $proxy = [System.Net.WebRequest]::GetSystemWebProxy()
    $proxy.Credentials = [System.Net.CredentialCache]::DefaultNetworkCredentials
    $client.Proxy = $proxy
    return $client
}

function Telecharger-Texte([string]$Url) {
    $client = Nouveau-ClientWeb
    $client.Encoding = [System.Text.Encoding]::UTF8
    return $client.DownloadString($Url)
}

function Telecharger-Fichier([string]$Url, [string]$Destination) {
    (Nouveau-ClientWeb).DownloadFile($Url, $Destination)
}

# Requête HTTP locale, sans proxy. Renvoie le code HTTP (0 si pas de réponse) et le corps.
function Lire-Http([string]$Url, [string]$Hote = '') {
    $requete = [System.Net.HttpWebRequest]::Create($Url)
    $requete.Proxy = $null
    $requete.Timeout = 60000
    $requete.AllowAutoRedirect = $false
    if ($Hote) { $requete.Host = $Hote }
    $reponse = $null
    try {
        $reponse = $requete.GetResponse()
    } catch {
        $ex = $_.Exception
        while ($ex -and -not ($ex -is [System.Net.WebException])) { $ex = $ex.InnerException }
        if ($ex -and $ex.Response) {
            $reponse = $ex.Response
        } else {
            $message = $_.Exception.Message
            if ($ex) { $message = $ex.Message }
            return [pscustomobject]@{ Code = 0; Corps = $message }
        }
    }
    try {
        $lecteur = New-Object System.IO.StreamReader($reponse.GetResponseStream(), [System.Text.Encoding]::UTF8)
        $corps = $lecteur.ReadToEnd()
        $lecteur.Close()
        return [pscustomobject]@{ Code = [int]$reponse.StatusCode; Corps = $corps }
    } finally {
        $reponse.Close()
    }
}

# Première liaison http du site, lue dans la ligne « SITE "…" (id:1,bindings:http/*:80:,state:Started) ».
function Lire-Liaison([string]$LigneSite) {
    $resultat = [pscustomobject]@{ Trouvee = $false; Ip = '127.0.0.1'; Port = 80; Hote = '' }
    if ($LigneSite -match 'bindings:(.*),state:') {
        foreach ($liaison in $Matches[1].Split(',')) {
            if ($liaison -match '^http/(.+):(\d+):([^:]*)$') {
                $resultat.Trouvee = $true
                if ($Matches[1] -ne '*') { $resultat.Ip = $Matches[1] }
                $resultat.Port = [int]$Matches[2]
                $resultat.Hote = $Matches[3]
                break
            }
        }
    }
    return $resultat
}

function Version-VCRedist {
    foreach ($cle in 'HKLM:\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\x64',
                     'HKLM:\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\x64') {
        $v = Get-ItemProperty -Path $cle -ErrorAction SilentlyContinue
        if ($v -and $v.Installed -eq 1) {
            return New-Object Version ([int]$v.Major), ([int]$v.Minor), ([int]$v.Bld)
        }
    }
    return $null
}

function Installer-VCRedist {
    $exe = Join-Path $PSScriptRoot 'vc_redist.x64.exe'
    if (Test-Path $exe) {
        Info "Installeur fourni à côté du script : $exe"
    } else {
        $exe = Join-Path $env:TEMP 'vc_redist.x64.exe'
        $telecharge = $false
        foreach ($url in 'https://aka.ms/vc14/vc_redist.x64.exe', 'https://aka.ms/vs/17/release/vc_redist.x64.exe') {
            try {
                Info "Téléchargement : $url"
                Telecharger-Fichier $url $exe
                $telecharge = $true
                break
            } catch {
                Attention "Échec : $($_.Exception.Message)"
            }
        }
        if (-not $telecharge) {
            throw ("Téléchargement du runtime Visual C++ impossible. Téléchargez vc_redist.x64.exe " +
                   "(https://aka.ms/vs/17/release/vc_redist.x64.exe), déposez-le à côté de ce script et relancez.")
        }
    }
    $signature = Get-AuthenticodeSignature -FilePath $exe
    if ($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch 'O=Microsoft Corporation') {
        throw "vc_redist.x64.exe n'est pas signé par Microsoft : installation refusée."
    }
    $processus = Start-Process -FilePath $exe -ArgumentList '/install', '/quiet', '/norestart' -Wait -PassThru
    switch ($processus.ExitCode) {
        0       { Ok 'Runtime Visual C++ installé' }
        1638    { Ok 'Runtime Visual C++ déjà à jour' }
        3010    { Ok 'Runtime Visual C++ installé (redémarrage conseillé)'; $script:redemarrage = $true }
        default { throw "L'installation du runtime Visual C++ a échoué (code $($processus.ExitCode))." }
    }
}

# Version de PHP à installer : zip déposé à côté du script, sinon windows.php.net.
function Trouver-PHP {
    $zip = Get-ChildItem -Path $PSScriptRoot -Filter 'php-*-nts-Win32-*-x64.zip' -ErrorAction SilentlyContinue |
        Sort-Object Name -Descending | Select-Object -First 1
    if ($zip) {
        $version = $null
        if ($zip.Name -match '^php-(\d+\.\d+\.\d+)') { $version = [version]$Matches[1] }
        return [pscustomobject]@{ Version = $version; Fichier = $zip.FullName; Url = $null; Sha256 = $null }
    }

    $versions = Telecharger-Texte 'https://windows.php.net/downloads/releases/releases.json' | ConvertFrom-Json
    $branches = @($versions.PSObject.Properties | Where-Object { $_.Name -match '^\d+\.\d+$' })
    $branche = $branches | Where-Object { $_.Name -eq $VersionPHP } | Select-Object -First 1
    if (-not $branche) {
        $branche = $branches | Sort-Object { [version]$_.Name } -Descending | Select-Object -First 1
        if (-not $branche) { throw 'Liste des versions de PHP illisible (windows.php.net).' }
        Attention "PHP $VersionPHP n'est plus proposé par windows.php.net : PHP $($branche.Name) sera utilisé."
    }
    $variante = $branche.Value.PSObject.Properties |
        Where-Object { $_.Name -match '^nts-vs\d+-x64$' } |
        Sort-Object { if ($_.Name -match 'vs(\d+)') { [int]$Matches[1] } else { 0 } } -Descending |
        Select-Object -First 1
    if (-not $variante) { throw "Aucune version x64 « Non Thread Safe » de PHP $($branche.Name) sur windows.php.net." }
    return [pscustomobject]@{
        Version = [version]$branche.Value.version
        Fichier = $null
        Url     = 'https://windows.php.net/downloads/releases/' + $variante.Value.zip.path
        Sha256  = $variante.Value.zip.sha256
    }
}

# Arrête le pool de l'application et les processus PHP de ce dossier (fichiers verrouillés sinon).
function Arreter-PHP {
    if (Test-Path $appcmd) { AppCmd @('stop', 'apppool', $NomPool) -SansErreur | Out-Null }
    Get-Process -Name 'php-cgi', 'php' -ErrorAction SilentlyContinue | Where-Object {
        try { $_.Path -and $_.Path.StartsWith($DossierPHP + '\', [StringComparison]::OrdinalIgnoreCase) } catch { $false }
    } | Stop-Process -Force -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
}

function Deployer-PHP($Disponible) {
    $zip = $Disponible.Fichier
    if (-not $zip) {
        $zip = Join-Path $env:TEMP ([IO.Path]::GetFileName($Disponible.Url))
        Info "Téléchargement de PHP $($Disponible.Version) : $($Disponible.Url)"
        Telecharger-Fichier $Disponible.Url $zip
        if ($Disponible.Sha256) {
            $empreinte = (Get-FileHash -Path $zip -Algorithm SHA256).Hash
            if ($empreinte -ne $Disponible.Sha256) {
                Remove-Item $zip -Force -ErrorAction SilentlyContinue
                throw 'Le fichier PHP téléchargé est corrompu (empreinte SHA-256 différente). Relancez le script.'
            }
            Ok 'Empreinte SHA-256 vérifiée'
        }
    } else {
        Info "Archive fournie à côté du script : $zip"
    }

    $temporaire = Join-Path $env:TEMP ('php-d8support-' + [guid]::NewGuid().ToString('N'))
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $temporaire)
    try {
        if (-not (Test-Path (Join-Path $temporaire 'php-cgi.exe'))) {
            throw "L'archive $zip ne contient pas php-cgi.exe : prenez la version « Non Thread Safe » x64 (zip)."
        }
        if (Test-Path (Join-Path $DossierPHP 'php-cgi.exe')) { Arreter-PHP }
        New-Item -ItemType Directory -Force -Path $DossierPHP | Out-Null
        Copy-Item -Path (Join-Path $temporaire '*') -Destination $DossierPHP -Recurse -Force
    } finally {
        Remove-Item $temporaire -Recurse -Force -ErrorAction SilentlyContinue
    }
    Set-Content -Path (Join-Path $DossierPHP $Marqueur) -Encoding ASCII -Value @(
        'Ce PHP a ete installe par installer-iis.ps1 (D8 Support).',
        'Relancer INSTALLER.cmd le met a jour ; php.ini n''est jamais ecrase.')
}

function Ecrire-PhpIni([string]$Fichier) {
    $modele = Join-Path $DossierPHP 'php.ini-production'
    $base = ''
    if (Test-Path $modele) { $base = [IO.File]::ReadAllText($modele) }
    $ext = Join-Path $DossierPHP 'ext'
    # Seulement les extensions livrées en DLL séparée (certaines deviennent intégrées).
    $extensions = @()
    foreach ($e in 'fileinfo', 'ldap', 'mbstring', 'openssl', 'pdo_sqlite', 'zip') {
        if (Test-Path (Join-Path $ext "php_$e.dll")) { $extensions += "extension=$e" }
    }
    $bloc = @"

; =============================================================================
;  D8 Support : reglages ajoutes par installer-iis.ps1
;  Ils remplacent les valeurs du meme nom ecrites plus haut dans ce fichier.
;  IIS relit ce fichier tout seul quand il est modifie : inutile de redemarrer.
; =============================================================================
extension_dir = "$ext"
$($extensions -join "`r`n")

; IIS / FastCGI
cgi.force_redirect = 0
cgi.fix_pathinfo = 1
fastcgi.impersonate = 1
fastcgi.logging = 0

; Erreurs : jamais a l'ecran des utilisateurs, toujours dans le journal
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = "$DossierPHP\logs\php-erreurs.log"

; Pieces jointes : jusqu'a 3 fichiers de 5 Mo par envoi
file_uploads = On
upload_max_filesize = 8M
post_max_size = 20M
upload_tmp_dir = "$DossierPHP\temp"
sys_temp_dir = "$DossierPHP\temp"

; La sauvegarde depuis le navigateur peut etre longue (nombreuses pieces jointes)
max_execution_time = 300

date.timezone = Europe/Paris
expose_php = Off
"@
    [IO.File]::WriteAllText($Fichier, $base + $bloc, (New-Object System.Text.UTF8Encoding $false))
}

function Afficher-Diagnostic($Reponse) {
    Attention "Réponse du serveur : HTTP $($Reponse.Code)"
    $corps = [string]$Reponse.Corps
    if ($Reponse.Code -eq 0) {
        Info "IIS ne répond pas : le site est arrêté ou le port est pris par un autre logiciel (XAMPP, Skype...)."
    } elseif ($corps -match '404\.3') {
        Info "PHP n'est pas branché sur l'application (gestionnaire $NomGestionnaire absent)."
    } elseif ($corps -match '500\.19') {
        Info 'Erreur dans un fichier web.config (voir le détail ci-dessous).'
    } elseif ($Reponse.Code -eq 503) {
        Info "Le pool d'applications $NomPool est arrêté : voir l'Observateur d'événements, journal Système."
    }
    $texte = (($corps -replace '(?s)<(script|style)[^>]*>.*?</\1>', ' ') -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
    if ($texte.Length -gt 900) { $texte = $texte.Substring(0, 900) + '...' }
    if ($texte) { Info $texte }
    foreach ($journal in (Join-Path $DossierPHP 'logs\php-erreurs.log'), (Join-Path $data '.ht_erreurs.log')) {
        if (Test-Path $journal) {
            Info "--- dernières lignes de $journal ---"
            Get-Content -Path $journal -Tail 15 | ForEach-Object { Info $_ }
        }
    }
}

# ==============================================================================
#  Installation
# ==============================================================================
$journalInstallation = Join-Path $PSScriptRoot 'installation-iis.log'
try {
    Start-Transcript -Path $journalInstallation -Append | Out-Null
} catch {
    $journalInstallation = Join-Path $env:TEMP 'd8support-installation-iis.log'
    try { Start-Transcript -Path $journalInstallation -Append | Out-Null } catch { $journalInstallation = '' }
}

$succes = $false
try {
    Write-Host ''
    Write-Host '  D8 Support : installation sur IIS' -ForegroundColor White
    Write-Host '  =================================' -ForegroundColor White

    # --------------------------------------------------------------------------
    Etape 'Vérifications'
    if ([Environment]::OSVersion.Version -lt [version]'6.2') {
        throw 'Windows 8 / Windows Server 2012 minimum.'
    }
    if (-not [Environment]::Is64BitOperatingSystem) {
        throw 'Windows 64 bits requis : PHP pour Windows n''existe plus en 32 bits.'
    }
    $sourceIndex = Join-Path $PSScriptRoot 'index.php'
    $sourceWebConfig = Join-Path $PSScriptRoot 'web.config'
    foreach ($f in $sourceIndex, $sourceWebConfig) {
        if (-not (Test-Path $f)) {
            throw "Fichier introuvable : $f`nGardez INSTALLER.cmd, installer-iis.ps1, index.php et web.config dans le même dossier."
        }
        try { Unblock-File -Path $f } catch { }
    }
    if ((Get-Content -Path $sourceIndex -TotalCount 1) -notmatch '^\s*<\?php') {
        throw "$sourceIndex n'est pas un fichier PHP."
    }
    Ok "Windows $([Environment]::OSVersion.Version), 64 bits, droits administrateur"
    Ok "Application : $sourceIndex"

    # --------------------------------------------------------------------------
    Etape 'IIS et FastCGI (fonctionnalités Windows ; la première fois, quelques minutes)'
    $voulues = @('IIS-WebServerRole', 'IIS-WebServer', 'IIS-CommonHttpFeatures', 'IIS-DefaultDocument',
                 'IIS-StaticContent', 'IIS-HttpErrors', 'IIS-HealthAndDiagnostics', 'IIS-HttpLogging',
                 'IIS-Security', 'IIS-RequestFiltering', 'IIS-ApplicationDevelopment', 'IIS-CGI',
                 'IIS-WebServerManagementTools', 'IIS-ManagementConsole')
    $etats = @{}
    Get-WindowsOptionalFeature -Online | ForEach-Object { $etats[$_.FeatureName] = [string]$_.State }
    foreach ($indispensable in 'IIS-WebServer', 'IIS-CGI') {
        if (-not $etats.ContainsKey($indispensable)) {
            throw "La fonctionnalité Windows $indispensable est introuvable sur cette édition de Windows."
        }
    }
    $aActiver = @($voulues | Where-Object { $etats.ContainsKey($_) -and $etats[$_] -notin 'Enabled', 'EnablePending' })
    if ($aActiver.Count -gt 0) {
        Info ('Activation : ' + ($aActiver -join ', '))
        $resultat = Enable-WindowsOptionalFeature -Online -FeatureName $aActiver -All -NoRestart -WarningAction SilentlyContinue
        if ($resultat.RestartNeeded) { $redemarrage = $true }
        Ok 'IIS activé'
    } else {
        Ok 'IIS et FastCGI déjà présents'
    }
    if (-not (Test-Path $appcmd)) { throw "IIS semble installé mais $appcmd est introuvable. Redémarrez Windows puis relancez." }
    foreach ($service in 'WAS', 'W3SVC') {
        Set-Service -Name $service -StartupType Automatic
        if ((Get-Service -Name $service).Status -ne 'Running') { Start-Service -Name $service }
    }
    Ok 'Services IIS démarrés'

    # --------------------------------------------------------------------------
    Etape 'Runtime Visual C++ (requis par PHP)'
    $vcInstalle = $false
    $vc = Version-VCRedist
    if ($null -eq $vc -or $vc -lt [version]'14.40') {
        if ($vc) { Info "Version présente trop ancienne : $vc" }
        Installer-VCRedist
        $vcInstalle = $true
    } else {
        Ok "Déjà présent ($vc)"
    }

    # --------------------------------------------------------------------------
    Etape 'PHP'
    if (-not $DossierPHP) {
        $DossierPHP = Join-Path $env:SystemDrive 'PHP'
        if ((Test-Path (Join-Path $DossierPHP 'php-cgi.exe')) -and -not (Test-Path (Join-Path $DossierPHP $Marqueur))) {
            Info "$DossierPHP contient un PHP installé à la main : il n'est pas modifié."
            $DossierPHP = Join-Path $env:SystemDrive 'PHP-D8Support'
        }
    }
    $phpCgi = Join-Path $DossierPHP 'php-cgi.exe'
    $phpExe = Join-Path $DossierPHP 'php.exe'
    $phpIni = Join-Path $DossierPHP 'php.ini'
    $present = Test-Path $phpCgi
    $aNous = (-not $present) -or (Test-Path (Join-Path $DossierPHP $Marqueur))

    $versionInstallee = $null
    if ($present -and (Test-Path $phpExe)) {
        try { $versionInstallee = [version]((Get-Item $phpExe).VersionInfo.ProductVersion -replace '[^0-9.].*$', '') } catch { }
        if (-not $versionInstallee) {
            $sortie = (Lancer -Programme $phpExe -Arguments @('-n', '-v') -SansErreur).Sortie
            if ($sortie -match '(?m)^PHP (\d+\.\d+\.\d+)') { $versionInstallee = [version]$Matches[1] }
        }
    }

    if (-not $present) {
        $disponible = $null
        try {
            $disponible = Trouver-PHP
        } catch {
            throw ("Impossible d'obtenir PHP : $($_.Exception.Message)`n" +
                   "Sans Internet : téléchargez sur https://windows.php.net/download/ le zip « VS17 x64 Non Thread Safe », " +
                   "déposez-le à côté de ce script et relancez.")
        }
        New-Item -ItemType Directory -Force -Path $DossierPHP | Out-Null
        Deployer-PHP $disponible
        Ok "PHP $($disponible.Version) installé dans $DossierPHP"
    } elseif ($aNous) {
        $disponible = $null
        try { $disponible = Trouver-PHP } catch { Attention "Recherche de mise à jour de PHP impossible : $($_.Exception.Message)" }
        if ($disponible -and $disponible.Version -and $versionInstallee -and $disponible.Version -gt $versionInstallee) {
            Info "Mise à jour de PHP $versionInstallee vers $($disponible.Version)"
            Deployer-PHP $disponible
            Ok "PHP $($disponible.Version) installé"
        } else {
            Ok "PHP $versionInstallee déjà à jour dans $DossierPHP"
        }
    } else {
        Ok "PHP existant utilisé tel quel : $DossierPHP"
    }

    if ($aNous) {
        # C:\PHP hériterait sinon de C:\ où tout utilisateur connecté peut écrire.
        Proteger-Dossier $DossierPHP
        Definir-Droits $DossierPHP @('/grant', "${USERS}:(OI)(CI)RX", "${IUSR}:(OI)(CI)RX", "${IIS_IUSRS}:(OI)(CI)RX")
    }
    foreach ($sous in 'temp', 'logs') {
        $chemin = Join-Path $DossierPHP $sous
        New-Item -ItemType Directory -Force -Path $chemin | Out-Null
        Proteger-Dossier $chemin -EcritureIIS
    }

    if (Test-Path $phpIni) {
        Ok "php.ini existant conservé ($phpIni)"
    } else {
        Ecrire-PhpIni $phpIni
        Ok "php.ini créé ($phpIni)"
    }

    # « -n » (sans php.ini) : un runtime incompatible s'affiche alors à l'écran au lieu du journal.
    $test = Lancer -Programme $phpExe -Arguments @('-n', '-v') -SansErreur
    if (($test.Code -ne 0 -or $test.Sortie -match 'not compatible|vcruntime') -and -not $vcInstalle) {
        Attention 'PHP réclame un runtime Visual C++ plus récent.'
        Installer-VCRedist
        $vcInstalle = $true
        $test = Lancer -Programme $phpExe -Arguments @('-n', '-v') -SansErreur
    }
    if ($test.Code -ne 0 -or $test.Sortie -notmatch '(?m)^PHP \d') {
        throw "PHP ne démarre pas correctement :`n$($test.Sortie)"
    }
    $modules = (Lancer -Programme $phpExe -Arguments @('-m') -SansErreur).Sortie
    $absentes = @('pdo_sqlite', 'fileinfo', 'zip', 'ldap', 'openssl', 'mbstring' | Where-Object { $modules -notmatch "(?m)^$_\s*$" })
    if ($absentes.Count -gt 0) {
        $journalPHP = Join-Path $DossierPHP 'logs\php-erreurs.log'
        if (Test-Path $journalPHP) { Get-Content -Path $journalPHP -Tail 5 | ForEach-Object { Info $_ } }
    }
    if ($absentes -contains 'pdo_sqlite' -or $absentes -contains 'fileinfo') {
        throw ("Extensions PHP indispensables absentes : $($absentes -join ', '). " +
               "Vérifiez les lignes extension= de $phpIni.")
    }
    if ($absentes.Count -gt 0) { Attention "Extensions facultatives absentes : $($absentes -join ', ')" }
    Ok (($test.Sortie -split "`n")[0])

    # --------------------------------------------------------------------------
    Etape "Fichiers de l'application"
    New-Item -ItemType Directory -Force -Path $Dossier, $data, (Join-Path $data 'uploads') | Out-Null
    foreach ($copie in @(@($sourceIndex, 'index.php'), @($sourceWebConfig, 'web.config'))) {
        $source = $copie[0]
        $cible = Join-Path $Dossier $copie[1]
        if ([IO.Path]::GetFullPath($source) -eq [IO.Path]::GetFullPath($cible)) {
            Ok "$($copie[1]) déjà en place"
        } elseif (-not (Test-Path $cible)) {
            Copy-Item -Path $source -Destination $cible
            Ok "$($copie[1]) copié"
        } elseif ((Get-FileHash $source).Hash -ne (Get-FileHash $cible).Hash) {
            Copy-Item -Path $cible -Destination (Join-Path $data "$($copie[1]).precedent") -Force
            Copy-Item -Path $source -Destination $cible -Force
            Ok "$($copie[1]) mis à jour (version précédente : data\$($copie[1]).precedent)"
        } else {
            Ok "$($copie[1]) déjà à jour"
        }
    }
    # IIS lit l'application ; il n'écrit que dans data\, fermé à tous les autres comptes.
    Definir-Droits $Dossier @('/grant', "${IUSR}:(OI)(CI)RX", "${IIS_IUSRS}:(OI)(CI)RX")
    Proteger-Dossier $data -EcritureIIS
    Ok "Droits posés : lecture sur $Dossier, écriture sur data\ uniquement"

    # --------------------------------------------------------------------------
    Etape 'Configuration IIS'
    $ligneSite = (AppCmd @('list', 'site', $Site) -SansErreur).Sortie
    if ($ligneSite -notmatch '^SITE "') {
        $sites = (AppCmd @('list', 'site') -SansErreur).Sortie
        throw "Le site IIS « $Site » n'existe pas. Sites présents :`n$sites`nRelancez avec -Site ""Nom du site""."
    }

    if ((AppCmd @('list', 'apppool', $NomPool) -SansErreur).Sortie -notmatch '^APPPOOL "') {
        AppCmd @('add', 'apppool', "/name:$NomPool") | Out-Null
    }
    # « Pas de code managé » : PHP n'a pas besoin de .NET.
    AppCmd @('set', 'apppool', $NomPool, '/managedRuntimeVersion:', '/managedPipelineMode:Integrated') -SansErreur | Out-Null
    Ok "Pool d'applications $NomPool"

    if ((AppCmd @('list', 'app', $cheminIIS) -SansErreur).Sortie -notmatch '^APP "') {
        AppCmd @('add', 'app', "/site.name:$Site", "/path:/$NomApplication", "/physicalPath:$Dossier", "/applicationPool:$NomPool") | Out-Null
    } else {
        AppCmd @('set', 'app', $cheminIIS, "/applicationPool:$NomPool") | Out-Null
        AppCmd @('set', 'vdir', "$cheminIIS/", "/physicalPath:$Dossier") | Out-Null
    }
    Ok "Application $cheminIIS -> $Dossier"

    # Processus PHP (déclaration globale, recréée à chaque passage pour rester à jour).
    $fcgi = "[fullPath='$phpCgi']"
    $configFcgi = (AppCmd @('list', 'config', '-section:system.webServer/fastCgi') -SansErreur).Sortie
    if ($configFcgi -match [regex]::Escape("fullPath=""$phpCgi""")) {
        AppCmd @('set', 'config', '-section:system.webServer/fastCgi', "/-$fcgi", '/commit:apphost') | Out-Null
    }
    AppCmd @('set', 'config', '-section:system.webServer/fastCgi',
             "/+[fullPath='$phpCgi',instanceMaxRequests='10000',activityTimeout='600',requestTimeout='600',stderrMode='IgnoreAndReturn200',monitorChangesTo='$phpIni']",
             '/commit:apphost') | Out-Null
    AppCmd @('set', 'config', '-section:system.webServer/fastCgi',
             "/+$fcgi.environmentVariables.[name='PHP_FCGI_MAX_REQUESTS',value='10000']", '/commit:apphost') | Out-Null
    AppCmd @('set', 'config', '-section:system.webServer/fastCgi',
             "/+$fcgi.environmentVariables.[name='PHPRC',value='$DossierPHP']", '/commit:apphost') | Out-Null
    Ok "FastCGI : $phpCgi"

    # PHP n'est branché QUE sur cette application : les autres sites du serveur ne changent pas.
    # responseBufferLimit=0 : la réponse part tout de suite, les e-mails sont envoyés après.
    AppCmd @('set', 'config', $cheminIIS, '-section:system.webServer/handlers',
             "/-[name='$NomGestionnaire']", '/commit:apphost') -SansErreur | Out-Null
    AppCmd @('set', 'config', $cheminIIS, '-section:system.webServer/handlers',
             "/+[name='$NomGestionnaire',path='*.php',verb='GET,HEAD,POST',modules='FastCgiModule',scriptProcessor='$phpCgi',resourceType='File',requireAccess='Script',responseBufferLimit='0']",
             '/commit:apphost') | Out-Null
    Ok "Gestionnaire *.php -> PHP (application $cheminIIS seulement)"

    # Sans cela, IIS remplace les messages d'erreur JSON de l'application par ses propres pages HTML.
    AppCmd @('set', 'config', $cheminIIS, '-section:system.webServer/httpErrors',
             '/existingResponse:PassThrough', '/commit:apphost') | Out-Null
    Ok "Messages d'erreur de l'application transmis tels quels"

    AppCmd @('start', 'apppool', $NomPool) -SansErreur | Out-Null
    $ligneSite = (AppCmd @('list', 'site', $Site) -SansErreur).Sortie
    if ($ligneSite -match 'state:Stopped') {
        AppCmd @('start', 'site', $Site) -SansErreur | Out-Null
        $ligneSite = (AppCmd @('list', 'site', $Site) -SansErreur).Sortie
        if ($ligneSite -match 'state:Stopped') {
            throw ("Le site « $Site » ne démarre pas : son port est sans doute déjà utilisé par un autre " +
                   "logiciel (XAMPP/Apache, Skype...). Voir : netstat -ano | findstr LISTENING")
        }
    }
    Ok "Site « $Site » démarré"

    $liaison = Lire-Liaison $ligneSite
    $nomServeur = $env:COMPUTERNAME
    if ($liaison.Hote) { $nomServeur = $liaison.Hote }
    $suffixePort = ''
    if ($liaison.Port -ne 80) { $suffixePort = ":$($liaison.Port)" }
    $adresse = "http://$nomServeur$suffixePort/$NomApplication/"

    # --------------------------------------------------------------------------
    Etape 'Pare-feu Windows'
    if (-not $liaison.Trouvee) {
        Attention "Le site n'a pas de liaison http : pare-feu non modifié."
    } else {
        try {
            $regle = $null
            if ($liaison.Port -eq 80) {
                $regle = Get-NetFirewallRule -Name 'IIS-WebServerRole-HTTP-In-TCP' -ErrorAction SilentlyContinue
            }
            if ($regle) {
                $regle | Enable-NetFirewallRule
            } elseif (-not (Get-NetFirewallRule -Name "D8Support-HTTP-$($liaison.Port)" -ErrorAction SilentlyContinue)) {
                New-NetFirewallRule -Name "D8Support-HTTP-$($liaison.Port)" -DisplayName "D8 Support (HTTP $($liaison.Port))" `
                    -Direction Inbound -Protocol TCP -LocalPort $liaison.Port -Action Allow | Out-Null
            }
            Ok "Port $($liaison.Port) ouvert en entrée"
        } catch {
            Attention "Pare-feu non modifié : $($_.Exception.Message)"
        }
    }

    # --------------------------------------------------------------------------
    Etape 'Vérification'
    $codeInstallation = ''
    if (-not $liaison.Trouvee) {
        Attention 'Pas de liaison http : vérification automatique impossible.'
    } else {
        $ipTest = $liaison.Ip
        $baseTest = "http://${ipTest}:$($liaison.Port)/$NomApplication/"

        $reponse = Lire-Http ($baseTest + 'index.php?action=boot') $liaison.Hote
        if ($reponse.Code -ne 200 -or $reponse.Corps -notmatch '"ok"\s*:\s*true') {
            Afficher-Diagnostic $reponse
            throw "L'application ne répond pas correctement."
        }
        Ok 'PHP répond, base de données créée ou ouverte'

        $reponse = Lire-Http $baseTest $liaison.Hote
        if ($reponse.Code -eq 200) { Ok 'Page d''accueil servie' } else { Attention "Page d'accueil : HTTP $($reponse.Code)" }

        $reponse = Lire-Http ($baseTest + 'data/.ht_ticketing.sqlite') $liaison.Hote
        if ($reponse.Code -eq 200) {
            throw "DANGER : la base est téléchargeable depuis le navigateur. Vérifiez que $Dossier\web.config est bien présent."
        }
        Ok "Dossier data\ inaccessible depuis le navigateur (HTTP $($reponse.Code))"

        $fichierCode = Join-Path $data '.ht_installation.txt'
        if (Test-Path $fichierCode) {
            if ((Get-Content -Path $fichierCode -Raw) -match '\b([0-9A-F]{6})\b') { $codeInstallation = $Matches[1] }
        }
    }

    # --------------------------------------------------------------------------
    $messageSauvegarde = 'aucune (option -SansSauvegarde)'
    if (-not $SansSauvegarde) {
        Etape 'Sauvegarde quotidienne'
        New-Item -ItemType Directory -Force -Path $DossierSauvegardes | Out-Null
        try {
            Proteger-Dossier $DossierSauvegardes
        } catch {
            # Partage réseau, par exemple : ses droits se gèrent sur le serveur de fichiers.
            Attention "Droits du dossier de sauvegarde non modifiés : $($_.Exception.Message)"
        }
        $cibleIndex = Join-Path $Dossier 'index.php'

        $systeme = (New-Object Security.Principal.SecurityIdentifier 'S-1-5-18').Translate([Security.Principal.NTAccount]).Value
        $action = New-ScheduledTaskAction -Execute $phpExe -WorkingDirectory $Dossier `
            -Argument ('"{0}" sauvegarde "{1}" {2}' -f $cibleIndex, $DossierSauvegardes, $SauvegardesAGarder)
        $declencheur = New-ScheduledTaskTrigger -Daily -At $HeureSauvegarde
        $options = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
            -ExecutionTimeLimit (New-TimeSpan -Hours 2)
        $compte = New-ScheduledTaskPrincipal -UserId $systeme -LogonType ServiceAccount -RunLevel Highest
        Register-ScheduledTask -TaskName $NomTache -Action $action -Trigger $declencheur -Settings $options `
            -Principal $compte -Description 'Sauvegarde de la base et des pièces jointes de D8 Support (installer-iis.ps1).' `
            -Force | Out-Null
        Ok "Tâche planifiée « $NomTache » : chaque jour à $HeureSauvegarde"

        $essai = Lancer -Programme $phpExe -Arguments @($cibleIndex, 'sauvegarde', $DossierSauvegardes, "$SauvegardesAGarder") -SansErreur -Utf8
        if ($essai.Code -eq 0) {
            $derniere = Get-ChildItem -Path $DossierSauvegardes -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
            Ok "Sauvegarde d'essai réussie : $($derniere.FullName)"
        } else {
            Attention "La sauvegarde d'essai a échoué :`n$($essai.Sortie)"
        }
        $messageSauvegarde = "chaque jour à $HeureSauvegarde dans $DossierSauvegardes ($SauvegardesAGarder gardées)"
    }

    # --------------------------------------------------------------------------
    $succes = $true
    Write-Host ''
    Write-Host '  ============================================================' -ForegroundColor Green
    Write-Host '   D8 Support est installé.' -ForegroundColor Green
    Write-Host '  ============================================================' -ForegroundColor Green
    Write-Host ''
    Write-Host "   Adresse à donner aux utilisateurs : $adresse" -ForegroundColor White
    Write-Host "   Vérification de l'installation    : ${adresse}index.php?page=verification"
    Write-Host ''
    if ($codeInstallation) {
        Write-Host "   Code d'installation : $codeInstallation" -ForegroundColor Yellow
        Write-Host "   Ouvrez l'adresse ci-dessus et saisissez ce code pour créer le compte administrateur."
    } else {
        Write-Host '   Le compte administrateur existe déjà : connectez-vous normalement.'
    }
    Write-Host ''
    Write-Host "   Application : $Dossier (données dans data\)"
    Write-Host "   PHP         : $DossierPHP (réglages : $phpIni)"
    Write-Host "   Sauvegarde  : $messageSauvegarde"
    Write-Host '   Pensez à copier régulièrement les sauvegardes sur un autre support.'
    if ($redemarrage) {
        Write-Host ''
        Write-Host '   Windows demande un redémarrage pour terminer : faites-le dès que possible.' -ForegroundColor Yellow
    }
} catch {
    Write-Host ''
    Write-Host '  ============================================================' -ForegroundColor Red
    Write-Host '   L''installation n''a pas abouti.' -ForegroundColor Red
    Write-Host '  ============================================================' -ForegroundColor Red
    Write-Host ''
    Write-Host "   $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ''
    Write-Host '   Corrigez le problème puis relancez INSTALLER.cmd : le script reprend là où il faut,'
    Write-Host '   sans rien casser de ce qui est déjà fait.'
} finally {
    if ($journalInstallation) {
        Write-Host ''
        Write-Host "   Journal complet : $journalInstallation"
        try { Stop-Transcript | Out-Null } catch { }
    }
    if (-not $SansPause) {
        Write-Host ''
        Read-Host '   Appuyez sur Entrée pour fermer' | Out-Null
    }
}

if ($succes) { exit 0 } else { exit 1 }
