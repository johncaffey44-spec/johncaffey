<#
.SYNOPSIS
    Installe ou met à jour Sentinelle XDR sur le PC Windows 11 Pro qui sert de serveur (IIS + PHP 8.x).

.DESCRIPTION
    Double-cliquez sur INSTALLER.cmd : il demande les droits administrateur puis lance ce script.

    Le script ANALYSE d'abord, affiche la liste des changements prévus ([à faire]) et attend votre accord (O)
    avant de modifier quoi que ce soit. Il peut être relancé sans risque : il ne refait que ce qui manque.
    C'est aussi la procédure de MISE À JOUR. Il ne supprime jamais vos données.

    Il reprend les leçons des installations déjà faites sur ce serveur (tutoriel « Serveur web IIS + PHP »,
    Boussole 1.1.1, Missions v3.1) :
      - web.config compatible avec un IIS déjà sécurisé : chaque règle est précédée d'un <remove>
        (sinon « entrée en double », erreur 500.19) ;
      - php.ini lu correctement avec PHP 8.5 (chemin entre guillemets dans « php --ini ») et modifié
        seulement si besoin, après une copie de sauvegarde ; ligne OPcache et register_argc_argv de PHP 8.5 ;
        open_basedir complétée si elle est utilisée ;
      - application IIS à part avec son propre pool ; les visiteurs anonymes tournent sous l'identité du pool
        (avec fastcgi.impersonate = 1, PHP écrirait sinon sous IUSR) ;
      - identifiant EXACT de l'application : pour un dossier qui n'est pas encore une application, appcmd
        renvoie la racine du site (piège corrigé dans Missions v3.1) ;
      - données HORS du site, lisibles par le seul pool de Sentinelle : la clé d'accès à la Freebox n'est
        accessible à aucune autre application du serveur ;
      - vérification finale en conditions réelles, avec la cause probable de chaque erreur IIS.

.PARAMETER DossierSite
    Dossier de l'application. Par défaut C:\inetpub\wwwroot\xdr, soit l'adresse http://localhost/xdr/.

.PARAMETER DossierDonnees
    Base de données, clé d'accès Freebox, sessions. Par défaut C:\SentinelleDonnees (hors du site web).

.PARAMETER DossierPHP
    Dossier de PHP. Par défaut : celui qu'IIS utilise déjà (réglage FastCGI), sinon C:\PHP.

.PARAMETER NomSite
    Site IIS qui publie l'application. Par défaut : Default Web Site.

.PARAMETER NomPool
    Pool d'applications propre à Sentinelle. Par défaut : SentinelleXDR.

.PARAMETER SansPareFeu
    Ne touche ni au pare-feu Windows ni au profil réseau.

.PARAMETER AdGuard
    Installe AdGuard Home (onglet Enfants) sans poser la question.

.PARAMETER SansAdGuard
    Ne propose pas AdGuard Home.

.PARAMETER SansExtensions
    Ne modifie jamais php.ini (le script s'arrête si une extension obligatoire manque).

.PARAMETER Simulation
    Analyse et affiche le plan, sans rien modifier.

.PARAMETER Desinstaller
    Retire l'application, son pool et sa tâche planifiée. Les données sont conservées.

.PARAMETER Oui
    Ne demande aucune confirmation.

.EXAMPLE
    .\Installer-XDR.ps1 -Simulation

.EXAMPLE
    .\Installer-XDR.ps1 -DossierDonnees 'D:\SentinelleDonnees'

.EXAMPLE
    .\Installer-XDR.ps1 -Desinstaller
#>
[CmdletBinding()]
param(
    [Alias('Target')]
    [string]$DossierSite = 'C:\inetpub\wwwroot\xdr',
    [string]$DossierDonnees = 'C:\SentinelleDonnees',
    [string]$DossierPHP = '',
    [Alias('Site')]
    [string]$NomSite = 'Default Web Site',
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$')]
    [string]$NomPool = 'SentinelleXDR',
    [Alias('SkipFirewall')]
    [switch]$SansPareFeu,
    [switch]$AdGuard,
    [switch]$SansAdGuard,
    [switch]$SansExtensions,
    [switch]$Simulation,
    [switch]$Desinstaller,
    [switch]$Oui
)

Set-StrictMode -Version 1.0
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# =============================================================================
# Affichage
# =============================================================================

$script:Etape = 0
$script:Avertissements = New-Object 'System.Collections.Generic.List[string]'
$script:Plan = New-Object 'System.Collections.Generic.List[string]'
$script:ArretVolontaire = $false

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
function Add-Plan([string]$Texte) { $script:Plan.Add($Texte) }
function Stop-Installation([string]$Texte) { $script:ArretVolontaire = $true; throw $Texte }

function Confirm-Choix([string]$Question) {
    if ($Oui) { return $true }
    $reponse = Read-Host ('    ' + $Question + ' (O/N)')
    return ($reponse -match '^\s*(o|oui|y|yes)\s*$')
}

# =============================================================================
# Exécution de programmes (formes déjà vérifiées sur ce serveur avec Boussole)
# =============================================================================

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

# appcmd reçoit sa ligne de commande EXACTEMENT comme dans une invite cmd.exe (formes du guide Missions) :
# Windows PowerShell entourerait sinon de guillemets tout un « /site.name:Default Web Site ».
function Invoke-Appcmd([string]$LigneArguments) {
    $infos = New-Object System.Diagnostics.ProcessStartInfo
    $infos.FileName = $script:Appcmd
    $infos.Arguments = $LigneArguments
    $infos.UseShellExecute = $false
    $infos.RedirectStandardOutput = $true
    $infos.RedirectStandardError = $true
    $infos.CreateNoWindow = $true
    try { $infos.StandardOutputEncoding = [Console]::OutputEncoding; $infos.StandardErrorEncoding = [Console]::OutputEncoding } catch { }
    try {
        $processus = [System.Diagnostics.Process]::Start($infos)
        $lectureErreurs = $processus.StandardError.ReadToEndAsync()
        $sortie = $processus.StandardOutput.ReadToEnd()
        $processus.WaitForExit()
        $erreurs = $lectureErreurs.Result
        $code = $processus.ExitCode
    } catch {
        return [pscustomobject]@{ Code = 9999; Sortie = @([string]$_.Exception.Message); Texte = [string]$_.Exception.Message }
    }
    $lignes = @((($sortie + "`n" + $erreurs) -split "`r?`n") | Where-Object { $_.Trim() -ne '' })
    return [pscustomobject]@{ Code = [int]$code; Sortie = $lignes; Texte = ($lignes -join "`n") }
}
function Q([string]$Texte) { return '"' + $Texte + '"' }

# Requête HTTP qui rend toujours un code et un texte (même en cas d'erreur 404 ou 500).
function Invoke-Http([string]$Url) {
    try {
        $reponseOk = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 60 -ErrorAction Stop
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

# Requête vers Sentinelle depuis CE PC, comme le fera la tâche planifiée (curl.exe, adresse de bouclage).
function Invoke-Local([string]$Chemin) {
    $url = $script:UrlLocale + $Chemin
    if (-not (Test-Path -LiteralPath $script:Curl)) { return (Invoke-Http $url) }
    $arguments = @('-s', '-S', '-m', '60', '-w', '\n%{http_code}')
    if ($script:Schema -eq 'https') { $arguments += '-k' }
    if ($script:Hote -ne 'localhost') { $arguments += @('--resolve', ($script:Hote + ':' + $script:Port + ':127.0.0.1')) }
    $arguments += $url
    $r = Invoke-Natif $script:Curl $arguments -Utf8
    $lignes = @($r.Sortie)
    if ($lignes.Count -gt 0 -and ([string]$lignes[$lignes.Count - 1]).Trim() -match '^\d{3}$') {
        $code = [int](([string]$lignes[$lignes.Count - 1]).Trim())
        $texte = ''
        if ($lignes.Count -gt 1) { $texte = ($lignes[0..($lignes.Count - 2)] -join "`n") }
        return [pscustomobject]@{ Code = $code; Texte = $texte }
    }
    return [pscustomobject]@{ Code = 0; Texte = $r.Texte }
}

# Dernière ligne non vide d'une sortie (les avertissements de démarrage de PHP s'affichent avant le résultat).
function Get-DerniereLigne($Resultat) {
    $lignesNonVides = @($Resultat.Sortie | Where-Object { ([string]$_).Trim() -ne '' })
    if ($lignesNonVides.Count -eq 0) { return '' }
    return ([string]$lignesNonVides[$lignesNonVides.Count - 1]).Trim()
}

function Get-Debut([string]$Texte) {
    $t = ($Texte -replace '<[^>]+>', ' ' -replace '&nbsp;', ' ' -replace '\s+', ' ').Trim()
    if ($t.Length -gt 300) { $t = $t.Substring(0, 300) + '...' }
    return $t
}

# Cause probable d'une réponse HTTP en échec (pages d'erreur détaillées d'IIS, en anglais ou en français).
function Get-Diagnostic($Reponse) {
    $t = [string]$Reponse.Texte
    $sous = ''
    if ($t -match '(?:HTTP Error|Erreur HTTP)\s+(\d{3}\.\d+)') { $sous = $Matches[1] }
    $detail = ''
    if ($t -match '(?is)<th>\s*(?:Config Error|Erreur de configuration)\s*</th>\s*<td>(.*?)</td>') { $detail = Get-Debut $Matches[1] }
    $fichier = ''
    if ($t -match '(?is)<th>\s*(?:Config File|Fichier de configuration)\s*</th>\s*<td>(.*?)</td>') { $fichier = (Get-Debut $Matches[1]) -replace '^\\\\\?\\', '' }
    $messageJson = ''
    try {
        $json = $t | ConvertFrom-Json
        if ($null -ne $json -and $json.PSObject.Properties.Match('error').Count -gt 0) { $messageJson = [string]$json.error }
    } catch { $messageJson = '' }
    if ($sous -eq '500.19') {
        $m = 'erreur IIS 500.19 : un web.config est refusé (règle en double, ou section verrouillée au niveau du serveur).'
        if ($detail -ne '') { $m += ' Détail : ' + $detail }
        if ($fichier -ne '') { $m += ' Fichier : ' + $fichier }
        return $m
    }
    if ($sous -eq '500.21') { return 'erreur IIS 500.21 : module FastCGI non reconnu. Fonctionnalités Windows > Internet Information Services > Services World Wide Web > Fonctionnalités de développement d''applications > CGI.' }
    if ($sous -eq '404.3') { return 'erreur IIS 404.3 : IIS ne transmet pas les fichiers .php à PHP (mappage *.php absent). Relancez Installer-IIS-PHP.cmd du tutoriel.' }
    if ($sous -eq '401.3') { return ('erreur IIS 401.3 : le pool « ' + $NomPool + ' » ne peut pas lire ' + $script:SiteDir + ' (droits NTFS). Relancez ce script.') }
    if ($t -match '(?i)FastCGI process exited unexpectedly|processus FastCGI') { return ('PHP s''arrête dès le démarrage : Visual C++ 2015-2022 x64 absent ou php.ini invalide (testez ' + $script:PhpCgi + ' -v).') }
    if ($Reponse.Code -eq 503) { return ('erreur 503 : le pool « ' + $NomPool + ' » est arrêté ou s''arrête au démarrage (Gestionnaire IIS > Pools d''applications ; Observateur d''événements > Système, source WAS).') }
    if ($Reponse.Code -eq 0) { return ('aucune réponse : le site IIS est-il démarré ? Le port ' + $script:Port + ' est-il pris par un autre logiciel (netstat -ano | findstr :' + $script:Port + ') ? ' + (Get-Debut $t)) }
    if ($Reponse.Code -eq 404) { return ('erreur 404 : ' + $script:UrlLocale + ' ne publie pas ' + $script:SiteDir + '. Vérifiez -NomSite et -DossierSite.') }
    if ($messageJson -ne '') { return ('Sentinelle répond : ' + $messageJson) }
    if ($Reponse.Code -eq 500) { return ('erreur 500 : lisez ' + (Join-Path $script:DataDir 'php-errors.log') + ', puis le journal des erreurs de PHP (C:\PHP\logs\php_errors.log).') }
    return ('code HTTP ' + $Reponse.Code + ' : ' + (Get-Debut $t))
}

# =============================================================================
# Chemins, IIS et php.ini
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

function Get-VersionXdr([string]$Dossier) {
    $fichier = Join-Path $Dossier 'app\bootstrap.php'
    if (-not (Test-Path -LiteralPath $fichier)) { return '' }
    $trouve = Select-String -LiteralPath $fichier -Pattern "const XDR_VERSION = '([^']+)'" -List
    if ($null -ne $trouve) { return $trouve.Matches[0].Groups[1].Value }
    return ''
}

# Applications IIS, lues dans la sortie standard d'appcmd : APP "Default Web Site/xdr" (applicationPool:SentinelleXDR)
function Get-Applications {
    $r = Invoke-Appcmd 'list app'
    $liste = @()
    foreach ($ligne in $r.Sortie) {
        if ($ligne -match '^\s*APP "([^"]+)" \(applicationPool:([^)]*)\)') {
            $liste += [pscustomobject]@{ Id = $Matches[1]; Pool = $Matches[2] }
        }
    }
    return $liste
}
# Pools d'applications : APPPOOL "SentinelleXDR" (MgdVersion:,MgdMode:Integrated,state:Started)
function Get-Pools {
    $r = Invoke-Appcmd 'list apppool'
    $liste = @()
    foreach ($ligne in $r.Sortie) {
        if ($ligne -match '^\s*APPPOOL "([^"]+)" \((.*)\)\s*$') {
            $nomTrouve = $Matches[1]
            $attributs = $Matches[2]
            $etatTrouve = ''
            if ($attributs -match 'state:([A-Za-z]+)') { $etatTrouve = $Matches[1] }
            $liste += [pscustomobject]@{ Nom = $nomTrouve; Etat = $etatTrouve }
        }
    }
    return $liste
}
function Get-SidPool([string]$Nom) {
    try { return (New-Object System.Security.Principal.NTAccount('IIS APPPOOL', $Nom)).Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { return '' }
}
# Le dossier des données est-il réservé au pool (héritage coupé, pas d'IIS_IUSRS, d'IUSR ni d'Utilisateurs) ?
function Test-DroitsDonnees([string]$Dossier, [string]$SidPool) {
    if ($SidPool -eq '' -or -not (Test-Path -LiteralPath $Dossier)) { return $false }
    try { $acl = Get-Acl -LiteralPath $Dossier } catch { return $false }
    if (-not $acl.AreAccessRulesProtected) { return $false }
    $poolOk = $false
    foreach ($regle in $acl.Access) {
        $sid = ''
        try { $sid = $regle.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value } catch { $sid = '' }
        if (@('S-1-5-32-568', 'S-1-5-17', 'S-1-5-32-545', 'S-1-1-0', 'S-1-5-11') -contains $sid) { return $false }
        if ($sid -eq $SidPool -and ($regle.FileSystemRights.ToString() -match 'Modify|FullControl')) { $poolOk = $true }
    }
    return $poolOk
}

# Réserve un dossier au pool : héritage coupé, administrateurs et système en contrôle total, pool en modification,
# puis retrait des accès larges qu'un ancien réglage aurait pu y laisser (IIS_IUSRS, IUSR, Utilisateurs, Tout le monde).
function Set-DroitsDonnees([string]$Dossier, [string]$SidPool) {
    $droits = Invoke-Natif $script:Icacls @($Dossier, '/inheritance:r', '/grant:r', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-18:(OI)(CI)F', ('*' + $SidPool + ':(OI)(CI)M'), '/C', '/Q')
    if ($droits.Code -ne 0) { Stop-Installation ('icacls sur ' + $Dossier + ' : ' + $droits.Texte) }
    $retrait = Invoke-Natif $script:Icacls @($Dossier, '/remove:g', '*S-1-5-32-568', '*S-1-5-17', '*S-1-5-32-545', '*S-1-5-11', '*S-1-1-0', '/C', '/Q')
    if ($retrait.Code -ne 0) { Write-Attention ('Retrait des accès larges sur ' + $Dossier + ' : ' + $retrait.Texte) }
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

# Valeur de la DERNIÈRE ligne active « nom = valeur » (celle que PHP retient), sans guillemets ; $null si absente.
function Get-DirectiveIni([string]$Texte, [string]$Nom) {
    $m = [regex]::Matches($Texte, ('(?im)^[ \t]*' + [regex]::Escape($Nom) + '[ \t]*=[ \t]*([^\r\n]*)'))
    if ($m.Count -eq 0) { return $null }
    $v = $m[$m.Count - 1].Groups[1].Value.Trim()
    if ($v.StartsWith('"')) {
        $fin = $v.IndexOf('"', 1)
        if ($fin -gt 0) { return $v.Substring(1, $fin - 1) }
        return $v.Substring(1)
    }
    $commentaire = $v.IndexOf(';')
    if ($commentaire -ge 0) { $v = $v.Substring(0, $commentaire) }
    return $v.Trim()
}
# Remplace TOUTES les lignes actives « nom = ... » (ou ajoute la directive à la fin), sans toucher au reste du fichier.
function Set-DirectiveIni([string]$Texte, [string]$Nom, [string]$Valeur) {
    $re = New-Object System.Text.RegularExpressions.Regex(('(?im)^[ \t]*' + [regex]::Escape($Nom) + '[ \t]*=[^\r\n]*'))
    $ligne = $Nom + ' = ' + $Valeur
    if ($re.IsMatch($Texte)) { return $re.Replace($Texte, $ligne.Replace('$', '$$')) }
    $fin = "`r`n"
    if ($Texte -notmatch "`r`n") { $fin = "`n" }
    return $Texte.TrimEnd() + $fin + $ligne + $fin
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
# PHP 8.5 : OPcache est intégré ; une ligne « zend_extension=opcache » restée active provoque un avertissement.
$script:MotifOpcache = '(?im)^([ \t]*)(zend_extension[ \t]*=[ \t]*"?(?:[^"\r\n]*[\\/])?(?:php_)?opcache(?:\.dll)?"?[ \t]*(?:;[^\r\n]*)?)(?=\r?$)'
function Test-OpcacheActiveIni([string]$Texte) { return [regex]::IsMatch($Texte, $script:MotifOpcache) }
function Disable-OpcacheIni([string]$Texte) {
    return [regex]::Replace($Texte, $script:MotifOpcache, '$1;$2')
}
# open_basedir couvre-t-elle ce dossier ? (chaque entrée est un préfixe)
function Test-CouvertOpenBasedir([string]$Valeur, [string]$Chemin) {
    $c = $Chemin.Replace('/', '\')
    foreach ($entree in ($Valeur -split ';')) {
        $p = $entree.Trim().Trim('"').Replace('/', '\')
        if ($p -eq '' -or $p -eq '.') { continue }
        if ($c.StartsWith($p.TrimEnd('\'), [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
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
        Write-Host 'Élévation refusée : faites un clic droit sur INSTALLER.cmd puis « Exécuter en tant qu''administrateur ».' -ForegroundColor Red
        exit 1
    }
    exit 0
}

# Journal complet, à côté d'INSTALLER.cmd (sinon dans C:\ProgramData\SentinelleXDR)
$script:Journal = $null
$horodatage = Get-Date -Format 'yyyyMMdd-HHmmss'
foreach ($dossierJournal in @((Split-Path -Parent $PSScriptRoot), (Join-Path $env:ProgramData 'SentinelleXDR'))) {
    try {
        if (-not (Test-Path -LiteralPath $dossierJournal)) { New-Item -ItemType Directory -Path $dossierJournal -Force | Out-Null }
        $essaiJournal = Join-Path $dossierJournal ('journal-installation-sentinelle-' + $horodatage + '.txt')
        Start-Transcript -Path $essaiJournal -Force | Out-Null
        $script:Journal = $essaiJournal
        break
    } catch { $script:Journal = $null }
}

$codeSortie = 0
$script:PoolArrete = $false
$system32 = Join-Path $env:windir 'System32'
$script:Appcmd = Join-Path $system32 'inetsrv\appcmd.exe'
$script:Curl = Join-Path $system32 'curl.exe'
$robocopy = Join-Path $system32 'robocopy.exe'
$icacls = Join-Path $system32 'icacls.exe'
$script:Icacls = $icacls
$nomTache = 'Sentinelle XDR'
$adressesMaison = @('LocalSubnet', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', 'fe80::/10', 'fc00::/7')
$script:UrlLocale = ''
$script:Schema = 'http'
$script:Hote = 'localhost'
$script:Port = 80
$script:PhpCgi = 'php-cgi.exe'

try {
    Write-Host ''
    Write-Host '  SENTINELLE XDR - installation sur Windows (IIS + PHP)' -ForegroundColor White
    if ($Simulation) { Write-Host '  Mode simulation : analyse seulement, rien ne sera modifié.' -ForegroundColor DarkGray }
    if ($null -ne $script:Journal) { Write-Host ('  Journal : ' + $script:Journal) -ForegroundColor DarkGray }

    $sourceDir = Get-CheminComplet (Join-Path $PSScriptRoot '..')
    $script:SiteDir = Get-CheminComplet $DossierSite
    $script:DataDir = Get-CheminComplet $DossierDonnees
    $siteDir = $script:SiteDir
    $dataDir = $script:DataDir

    # =========================================================================
    # Le site IIS (commun à l'installation et à la désinstallation)
    # =========================================================================
    Write-Etape 'IIS et le site web'
    $serviceIis = Get-Service -Name 'W3SVC' -ErrorAction SilentlyContinue
    if ($null -eq $serviceIis -or -not (Test-Path -LiteralPath $script:Appcmd)) {
        Stop-Installation 'IIS n''est pas installé. Suivez d''abord le tutoriel « Serveur web IIS + PHP sur Windows 11 Pro » (Installer-IIS-PHP.cmd).'
    }
    foreach ($composant in @((Join-Path $system32 'inetsrv\iisfcgi.dll'), $robocopy, $icacls)) {
        if (-not (Test-Path -LiteralPath $composant)) { Stop-Installation ('Composant Windows introuvable : ' + $composant + ' (IIS avec CGI/FastCGI est-il installé ? sinon erreur 500.21)') }
    }
    if ($serviceIis.Status -ne 'Running') {
        if ($Simulation) { Add-Plan 'démarrer le service IIS (W3SVC)' }
        else { Start-Service -Name 'W3SVC'; Write-Ok 'Service IIS démarré' }
    }
    Write-Ok 'IIS est installé (avec FastCGI)'

    $r = Invoke-Appcmd ('list site ' + (Q $NomSite) + ' /text:bindings')
    $liaisons = $r.Texte.Trim()
    if ($r.Code -ne 0 -or $liaisons -eq '') {
        $nomsSites = (Invoke-Appcmd 'list site /text:name').Sortie -join ', '
        Stop-Installation ('Site IIS « ' + $NomSite + ' » introuvable. Sites existants : ' + $nomsSites + '. Précisez-le avec -NomSite.')
    }
    $schemaTrouve = ''
    foreach ($liaison in ($liaisons -split ',')) {
        if ($liaison.Trim() -match '^(https?)/(.*):(\d+):([^:]*)$') {
            if ($schemaTrouve -eq '' -or ($schemaTrouve -eq 'https' -and $Matches[1] -eq 'http')) {
                $schemaTrouve = $Matches[1]
                $script:Port = [int]$Matches[3]
                if ($Matches[4] -ne '') { $script:Hote = $Matches[4] }
                elseif ($Matches[2] -ne '' -and $Matches[2] -ne '*') { $script:Hote = $Matches[2] }
                else { $script:Hote = 'localhost' }
            }
        }
    }
    if ($schemaTrouve -eq '') { Stop-Installation ('Le site « ' + $NomSite + ' » n''a pas de liaison http ou https (' + $liaisons + ').') }
    $script:Schema = $schemaTrouve
    $suffixePort = ''
    if (($script:Schema -eq 'http' -and $script:Port -ne 80) -or ($script:Schema -eq 'https' -and $script:Port -ne 443)) { $suffixePort = ':' + $script:Port }
    if ($script:Hote -ne 'localhost') { Write-Attention ('Le site répond au nom ou à l''adresse « ' + $script:Hote + ' » : les adresses affichées à la fin peuvent devoir être adaptées.') }

    $r = Invoke-Appcmd ('list vdir ' + (Q ($NomSite + '/')) + ' /text:physicalPath')
    if ($r.Code -ne 0 -or $r.Texte.Trim() -eq '') { Stop-Installation ('Dossier racine du site « ' + $NomSite + ' » illisible : ' + $r.Texte) }
    $racineSite = Get-CheminComplet $r.Texte.Trim()
    foreach ($chemin in @($siteDir, $dataDir)) {
        if (Test-RacineDisque $chemin) { Stop-Installation ('Choisissez un sous-dossier, pas la racine d''un disque : ' + $chemin) }
    }
    if ($siteDir -ieq $racineSite) { Stop-Installation 'Installez Sentinelle dans un sous-dossier du site (exemple : C:\inetpub\wwwroot\xdr), pas à sa racine.' }
    if (Test-Dans $siteDir $racineSite) { $relatif = $siteDir.Substring($racineSite.TrimEnd('\').Length + 1) }
    else { $relatif = Split-Path -Leaf $siteDir }
    $cheminApp = '/' + (($relatif -split '\\') -join '/')
    $idApp = $NomSite + $cheminApp
    $cheminUrl = '/' + ((($relatif -split '\\') | ForEach-Object { [System.Uri]::EscapeDataString($_) }) -join '/') + '/'
    $script:UrlLocale = $script:Schema + '://' + $script:Hote + $suffixePort + $cheminUrl
    $argumentChemin = '/path:' + $cheminApp
    if ($cheminApp -match ' ') { $argumentChemin = '/path:' + (Q $cheminApp) }
    Write-Ok ('Site « ' + $NomSite + ' » (' + $racineSite + ') : Sentinelle sera à l''adresse ' + $script:UrlLocale)

    $applications = @(Get-Applications)
    $application = $null
    foreach ($a in $applications) { if ($a.Id -ieq $idApp) { $application = $a } }
    $pools = @(Get-Pools)
    $pool = $null
    foreach ($p in $pools) { if ($p.Nom -ieq $NomPool) { $pool = $p } }

    if ($Desinstaller) {
        # =====================================================================
        # Désinstallation : application, pool, tâche (jamais les données)
        # =====================================================================
        Write-Etape 'Désinstallation'
        Write-Info ('Application retirée  : ' + $siteDir + ' (' + $idApp + ')')
        Write-Info ('Données CONSERVÉES   : ' + $dataDir)
        if ((Test-Path -LiteralPath $siteDir) -and -not ((Test-Path -LiteralPath (Join-Path $siteDir 'api.php')) -and (Test-Path -LiteralPath (Join-Path $siteDir 'app\bootstrap.php')))) {
            Stop-Installation ($siteDir + ' ne ressemble pas à un dossier Sentinelle XDR : rien n''a été supprimé. Précisez -DossierSite.')
        }
        $confirme = $true
        if ($Simulation) { $confirme = $false; Write-Info 'Simulation : rien n''est supprimé.' }
        elseif (-not $Oui) { $confirme = ((Read-Host '    Tapez OUI pour confirmer') -ceq 'OUI') }
        if ($confirme) {
            $tache = $null
            try { $tache = Get-ScheduledTask -TaskName $nomTache -ErrorAction Stop } catch { $tache = $null }
            if ($null -ne $tache) { Unregister-ScheduledTask -TaskName $nomTache -Confirm:$false; Write-Ok 'Tâche planifiée supprimée' }
            if ($null -ne $application) {
                $r = Invoke-Appcmd ('delete app ' + (Q $idApp))
                if ($r.Code -eq 0) { Write-Ok ('Application ' + $idApp + ' retirée d''IIS') } else { Write-Attention ('Application non retirée : ' + $r.Texte) }
            }
            $autres = @(Get-Applications | Where-Object { $_.Pool -ieq $NomPool })
            if ($null -ne $pool -and $autres.Count -eq 0) {
                $r = Invoke-Appcmd ('delete apppool ' + (Q $NomPool))
                if ($r.Code -eq 0) { Write-Ok ('Pool « ' + $NomPool + ' » supprimé') } else { Write-Attention ('Pool non supprimé : ' + $r.Texte) }
            }
            if (Test-Path -LiteralPath $siteDir) {
                if (Test-Path -LiteralPath (Join-Path $siteDir 'data')) {
                    Write-Attention ('Le dossier ' + (Join-Path $siteDir 'data') + ' contient des données : il est conservé, le reste de l''application est retiré.')
                    Get-ChildItem -LiteralPath $siteDir -Force | Where-Object { $_.Name -ne 'data' } | Remove-Item -Recurse -Force
                } else {
                    Remove-Item -LiteralPath $siteDir -Recurse -Force
                }
                Write-Ok ('Application retirée de ' + $siteDir)
            }
            Write-Host ''
            Write-Host ('  Vos données sont toujours dans ' + $dataDir + ' : supprimez-les vous-même, seulement si vous n''en avez plus besoin.') -ForegroundColor White
        } elseif (-not $Simulation) {
            Write-Info 'Désinstallation annulée : rien n''a été modifié.'
        }
    } else {
        # =====================================================================
        # 1. Analyse (rien n'est modifié)
        # =====================================================================
        Write-Etape 'Application et version'
        $os = Get-CimInstance -ClassName Win32_OperatingSystem
        Write-Info ('Windows : ' + $os.Caption + ' (' + $os.Version + ')')
        foreach ($fichier in @('api.php', 'index.html', 'web.config', 'app\bootstrap.php', 'assets\app.js', 'tools\setup.php')) {
            if (-not (Test-Path -LiteralPath (Join-Path $sourceDir $fichier))) {
                Stop-Installation ('Fichier manquant : ' + (Join-Path $sourceDir $fichier) + '. Décompressez TOUT le fichier ZIP (clic droit > Extraire tout), puis relancez INSTALLER.cmd.')
            }
        }
        if (Test-Dans $dataDir $siteDir) { Write-Attention 'Le dossier des données est DANS le site web : web.config le cache, mais un dossier hors du site (C:\SentinelleDonnees) est plus sûr.' }
        if (Test-Dans $siteDir $dataDir) { Stop-Installation 'Le site web ne peut pas être dans le dossier des données.' }
        $versionSource = Get-VersionXdr $sourceDir
        $versionSite = Get-VersionXdr $siteDir
        if ($versionSite -eq '') { Write-Ok ('Sentinelle ' + $versionSource + ' : première installation') }
        elseif ($sourceDir -ieq $siteDir) { Write-Ok ('Sentinelle ' + $versionSite + ' : fichiers déjà en place dans le site') }
        else { Write-Ok ('Sentinelle ' + $versionSite + ' déjà installée : mise à jour vers ' + $versionSource) }
        if ($sourceDir -ine $siteDir) { Add-Plan ('copier Sentinelle ' + $versionSource + ' dans ' + $siteDir + ' (jamais config\config.php ni les données)') }

        Write-Etape 'PHP'
        $r = Invoke-Appcmd 'list config /section:system.webServer/fastCgi'
        $phpCgiIis = ''
        if ($r.Texte -match '(?i)fullPath="([^"]*php-cgi\.exe)"') { $phpCgiIis = [Environment]::ExpandEnvironmentVariables($Matches[1]) }
        if ($DossierPHP -ne '') { $phpDir = Get-CheminComplet $DossierPHP }
        elseif ($phpCgiIis -ne '') { $phpDir = Split-Path -Parent (Get-CheminComplet $phpCgiIis) }
        else { $phpDir = 'C:\PHP' }
        $php = Join-Path $phpDir 'php.exe'
        $script:PhpCgi = Join-Path $phpDir 'php-cgi.exe'
        if (-not (Test-Path -LiteralPath $php)) { Stop-Installation ('php.exe introuvable dans ' + $phpDir + '. Installez d''abord IIS + PHP (tutoriel « Serveur web IIS + PHP »), ou précisez -DossierPHP.') }
        if (-not (Test-Path -LiteralPath $script:PhpCgi)) { Stop-Installation ('php-cgi.exe introuvable dans ' + $phpDir + '.') }
        if ($phpCgiIis -eq '') { Write-Attention ('IIS n''a aucune application FastCGI vers php-cgi.exe (erreur 404.3 ou 500 à prévoir) : relancez Installer-IIS-PHP.cmd du tutoriel.') }
        elseif ((Get-CheminComplet $phpCgiIis) -ine $script:PhpCgi) { Write-Attention ('IIS utilise ' + $phpCgiIis + ' et non ' + $script:PhpCgi + ' : vérifiez -DossierPHP.') }
        $r = Invoke-Natif $php @('-n', '-r', 'echo PHP_VERSION;')
        $versionPhp = Get-DerniereLigne $r
        if ($versionPhp -notmatch '^(\d+)\.(\d+)') { Stop-Installation ('PHP ne démarre pas (' + (Get-Debut $r.Texte) + ') : cause habituelle, Visual C++ 2015-2022 x64 absent (vc_redist.x64.exe de Microsoft).') }
        $phpMajMin = [int]$Matches[1] * 100 + [int]$Matches[2]
        if ($phpMajMin -lt 801) { Stop-Installation ('PHP ' + $versionPhp + ' est trop ancien : Sentinelle demande PHP 8.1 ou plus (8.5 recommandé).') }
        $r = Invoke-Natif $script:PhpCgi @('-n', '-v')
        if ($r.Texte -notmatch 'PHP \d') { Stop-Installation ('php-cgi.exe ne démarre pas (' + (Get-Debut $r.Texte) + ') : IIS répondrait « FastCGI process exited unexpectedly ». Installez Visual C++ 2015-2022 x64, puis relancez.') }
        $r = Invoke-Natif $php @('--ini')
        $ini = ''
        foreach ($ligne in $r.Sortie) {
            if ($ligne -match 'Loaded Configuration File:\s*(.+)$') {
                # PHP 8.5 entoure le chemin de guillemets ("C:\PHP\php.ini") : on les retire (leçon de Boussole 1.1.1)
                $valeurIni = $Matches[1].Trim([char[]]@([char]34, [char]39, [char]32, [char]9))
                if ($valeurIni -ne '(none)' -and $valeurIni -ne '') { $ini = $valeurIni }
            }
        }
        if ($ini -eq '' -or -not (Test-Path -LiteralPath $ini)) { $ini = Join-Path $phpDir 'php.ini' }
        if (-not (Test-Path -LiteralPath $ini)) { Stop-Installation ('php.ini introuvable (' + $ini + ') : copiez php.ini-production en php.ini dans ' + $phpDir + '.') }
        Write-Ok ('PHP ' + $versionPhp + ' (' + $phpDir + '), configuration ' + $ini)
        $r = Invoke-Appcmd ('list config ' + (Q ($NomSite + '/')) + ' /section:system.webServer/handlers')
        if ($r.Texte -notmatch 'path="\*\.php"') { Write-Attention 'Aucun gestionnaire *.php dans IIS (erreur 404.3 à prévoir) : relancez Installer-IIS-PHP.cmd du tutoriel.' }
        else { Write-Ok 'Les fichiers .php sont confiés à PHP (FastCGI)' }

        # extensions et réglages de php.ini (fichier lu octet par octet : le reste reste identique)
        $r = Invoke-Natif $php @('-m')
        $modules = @($r.Sortie | ForEach-Object { $_.Trim().ToLower() } | Where-Object { $_ -ne '' -and $_ -notmatch '^\[' })
        $r = Invoke-Natif $php @('-r', 'echo ini_get(''extension_dir'');')
        $extDir = Get-DerniereLigne $r
        if ($extDir -eq '') { $extDir = 'ext' }
        if (-not [System.IO.Path]::IsPathRooted($extDir)) { $extDir = Join-Path $phpDir $extDir }
        $latin1 = [System.Text.Encoding]::GetEncoding(28591)
        $texteIniOrigine = $latin1.GetString([System.IO.File]::ReadAllBytes($ini))
        $texteIni = $texteIniOrigine
        $changementsIni = New-Object 'System.Collections.Generic.List[string]'
        $aActiver = New-Object 'System.Collections.Generic.List[string]'
        $extensions = @(
            @{ Nom = 'pdo_sqlite'; Role = 'base de données' },
            @{ Nom = 'curl'; Role = 'dialogue avec la Freebox, actualités, tests de débit' },
            @{ Nom = 'mbstring'; Role = 'textes accentués' }
        )
        foreach ($ext in $extensions) {
            $nomExt = [string]$ext.Nom
            if ($modules -contains $nomExt) { Write-Ok ($nomExt + ' : ' + $ext.Role); continue }
            $dll = Join-Path $extDir ('php_' + $nomExt + '.dll')
            if ($SansExtensions) { Stop-Installation ('Extension PHP obligatoire « ' + $nomExt + ' » absente (option -SansExtensions : php.ini n''est pas modifié).') }
            if (-not (Test-Path -LiteralPath $dll)) { Stop-Installation ('Extension PHP obligatoire « ' + $nomExt + ' » : fichier ' + $dll + ' introuvable.') }
            if (Test-ExtensionActiveIni $texteIni $nomExt) { Stop-Installation ('Extension « ' + $nomExt + ' » activée dans php.ini mais elle ne se charge pas : lisez le journal des erreurs de PHP.') }
            $texteIni = Enable-ExtensionIni $texteIni $nomExt
            $aActiver.Add($nomExt)
            $changementsIni.Add('extension=' + $nomExt)
        }
        foreach ($integre in @('simplexml', 'session', 'json')) {
            if ($modules -notcontains $integre) { Write-Attention ('Module PHP « ' + $integre + ' » absent : une partie de Sentinelle ne fonctionnera pas (actualités, connexion).') }
        }
        if (-not $SansExtensions) {
            if ($phpMajMin -ge 805 -and (Test-OpcacheActiveIni $texteIni)) {
                $texteIni = Disable-OpcacheIni $texteIni
                $changementsIni.Add('ligne zend_extension=opcache mise en commentaire (OPcache est intégré à PHP 8.5 : elle provoque un avertissement)')
            }
            $argcArgv = Get-DirectiveIni $texteIni 'register_argc_argv'
            if ($phpMajMin -ge 805 -and $null -ne $argcArgv -and $argcArgv -match '^(?i:on|1|true|yes)$') {
                $texteIni = Set-DirectiveIni $texteIni 'register_argc_argv' 'Off'
                $changementsIni.Add('register_argc_argv = Off (en PHP 8.5, sinon une dépréciation à chaque appel)')
            }
            $openBasedir = Get-DirectiveIni $texteIni 'open_basedir'
            if ($null -ne $openBasedir -and $openBasedir.Trim() -ne '') {
                $ajouts = @()
                foreach ($c in @($siteDir, $dataDir)) { if (-not (Test-CouvertOpenBasedir $openBasedir $c)) { $ajouts += $c } }
                if ($ajouts.Count -gt 0) {
                    $texteIni = Set-DirectiveIni $texteIni 'open_basedir' ('"' + $openBasedir.TrimEnd(';') + ';' + ($ajouts -join ';') + '"')
                    $changementsIni.Add('open_basedir complétée par ' + ($ajouts -join ' et '))
                }
            }
            $r = Invoke-Natif $php @('-r', 'echo ini_get(''post_max_size''), ''|'', ini_get(''memory_limit'');')
            $tailles = (Get-DerniereLigne $r).Split('|')
            if ($tailles.Count -eq 2) {
                $envoi = ConvertTo-Octets $tailles[0]
                $memoire = ConvertTo-Octets $tailles[1]
                if ($envoi -gt 0 -and $envoi -lt 8388608) { $texteIni = Set-DirectiveIni $texteIni 'post_max_size' '16M'; $changementsIni.Add('post_max_size = 16M (test de débit)') }
                if ($memoire -ge 0 -and $memoire -lt 134217728) { $texteIni = Set-DirectiveIni $texteIni 'memory_limit' '256M'; $changementsIni.Add('memory_limit = 256M') }
            }
        }
        $iniModifie = ($texteIni -cne $texteIniOrigine)
        if ($iniModifie) { Add-Plan ('php.ini : ' + ($changementsIni -join ' ; ') + ' (copie de sauvegarde faite avant)') }
        else { Write-Ok 'php.ini déjà prêt : aucune modification' }

        Write-Etape 'Pool et application IIS'
        if ($null -eq $pool) { Add-Plan ('créer le pool d''applications « ' + $NomPool + ' » : un compte Windows propre à Sentinelle') }
        else { Write-Ok ('Pool « ' + $NomPool + ' » présent (' + $pool.Etat + ')') }
        if ($null -eq $application) { Add-Plan ('faire de ' + $cheminApp + ' une application IIS à part, dans le pool « ' + $NomPool + ' »') }
        elseif ($application.Pool -ine $NomPool) { Add-Plan ('passer l''application ' + $cheminApp + ' du pool « ' + $application.Pool + ' » au pool « ' + $NomPool + ' »') }
        else { Write-Ok ('Application ' + $idApp + ' dans le pool « ' + $NomPool + ' »') }
        $identiteOk = $false
        if ($null -ne $application) {
            $r = Invoke-Appcmd ('list config ' + (Q $idApp) + ' -section:system.webServer/security/authentication/anonymousAuthentication')
            $identiteOk = ($r.Texte -match 'userName=""')
        }
        if ($identiteOk) { Write-Ok 'Visiteurs anonymes sous l''identité du pool' }
        else { Add-Plan 'faire tourner les visiteurs anonymes de Sentinelle sous l''identité de son pool (avec fastcgi.impersonate = 1, PHP écrirait sinon sous IUSR)' }

        Write-Etape 'Données'
        $ancienData = Join-Path $siteDir 'data'
        $migrer = $false
        if (Test-Path -LiteralPath $dataDir) {
            $contenu = @(Get-ChildItem -LiteralPath $dataDir -Force)
            $estXdr = (Test-Path -LiteralPath (Join-Path $dataDir 'xdr.sqlite')) -or (Test-Path -LiteralPath (Join-Path $dataDir 'admin.json')) -or (Test-Path -LiteralPath (Join-Path $dataDir 'settings.json'))
            if ($contenu.Count -gt 0 -and -not $estXdr) { Stop-Installation ('Le dossier ' + $dataDir + ' existe déjà et contient d''autres fichiers : choisissez un dossier vide ou nouveau avec -DossierDonnees.') }
            if ($estXdr) { Write-Ok ('Données existantes dans ' + $dataDir + ' (conservées)') }
        } else {
            Add-Plan ('créer ' + $dataDir + ' (données hors du site web)')
        }
        if (($ancienData -ine $dataDir) -and (Test-Path -LiteralPath (Join-Path $ancienData 'xdr.sqlite'))) {
            if (Test-Path -LiteralPath (Join-Path $dataDir 'xdr.sqlite')) {
                Write-Attention ('Deux bases existent : ' + $ancienData + ' et ' + $dataDir + '. La seconde est utilisée ; l''ancienne reste en place (invisible depuis un navigateur). Comparez-les avant d''en supprimer une.')
            } else {
                $migrer = $true
                Add-Plan ('déplacer les données de l''installation précédente (' + $ancienData + ') vers ' + $dataDir)
            }
        }
        $sidPoolActuel = ''
        if ($null -ne $pool) { $sidPoolActuel = Get-SidPool $NomPool }
        if (Test-DroitsDonnees $dataDir $sidPoolActuel) { Write-Ok ($dataDir + ' : réservé au pool, au système et aux administrateurs') }
        else { Add-Plan ('réserver ' + $dataDir + ' au seul pool « ' + $NomPool + ' », au système et aux administrateurs (droits NTFS)') }
        Add-Plan ('(re)programmer l''analyse chaque minute (tâche planifiée « ' + $nomTache + ' »)')

        $profilsPublics = @()
        $reglesLarges = @()
        $regles80 = @()
        if (-not $SansPareFeu) {
            Write-Etape 'Réseau et pare-feu'
            try { $profilsPublics = @(Get-NetConnectionProfile -ErrorAction Stop | Where-Object { [string]$_.NetworkCategory -eq 'Public' }) } catch { $profilsPublics = @() }
            foreach ($profil in $profilsPublics) { Add-Plan ('passer le réseau « ' + $profil.Name + ' » en profil Privé (en Public, les autres appareils ne voient pas le serveur)') }
            if ($profilsPublics.Count -eq 0) { Write-Ok 'Réseau en profil Privé' }
            try {
                $filtres = @(Get-NetFirewallPortFilter -ErrorAction Stop | Where-Object { [string]$_.Protocol -eq 'TCP' -and ((@($_.LocalPort) -contains '80') -or (@($_.LocalPort) -contains '443')) })
                $regles = @()
                if ($filtres.Count -gt 0) {
                    $regles = @($filtres | Get-NetFirewallRule -ErrorAction Stop | Where-Object { [string]$_.Direction -eq 'Inbound' -and [string]$_.Action -eq 'Allow' -and [string]$_.Enabled -eq 'True' })
                }
                foreach ($regle in $regles) {
                    $portsRegle = @(($regle | Get-NetFirewallPortFilter).LocalPort)
                    if ($portsRegle -contains '80') { $regles80 += $regle }
                    $adresses = @()
                    try { $adresses = @(($regle | Get-NetFirewallAddressFilter -ErrorAction Stop).RemoteAddress) } catch { $adresses = @('Any') }
                    if ($adresses -contains 'Any') {
                        $reglesLarges += $regle
                        Add-Plan ('limiter la règle de pare-feu « ' + $regle.DisplayName + ' » au réseau de la maison et aux VPN (elle accepte tout Internet, IPv6 compris)')
                    } else {
                        Write-Ok ('Règle « ' + $regle.DisplayName + ' » : limitée au réseau local')
                    }
                }
                if ($regles80.Count -eq 0) { Add-Plan 'ouvrir le port 80 au seul réseau de la maison (règle « Sentinelle XDR - HTTP réseau local »)' }
            } catch {
                Write-Attention ('Lecture du pare-feu impossible : ' + $_.Exception.Message)
            }
        }
        # veille : Sentinelle ne surveille rien pendant que le PC dort (choix laissé à l'utilisateur)
        try {
            $planActif = Get-CimInstance -Namespace 'root\cimv2\power' -ClassName Win32_PowerPlan -Filter 'IsActive = TRUE' -ErrorAction Stop | Select-Object -First 1
            $guidPlan = ([string]$planActif.InstanceID) -replace '^.*(\{[^}]+\}).*$', '$1'
            $suffixeVeille = $guidPlan + '\AC\{29f6c1db-86da-48c5-9fdb-f2b67b1f44da}'
            $indexVeille = Get-CimInstance -Namespace 'root\cimv2\power' -ClassName Win32_PowerSettingDataIndex -ErrorAction Stop |
                Where-Object { ([string]$_.InstanceID).EndsWith($suffixeVeille, [System.StringComparison]::OrdinalIgnoreCase) } | Select-Object -First 1
            if ($null -ne $indexVeille -and [int64]$indexVeille.SettingIndexValue -gt 0) {
                Write-Attention ('Le PC se met en veille sur secteur après ' + [int]([int64]$indexVeille.SettingIndexValue / 60) + ' min : la surveillance s''arrête pendant la veille. Pour l''empêcher : powercfg /change standby-timeout-ac 0')
            } elseif ($null -ne $indexVeille) {
                Write-Ok 'Pas de mise en veille sur secteur'
            }
        } catch { }

        # =====================================================================
        # 2. Plan et accord
        # =====================================================================
        Write-Etape 'Changements prévus'
        foreach ($ligne in $script:Plan) { Write-Host ('    [à faire] ' + $ligne) -ForegroundColor Magenta }
        if ($Simulation) {
            Write-Host ''
            Write-Host '  Simulation terminée : relancez sans -Simulation pour installer. Rien n''a été modifié.' -ForegroundColor White
        } elseif (-not (Confirm-Choix 'Appliquer ces changements ?')) {
            Write-Host ''
            Write-Host '  Installation annulée : rien n''a été modifié.' -ForegroundColor White
        } else {
            # =================================================================
            # 3. Application
            # =================================================================
            Write-Etape 'Installation'
            if ($iniModifie) {
                $copieIni = $ini + '.avant-sentinelle-' + $horodatage
                Copy-Item -LiteralPath $ini -Destination $copieIni -Force
                [System.IO.File]::WriteAllBytes($ini, $latin1.GetBytes($texteIni))
                Write-Ok ('php.ini modifié (copie de l''ancien : ' + $copieIni + ')')
                $r = Invoke-Natif $php @('-m')
                $modulesApres = @($r.Sortie | ForEach-Object { $_.Trim().ToLower() })
                foreach ($nomExt in $aActiver) {
                    if ($modulesApres -notcontains $nomExt) { Stop-Installation ('L''extension ' + $nomExt + ' ne se charge pas après activation : ' + (Get-Debut $r.Texte)) }
                    Write-Ok ($nomExt + ' activée')
                }
            }

            if ($null -eq $pool) {
                $r = Invoke-Appcmd ('add apppool /name:' + $NomPool + ' /managedRuntimeVersion:"" /managedPipelineMode:Integrated')
                if ($r.Code -ne 0) { Stop-Installation ('Création du pool « ' + $NomPool + ' » impossible : ' + $r.Texte) }
                Write-Ok ('Pool « ' + $NomPool + ' » créé')
            }
            $sidPool = Get-SidPool $NomPool
            if ($sidPool -eq '') { Stop-Installation ('Compte « IIS AppPool\' + $NomPool + ' » introuvable alors que le pool existe : vérifiez « appcmd list apppool » puis relancez.') }

            if (-not (Test-Path -LiteralPath $siteDir)) { New-Item -ItemType Directory -Path $siteDir -Force | Out-Null }
            if ($null -eq $application) {
                $r = Invoke-Appcmd ('add app /site.name:' + (Q $NomSite) + ' ' + $argumentChemin + ' /physicalPath:' + (Q $siteDir))
                if ($r.Code -ne 0) { Stop-Installation ('Création de l''application ' + $idApp + ' impossible : ' + $r.Texte) }
                # identifiant EXACT : un dossier qui n'est pas une application renverrait la racine du site
                $application = $null
                foreach ($a in @(Get-Applications)) { if ($a.Id -ieq $idApp) { $application = $a } }
                if ($null -eq $application) { Stop-Installation ('L''application ' + $idApp + ' n''apparaît pas dans « appcmd list app » après sa création : ' + $r.Texte) }
                Write-Ok ('Application ' + $idApp + ' créée')
            }
            if ($application.Pool -ine $NomPool) {
                $r = Invoke-Appcmd ('set app ' + (Q $idApp) + ' /applicationPool:' + $NomPool)
                if ($r.Code -ne 0) { Stop-Installation ('Affectation du pool impossible : ' + $r.Texte) }
                Write-Ok ('Application ' + $idApp + ' placée dans le pool « ' + $NomPool + ' »')
            }
            $r = Invoke-Appcmd ('set config ' + (Q $idApp) + ' -section:system.webServer/security/authentication/anonymousAuthentication /userName:"" /commit:apphost')
            if ($r.Code -ne 0) { Write-Attention ('Identité des visiteurs anonymes non réglée : ' + $r.Texte) }
            else { Write-Ok 'Visiteurs anonymes sous l''identité du pool' }

            # pool arrêté le temps de la copie et du déplacement des données (les autres applications continuent)
            $r = Invoke-Appcmd ('stop apppool /apppool.name:' + $NomPool)
            $script:PoolArrete = $true

            if ($sourceDir -ine $siteDir) {
                $copie = Invoke-Natif $robocopy @($sourceDir, $siteDir, '/E', '/R:2', '/W:2', '/NP', '/NFL', '/NDL', '/NJH', '/NJS',
                    '/XD', 'data', 'docs', '.git', '.github',
                    '/XF', 'config.php', 'installeur.php', '*.md', 'LISEZ-MOI.txt', 'INSTALLER.cmd', 'Installer-XDR.ps1', 'journal-installation-*.txt', '*.avant-sentinelle-*', '.gitattributes', '.gitignore', 'Thumbs.db', 'desktop.ini')
                if ($copie.Code -ge 8) { Stop-Installation ('La copie a échoué (robocopy, code ' + $copie.Code + ') : ' + $copie.Texte) }
                if (-not (Test-Path -LiteralPath (Join-Path $siteDir 'api.php'))) { Stop-Installation ('Copie incomplète : api.php absent de ' + $siteDir) }
                foreach ($vieux in @('tools\Installer-XDR.cmd', 'tools\Installer-XDR.ps1', 'INSTALLER.cmd', 'README.md', 'LISEZ-MOI.txt')) {
                    $cheminVieux = Join-Path $siteDir $vieux
                    if (Test-Path -LiteralPath $cheminVieux) { Remove-Item -LiteralPath $cheminVieux -Force -ErrorAction SilentlyContinue }
                }
                Write-Ok ('Sentinelle ' + $versionSource + ' copiée dans ' + $siteDir)
            }
            $droits = Invoke-Natif $icacls @($siteDir, '/grant', ('*' + $sidPool + ':(OI)(CI)RX'), '/C', '/Q')
            if ($droits.Code -ne 0) { Write-Attention ('Droit de lecture du pool sur ' + $siteDir + ' : ' + $droits.Texte) }

            if (-not (Test-Path -LiteralPath $dataDir)) { New-Item -ItemType Directory -Path $dataDir -Force | Out-Null }
            Set-DroitsDonnees $dataDir $sidPool
            if ($migrer) {
                $deplacement = Invoke-Natif $robocopy @($ancienData, $dataDir, '/E', '/MOVE', '/R:2', '/W:2', '/NP', '/NFL', '/NDL', '/NJH', '/NJS')
                if ($deplacement.Code -ge 8) { Stop-Installation ('Déplacement des données impossible (robocopy, code ' + $deplacement.Code + ') : ' + $deplacement.Texte) }
                if ((Test-Path -LiteralPath $ancienData) -and (@(Get-ChildItem -LiteralPath $ancienData -Recurse -Force -File).Count -eq 0)) {
                    Remove-Item -LiteralPath $ancienData -Recurse -Force
                }
                Write-Ok ('Données de l''installation précédente déplacées vers ' + $dataDir)
            }

            # emplacement des données pour l'application (config.php, s'il existe, reste prioritaire)
            $fichierInstalleur = Join-Path $siteDir 'config\installeur.php'
            $fin = "`r`n"
            $contenuInstalleur = '<?php' + $fin +
                '// Écrit par l''installeur de Sentinelle XDR à chaque installation : ne pas modifier.' + $fin +
                '// Vos propres réglages vont dans config.php (prioritaire) : voir config.sample.php.' + $fin +
                'return [' + $fin +
                '    ''data_dir'' => ' + (ConvertTo-ChainePhp (ConvertTo-CheminPhp $dataDir)) + ',' + $fin +
                '];' + $fin
            [System.IO.File]::WriteAllText($fichierInstalleur, $contenuInstalleur, (New-Object System.Text.UTF8Encoding($false)))
            $verif = Invoke-Natif $php @('-l', $fichierInstalleur)
            if ($verif.Code -ne 0) { Stop-Installation ('config\installeur.php contient une erreur : ' + $verif.Texte) }
            $fichierConfig = Join-Path $siteDir 'config\config.php'
            if (Test-Path -LiteralPath $fichierConfig) {
                $octets = [System.IO.File]::ReadAllBytes($fichierConfig)
                if ($octets.Length -ge 3 -and $octets[0] -eq 0xEF -and $octets[1] -eq 0xBB -and $octets[2] -eq 0xBF) {
                    Copy-Item -LiteralPath $fichierConfig -Destination ($fichierConfig + '.avant-sentinelle-' + $horodatage) -Force
                    if ($octets.Length -eq 3) { [System.IO.File]::WriteAllBytes($fichierConfig, [byte[]]@()) }
                    else { [System.IO.File]::WriteAllBytes($fichierConfig, [byte[]]($octets[3..($octets.Length - 1)])) }
                    Write-Ok 'config\config.php commençait par un BOM UTF-8 : retiré (il casse les réponses de PHP)'
                }
                $verif = Invoke-Natif $php @('-l', $fichierConfig)
                if ($verif.Code -ne 0) { Stop-Installation ('config\config.php contient une erreur : ' + $verif.Texte) }
            }
            $r = Invoke-Natif $php @((Join-Path $siteDir 'tools\setup.php'), '--data-dir') -Utf8
            $dataEffectif = Get-DerniereLigne $r
            if ($r.Code -ne 0 -or $dataEffectif -eq '') { Stop-Installation ('tools\setup.php --data-dir : ' + $r.Texte) }
            $dataEffectif = Get-CheminComplet $dataEffectif
            if ($dataEffectif -ine $dataDir) {
                Write-Attention ('config\config.php impose un autre dossier de données : ' + $dataEffectif + '. C''est lui qui est utilisé.')
                if (-not (Test-Path -LiteralPath $dataEffectif)) { New-Item -ItemType Directory -Path $dataEffectif -Force | Out-Null }
                Set-DroitsDonnees $dataEffectif $sidPool
                $dataDir = $dataEffectif
                $script:DataDir = $dataEffectif
            }
            $r = Invoke-Natif $php @((Join-Path $siteDir 'tools\setup.php'), '--cron-key') -Utf8
            $cleTache = Get-DerniereLigne $r
            if ($r.Code -ne 0 -or $cleTache -notmatch '^[0-9a-f]{32}$') { Stop-Installation ('Sentinelle n''a pas pu créer sa base dans ' + $dataDir + ' : ' + $r.Texte) }
            $heritage = Invoke-Natif $icacls @((Join-Path $dataDir '*'), '/reset', '/T', '/C', '/Q')
            if ($heritage.Code -ne 0) { Write-Attention ('icacls /reset : ' + $heritage.Texte) }
            Write-Ok ($dataDir + ' : base prête, accessible au seul pool « ' + $NomPool + ' », au système et aux administrateurs')

            $r = Invoke-Appcmd ('start apppool /apppool.name:' + $NomPool)
            $etatFinal = ''
            foreach ($p in @(Get-Pools)) { if ($p.Nom -ieq $NomPool) { $etatFinal = $p.Etat } }
            if ($etatFinal -ieq 'Started') { $script:PoolArrete = $false; Write-Ok ('Pool « ' + $NomPool + ' » démarré') }
            else { Stop-Installation ('Le pool « ' + $NomPool + ' » ne démarre pas (' + $r.Texte + ') : Observateur d''événements > Journaux Windows > Système, source WAS.') }

            # =================================================================
            # 4. Vérification en conditions réelles
            # =================================================================
            Write-Etape 'Vérification'
            $sante = Invoke-Local 'api.php?action=health'
            $etat = $null
            try { $etat = $sante.Texte | ConvertFrom-Json } catch { $etat = $null }
            if ($sante.Code -ne 200 -or $null -eq $etat -or $null -eq $etat.data) {
                Stop-Installation ('Sentinelle ne répond pas correctement (' + $script:UrlLocale + 'api.php) : ' + (Get-Diagnostic $sante))
            }
            $d = $etat.data
            Write-Ok ('Sentinelle ' + $d.version + ' répond (PHP ' + $d.php + ')')
            if ([string]$d.pool -ne '' -and [string]$d.pool -ine $NomPool) { Write-Attention ('L''API tourne dans le pool « ' + $d.pool + ' » et non « ' + $NomPool + ' » : l''application ' + $cheminApp + ' n''est pas à part.') }
            elseif ([string]$d.pool -ne '') { Write-Ok ('Tourne dans son propre pool « ' + $d.pool + ' »') }
            if (@($d.missing).Count -gt 0) { Stop-Installation ('Extensions PHP absentes côté IIS : ' + (@($d.missing) -join ', ') + ' (IIS lit-il un autre php.ini que ' + $ini + ' ?)') }
            if (-not $d.writable) {
                $cause = 'le pool ne peut pas écrire dans ' + $d.data_dir + '.'
                if ([string]$d.open_basedir -ne '') { $cause += ' open_basedir vaut « ' + $d.open_basedir + ' » : ce dossier doit en faire partie.' }
                $cause += ' Avec fastcgi.impersonate = 1, les visiteurs anonymes de ' + $cheminApp + ' doivent tourner sous l''identité du pool (Gestionnaire IIS > ' + $cheminApp + ' > Authentification > Authentification anonyme > Identité du pool d''applications).'
                Stop-Installation ('Sentinelle ne peut pas écrire ses données : ' + $cause)
            }
            Write-Ok ('Écrit ses données dans ' + $d.data_dir)
            $exposes = 0
            foreach ($interne in @('config/installeur.php', 'config/config.sample.php', 'app/bootstrap.php', 'tools/setup.php', 'certs/freebox-ca.pem')) {
                $essai = Invoke-Local $interne
                if ($essai.Code -eq 200 -and $essai.Texte.Trim() -ne '') {
                    $exposes++
                    Write-Attention ('DANGER : ' + $script:UrlLocale + $interne + ' est lisible depuis un navigateur. Vérifiez que web.config a bien été copié et que le filtrage des demandes d''IIS est installé.')
                }
            }
            if ($exposes -eq 0) { Write-Ok 'Fichiers internes invisibles depuis un navigateur (code, réglages, certificats)' }
            $accueil = Invoke-Local ''
            if ($accueil.Code -eq 200 -and $accueil.Texte -match 'Sentinelle') { Write-Ok 'Page d''accueil servie' }
            else { Write-Attention ('La page d''accueil ne s''affiche pas : ' + (Get-Diagnostic $accueil)) }
            $analyse = Invoke-Local ('api.php?action=cron&key=' + $cleTache)
            $etatAnalyse = $null
            try { $etatAnalyse = $analyse.Texte | ConvertFrom-Json } catch { $etatAnalyse = $null }
            if ($analyse.Code -eq 200 -and $null -ne $etatAnalyse -and $etatAnalyse.ok) { Write-Ok 'Première analyse du réseau réussie' }
            else { Write-Attention ('Analyse du réseau en échec : ' + (Get-Diagnostic $analyse)) }

            # =================================================================
            # 5. Tâche planifiée
            # =================================================================
            Write-Etape 'Surveillance continue (tâche planifiée)'
            $argumentsCurl = '-s -S -f -m 50 -o NUL'
            if ($script:Schema -eq 'https') { $argumentsCurl += ' -k' }
            if ($script:Hote -ne 'localhost') { $argumentsCurl += ' --resolve ' + $script:Hote + ':' + $script:Port + ':127.0.0.1' }
            $actionTache = New-ScheduledTaskAction -Execute $script:Curl -Argument ($argumentsCurl + ' "' + $script:UrlLocale + 'api.php?action=cron&key=' + $cleTache + '"')
            $declencheur = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
            $reglagesTache = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 2) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
            $description = 'Sentinelle XDR : analyse du réseau de la maison chaque minute (appareils, alertes, horaires).'
            try {
                $compte = (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-19')).Translate([System.Security.Principal.NTAccount]).Value
                $principalTache = New-ScheduledTaskPrincipal -UserId $compte -LogonType ServiceAccount
                Register-ScheduledTask -TaskName $nomTache -Action $actionTache -Trigger $declencheur -Principal $principalTache -Settings $reglagesTache -Description $description -Force | Out-Null
                Write-Ok ('Tâche « ' + $nomTache + ' » programmée chaque minute (compte Service local)')
            } catch {
                $compte = (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')).Translate([System.Security.Principal.NTAccount]).Value
                $principalTache = New-ScheduledTaskPrincipal -UserId $compte -LogonType ServiceAccount -RunLevel Highest
                Register-ScheduledTask -TaskName $nomTache -Action $actionTache -Trigger $declencheur -Principal $principalTache -Settings $reglagesTache -Description $description -Force | Out-Null
                Write-Ok ('Tâche « ' + $nomTache + ' » programmée chaque minute (compte Système)')
            }
            try {
                Start-ScheduledTask -TaskName $nomTache
                Start-Sleep -Seconds 8
                $infoTache = Get-ScheduledTaskInfo -TaskName $nomTache
                $resultat = [int64]$infoTache.LastTaskResult
                if ($resultat -eq 0) { Write-Ok 'La tâche fonctionne (dernier résultat 0x0)' }
                elseif ($resultat -eq 267009 -or $resultat -eq 267011) { Write-Info 'La tâche est en cours ou n''a pas encore tourné : regardez le Planificateur de tâches dans quelques minutes (résultat attendu 0x0).' }
                else {
                    $explication = 'code ' + $resultat
                    if ($resultat -eq 22) { $explication = 'erreur HTTP (curl 22)' } elseif ($resultat -eq 7) { $explication = 'connexion impossible (curl 7)' } elseif ($resultat -eq 28) { $explication = 'délai dépassé (curl 28)' }
                    Write-Attention ('Premier lancement de la tâche en échec : ' + $explication + '. Planificateur de tâches > « ' + $nomTache + ' » > Historique.')
                }
            } catch { Write-Attention ('Lancement d''essai de la tâche impossible : ' + $_.Exception.Message) }

            # =================================================================
            # 6. Réseau et pare-feu
            # =================================================================
            if (-not $SansPareFeu -and ($profilsPublics.Count -gt 0 -or $reglesLarges.Count -gt 0 -or $regles80.Count -eq 0)) {
                Write-Etape 'Réseau et pare-feu'
                foreach ($profil in $profilsPublics) {
                    try { Set-NetConnectionProfile -InterfaceIndex $profil.InterfaceIndex -NetworkCategory Private -ErrorAction Stop; Write-Ok ('Réseau « ' + $profil.Name + ' » passé en profil Privé') }
                    catch { Write-Attention ('Profil du réseau « ' + $profil.Name + ' » inchangé : ' + $_.Exception.Message) }
                }
                foreach ($regle in $reglesLarges) {
                    try { Set-NetFirewallRule -Name $regle.Name -RemoteAddress $adressesMaison -ErrorAction Stop; Write-Ok ('Règle « ' + $regle.DisplayName + ' » limitée au réseau de la maison et aux VPN') }
                    catch { Write-Attention ('Règle « ' + $regle.DisplayName + ' » non modifiée (imposée par une stratégie ?) : ' + $_.Exception.Message) }
                }
                if ($regles80.Count -eq 0) {
                    try {
                        New-NetFirewallRule -DisplayName 'Sentinelle XDR - HTTP réseau local' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80 -Profile 'Domain, Private' -RemoteAddress $adressesMaison -ErrorAction Stop | Out-Null
                        Write-Ok 'Port 80 ouvert au seul réseau de la maison'
                    } catch { Write-Attention ('Règle de pare-feu non créée : ' + $_.Exception.Message) }
                }
            }

            # =================================================================
            # 7. AdGuard Home (facultatif) : voir les applications utilisées, filtrer pour les enfants
            # =================================================================
            if (-not $SansAdGuard) {
                Write-Etape 'AdGuard Home (facultatif, pour l''onglet Enfants)'
                $agDir = Join-Path $env:ProgramFiles 'AdGuardHome'
                $agExe = Join-Path $agDir 'AdGuardHome.exe'
                if (Test-Path -LiteralPath $agExe) {
                    Write-Ok ('AdGuard Home est déjà installé (' + $agDir + ')')
                } elseif ($AdGuard -or (Confirm-Choix 'Installer AdGuard Home (gratuit, officiel, service Windows) sur ce PC ?')) {
                    try {
                        $zip = Join-Path $env:TEMP 'AdGuardHome_windows_amd64.zip'
                        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
                        Invoke-WebRequest -UseBasicParsing -Uri 'https://github.com/AdguardTeam/AdGuardHome/releases/latest/download/AdGuardHome_windows_amd64.zip' -OutFile $zip
                        Expand-Archive -Path $zip -DestinationPath $env:ProgramFiles -Force
                        Remove-Item -LiteralPath $zip -Force -ErrorAction SilentlyContinue
                        $r = Invoke-Natif $agExe @('-s', 'install')
                        if ($r.Code -ne 0) { Write-Attention ('AdGuard Home : l''installation du service a renvoyé le code ' + $r.Code + ' : ' + (Get-Debut $r.Texte)) }
                        else { Write-Ok 'AdGuard Home installé comme service Windows' }
                        foreach ($proto in @('UDP', 'TCP')) {
                            $nomRegle = 'AdGuard Home DNS (' + $proto + ', réseau local)'
                            if (-not (Get-NetFirewallRule -DisplayName $nomRegle -ErrorAction SilentlyContinue)) {
                                New-NetFirewallRule -DisplayName $nomRegle -Direction Inbound -Protocol $proto -LocalPort 53 -RemoteAddress $adressesMaison -Profile 'Domain, Private' -Action Allow | Out-Null
                            }
                        }
                        Write-Ok 'Pare-feu : DNS (port 53) ouvert au seul réseau de la maison'
                        Write-Info 'Ouvrez http://127.0.0.1:3000/ : interface d''administration sur le PORT 3000 (IIS occupe le port 80), DNS sur le port 53, puis créez un identifiant.'
                        Write-Info 'Ensuite : Sentinelle > Paramètres > Navigation (DNS) : http://127.0.0.1:3000 + cet identifiant, puis l''onglet Enfants.'
                    } catch { Write-Attention ('AdGuard Home non installé : ' + $_.Exception.Message + ' (vous pourrez le faire plus tard, voir le guide).') }
                } else {
                    Write-Info 'Étape passée : vous pourrez l''installer plus tard (relancez avec -AdGuard).'
                }
            }

            # =================================================================
            # 8. Résumé
            # =================================================================
            Write-Etape 'Terminé'
            Write-Host ''
            Write-Host ('  Sur ce PC (création du mot de passe, la première fois) : ' + $script:UrlLocale) -ForegroundColor White
            $ipv4 = @()
            try { $ipv4 = @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop | Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } | Sort-Object -Property InterfaceIndex | Select-Object -ExpandProperty IPAddress) } catch { $ipv4 = @() }
            foreach ($ip in $ipv4) {
                $libelle = 'Depuis la maison (téléphone, tablette, PC)'
                if ($ip -match '^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.') { $libelle = 'Par le VPN Tailscale                     ' }
                Write-Host ('  ' + $libelle + ' : ' + $script:Schema + '://' + $ip + $suffixePort + $cheminUrl) -ForegroundColor White
            }
            Write-Host ''
            Write-Host '  Prochaines étapes :'
            Write-Host ('   1. Sur CE PC, ouvrez ' + $script:UrlLocale + ' et choisissez votre mot de passe.')
            Write-Host '   2. Paramètres > Freebox : associez Sentinelle (validez avec la flèche droite sur l''écran de la Freebox).'
            Write-Host '   3. Freebox OS : bail DHCP statique pour ce PC, pare-feu IPv6 activé, aucune redirection de port vers ce PC.'
            Write-Host ('   4. Notez où sont vos données : ' + $dataDir + ' (à inclure dans vos sauvegardes).')
        }
    }
} catch {
    $codeSortie = 1
    Write-Host ''
    if ($script:ArretVolontaire) {
        Write-Host ('  ARRÊT : ' + $_.Exception.Message) -ForegroundColor Red
    } else {
        Write-Host ('  ERREUR INATTENDUE : ' + $_.Exception.Message) -ForegroundColor Red
        Write-Host ('  ' + $_.InvocationInfo.PositionMessage) -ForegroundColor DarkGray
    }
    Write-Host ''
    Write-Host '  Vos données n''ont pas été touchées. Corrigez le problème indiqué puis relancez INSTALLER.cmd : il reprend là où il faut.' -ForegroundColor Yellow
} finally {
    if ($script:PoolArrete) {
        $redemarrage = Invoke-Appcmd ('start apppool /apppool.name:' + $NomPool)
        if ($redemarrage.Code -eq 0) { Write-Host ('    Pool « ' + $NomPool + ' » redémarré.') }
        else { Write-Host ('    Redémarrez le pool « ' + $NomPool + ' » dans le Gestionnaire IIS : ' + $redemarrage.Texte) -ForegroundColor Yellow }
    }
    if ($script:Avertissements.Count -gt 0) {
        Write-Host ''
        Write-Host ('  ' + $script:Avertissements.Count + ' point(s) à regarder :') -ForegroundColor Yellow
        foreach ($avertissement in $script:Avertissements) { Write-Host ('   - ' + $avertissement) -ForegroundColor Yellow }
    }
    if ($null -ne $script:Journal) {
        Write-Host ''
        Write-Host ('  Journal complet : ' + $script:Journal) -ForegroundColor DarkGray
        try { Stop-Transcript | Out-Null } catch { }
    }
}
exit $codeSortie
