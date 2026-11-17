# =====================================================================
#  Gardien - certificat HTTPS auto-signe pour IIS (port 8443)
#
#  Serveur en IP interne => pas de Let's Encrypt possible. On cree un
#  certificat AUTO-SIGNE aux normes des telephones (SAN avec l'IP,
#  serverAuth, SHA-256, RSA 2048, < 825 jours pour iOS), on le branche a
#  IIS sur le port 8443, on ouvre le pare-feu, et on exporte le certificat
#  PUBLIC (gardien-ca.cer) a installer sur l'iPhone et l'Android.
#
#  Usage : double-clic sur Certificat-HTTPS.cmd (lance ce script en admin)
#  ou :    powershell -ExecutionPolicy Bypass -File Certificat-HTTPS.ps1 -Ip 192.168.1.50 -ExtraNames 10.8.0.1,gardien.lan
# =====================================================================
param(
  [string]$Ip = "",
  [string[]]$ExtraNames = @(),
  [int]$Port = 8443,
  [string]$SiteName = "Default Web Site",
  [string]$OutDir = ""
)

$ErrorActionPreference = "Stop"
function Info($m){ Write-Host $m -ForegroundColor Cyan }
function Good($m){ Write-Host $m -ForegroundColor Green }
function Warn($m){ Write-Host $m -ForegroundColor Yellow }
function Bad($m){ Write-Host $m -ForegroundColor Red }

Write-Host ""
Info "=== Certificat HTTPS Gardien (port $Port) ==="
Write-Host ""

# --- 1. Rassembler les noms du certificat (IP du serveur + extras + nom machine) ---
$names = New-Object System.Collections.Generic.List[string]
if ($Ip) { $names.Add($Ip) }
else {
  try {
    Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
      Where-Object { $_.IPAddress -ne '127.0.0.1' -and $_.IPAddress -notlike '169.254.*' } |
      ForEach-Object { $names.Add($_.IPAddress) }
  } catch { }
}
foreach ($n in $ExtraNames) { if ($n) { $names.Add($n.Trim()) } }
$names.Add($env:COMPUTERNAME)
$names = $names | Where-Object { $_ } | Select-Object -Unique
if (-not $names) { Bad "Aucune adresse trouvee. Relancez avec -Ip <adresse>."; Read-Host "Entree"; exit 1 }
Info ("Noms du certificat : " + ($names -join ", "))

# --- 2. Construire la liste SAN (IPAddress= pour les IP, DNS= pour les noms) ---
$sanParts = @()
foreach ($n in $names) {
  $ipObj = $null
  if ([System.Net.IPAddress]::TryParse($n, [ref]$ipObj)) { $sanParts += "IPAddress=$n" }
  else { $sanParts += "DNS=$n" }
}
$san = "2.5.29.17={text}" + ($sanParts -join "&")
$eku = "2.5.29.37={text}1.3.6.1.5.5.7.3.1"   # Extended Key Usage : serverAuth

# --- 3. Creer le certificat auto-signe ---
$cert = New-SelfSignedCertificate `
  -Subject "CN=Gardien ($($names[0]))" `
  -FriendlyName "Gardien HTTPS" `
  -CertStoreLocation "Cert:\LocalMachine\My" `
  -KeyAlgorithm RSA -KeyLength 2048 -HashAlgorithm SHA256 `
  -KeyExportPolicy Exportable `
  -NotAfter (Get-Date).AddDays(820) `
  -TextExtension @($san, $eku)
Good ("Certificat cree. Empreinte : " + $cert.Thumbprint)

# --- 4. Brancher le certificat a IIS sur le port $Port ---
Import-Module WebAdministration -ErrorAction SilentlyContinue
try {
  if (-not (Get-WebBinding -Name $SiteName -Protocol https -Port $Port -ErrorAction SilentlyContinue)) {
    New-WebBinding -Name $SiteName -Protocol https -Port $Port -IPAddress "*" | Out-Null
  }
  $b = Get-WebBinding -Name $SiteName -Protocol https -Port $Port
  $b.AddSslCertificate($cert.Thumbprint, "My")
  Good "HTTPS active sur IIS, site '$SiteName', port $Port."
} catch {
  Warn "Branchement IIS auto impossible ($($_.Exception.Message))."
  Warn "Faites-le a la main : IIS > $SiteName > Liaisons > Ajouter > https, port $Port, certificat 'Gardien HTTPS'."
}

# --- 5. Pare-feu ---
try {
  if (-not (Get-NetFirewallRule -DisplayName "Gardien HTTPS $Port" -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName "Gardien HTTPS $Port" -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow | Out-Null
  }
  Good "Pare-feu : port $Port ouvert (entrant)."
} catch { Warn "Ouvrez le port $Port dans le pare-feu a la main." }

# --- 6. Exporter le certificat PUBLIC a installer sur les telephones ---
if (-not $OutDir) {
  $here = Split-Path -Parent $MyInvocation.MyCommand.Path
  $OutDir = $here
}
$cerPath = Join-Path $OutDir "gardien-ca.cer"
Export-Certificate -Cert $cert -FilePath $cerPath -Type CERT | Out-Null
Good ("Certificat public exporte : " + $cerPath)
Write-Host "  (fichier a envoyer sur l'iPhone et l'Android - il ne contient PAS la cle privee)" -ForegroundColor DarkGray

# --- 7. Resume ---
Write-Host ""
Good "=== Termine ==="
Write-Host ""
Write-Host "  Acces HTTPS :" -ForegroundColor White
foreach ($n in $names) { if ($n -ne $env:COMPUTERNAME) { Write-Host ("    https://" + $n + ":" + $Port + "/gardien/") -ForegroundColor White } }
Write-Host ""
Write-Host "  Ensuite, installez gardien-ca.cer sur chaque appareil (voir HTTPS.md) :" -ForegroundColor Gray
Write-Host "   - iPhone : ouvrir le .cer -> Reglages -> profil -> installer," -ForegroundColor Gray
Write-Host "              puis Reglages > General > Informations > Reglages de confiance des certificats -> activer." -ForegroundColor Gray
Write-Host "   - Android : Parametres > Securite > Installer un certificat > Certificat CA." -ForegroundColor Gray
Write-Host ""
Read-Host "Entree pour fermer"
