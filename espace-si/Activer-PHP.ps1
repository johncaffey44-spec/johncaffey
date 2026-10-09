<#
  D8 · Espace SI — activer PHP (FastCGI) pour le dossier de l'application sous IIS
  ---------------------------------------------------------------------------------
  À lancer UNE FOIS sur le serveur IIS, en administrateur : double-cliquez sur
  Activer-PHP.cmd (dans C:\inetpub\wwwroot\espace-si).

  Ce que fait le script, dans l'ordre :
    1. vérifie qu'IIS et son module FastCGI sont présents (installe la
       fonctionnalité CGI si elle manque) ;
    2. trouve le site IIS qui contient le dossier ;
    3. donne aux comptes IIS le droit d'écrire dans le dossier (sous-dossier data) ;
    4. teste api.php : si PHP répond déjà, il s'arrête là, sans rien modifier ;
    5. sinon, reprend le PHP déjà utilisé par vos autres sites (Planning,
       Ticketing…) ; à défaut, le PHP enregistré dans IIS ; à défaut, cherche
       php-cgi.exe sur le disque ;
    6. ajoute le mappage « *.php → FastCGI » pour ce seul dossier (dans la
       configuration d'IIS, pas dans les autres sites) ;
    7. reteste api.php et affiche le résultat.

  Annuler : Activer-PHP.cmd -Annuler   (retire uniquement le mappage ajouté)
#>
param(
    # dossier de l'application (par défaut : celui du script)
    [string]$Dossier = $(if (Test-Path (Join-Path $PSScriptRoot 'api.php')) { $PSScriptRoot } else { 'C:\inetpub\wwwroot\espace-si' }),
    # imposer un php-cgi.exe précis, ex. -PhpCgi 'C:\PHP\php-cgi.exe'
    [string]$PhpCgi = '',
    # retirer le mappage PHP ajouté par ce script
    [switch]$Annuler
)
$ErrorActionPreference = 'Stop'
$NomMappage = 'PHP_EspaceSI'
function Ok($m)   { Write-Host "  [OK] $m" -ForegroundColor Green }
function Info($m) { Write-Host "  ...  $m" }
function Warn($m) { Write-Host "  [!]  $m" -ForegroundColor Yellow }
function Stop-Erreur($m) { Write-Host "  [X]  $m" -ForegroundColor Red; Write-Host ''; exit 1 }

Write-Host ''
Write-Host "D8 · Espace SI — activation de PHP pour $Dossier" -ForegroundColor Cyan
Write-Host ''

# --- 0. administrateur ---
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Stop-Erreur "Lancez ce script en administrateur (double-clic sur Activer-PHP.cmd)." }

# --- 1. IIS et module FastCGI ---
try { Import-Module WebAdministration } catch { Stop-Erreur "IIS n'est pas installé sur cette machine (module WebAdministration introuvable)." }
if (-not (Get-WebGlobalModule -Name FastCgiModule -ErrorAction SilentlyContinue)) {
    Info "Le module FastCGI d'IIS est absent : installation de la fonctionnalité CGI…"
    if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) { Install-WindowsFeature Web-CGI | Out-Null }
    else { Enable-WindowsOptionalFeature -Online -FeatureName IIS-CGI -All -NoRestart | Out-Null }
    if (-not (Get-WebGlobalModule -Name FastCgiModule -ErrorAction SilentlyContinue)) { Stop-Erreur "Impossible d'installer le module FastCGI d'IIS (fonctionnalité « CGI »)." }
}
Ok "IIS et le module FastCGI sont présents"

# --- 2. site IIS qui contient le dossier ---
$Dossier = $Dossier.TrimEnd('\')
if (-not (Test-Path (Join-Path $Dossier 'api.php'))) { Stop-Erreur "api.php introuvable dans $Dossier : copiez d'abord les fichiers de l'Espace SI." }
$site = $null; $longueur = -1; $rel = ''
foreach ($s in Get-Website) {
    $racine = [Environment]::ExpandEnvironmentVariables([string]$s.physicalPath).TrimEnd('\')
    if ($Dossier -ieq $racine -or $Dossier.StartsWith($racine + '\', [StringComparison]::OrdinalIgnoreCase)) {
        if ($racine.Length -gt $longueur) { $site = $s; $longueur = $racine.Length; $rel = $Dossier.Substring($racine.Length).TrimStart('\', '/') -replace '\\', '/' }
    }
}
if (-not $site) { Stop-Erreur "Aucun site IIS ne contient le dossier $Dossier." }
$location = if ($rel) { "$($site.Name)/$rel" } else { $site.Name }
$psDossier = "IIS:\Sites\$($site.Name)" + $(if ($rel) { '\' + ($rel -replace '/', '\') } else { '' })
$liaison = @($site.bindings.Collection | Where-Object { $_.protocol -eq 'http' })[0]
$port = 80; $hote = 'localhost'
if ($liaison) { $p = ([string]$liaison.bindingInformation).Split(':'); if ($p.Count -ge 2 -and $p[1]) { $port = [int]$p[1] }; if ($p.Count -ge 3 -and $p[2]) { $hote = $p[2] } }
$base = "http://$hote" + $(if ($port -ne 80) { ":$port" } else { '' }) + $(if ($rel) { "/$rel" } else { '' })
$url = "$base/api.php?a=ping"
Ok "Site IIS « $($site.Name) », adresse locale $base/"

# --- annulation ---
if ($Annuler) {
    Remove-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter 'system.webServer/handlers' -Name '.' -AtElement @{ name = $NomMappage } -ErrorAction SilentlyContinue
    Ok "Mappage $NomMappage retiré pour /$rel/ (les autres sites ne sont pas touchés)."
    Write-Host ''; exit 0
}

# --- 3. droit d'écriture pour PHP (sous-dossier data) ---
foreach ($compte in 'IIS_IUSRS', 'IUSR') {
    & icacls "$Dossier" /grant "${compte}:(OI)(CI)M" /T /C /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { Warn "Droits non appliqués pour $compte (code $LASTEXITCODE)." }
}
Ok "Droit d'écriture donné à IIS_IUSRS et IUSR sur $Dossier"

# --- 4. PHP répond-il déjà ? ---
function Test-Api {
    try {
        $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
        return @{ code = [int]$r.StatusCode; body = [string]$r.Content }
    } catch {
        $rep = $_.Exception.Response
        if ($rep) {
            try { $lu = (New-Object IO.StreamReader($rep.GetResponseStream())).ReadToEnd() } catch { $lu = '' }
            return @{ code = [int]$rep.StatusCode; body = [string]$lu }
        }
        return @{ code = 0; body = $_.Exception.Message }
    }
}
function Get-Etat($t) {
    if ($t.body -match '"app"\s*:\s*"espace-si"') { return 'ok' }
    if ($t.body -match '^\s*\{.*"error"') { return 'erreur-php' }
    return 'pas-de-php'
}
function Show-Fin {
    Write-Host ''
    Write-Host "  PHP est actif pour l'Espace SI." -ForegroundColor Green
    Write-Host "  Adresse pour l'équipe : http://192.168.1.174$(if ($port -ne 80) { ":$port" })/$rel/"
    $codes = Join-Path $Dossier 'data\PREMIERE-CONNEXION.txt'
    if (Test-Path $codes) { Write-Host "  Codes de première connexion : $codes" }
    Write-Host ''
}
$t = Test-Api
$etat = Get-Etat $t
if ($etat -eq 'ok') { Ok "PHP répond déjà pour ce dossier : aucune modification nécessaire."; Show-Fin; exit 0 }
if ($etat -eq 'erreur-php') { Ok 'PHP répond déjà pour ce dossier.'; Stop-Erreur ("api.php signale une erreur : " + $t.body) }
Info "PHP ne répond pas encore pour ce dossier (réponse HTTP $($t.code))."

# --- 5. quel PHP utiliser ? ---
$proc = ''
if ($PhpCgi) {
    if (-not (Test-Path $PhpCgi)) { Stop-Erreur "Fichier introuvable : $PhpCgi" }
    $proc = (Resolve-Path $PhpCgi).Path
    Info "PHP imposé : $proc"
}
if (-not $proc) {
    # a) mappage *.php déjà utilisé par un site ou une application (Planning, Ticketing…)
    $trouves = @()
    foreach ($s in Get-Website) {
        $chemins = @("IIS:\Sites\$($s.Name)") + @(Get-WebApplication -Site $s.Name | ForEach-Object { "IIS:\Sites\$($s.Name)" + ($_.path -replace '/', '\') })
        foreach ($c in $chemins) {
            try {
                Get-WebHandler -PSPath $c | Where-Object { $_.path -eq '*.php' -and $_.modules -match 'FastCgiModule' -and $_.scriptProcessor } |
                    ForEach-Object { $trouves += [pscustomobject]@{ Ou = $c; Proc = [string]$_.scriptProcessor } }
            } catch { }
        }
    }
    $choix = @($trouves | Where-Object { $_.Ou -eq "IIS:\Sites\$($site.Name)" }) + @($trouves)
    if ($choix.Count) { $proc = $choix[0].Proc; Info "PHP repris de $($choix[0].Ou) : $proc" }
}
if (-not $proc) {
    # b) application FastCGI déjà enregistrée dans IIS
    $app = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi/application' | Where-Object { $_.fullPath -match 'php-cgi\.exe$' })[0]
    if ($app) { $proc = [string]$app.fullPath + $(if ($app.arguments) { '|' + $app.arguments } else { '' }); Info "PHP enregistré dans IIS : $proc" }
}
if (-not $proc) {
    # c) recherche sur le disque (version la plus récente)
    $exes = @('C:\PHP', 'C:\php*', 'C:\Program Files\PHP', 'C:\Program Files\PHP\*', 'C:\Program Files (x86)\PHP', 'C:\Program Files (x86)\PHP\*', 'C:\tools\php*', 'C:\xampp\php') |
        ForEach-Object { Get-ChildItem -Path (Join-Path $_ 'php-cgi.exe') -ErrorAction SilentlyContinue } |
        Sort-Object { $v = $_.VersionInfo; [version]('{0}.{1}.{2}' -f $v.FileMajorPart, $v.FileMinorPart, $v.FileBuildPart) } -Descending
    if (@($exes).Count) { $proc = @($exes)[0].FullName; Info "PHP trouvé sur le disque : $proc" }
}
if (-not $proc) { Stop-Erreur "php-cgi.exe introuvable. Relancez avec le chemin : Activer-PHP.cmd -PhpCgi 'C:\chemin\vers\php-cgi.exe'" }

$exe = $proc.Split('|')[0]
$argsPhp = if ($proc.Contains('|')) { $proc.Substring($proc.IndexOf('|') + 1) } else { '' }
if (-not (Test-Path $exe)) { Stop-Erreur "Le PHP configuré pointe vers un fichier absent : $exe" }
try {
    $ver = ((& $exe -v 2>$null) | Select-Object -First 1)
    if ($ver -match 'PHP (\d+)\.(\d+)') {
        if ([int]$Matches[1] * 100 + [int]$Matches[2] -lt 704) { Warn "$ver : l'Espace SI demande PHP 7.4 ou plus." } else { Ok $ver }
    }
} catch { }

# --- 6. application FastCGI (niveau serveur) puis mappage pour ce dossier seulement ---
$apps = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi/application')
if (-not ($apps | Where-Object { [string]$_.fullPath -ieq $exe -and [string]$_.arguments -eq $argsPhp })) {
    Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi' -Value @{ fullPath = $exe; arguments = $argsPhp; activityTimeout = 600; requestTimeout = 600; instanceMaxRequests = 10000 }
    try { Add-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter "system.webServer/fastCgi/application[@fullPath='$exe']/environmentVariables" -Value @{ name = 'PHP_FCGI_MAX_REQUESTS'; value = '10000' } } catch { }
    Ok "Application FastCGI enregistrée dans IIS : $exe"
} else { Ok "Application FastCGI déjà enregistrée dans IIS" }

Remove-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter 'system.webServer/handlers' -Name '.' -AtElement @{ name = $NomMappage } -ErrorAction SilentlyContinue
Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location $location -Filter 'system.webServer/handlers' -Name '.' -AtIndex 0 -Value @{
    name = $NomMappage; path = '*.php'; verb = 'GET,HEAD,POST'; modules = 'FastCgiModule'; scriptProcessor = $proc; resourceType = 'Either'; requireAccess = 'Script'
}
Ok "Mappage *.php → FastCGI ajouté pour /$rel/ uniquement"

# --- 7. vérification ---
for ($i = 0; $i -lt 6; $i++) {
    Start-Sleep -Seconds 1
    $t = Test-Api; $etat = Get-Etat $t
    if ($etat -ne 'pas-de-php') { break }
}
if ($etat -eq 'ok') { Ok "api.php répond : $url"; Show-Fin; exit 0 }
if ($etat -eq 'erreur-php') { Ok 'PHP fonctionne.'; Stop-Erreur ("api.php signale une erreur : " + $t.body) }
Warn "api.php ne répond toujours pas (HTTP $($t.code))."
Write-Host ("        " + ($t.body -replace '\s+', ' ').Substring(0, [Math]::Min(300, ($t.body -replace '\s+', ' ').Length)))
Write-Host "        Ouvrez $url dans un navigateur sur le serveur pour voir le détail de l'erreur IIS."
Write-Host ''
exit 1
