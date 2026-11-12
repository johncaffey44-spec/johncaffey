# =====================================================================
#  Gardien - antivol : installation dans un dossier dedie de wwwroot (IIS)
#
#  Cree <wwwroot>\gardien\, y depose la version autonome (api.php + index.html
#  + gardien.apk), prepare data\gardien (protege + accessible en ecriture IIS),
#  et affiche l'adresse d'acces (localhost + IP du serveur).
#  Ne touche PAS au planning D8. Ne reinstalle PAS PHP (voir Installer-IIS-PHP).
#
#  Usage : double-clic sur Installer-Gardien.cmd (lance ce script en admin)
#  ou :    powershell -ExecutionPolicy Bypass -File Installer-Gardien.ps1 [-Name gardien] [-WebRoot "C:\inetpub\wwwroot"]
# =====================================================================
param(
  [string]$Name = "gardien",
  [string]$WebRoot = ""
)

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
function Info($m){ Write-Host $m -ForegroundColor Cyan }
function Good($m){ Write-Host $m -ForegroundColor Green }
function Warn($m){ Write-Host $m -ForegroundColor Yellow }
function Bad($m){ Write-Host $m -ForegroundColor Red }

Write-Host ""
Info "=== Installation de Gardien (antivol) dans wwwroot ==="
Write-Host ""

# --- 1. Racine web : param, sinon chemin du site IIS par defaut, sinon C:\inetpub\wwwroot ---
if (-not $WebRoot) {
  try {
    Import-Module WebAdministration -ErrorAction Stop
    $site = Get-Website | Where-Object { $_.Name -eq 'Default Web Site' } | Select-Object -First 1
    if (-not $site) { $site = Get-Website | Select-Object -First 1 }
    if ($site -and $site.physicalPath) { $WebRoot = [System.Environment]::ExpandEnvironmentVariables([string]$site.physicalPath) }
  } catch { }
}
if (-not $WebRoot) { $WebRoot = "C:\inetpub\wwwroot" }
if (-not (Test-Path $WebRoot)) { Bad ("Racine web introuvable : " + $WebRoot); Read-Host "Entree pour quitter"; exit 1 }
Info ("Racine web   : " + $WebRoot)

# --- 2. Dossier dedie <WebRoot>\<Name> ---
$Name = ($Name -replace '[^A-Za-z0-9_-]', '')
if (-not $Name) { $Name = "gardien" }
$Target = Join-Path $WebRoot $Name
if (Test-Path $Target) { Warn ("Le dossier existe deja, les fichiers seront mis a jour : " + $Target) }
New-Item -ItemType Directory -Force -Path $Target | Out-Null
Info ("Dossier      : " + $Target)

# --- 3. Deployer la version autonome : api.php + index.html (+ gardien.apk) ---
$apiSrc  = Join-Path $here "gardien-api.php"
$htmlSrc = Join-Path $here "gardien.html"
if (-not (Test-Path $apiSrc))  { Bad "gardien-api.php manquant dans l'archive"; Read-Host "Entree pour quitter"; exit 1 }
if (-not (Test-Path $htmlSrc)) { Bad "gardien.html manquant dans l'archive";    Read-Host "Entree pour quitter"; exit 1 }
# Le backend se reference par son propre nom, le tableau de bord auto-detecte : on peut renommer.
Copy-Item $apiSrc  (Join-Path $Target "api.php")    -Force
Copy-Item $htmlSrc (Join-Path $Target "index.html") -Force
Good "Deploye : api.php + index.html"

$apkSrc = Join-Path $here "gardien.apk"
if (-not (Test-Path $apkSrc)) { $apkSrc = Join-Path $here "gardien-standalone\gardien.apk" }
if (Test-Path $apkSrc) {
  Copy-Item $apkSrc (Join-Path $Target "gardien.apk") -Force
  Good "Deploye : gardien.apk (telechargeable depuis la page d'installation)"
} else {
  Warn "gardien.apk absente de l'archive : la page d'installation ne proposera pas le telechargement."
}

# --- 4. data : creation, protection, droits d'ecriture IIS ---
$data = Join-Path $Target "data"
$gdir = Join-Path $data "gardien"
New-Item -ItemType Directory -Force -Path $gdir | Out-Null
$webcfg = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>'
$webcfgPath = Join-Path $data "web.config"
if (-not (Test-Path $webcfgPath)) { [System.IO.File]::WriteAllText($webcfgPath, $webcfg) }
$htPath = Join-Path $data ".htaccess"
if (-not (Test-Path $htPath)) { [System.IO.File]::WriteAllText($htPath, "Require all denied`r`nDeny from all`r`n") }
# S-1-5-32-568 = groupe IIS_IUSRS (identites de pool), via SID (independant de la langue).
try {
  & icacls "$data" /grant "*S-1-5-32-568:(OI)(CI)M" /T /C | Out-Null
  Good "Dossier data pret, protege, accessible en ecriture par IIS."
} catch {
  Warn "icacls a echoue : accordez 'Modifier' au groupe IIS_IUSRS sur $data"
}

# --- 5. PHP ---
Info "PHP doit deja etre configure sous IIS (votre Installer-IIS-PHP). Gardien n'y touche pas."

# --- 6. Adresses d'acces (localhost + IP du serveur) ---
$ips = @()
try {
  $ips = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
    Where-Object { $_.IPAddress -ne '127.0.0.1' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -ExpandProperty IPAddress
} catch {
  try { $ips = [System.Net.Dns]::GetHostAddresses([System.Net.Dns]::GetHostName()) | Where-Object { $_.AddressFamily -eq 'InterNetwork' } | ForEach-Object { $_.IPAddressToString } } catch { }
}

Write-Host ""
Good "=== Installation terminee ==="
Write-Host ""
Write-Host ("  Sur ce serveur   :  http://localhost/" + $Name + "/") -ForegroundColor White
foreach ($ip in $ips) { Write-Host ("  Depuis le reseau :  http://" + $ip + "/" + $Name + "/") -ForegroundColor White }
Write-Host ""
Write-Host "  1. Ouvrez une de ces adresses depuis un poste du reseau interne." -ForegroundColor Gray
Write-Host "  2. Creez le compte administrateur (proprietaire)." -ForegroundColor Gray
Write-Host "  3. 'Ajouter' : scannez le QR avec le telephone (meme Wi-Fi que le serveur)." -ForegroundColor Gray
Write-Host "     -> telechargement auto de l'app, puis appairage auto." -ForegroundColor Gray
Write-Host ""
Write-Host "  Gardien sert vos PROPRES appareils, en clair et avec accord." -ForegroundColor DarkGray
Write-Host ""
Read-Host "Entree pour fermer"
