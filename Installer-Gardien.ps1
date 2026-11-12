# =====================================================================
#  Gardien - antivol : installation sur serveur IIS (Windows)
#  Depose gardien-api.php + gardien.html a cote de votre planning,
#  prepare le dossier data (protege + accessible en ecriture par IIS).
#  Ne reinstalle PAS PHP : votre Installer-IIS-PHP s'en charge deja.
#
#  Usage :  double-clic sur Installer-Gardien.cmd  (lance ce script en admin)
#  ou :     powershell -ExecutionPolicy Bypass -File Installer-Gardien.ps1 [-Dest "C:\chemin"]
# =====================================================================
param([string]$Dest = "")

$ErrorActionPreference = "Stop"
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
function Info($m){ Write-Host $m -ForegroundColor Cyan }
function Good($m){ Write-Host $m -ForegroundColor Green }
function Warn($m){ Write-Host $m -ForegroundColor Yellow }
function Bad($m){ Write-Host $m -ForegroundColor Red }

Write-Host ""
Info "=== Installation de Gardien (antivol) sur IIS ==="
Write-Host ""

# --- 1. Trouver le dossier web (la ou se trouve deja api.php / le planning) ---
if (-not $Dest) {
  $candidates = @()
  try {
    Import-Module WebAdministration -ErrorAction Stop
    Get-Website | ForEach-Object {
      $p = [System.Environment]::ExpandEnvironmentVariables([string]$_.physicalPath)
      if ($p) { $candidates += $p }
    }
  } catch { }
  $candidates += "C:\inetpub\wwwroot"
  foreach ($c in $candidates) {
    if ($c -and (Test-Path (Join-Path $c "api.php"))) { $Dest = $c; break }
  }
  if (-not $Dest -and (Test-Path (Join-Path $here "api.php"))) { $Dest = $here }
}
if (-not $Dest) {
  $Dest = Read-Host "Chemin du dossier web ou deposer Gardien (ex. C:\inetpub\wwwroot)"
}
if (-not (Test-Path $Dest)) { Bad "Dossier introuvable : $Dest"; Read-Host "Entree pour quitter"; exit 1 }
Info ("Destination : " + $Dest)
if (-not (Test-Path (Join-Path $Dest "api.php"))) {
  Warn "Aucun api.php ici : Gardien sera installe seul (c'est permis)."
}

# --- 2. Copier les deux fichiers ---
foreach ($f in @("gardien-api.php","gardien.html")) {
  $src = Join-Path $here $f
  if (-not (Test-Path $src)) { Bad ("Fichier manquant dans l'archive : " + $f); Read-Host "Entree pour quitter"; exit 1 }
  Copy-Item $src (Join-Path $Dest $f) -Force
}
Good "Fichiers copies : gardien-api.php, gardien.html"

# --- 3. Dossier data : creation, protection, droits d'ecriture IIS ---
$data = Join-Path $Dest "data"
$gdir = Join-Path $data "gardien"
New-Item -ItemType Directory -Force -Path $gdir | Out-Null

# Protection de l'acces direct au dossier data (IIS requestFiltering), comme api.php.
$webcfg = '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><security><requestFiltering><fileExtensions allowUnlisted="false" /></requestFiltering></security></system.webServer></configuration>'
$webcfgPath = Join-Path $data "web.config"
if (-not (Test-Path $webcfgPath)) { [System.IO.File]::WriteAllText($webcfgPath, $webcfg) }
$htaccessPath = Join-Path $data ".htaccess"
if (-not (Test-Path $htaccessPath)) { [System.IO.File]::WriteAllText($htaccessPath, "Require all denied`r`nDeny from all`r`n") }

# Droits d'ecriture pour IIS. S-1-5-32-568 = groupe IIS_IUSRS (couvre les identites de pool),
# via le SID pour rester valable quelle que soit la langue de Windows.
try {
  & icacls "$data" /grant "*S-1-5-32-568:(OI)(CI)M" /T /C | Out-Null
  Good "Dossier data pret, protege, accessible en ecriture par IIS."
} catch {
  Warn "icacls a echoue : accordez manuellement 'Modifier' au groupe IIS_IUSRS sur le dossier data."
}

# --- 4. PHP ---
Info "PHP doit deja etre configure sous IIS (votre Installer-IIS-PHP). Gardien n'y touche pas."

# --- 5. URL finale ---
$url = "http://localhost/gardien.html"
try {
  Import-Module WebAdministration -ErrorAction Stop
  $site = Get-Website | Where-Object { [System.Environment]::ExpandEnvironmentVariables([string]$_.physicalPath) -eq $Dest } | Select-Object -First 1
  if ($site) {
    $b = @($site.bindings.Collection)[0]
    if ($b -and $b.bindingInformation) {
      $parts = $b.bindingInformation -split ':'
      $port  = $parts[1]
      if ($port -and $port -ne "80") { $url = "http://localhost:$port/gardien.html" }
    }
  }
} catch { }

Write-Host ""
Good "=== Installation terminee ==="
Write-Host ""
Write-Host ("  1. Ouvrez :  " + $url) -ForegroundColor White
Write-Host "  2. Creez le compte administrateur (proprietaire)." -ForegroundColor White
Write-Host "  3. Cliquez 'Ajouter' pour appairer un telephone (QR ou code)." -ForegroundColor White
Write-Host ""
Write-Host "  Rappel : Gardien sert vos PROPRES appareils, en clair et avec accord." -ForegroundColor DarkGray
Write-Host ""
Read-Host "Entree pour fermer"
