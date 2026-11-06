<#
.SYNOPSIS
    Installe ou met à jour Boussole (finances du foyer) sur un PC Windows 11 équipé d'IIS et de PHP.

.DESCRIPTION
    Lancez Installer-Boussole.cmd (double-clic) : il demande les droits administrateur puis exécute ce script.
    Le script peut être relancé autant de fois que nécessaire : c'est aussi la procédure de MISE À JOUR.
    Il ne supprime jamais vos données (base, documents, clé de chiffrement) ni vos sauvegardes.

    Ce qu'il fait :
      1. vérifie Windows, IIS, PHP et le lien FastCGI entre IIS et PHP ;
      2. active les extensions PHP nécessaires (php.ini est sauvegardé avant toute modification) ;
      3. copie l'application dans le site web ;
      4. range les données HORS du site web, dans un dossier accessible seulement à IIS et aux administrateurs ;
      5. écrit config.local.php : emplacement des données, accès réservé aux appareils de la maison ;
      6. vérifie que l'application répond et que les fichiers internes sont invisibles depuis un navigateur ;
      7. programme la veille quotidienne (alertes par e-mail) et la sauvegarde quotidienne, puis fait une sauvegarde ;
      8. contrôle le pare-feu et affiche les adresses à ouvrir depuis le téléphone ou un autre PC.

.PARAMETER DossierSite
    Dossier de l'application. Par défaut C:\inetpub\wwwroot\finances, soit l'adresse http://localhost/finances/.
    Un dossier situé hors du site IIS est publié comme répertoire virtuel portant le même nom.

.PARAMETER DossierDonnees
    Base de données, documents chiffrés et clé de chiffrement. Par défaut C:\BoussoleDonnees (hors du site web).

.PARAMETER DossierSauvegardes
    Destination des sauvegardes quotidiennes. Par défaut C:\BoussoleSauvegardes ; un autre disque est préférable,
    par exemple : -DossierSauvegardes 'E:\Sauvegardes\Boussole'

.PARAMETER DossierPHP
    Dossier de PHP (php.exe, php-cgi.exe, php.ini). Par défaut C:\PHP.

.PARAMETER NomSite
    Site IIS qui publie l'application. Par défaut : Default Web Site.

.PARAMETER HeureVeille
    Heure de la veille quotidienne (alertes par e-mail), au format HH:MM. Par défaut 07:05.

.PARAMETER HeureSauvegarde
    Heure de la sauvegarde quotidienne, au format HH:MM. Par défaut 03:15.

.PARAMETER GarderSauvegardes
    Nombre de sauvegardes conservées (les plus anciennes sont supprimées). Par défaut 30.

.PARAMETER Acces
    local (par défaut) : seuls les appareils de la maison et ceux du VPN Tailscale peuvent ouvrir l'application.
    tout : aucun filtrage par adresse (seulement derrière HTTPS et une protection supplémentaire).

.PARAMETER PareFeu
    Corrige aussi le pare-feu Windows : port 80 ouvert au seul réseau local, profil réseau Privé.

.PARAMETER SansTaches
    Ne programme ni la veille ni la sauvegarde.

.PARAMETER SansExtensions
    Ne modifie pas php.ini (le script s'arrête si une extension obligatoire manque).

.PARAMETER Simulation
    Vérifie tout et affiche ce qui serait fait, sans rien modifier.

.PARAMETER Desinstaller
    Retire l'application et ses tâches planifiées. Les données et les sauvegardes sont conservées.

.PARAMETER Oui
    Ne demande pas de confirmation (désinstallation).

.EXAMPLE
    .\Installer-Boussole.ps1 -Simulation

.EXAMPLE
    .\Installer-Boussole.ps1 -DossierSauvegardes 'E:\Sauvegardes\Boussole' -GarderSauvegardes 60 -PareFeu

.EXAMPLE
    .\Installer-Boussole.ps1 -Desinstaller
#>
[CmdletBinding()]
param(
    [string]$DossierSite = 'C:\inetpub\wwwroot\finances',
    [string]$DossierDonnees = 'C:\BoussoleDonnees',
    [string]$DossierSauvegardes = 'C:\BoussoleSauvegardes',
    [string]$DossierPHP = 'C:\PHP',
    [string]$NomSite = 'Default Web Site',
    [ValidatePattern('^([01][0-9]|2[0-3]):[0-5][0-9]$')]
    [string]$HeureVeille = '07:05',
    [ValidatePattern('^([01][0-9]|2[0-3]):[0-5][0-9]$')]
    [string]$HeureSauvegarde = '03:15',
    [ValidateRange(1, 3650)]
    [int]$GarderSauvegardes = 30,
    [ValidateSet('local', 'tout')]
    [string]$Acces = 'local',
    [switch]$PareFeu,
    [switch]$SansTaches,
    [switch]$SansExtensions,
    [switch]$Simulation,
    [switch]$Desinstaller,
    [switch]$Oui
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# =============================================================================
# Outils d'affichage et d'exécution
# =============================================================================

$script:Etape = 0
$script:Avertissements = New-Object 'System.Collections.Generic.List[string]'

function Write-Etape([string]$Texte) {
    $script:Etape++
    Write-Host ''
    Write-Host ('[{0}] {1}' -f $script:Etape, $Texte) -ForegroundColor Cyan
}
function Write-Ok([string]$Texte) { Write-Host ('    OK  ' + $Texte) -ForegroundColor Green }
function Write-Info([string]$Texte) { Write-Host ('        ' + $Texte) }
function Write-Attention([string]$Texte) {
    Write-Host ('    !!  ' + $Texte) -ForegroundColor Yellow
    $script:Avertissements.Add($Texte)
}
$script:ArretVolontaire = $false
function Stop-Installation([string]$Texte) { $script:ArretVolontaire = $true; throw $Texte }

# En simulation, décrit l'action au lieu de l'exécuter.
function Invoke-Etape {
    param(
        [Parameter(Mandatory = $true)][string]$Description,
        [Parameter(Mandatory = $true)][scriptblock]$Action
    )
    if ($Simulation) {
        Write-Host ('    ..  [simulation] ' + $Description) -ForegroundColor DarkGray
        return
    }
    & $Action
}

# Lance un programme et rend son code de sortie et ses lignes (les messages d'erreur ne coupent pas le script).
function Invoke-Natif {
    param(
        [Parameter(Mandatory = $true)][string]$Programme,
        [string[]]$Arguments = @(),
        [switch]$Utf8
    )
    $ErrorActionPreference = 'Continue'
    $encodageAvant = $null
    if ($Utf8) {
        try { $encodageAvant = [Console]::OutputEncoding; [Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false) } catch { $encodageAvant = $null }
    }
    $global:LASTEXITCODE = 0
    $lignes = @()
    try {
        $lignes = @(& $Programme @Arguments 2>&1 | ForEach-Object { [string]$_ })
    } catch {
        $lignes = @([string]$_.Exception.Message)
        $global:LASTEXITCODE = 9999
    } finally {
        if ($null -ne $encodageAvant) { try { [Console]::OutputEncoding = $encodageAvant } catch { } }
    }
    $code = $global:LASTEXITCODE
    if ($null -eq $code) { $code = 0 }
    return [pscustomobject]@{ Code = [int]$code; Sortie = $lignes; Texte = ($lignes -join "`n") }
}

# Requête HTTP qui rend toujours un code et un texte (même en cas d'erreur 404 ou 500).
function Invoke-Http([string]$Url) {
    try {
        $reponseOk = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 30 -ErrorAction Stop
        return [pscustomobject]@{ Code = [int]$reponseOk.StatusCode; Texte = [string]$reponseOk.Content }
    } catch {
        $codeHttp = 0
        $texteHttp = [string]$_.Exception.Message
        $reponseErreur = $null
        if ($_.Exception.PSObject.Properties.Match('Response').Count -gt 0) { $reponseErreur = $_.Exception.Response }
        if ($null -ne $reponseErreur) {
            try { $codeHttp = [int]$reponseErreur.StatusCode } catch { $codeHttp = 0 }
            try {
                $lecteur = New-Object System.IO.StreamReader($reponseErreur.GetResponseStream(), [System.Text.Encoding]::UTF8)
                $texteHttp = $lecteur.ReadToEnd()
                $lecteur.Close()
            } catch { }
        }
        return [pscustomobject]@{ Code = $codeHttp; Texte = $texteHttp }
    }
}

# Dernière ligne non vide d'une sortie (les avertissements de démarrage de PHP s'affichent avant le résultat).
function Get-DerniereLigne($Resultat) {
    $lignesNonVides = @($Resultat.Sortie | Where-Object { ([string]$_).Trim() -ne '' })
    if ($lignesNonVides.Count -eq 0) { return '' }
    return ([string]$lignesNonVides[$lignesNonVides.Count - 1]).Trim()
}

function Get-Debut([string]$Texte) {
    $t = ($Texte -replace '<[^>]+>', ' ' -replace '\s+', ' ').Trim()
    if ($t.Length -gt 300) { $t = $t.Substring(0, 300) + '...' }
    return $t
}

# =============================================================================
# Outils pour les chemins et pour PHP
# =============================================================================

function Get-CheminComplet([string]$Chemin) {
    $c = [Environment]::ExpandEnvironmentVariables($Chemin.Trim().Trim('"'))
    $c = [System.IO.Path]::GetFullPath($c)
    if ($c.Length -gt 3) { $c = $c.TrimEnd('\') }
    return $c
}
function Test-RacineDisque([string]$Chemin) {
    return (($Chemin -match '^[A-Za-z]:\\?$') -or ($Chemin -match '^\\\\[^\\]+\\[^\\]+\\?$'))
}
function Test-Dans([string]$Enfant, [string]$Parent) {
    if ($Enfant.Equals($Parent, [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    return $Enfant.StartsWith($Parent.TrimEnd('\') + '\', [System.StringComparison]::OrdinalIgnoreCase)
}
function ConvertTo-CheminPhp([string]$Chemin) { return $Chemin.Replace('\', '/') }
function ConvertTo-ChainePhp([string]$Texte) { return "'" + $Texte.Replace('\', '\\').Replace("'", "\'") + "'" }

function Get-VersionBoussole([string]$Dossier) {
    $fichier = Join-Path $Dossier 'api.php'
    if (-not (Test-Path -LiteralPath $fichier)) { return '' }
    $trouve = Select-String -LiteralPath $fichier -Pattern "define\('BOUSSOLE',\s*'([^']+)'\)" -List
    if ($null -ne $trouve) { return $trouve.Matches[0].Groups[1].Value }
    return ''
}

# Taille écrite dans php.ini (« 64M », « 1G », « -1 ») convertie en octets.
function ConvertTo-Octets([string]$Valeur) {
    $v = $Valeur.Trim()
    if ($v -eq '' -or $v -eq '-1') { return [long]-1 }
    if ($v -notmatch '^(\d+)\s*([KkMmGg]?)') { return [long]-1 }
    $n = [long]$Matches[1]
    switch ($Matches[2].ToUpper()) {
        'K' { return $n * 1024 }
        'M' { return $n * 1024 * 1024 }
        'G' { return $n * 1024 * 1024 * 1024 }
        default { return $n }
    }
}

# Remplace (ou ajoute) une directive « nom = valeur » de php.ini sans toucher au reste du fichier.
function Set-DirectiveIni([string]$Texte, [string]$Nom, [string]$Valeur) {
    $re = New-Object System.Text.RegularExpressions.Regex(('(?im)^[ \t]*' + [regex]::Escape($Nom) + '[ \t]*=[^\r\n]*'))
    if ($re.IsMatch($Texte)) { return $re.Replace($Texte, ($Nom + ' = ' + $Valeur), 1) }
    $fin = "`r`n"
    if ($Texte -notmatch "`r`n") { $fin = "`n" }
    return $Texte.TrimEnd() + $fin + $Nom + ' = ' + $Valeur + $fin
}

# Active « extension=nom » : décommente la ligne prévue ou l'ajoute après la dernière extension active.
function Enable-ExtensionIni([string]$Texte, [string]$Nom) {
    $nomRe = '"?(?:php_)?' + [regex]::Escape($Nom) + '(?:\.dll)?"?'
    $commentee = New-Object System.Text.RegularExpressions.Regex(('(?im)^[ \t]*;[ \t]*extension[ \t]*=[ \t]*' + $nomRe + '[ \t]*(?=\r?$)'))
    if ($commentee.IsMatch($Texte)) { return $commentee.Replace($Texte, ('extension=' + $Nom), 1) }
    $fin = "`r`n"
    if ($Texte -notmatch "`r`n") { $fin = "`n" }
    $actives = [regex]::Matches($Texte, '(?im)^[ \t]*extension[ \t]*=[^\r\n]*')
    if ($actives.Count -gt 0) {
        $derniere = $actives[$actives.Count - 1]
        $position = $derniere.Index + $derniere.Length
        return $Texte.Substring(0, $position) + $fin + 'extension=' + $Nom + $Texte.Substring($position)
    }
    return $Texte.TrimEnd() + $fin + 'extension=' + $Nom + $fin
}
function Test-ExtensionActiveIni([string]$Texte, [string]$Nom) {
    $motif = '(?im)^[ \t]*extension[ \t]*=[ \t]*"?(?:php_)?' + [regex]::Escape($Nom) + '(?:\.dll)?"?[ \t]*(?=\r?$)'
    return [regex]::IsMatch($Texte, $motif)
}

# =============================================================================
# Droits administrateur : le script se relance lui-même avec élévation si nécessaire
# =============================================================================

$principalCourant = New-Object System.Security.Principal.WindowsPrincipal([System.Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principalCourant.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host 'Droits administrateur nécessaires : Windows va vous les demander.' -ForegroundColor Yellow
    $liste = New-Object 'System.Collections.Generic.List[string]'
    foreach ($element in @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-NoExit', '-File')) { $liste.Add($element) }
    $liste.Add('"' + $PSCommandPath + '"')
    foreach ($cle in @($PSBoundParameters.Keys)) {
        $valeur = $PSBoundParameters[$cle]
        if ($valeur -is [System.Management.Automation.SwitchParameter]) {
            if ($valeur.IsPresent) { $liste.Add('-' + $cle) }
        } else {
            $liste.Add('-' + $cle)
            $liste.Add('"' + ([string]$valeur).TrimEnd('\') + '"')
        }
    }
    try {
        Start-Process -FilePath 'powershell.exe' -ArgumentList $liste.ToArray() -Verb RunAs | Out-Null
    } catch {
        Write-Host 'Élévation refusée : faites un clic droit sur Installer-Boussole.cmd puis « Exécuter en tant qu''administrateur ».' -ForegroundColor Red
        exit 1
    }
    exit 0
}

# Journal complet de l'installation (utile en cas de problème)
$journal = $null
try {
    $dossierJournal = Join-Path $env:ProgramData 'Boussole'
    if (-not (Test-Path -LiteralPath $dossierJournal)) { New-Item -ItemType Directory -Path $dossierJournal -Force | Out-Null }
    $journal = Join-Path $dossierJournal ('installation-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.log')
    Start-Transcript -Path $journal -Force | Out-Null
} catch { $journal = $null }

$codeSortie = 0
$siteArrete = $false
$appcmd = Join-Path $env:windir 'System32\inetsrv\appcmd.exe'

try {
    Write-Host ''
    Write-Host '  BOUSSOLE - installation sur Windows (IIS + PHP)' -ForegroundColor White
    if ($Simulation) { Write-Host '  Mode simulation : rien ne sera modifié.' -ForegroundColor DarkGray }

    $system32 = Join-Path $env:windir 'System32'
    $robocopy = Join-Path $system32 'robocopy.exe'
    $icacls = Join-Path $system32 'icacls.exe'
    $curl = Join-Path $system32 'curl.exe'
    $siteDir = Get-CheminComplet $DossierSite
    $dataDir = Get-CheminComplet $DossierDonnees
    $backupDir = Get-CheminComplet $DossierSauvegardes
    $phpDir = Get-CheminComplet $DossierPHP
    $sourceDir = Get-CheminComplet (Join-Path $PSScriptRoot '..')
    $php = Join-Path $phpDir 'php.exe'
    $phpCgi = Join-Path $phpDir 'php-cgi.exe'
    $tachesDossier = '\Boussole\'
    $scriptSauvegarde = Join-Path $siteDir 'outils\sauvegarde.php'

    if ($Desinstaller) {
        # =====================================================================
        # Désinstallation : application et tâches planifiées (jamais les données)
        # =====================================================================
        Write-Etape 'Désinstallation'
        Write-Info ('Application retirée      : ' + $siteDir)
        Write-Info ('Données CONSERVÉES       : ' + $dataDir)
        Write-Info ('Sauvegardes CONSERVÉES   : ' + $backupDir)
        if (Test-RacineDisque $siteDir) { Stop-Installation ('Dossier de l''application invalide : ' + $siteDir) }
        if ((Test-Path -LiteralPath $siteDir) -and -not ((Test-Path -LiteralPath (Join-Path $siteDir 'api.php')) -and (Test-Path -LiteralPath (Join-Path $siteDir 'assets\app.js')))) {
            Stop-Installation ($siteDir + ' ne ressemble pas à un dossier Boussole : rien n''a été supprimé. Précisez -DossierSite.')
        }
        $confirme = $true
        if (-not $Oui -and -not $Simulation) {
            $reponse = Read-Host 'Tapez OUI pour confirmer'
            $confirme = ($reponse -ceq 'OUI')
        }
        if (-not $confirme) {
            Write-Info 'Désinstallation annulée : rien n''a été modifié.'
        } else {
            foreach ($nomTache in @('Veille', 'Sauvegarde')) {
                $tache = $null
                try { $tache = Get-ScheduledTask -TaskPath $tachesDossier -TaskName $nomTache -ErrorAction Stop } catch { $tache = $null }
                if ($null -ne $tache) {
                    Invoke-Etape ('supprimer la tâche planifiée ' + $nomTache) { Unregister-ScheduledTask -TaskPath $tachesDossier -TaskName $nomTache -Confirm:$false }
                    Write-Ok ('Tâche planifiée ' + $nomTache + ' supprimée')
                }
            }
            Invoke-Etape 'supprimer le dossier \Boussole\ du planificateur de tâches' {
                try {
                    $planificateur = New-Object -ComObject 'Schedule.Service'
                    $planificateur.Connect()
                    $planificateur.GetFolder('\').DeleteFolder('Boussole', 0)
                } catch { }
            }
            $nomDossier = Split-Path -Leaf $siteDir
            if (Test-Path -LiteralPath $appcmd) {
                $r = Invoke-Natif $appcmd @('list', 'vdir', ($NomSite + '/' + $nomDossier), '/text:physicalPath')
                $cheminVdir = $r.Texte.Trim()
                if ($r.Code -eq 0 -and $cheminVdir -ne '' -and ((Get-CheminComplet $cheminVdir) -eq $siteDir)) {
                    Invoke-Etape ('retirer le répertoire virtuel /' + $nomDossier) {
                        $r2 = Invoke-Natif $appcmd @('delete', 'vdir', ($NomSite + '/' + $nomDossier))
                        if ($r2.Code -ne 0) { throw ('appcmd : ' + $r2.Texte) }
                    }
                    Write-Ok ('Répertoire virtuel /' + $nomDossier + ' retiré')
                }
            }
            if (Test-Path -LiteralPath $siteDir) {
                $ancienneDonnees = Join-Path $siteDir 'data'
                if (Test-Path -LiteralPath $ancienneDonnees) {
                    Write-Attention ('Le dossier ' + $ancienneDonnees + ' contient des données : il est conservé, le reste de l''application est retiré.')
                    Invoke-Etape ('supprimer ' + $siteDir + ' sauf data') {
                        Get-ChildItem -LiteralPath $siteDir -Force | Where-Object { $_.Name -ne 'data' } | Remove-Item -Recurse -Force
                    }
                } else {
                    Invoke-Etape ('supprimer ' + $siteDir) { Remove-Item -LiteralPath $siteDir -Recurse -Force }
                }
                Write-Ok ('Application retirée de ' + $siteDir)
            } else {
                Write-Info ('Aucune application dans ' + $siteDir)
            }
            Write-Host ''
            Write-Host ('  Vos données sont toujours dans ' + $dataDir + ' et vos sauvegardes dans ' + $backupDir + '.') -ForegroundColor White
            Write-Host '  Supprimez-les vous-même seulement après avoir vérifié que vous n''en avez plus besoin.' -ForegroundColor White
        }
    } else {
        # =====================================================================
        # 1. Vérifications
        # =====================================================================
        Write-Etape 'Vérifications'
        $os = Get-CimInstance -ClassName Win32_OperatingSystem
        Write-Info ('Windows : ' + $os.Caption + ' (' + $os.Version + ')')

        foreach ($chemin in @($siteDir, $dataDir, $backupDir)) {
            if (Test-RacineDisque $chemin) { Stop-Installation ('Choisissez un sous-dossier, pas la racine d''un disque : ' + $chemin) }
        }
        if (Test-Dans $backupDir $dataDir) { Stop-Installation 'Les sauvegardes ne peuvent pas être rangées dans le dossier des données.' }
        if (Test-Dans $backupDir $siteDir) { Stop-Installation 'Les sauvegardes ne doivent jamais être dans le site web.' }
        if (Test-Dans $siteDir $dataDir) { Stop-Installation 'Le site web ne peut pas être dans le dossier des données.' }
        if (Test-Dans $dataDir $siteDir) {
            Write-Attention 'Le dossier des données est DANS le site web : il reste protégé par web.config, mais un dossier hors du site (C:\BoussoleDonnees) est plus sûr.'
        }
        if ($backupDir.Substring(0, 2).ToUpper() -eq $dataDir.Substring(0, 2).ToUpper()) {
            Write-Attention ('Les sauvegardes sont sur le même disque (' + $dataDir.Substring(0, 2) + ') que les données : elles protègent d''une erreur, pas d''une panne du disque. Indiquez un autre disque avec -DossierSauvegardes.')
        }
        foreach ($fichier in @('api.php', 'index.html', 'web.config', 'lib\util.php', 'assets\app.js', 'outils\sauvegarde.php')) {
            if (-not (Test-Path -LiteralPath (Join-Path $sourceDir $fichier))) {
                Stop-Installation ('Fichier manquant : ' + (Join-Path $sourceDir $fichier) + '. Lancez ce script depuis le dossier « windows » du téléchargement complet de Boussole (fichier ZIP décompressé).')
            }
        }
        $versionSource = Get-VersionBoussole $sourceDir
        $versionSite = Get-VersionBoussole $siteDir
        if ($versionSite -eq '') { Write-Ok ('Boussole ' + $versionSource + ' : première installation') }
        else { Write-Ok ('Boussole ' + $versionSite + ' déjà installée : mise à jour vers ' + $versionSource) }

        # IIS
        $serviceIis = Get-Service -Name 'W3SVC' -ErrorAction SilentlyContinue
        if ($null -eq $serviceIis) { Stop-Installation 'IIS n''est pas installé. Suivez d''abord le tutoriel « Serveur web IIS + PHP » (Installer-IIS-PHP.cmd).' }
        foreach ($composant in @($appcmd, (Join-Path $system32 'inetsrv\iisfcgi.dll'), $robocopy, $icacls)) {
            if (-not (Test-Path -LiteralPath $composant)) { Stop-Installation ('Composant Windows introuvable : ' + $composant + ' (IIS avec CGI/FastCGI est-il installé ?)') }
        }
        if ($serviceIis.Status -ne 'Running') {
            Invoke-Etape 'démarrer le service IIS (W3SVC)' { Start-Service -Name 'W3SVC' }
        }
        Write-Ok 'IIS est installé (avec FastCGI)'

        $r = Invoke-Natif $appcmd @('list', 'site', $NomSite, '/text:bindings')
        $liaisons = $r.Texte.Trim()
        if ($r.Code -ne 0 -or $liaisons -eq '') {
            $nomsSites = (Invoke-Natif $appcmd @('list', 'site', '/text:name')).Sortie -join ', '
            Stop-Installation ('Site IIS « ' + $NomSite + ' » introuvable. Sites existants : ' + $nomsSites + '. Précisez-le avec -NomSite.')
        }
        $schema = ''
        $port = 0
        $hote = 'localhost'
        foreach ($liaison in ($liaisons -split ',')) {
            if ($liaison.Trim() -match '^(https?)/(.*):(\d+):([^:]*)$') {
                if ($schema -eq '' -or ($schema -eq 'https' -and $Matches[1] -eq 'http')) {
                    $schema = $Matches[1]
                    $port = [int]$Matches[3]
                    if ($Matches[4] -ne '') { $hote = $Matches[4] }
                    elseif ($Matches[2] -ne '' -and $Matches[2] -ne '*') { $hote = $Matches[2] }
                    else { $hote = 'localhost' }
                }
            }
        }
        if ($schema -eq '') { Stop-Installation ('Le site « ' + $NomSite + ' » n''a pas de liaison http ou https (' + $liaisons + ').') }
        $suffixePort = ''
        if (($schema -eq 'http' -and $port -ne 80) -or ($schema -eq 'https' -and $port -ne 443)) { $suffixePort = ':' + $port }
        if ($hote -ne 'localhost') { Write-Attention ('Le site répond au nom « ' + $hote + ' » : les adresses par IP affichées à la fin peuvent ne pas fonctionner.') }

        $r = Invoke-Natif $appcmd @('list', 'vdir', ($NomSite + '/'), '/text:physicalPath')
        if ($r.Code -ne 0 -or $r.Texte.Trim() -eq '') { Stop-Installation ('Dossier racine du site « ' + $NomSite + ' » illisible : ' + $r.Texte) }
        $racineSite = Get-CheminComplet $r.Texte.Trim()
        $r = Invoke-Natif $appcmd @('list', 'app', ($NomSite + '/'), '/text:applicationPool')
        $pool = $r.Texte.Trim()
        $r = Invoke-Natif $appcmd @('list', 'site', $NomSite, '/text:state')
        $etatSite = $r.Texte.Trim()

        $vdirChemin = ''
        if ($siteDir -eq $racineSite) { Stop-Installation 'Installez Boussole dans un sous-dossier du site (exemple : C:\inetpub\wwwroot\finances), pas à sa racine.' }
        if (Test-Dans $siteDir $racineSite) {
            $relatif = $siteDir.Substring($racineSite.TrimEnd('\').Length + 1)
        } else {
            $relatif = Split-Path -Leaf $siteDir
            $vdirChemin = '/' + $relatif
        }
        $cheminUrl = '/' + ((($relatif -split '\\') | ForEach-Object { [System.Uri]::EscapeDataString($_) }) -join '/') + '/'
        $urlLocale = $schema + '://' + $hote + $suffixePort + $cheminUrl
        Write-Ok ('Site « ' + $NomSite + ' » (' + $racineSite + ') : l''application sera à l''adresse ' + $urlLocale)

        # PHP
        if (-not (Test-Path -LiteralPath $php)) { Stop-Installation ('php.exe introuvable dans ' + $phpDir + '. Précisez -DossierPHP ou suivez d''abord le tutoriel IIS + PHP.') }
        if (-not (Test-Path -LiteralPath $phpCgi)) { Stop-Installation ('php-cgi.exe introuvable dans ' + $phpDir + '.') }
        $r = Invoke-Natif $php @('-n', '-r', 'echo PHP_VERSION;')
        $versionPhp = Get-DerniereLigne $r
        if ($versionPhp -notmatch '^(\d+)\.(\d+)') { Stop-Installation ('PHP ne démarre pas : ' + $r.Texte) }
        if (([int]$Matches[1] * 100 + [int]$Matches[2]) -lt 704) { Stop-Installation ('PHP ' + $versionPhp + ' est trop ancien : la version 7.4 ou plus récente est nécessaire.') }
        $r = Invoke-Natif $php @('--ini')
        $ini = ''
        foreach ($ligne in $r.Sortie) {
            if ($ligne -match 'Loaded Configuration File:\s*(.+)$') {
                # PHP 8.5 entoure le chemin de guillemets ("C:\PHP\php.ini") : on les retire
                $valeurIni = $Matches[1].Trim([char[]]@([char]34, [char]39, [char]32, [char]9))
                if ($valeurIni -ne '(none)' -and $valeurIni -ne '') { $ini = $valeurIni }
            }
        }
        if ($ini -eq '' -or -not (Test-Path -LiteralPath $ini)) { $ini = Join-Path $phpDir 'php.ini' }
        if (-not (Test-Path -LiteralPath $ini)) { Stop-Installation ('php.ini introuvable (' + $ini + ') : copiez php.ini-production en php.ini dans ' + $phpDir + '.') }
        Write-Ok ('PHP ' + $versionPhp + ' (' + $phpDir + '), configuration ' + $ini)
        $r = Invoke-Natif $appcmd @('list', 'config', '/section:system.webServer/fastCgi')
        if ($r.Texte -notmatch [regex]::Escape($phpCgi)) {
            Write-Attention ('IIS ne semble pas utiliser ' + $phpCgi + ' (section fastCgi). Si l''application ne répond pas, relancez le script du tutoriel IIS + PHP.')
        }
        $r = Invoke-Natif $appcmd @('list', 'config', ($NomSite + '/'), '/section:system.webServer/handlers')
        if ($r.Texte -notmatch 'path="\*\.php"') {
            Write-Attention 'Aucun gestionnaire *.php dans IIS : les pages PHP risquent d''être refusées. Relancez le script du tutoriel IIS + PHP.'
        }

        # =====================================================================
        # 2. Extensions et réglages de PHP
        # =====================================================================
        Write-Etape 'Extensions de PHP'
        $r = Invoke-Natif $php @('-m')
        $modules = @($r.Sortie | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ -ne '' -and $_ -notmatch '^\[' })
        $r = Invoke-Natif $php @('-r', 'echo ini_get(''extension_dir'');')
        $extDir = Get-DerniereLigne $r
        if ($extDir -eq '') { $extDir = 'ext' }
        if (-not [System.IO.Path]::IsPathRooted($extDir)) { $extDir = Join-Path $phpDir $extDir }

        $latin1 = [System.Text.Encoding]::GetEncoding(28591)     # lecture octet par octet : le reste de php.ini reste identique
        $texteIniOrigine = $latin1.GetString([System.IO.File]::ReadAllBytes($ini))
        $texteIni = $texteIniOrigine
        $extensions = @(
            @{ Nom = 'pdo_sqlite'; Obligatoire = $true; Role = 'base de données SQLite' },
            @{ Nom = 'openssl'; Obligatoire = $true; Role = 'chiffrement et envoi sécurisé des e-mails' },
            @{ Nom = 'sodium'; Obligatoire = $false; Role = 'chiffrement moderne des documents' },
            @{ Nom = 'mbstring'; Obligatoire = $false; Role = 'textes accentués' },
            @{ Nom = 'fileinfo'; Obligatoire = $false; Role = 'reconnaissance du type des fichiers' }
        )
        $aActiver = New-Object 'System.Collections.Generic.List[string]'
        foreach ($ext in $extensions) {
            $nomExt = [string]$ext.Nom
            if ($modules -contains $nomExt) { Write-Ok ($nomExt + ' : ' + $ext.Role); continue }
            $dll = Join-Path $extDir ('php_' + $nomExt + '.dll')
            $probleme = ''
            if ($SansExtensions) { $probleme = 'non activée (option -SansExtensions)' }
            elseif (-not (Test-Path -LiteralPath $dll)) { $probleme = 'fichier ' + $dll + ' introuvable' }
            elseif (Test-ExtensionActiveIni $texteIni $nomExt) { $probleme = 'activée dans php.ini mais ne se charge pas (voir le journal des erreurs de PHP)' }
            if ($probleme -ne '') {
                if ($ext.Obligatoire) { Stop-Installation ('Extension PHP obligatoire « ' + $nomExt + ' » : ' + $probleme + '.') }
                Write-Attention ('Extension « ' + $nomExt + ' » (' + $ext.Role + ') : ' + $probleme + '. Boussole fonctionne sans, en mode dégradé.')
                continue
            }
            $texteIni = Enable-ExtensionIni $texteIni $nomExt
            $aActiver.Add($nomExt)
        }
        foreach ($integre in @('zlib', 'dom', 'simplexml', 'xmlreader', 'json', 'session')) {
            if ($modules -notcontains $integre) { Write-Attention ('Module PHP « ' + $integre + ' » absent : la lecture des fichiers Excel, OpenDocument et XML bancaires sera indisponible.') }
        }

        # tailles d'envoi : Boussole accepte des fichiers jusqu'à 25 Mo
        $r = Invoke-Natif $php @('-r', 'echo ini_get(''upload_max_filesize''), ''|'', ini_get(''post_max_size''), ''|'', ini_get(''memory_limit'');')
        $tailles = (Get-DerniereLigne $r).Split('|')
        $reglagesIni = New-Object 'System.Collections.Generic.List[string]'
        if ($tailles.Count -eq 3) {
            $envoi = ConvertTo-Octets $tailles[0]
            $post = ConvertTo-Octets $tailles[1]
            $memoire = ConvertTo-Octets $tailles[2]
            if ($envoi -ge 0 -and $envoi -lt 26214400) { $texteIni = Set-DirectiveIni $texteIni 'upload_max_filesize' '32M'; $reglagesIni.Add('upload_max_filesize = 32M') }
            if ($post -ge 0 -and $post -lt 31457280) { $texteIni = Set-DirectiveIni $texteIni 'post_max_size' '40M'; $reglagesIni.Add('post_max_size = 40M') }
            if ($memoire -ge 0 -and $memoire -lt 134217728) { $texteIni = Set-DirectiveIni $texteIni 'memory_limit' '256M'; $reglagesIni.Add('memory_limit = 256M') }
            if ($reglagesIni.Count -eq 0) { Write-Ok ('Tailles d''envoi suffisantes (upload_max_filesize ' + $tailles[0] + ', post_max_size ' + $tailles[1] + ', memory_limit ' + $tailles[2] + ')') }
        }

        $iniModifie = ($texteIni -cne $texteIniOrigine)
        if ($iniModifie) {
            $copieIni = $ini + '.avant-boussole-' + (Get-Date -Format 'yyyyMMdd-HHmmss')
            $quoi = @($aActiver | ForEach-Object { 'extension=' + $_ }) + @($reglagesIni)
            Invoke-Etape ('modifier php.ini (' + ($quoi -join ', ') + ') après en avoir fait une copie') {
                Copy-Item -LiteralPath $ini -Destination $copieIni -Force
                [System.IO.File]::WriteAllBytes($ini, $latin1.GetBytes($texteIni))
            }
            if (-not $Simulation) {
                Write-Ok ('php.ini modifié : ' + ($quoi -join ', ') + ' (copie de l''ancien : ' + $copieIni + ')')
                $r = Invoke-Natif $php @('-m')
                $modulesApres = @($r.Sortie | ForEach-Object { $_.Trim().ToLower() })
                foreach ($nomExt in $aActiver) {
                    if ($modulesApres -contains $nomExt) { Write-Ok ($nomExt + ' activée') }
                    else {
                        $obligatoire = ($nomExt -eq 'pdo_sqlite' -or $nomExt -eq 'openssl')
                        if ($obligatoire) { Stop-Installation ('L''extension ' + $nomExt + ' ne se charge pas après activation : ' + $r.Texte) }
                        Write-Attention ('L''extension ' + $nomExt + ' ne se charge pas après activation.')
                    }
                }
            }
        } else {
            Write-Ok 'php.ini déjà prêt : aucune modification'
        }

        # =====================================================================
        # 3. Copie de l'application (le site est arrêté quelques secondes)
        # =====================================================================
        Write-Etape 'Copie de l''application'
        if (-not $Simulation -and $etatSite -eq 'Started') {
            $r = Invoke-Natif $appcmd @('stop', 'site', $NomSite)
            if ($r.Code -eq 0) { $siteArrete = $true; Write-Info ('Site « ' + $NomSite + ' » arrêté pendant la mise à jour.') }
            else { Write-Attention ('Impossible d''arrêter le site pendant la copie : ' + $r.Texte) }
        }
        if ($sourceDir -eq $siteDir) {
            Write-Ok 'Les fichiers sont déjà dans le site web : rien à copier'
        } else {
            Invoke-Etape ('copier ' + $sourceDir + ' vers ' + $siteDir) {
                $copie = Invoke-Natif $robocopy @($sourceDir, $siteDir, '/E', '/R:2', '/W:2', '/NP', '/NFL', '/NDL', '/NJH', '/NJS',
                    '/XD', 'data', 'windows', 'tests', '.git', '.github', '/XF', 'config.local.php', '*.md', '.gitattributes', '.gitignore', 'Thumbs.db', 'desktop.ini')
                if ($copie.Code -ge 8) { throw ('la copie a échoué (robocopy, code ' + $copie.Code + ') : ' + $copie.Texte) }
            }
            if (-not $Simulation) {
                if (-not (Test-Path -LiteralPath (Join-Path $siteDir 'api.php'))) { Stop-Installation ('Copie incomplète : api.php absent de ' + $siteDir) }
                Write-Ok ('Application copiée dans ' + $siteDir)
            }
        }
        if ($vdirChemin -ne '') {
            $r = Invoke-Natif $appcmd @('list', 'vdir', ($NomSite + $vdirChemin), '/text:physicalPath')
            $vdirExistant = $r.Texte.Trim()
            if ($r.Code -ne 0 -or $vdirExistant -eq '') {
                Invoke-Etape ('publier ' + $siteDir + ' à l''adresse ' + $vdirChemin + '/ (répertoire virtuel)') {
                    $ajout = Invoke-Natif $appcmd @('add', 'vdir', ('/app.name:' + $NomSite + '/'), ('/path:' + $vdirChemin), ('/physicalPath:' + $siteDir))
                    if ($ajout.Code -ne 0) { throw ('création du répertoire virtuel impossible : ' + $ajout.Texte) }
                }
            } elseif ((Get-CheminComplet $vdirExistant) -ne $siteDir) {
                Stop-Installation ('L''adresse ' + $vdirChemin + '/ du site publie déjà un autre dossier (' + $vdirExistant + ').')
            }
            Invoke-Etape 'autoriser IIS à lire le dossier de l''application' {
                $droits = Invoke-Natif $icacls @($siteDir, '/grant', '*S-1-5-32-568:(OI)(CI)RX', '*S-1-5-17:(OI)(CI)RX', '/C', '/Q')
                if ($droits.Code -ne 0) { throw ('icacls : ' + $droits.Texte) }
            }
        }

        # =====================================================================
        # 4. Dossier des données, hors du site web
        # =====================================================================
        Write-Etape 'Dossier des données'
        if (Test-Path -LiteralPath $dataDir) {
            $contenu = @(Get-ChildItem -LiteralPath $dataDir -Force)
            $estBoussole = (Test-Path -LiteralPath (Join-Path $dataDir 'boussole.sqlite')) -or (Test-Path -LiteralPath (Join-Path $dataDir 'secret.key')) -or (Test-Path -LiteralPath (Join-Path $dataDir 'vault'))
            if ($contenu.Count -gt 0 -and -not $estBoussole) {
                Stop-Installation ('Le dossier ' + $dataDir + ' existe déjà et contient d''autres fichiers : choisissez un dossier vide ou nouveau avec -DossierDonnees.')
            }
        } else {
            Invoke-Etape ('créer ' + $dataDir) { New-Item -ItemType Directory -Path $dataDir -Force | Out-Null }
        }
        # ancien emplacement (dossier data dans le site) : les données sont déplacées, jamais écrasées
        $ancienData = Join-Path $siteDir 'data'
        if (($ancienData -ne $dataDir) -and (Test-Path -LiteralPath (Join-Path $ancienData 'boussole.sqlite'))) {
            if (Test-Path -LiteralPath (Join-Path $dataDir 'boussole.sqlite')) {
                Write-Attention ('Deux bases existent : ' + $ancienData + ' et ' + $dataDir + '. La nouvelle est utilisée ; l''ancienne est laissée en place (invisible depuis un navigateur). Comparez-les avant d''en supprimer une.')
            } else {
                Invoke-Etape ('déplacer les données existantes de ' + $ancienData + ' vers ' + $dataDir) {
                    $deplacement = Invoke-Natif $robocopy @($ancienData, $dataDir, '/E', '/MOVE', '/R:2', '/W:2', '/NP', '/NFL', '/NDL', '/NJH', '/NJS')
                    if ($deplacement.Code -ge 8) { throw ('déplacement des données impossible (robocopy, code ' + $deplacement.Code + ') : ' + $deplacement.Texte) }
                    if ((Test-Path -LiteralPath $ancienData) -and (@(Get-ChildItem -LiteralPath $ancienData -Recurse -Force -File).Count -eq 0)) {
                        Remove-Item -LiteralPath $ancienData -Recurse -Force
                    }
                }
                Write-Ok ('Données existantes déplacées vers ' + $dataDir)
            }
        }
        Invoke-Etape ('réserver ' + $dataDir + ' à IIS et aux administrateurs') {
            $droits = Invoke-Natif $icacls @($dataDir, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-568:(OI)(CI)M', '*S-1-5-17:(OI)(CI)M', '/C', '/Q')
            if ($droits.Code -ne 0) { throw ('icacls : ' + $droits.Texte) }
            if (@(Get-ChildItem -LiteralPath $dataDir -Force).Count -gt 0) {
                $heritage = Invoke-Natif $icacls @((Join-Path $dataDir '*'), '/reset', '/T', '/C', '/Q')
                if ($heritage.Code -ne 0) { throw ('icacls : ' + $heritage.Texte) }
            }
        }
        if (-not $Simulation) { Write-Ok ($dataDir + ' : accessible seulement à IIS, au système et aux administrateurs') }

        if (-not (Test-Path -LiteralPath $backupDir)) {
            Invoke-Etape ('créer ' + $backupDir + ' (réservé aux administrateurs)') {
                New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
                $droits = Invoke-Natif $icacls @($backupDir, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', '/C', '/Q')
                if ($droits.Code -ne 0) { throw ('icacls : ' + $droits.Texte) }
            }
        }

        # =====================================================================
        # 5. Réglages de cette installation (config.local.php)
        # =====================================================================
        Write-Etape 'Réglages de cette installation'
        $fichierConfig = Join-Path $siteDir 'config.local.php'
        $fin = "`r`n"
        $lignesBloc = @(
            '// >>> installeur Boussole : bloc réécrit à chaque installation (ajoutez vos propres réglages en dessous)',
            ('$DATA_DIR = ' + (ConvertTo-ChainePhp (ConvertTo-CheminPhp $dataDir)) + ';'),
            ('$ALLOWED_NETS = [' + (ConvertTo-ChainePhp $Acces) + '];'),
            '// <<< installeur Boussole'
        )
        $bloc = $lignesBloc -join $fin
        if (Test-Path -LiteralPath $fichierConfig) {
            $actuel = [System.IO.File]::ReadAllText($fichierConfig).TrimStart([char]0xFEFF)
        } else {
            $actuel = '<?php' + $fin + '// Boussole : réglages propres à ce serveur (ce fichier n''est jamais publié ni remplacé par une mise à jour).' + $fin
        }
        $debutBloc = $actuel.IndexOf('// >>> installeur Boussole')
        $finBloc = $actuel.IndexOf('// <<< installeur Boussole')
        if ($debutBloc -ge 0 -and $finBloc -gt $debutBloc) {
            $finLigne = $actuel.IndexOf("`n", $finBloc)
            if ($finLigne -lt 0) { $finLigne = $actuel.Length } else { $finLigne = $finLigne + 1 }
            $nouveau = $actuel.Substring(0, $debutBloc) + $bloc + $fin + $actuel.Substring($finLigne)
        } else {
            $balise = $actuel.IndexOf('<?php')
            if ($balise -lt 0) {
                $nouveau = '<?php' + $fin + $bloc + $fin + $actuel
            } else {
                $apres = $actuel.IndexOf("`n", $balise)
                if ($apres -lt 0) { $nouveau = $actuel + $fin + $bloc + $fin }
                else { $nouveau = $actuel.Substring(0, $apres + 1) + $bloc + $fin + $actuel.Substring($apres + 1) }
            }
        }
        Invoke-Etape ('écrire ' + $fichierConfig) {
            [System.IO.File]::WriteAllText($fichierConfig, $nouveau, (New-Object System.Text.UTF8Encoding($false)))
            $verif = Invoke-Natif $php @('-l', $fichierConfig)
            if ($verif.Code -ne 0) { throw ('config.local.php contient une erreur : ' + $verif.Texte) }
        }
        if (-not $Simulation) {
            Write-Ok ('Données : ' + $dataDir)
            if ($Acces -eq 'local') { Write-Ok 'Accès réservé aux appareils de la maison et au VPN Tailscale' }
            else { Write-Attention 'Accès ouvert à toutes les adresses (-Acces tout) : réservez cela à une installation en HTTPS.' }
        }

        # redémarrage du site et de PHP (nouveau php.ini)
        if ($siteArrete) {
            $r = Invoke-Natif $appcmd @('start', 'site', $NomSite)
            if ($r.Code -eq 0) { $siteArrete = $false; Write-Ok ('Site « ' + $NomSite + ' » redémarré') }
            else { Write-Attention ('Le site ne redémarre pas : ' + $r.Texte) }
        } elseif (-not $Simulation -and $etatSite -ne 'Started') {
            $r = Invoke-Natif $appcmd @('start', 'site', $NomSite)
            if ($r.Code -eq 0) { Write-Ok ('Site « ' + $NomSite + ' » démarré (il était arrêté)') }
            else { Write-Attention ('Le site « ' + $NomSite + ' » est arrêté et ne démarre pas : ' + $r.Texte) }
        }
        if ($iniModifie -and -not $Simulation) {
            $relance = $null
            if ($pool -ne '') { $relance = Invoke-Natif $appcmd @('recycle', 'apppool', $pool) }
            if ($null -eq $relance -or $relance.Code -ne 0) { $relance = Invoke-Natif (Join-Path $system32 'iisreset.exe') @('/restart') }
            if ($relance.Code -eq 0) { Write-Ok 'PHP relancé avec la nouvelle configuration' }
            else { Write-Attention ('Redémarrez IIS (commande iisreset) pour appliquer le nouveau php.ini : ' + $relance.Texte) }
        }

        # =====================================================================
        # 6. Vérification en conditions réelles
        # =====================================================================
        Write-Etape 'Vérification de l''application'
        if ($Simulation -and -not (Test-Path -LiteralPath (Join-Path $siteDir 'api.php'))) {
            Write-Info 'Simulation : application pas encore copiée, vérification sautée.'
        } else {
            if ($Simulation) { Write-Info 'Simulation : vérification de l''installation actuelle.' }
            $reponse = Invoke-Http ($urlLocale + 'api.php?a=status')
            $etat = $null
            try { $etat = $reponse.Texte | ConvertFrom-Json } catch { $etat = $null }
            if ($reponse.Code -eq 200 -and $null -ne $etat -and $null -ne $etat.version) {
                Write-Ok ('L''application répond : ' + $urlLocale + ' (version ' + $etat.version + ')')
                if ([string]$etat.chiffrement -eq '') { Write-Attention 'Ni sodium ni OpenSSL ne sont actifs dans PHP : les documents ne pourront pas être chiffrés.' }
                else { Write-Ok ('Chiffrement des documents : ' + $etat.chiffrement) }
            } else {
                $message = Get-Debut $reponse.Texte
                if ($null -ne $etat -and $null -ne $etat.error) { $message = [string]$etat.error }
                $conseil = ''
                if ($reponse.Code -eq 404) { $conseil = ' Vérifiez que le dossier ' + $siteDir + ' est bien publié par le site « ' + $NomSite + ' ».' }
                elseif ($reponse.Code -eq 500) { $conseil = ' Le détail est dans ' + (Join-Path $dataDir 'erreurs.log') + ' ou dans le journal des erreurs de PHP.' }
                elseif ($reponse.Code -eq 0) { $conseil = ' Le site web est-il démarré ? (Gestionnaire des services Internet IIS)' }
                Stop-Installation ('L''application ne répond pas correctement (' + $urlLocale + 'api.php, code HTTP ' + $reponse.Code + ') : ' + $message + $conseil)
            }
            if (Test-Path -LiteralPath (Join-Path $dataDir 'boussole.sqlite')) { Write-Ok ('IIS écrit bien dans ' + $dataDir) }
            elseif (-not $Simulation) { Write-Attention ('La base n''a pas été créée dans ' + $dataDir + ' : IIS n''a peut-être pas le droit d''y écrire.') }
            $exposes = 0
            foreach ($interne in @('data/probe.txt', 'config.local.php', 'lib/util.php', 'outils/sauvegarde.php', 'LISEZ-MOI.md', 'tests/run-tests.php')) {
                $essai = Invoke-Http ($urlLocale + $interne)
                if ($essai.Code -eq 200 -and $essai.Texte.Trim() -ne '') {
                    $exposes++
                    Write-Attention ('DANGER : ' + $urlLocale + $interne + ' est lisible depuis un navigateur. Vérifiez que web.config a bien été copié et que le filtrage des requêtes d''IIS est installé.')
                }
            }
            if ($exposes -eq 0) { Write-Ok 'Fichiers internes invisibles depuis un navigateur (base, clé, réglages, code)' }
            $accueil = Invoke-Http $urlLocale
            if ($accueil.Code -eq 200 -and $accueil.Texte -match 'Boussole') { Write-Ok 'Page d''accueil servie' }
            else { Write-Attention ('La page d''accueil ne s''affiche pas (code HTTP ' + $accueil.Code + ').') }
        }

        # =====================================================================
        # 7. Tâches planifiées et première sauvegarde
        # =====================================================================
        Write-Etape 'Veille et sauvegarde automatiques'
        if ($SansTaches) {
            Write-Info 'Option -SansTaches : aucune tâche planifiée.'
        } else {
            $compteSysteme = 'SYSTEM'
            try { $compteSysteme = (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')).Translate([System.Security.Principal.NTAccount]).Value } catch { $compteSysteme = 'SYSTEM' }
            $principalTache = New-ScheduledTaskPrincipal -UserId $compteSysteme -LogonType ServiceAccount -RunLevel Highest
            $reglagesTache = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Hours 2) -MultipleInstances IgnoreNew
            $urlVeille = $urlLocale + 'api.php?a=veille'
            if (Test-Path -LiteralPath $curl) {
                $argumentsVeille = '-s -S -f -m 300 -o NUL'
                if ($schema -eq 'https') { $argumentsVeille = $argumentsVeille + ' -k' }
                $actionVeille = New-ScheduledTaskAction -Execute $curl -Argument ($argumentsVeille + ' "' + $urlVeille + '"')
            } else {
                $actionVeille = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -Command "Invoke-WebRequest -UseBasicParsing -TimeoutSec 300 -Uri ''' + $urlVeille + ''' | Out-Null"')
            }
            $declencheurVeille = New-ScheduledTaskTrigger -Daily -At $HeureVeille
            Invoke-Etape ('programmer la veille chaque jour à ' + $HeureVeille) {
                Register-ScheduledTask -TaskPath $tachesDossier -TaskName 'Veille' -Action $actionVeille -Trigger $declencheurVeille -Principal $principalTache -Settings $reglagesTache -Force `
                    -Description 'Boussole : recalcule les alertes de chaque personne (découvert prévu, frais bancaires, papiers qui expirent) et envoie les e-mails.' | Out-Null
            }
            $argumentsSauvegarde = '"' + $scriptSauvegarde + '" --vers="' + (ConvertTo-CheminPhp $backupDir) + '" --garder=' + $GarderSauvegardes
            $actionSauvegarde = New-ScheduledTaskAction -Execute $php -Argument $argumentsSauvegarde -WorkingDirectory $phpDir
            $declencheurSauvegarde = New-ScheduledTaskTrigger -Daily -At $HeureSauvegarde
            Invoke-Etape ('programmer la sauvegarde chaque jour à ' + $HeureSauvegarde + ' vers ' + $backupDir) {
                Register-ScheduledTask -TaskPath $tachesDossier -TaskName 'Sauvegarde' -Action $actionSauvegarde -Trigger $declencheurSauvegarde -Principal $principalTache -Settings $reglagesTache -Force `
                    -Description ('Boussole : sauvegarde de la base, de la clé et des documents chiffrés ; les ' + $GarderSauvegardes + ' plus récentes sont conservées.') | Out-Null
            }
            if (-not $Simulation) {
                foreach ($nomTache in @('Veille', 'Sauvegarde')) {
                    $infoTache = Get-ScheduledTaskInfo -TaskPath $tachesDossier -TaskName $nomTache
                    $prochaine = 'date inconnue'
                    if ($null -ne $infoTache -and $null -ne $infoTache.NextRunTime) { $prochaine = ([datetime]$infoTache.NextRunTime).ToString('dd/MM/yyyy HH:mm') }
                    Write-Ok ('Tâche « ' + $nomTache + ' » programmée (prochaine exécution : ' + $prochaine + ')')
                }
            }
        }
        if (-not $Simulation) {
            Write-Info 'Première sauvegarde...'
            $sauvegarde = Invoke-Natif $php @($scriptSauvegarde, ('--vers=' + (ConvertTo-CheminPhp $backupDir)), ('--garder=' + $GarderSauvegardes)) -Utf8
            foreach ($ligne in $sauvegarde.Sortie) { if ($ligne.Trim() -ne '') { Write-Info $ligne } }
            if ($sauvegarde.Code -eq 0) { Write-Ok ('Sauvegarde réussie dans ' + $backupDir) }
            else { Write-Attention ('La première sauvegarde a échoué (code ' + $sauvegarde.Code + ') : lisez le message ci-dessus.') }
        }

        # =====================================================================
        # 8. Pare-feu et accès depuis les autres appareils
        # =====================================================================
        Write-Etape 'Accès depuis les autres appareils de la maison'
        $profilsPublics = @()
        try { $profilsPublics = @(Get-NetConnectionProfile -ErrorAction Stop | Where-Object { [string]$_.NetworkCategory -eq 'Public' }) } catch { $profilsPublics = @() }
        foreach ($profil in $profilsPublics) {
            if ($PareFeu) {
                $indexInterface = $profil.InterfaceIndex
                Invoke-Etape ('passer le réseau « ' + $profil.Name + ' » en profil Privé') { Set-NetConnectionProfile -InterfaceIndex $indexInterface -NetworkCategory Private }
                if (-not $Simulation) { Write-Ok ('Réseau « ' + $profil.Name + ' » passé en profil Privé') }
            } else {
                Write-Attention ('Le réseau « ' + $profil.Name + ' » est en profil Public : les autres appareils ne pourront pas se connecter. Paramètres > Réseau et Internet > Ethernet ou Wi-Fi > Type de profil réseau : Privé (ou relancez avec -PareFeu).')
            }
        }
        $regles80 = @()
        try {
            $filtres80 = @(Get-NetFirewallPortFilter -ErrorAction Stop | Where-Object { [string]$_.Protocol -eq 'TCP' -and @($_.LocalPort) -contains '80' })
            if ($filtres80.Count -gt 0) {
                $regles80 = @($filtres80 | Get-NetFirewallRule -ErrorAction Stop | Where-Object { [string]$_.Direction -eq 'Inbound' -and [string]$_.Action -eq 'Allow' -and [string]$_.Enabled -eq 'True' })
            }
        } catch {
            Write-Attention ('Lecture du pare-feu impossible : ' + $_.Exception.Message)
        }
        $reglesLarges = @()
        $reglesLimitees = @()
        foreach ($regle in $regles80) {
            $adresses = @()
            try { $adresses = @(($regle | Get-NetFirewallAddressFilter -ErrorAction Stop).RemoteAddress) } catch { $adresses = @('Any') }
            if ($adresses -contains 'Any') { $reglesLarges += $regle } else { $reglesLimitees += $regle }
        }
        if ($regles80.Count -eq 0) {
            if ($PareFeu) {
                Invoke-Etape 'ouvrir le port 80 au seul réseau de la maison (règle « Boussole - HTTP réseau local »)' {
                    New-NetFirewallRule -DisplayName 'Boussole - HTTP réseau local' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Profile 'Domain, Private' `
                        -RemoteAddress @('LocalSubnet', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fe80::/10', 'fc00::/7') | Out-Null
                }
                if (-not $Simulation) { Write-Ok 'Port 80 ouvert au seul réseau de la maison' }
            } else {
                Write-Attention 'Aucune règle du pare-feu n''ouvre le port 80 : seul ce PC peut ouvrir Boussole. Relancez avec -PareFeu pour l''ouvrir au seul réseau de la maison.'
            }
        } else {
            foreach ($regle in $reglesLimitees) { Write-Ok ('Règle « ' + $regle.DisplayName + ' » : port 80 limité au réseau local') }
        }
        foreach ($regle in $reglesLarges) {
            if ($PareFeu) {
                $nomRegle = [string]$regle.Name
                if ($reglesLimitees.Count -eq 0) {
                    Invoke-Etape 'ajouter la règle « Boussole - HTTP réseau local »' {
                        New-NetFirewallRule -DisplayName 'Boussole - HTTP réseau local' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Profile 'Domain, Private' `
                            -RemoteAddress @('LocalSubnet', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fe80::/10', 'fc00::/7') | Out-Null
                    }
                    $reglesLimitees += $regle
                }
                Invoke-Etape ('désactiver la règle trop large « ' + $regle.DisplayName + ' »') { Disable-NetFirewallRule -Name $nomRegle }
                if (-not $Simulation) { Write-Ok ('Règle « ' + $regle.DisplayName + ' » désactivée (elle ouvrait le port 80 à toutes les adresses)') }
            } else {
                Write-Attention ('La règle « ' + $regle.DisplayName + ' » ouvre le port 80 à TOUTES les adresses. Boussole refuse déjà les appareils extérieurs, mais relancez avec -PareFeu pour la remplacer par une règle limitée au réseau local.')
            }
        }

        # =====================================================================
        # 9. Résumé
        # =====================================================================
        Write-Etape 'Terminé'
        if ($Simulation) {
            Write-Host ''
            Write-Host '  Simulation terminée : relancez sans -Simulation pour installer.' -ForegroundColor White
        } else {
            Write-Host ''
            Write-Host ('  Sur ce PC                      : ' + $urlLocale) -ForegroundColor White
            $ipv4 = @()
            try { $ipv4 = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop | Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } | Sort-Object -Property InterfaceIndex | Select-Object -ExpandProperty IPAddress) } catch { $ipv4 = @() }
            foreach ($ip in $ipv4) {
                $libelle = 'Depuis la maison (téléphone, PC)'
                if ($ip -match '^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.') { $libelle = 'Par le VPN Tailscale            ' }
                Write-Host ('  ' + $libelle + ' : ' + $schema + '://' + $ip + $suffixePort + $cheminUrl) -ForegroundColor White
            }
            Write-Host ''
            Write-Host '  Prochaines étapes :'
            Write-Host '   1. Ouvrez l''adresse ci-dessus : créez votre accès (adresse e-mail + mot de passe) et notez le code de secours.'
            Write-Host '   2. Réglages > Envoi des e-mails : Gmail avec un « mot de passe d''application », puis « Envoyer un test ».'
            Write-Host '   3. Foyer : invitez les autres personnes ; chacune choisit son propre mot de passe.'
            Write-Host '   4. Freebox OS : réservez l''adresse IP de ce PC (bail DHCP statique) pour que l''adresse ne change pas.'
            Write-Host '   5. Pas de redirection de port vers ce PC : de l''extérieur, passez par Tailscale (ou installez HTTPS, tutoriel 2).'
            Write-Host '   6. Chiffrez le disque (BitLocker) et sauvegardez aussi sur un disque externe.'
        }
        if ($script:Avertissements.Count -gt 0) {
            Write-Host ''
            Write-Host ('  ' + $script:Avertissements.Count + ' point(s) à regarder :') -ForegroundColor Yellow
            foreach ($avertissement in $script:Avertissements) { Write-Host ('   - ' + $avertissement) -ForegroundColor Yellow }
        }
    }
} catch {
    $codeSortie = 1
    Write-Host ''
    Write-Host ('ÉCHEC : ' + $_.Exception.Message) -ForegroundColor Red
    if (-not $script:ArretVolontaire -and $null -ne $_.InvocationInfo -and $_.InvocationInfo.ScriptLineNumber -gt 0) {
        Write-Host ('(ligne ' + $_.InvocationInfo.ScriptLineNumber + ' du script)') -ForegroundColor DarkGray
    }
    Write-Host 'Vos données n''ont pas été touchées. Corrigez le problème indiqué puis relancez le script : il reprend là où il faut.' -ForegroundColor Yellow
} finally {
    if ($siteArrete) {
        $redemarrage = Invoke-Natif $appcmd @('start', 'site', $NomSite)
        if ($redemarrage.Code -eq 0) { Write-Host ('    Site « ' + $NomSite + ' » redémarré.') }
        else { Write-Host ('    Redémarrez le site « ' + $NomSite + ' » dans le Gestionnaire IIS : ' + $redemarrage.Texte) -ForegroundColor Yellow }
    }
    if ($null -ne $journal) {
        try { Stop-Transcript | Out-Null } catch { }
        Write-Host ('Journal de l''installation : ' + $journal) -ForegroundColor DarkGray
    }
}
exit $codeSortie
