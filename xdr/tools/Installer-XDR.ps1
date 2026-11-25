<#
  Sentinelle XDR — installation sur Windows 11 Pro + IIS + PHP.
  Lancé par Installer-XDR.cmd (en administrateur). Étapes :
    1. trouve PHP (celui d'IIS) et active les extensions nécessaires dans php.ini ;
    2. copie Sentinelle dans le site IIS (par défaut C:\inetpub\wwwroot\xdr) sans écraser vos données ;
    3. donne au serveur web le droit d'écrire dans le dossier data ;
    4. crée la tâche planifiée « Sentinelle XDR » (analyse chaque minute) ;
    5. vérifie que le pare-feu Windows n'ouvre pas IIS à tout Internet (IPv6 compris) ;
    6. propose d'installer AdGuard Home (voir les applications utilisées, filtrer pour les enfants) ;
    7. ouvre Sentinelle dans le navigateur pour choisir le mot de passe.
  Options : -Target "D:\sites\xdr"  -Site "Default Web Site"  -SkipFirewall
#>
param(
    [string]$Target = "$env:SystemDrive\inetpub\wwwroot\xdr",
    [string]$Site = "Default Web Site",
    [switch]$SkipFirewall
)
$ErrorActionPreference = 'Stop'
function Step([string]$t) { Write-Host ""; Write-Host "== $t" -ForegroundColor Cyan }
function Ok([string]$t) { Write-Host "   [OK] $t" -ForegroundColor Green }
function Warn([string]$t) { Write-Host "   [!]  $t" -ForegroundColor Yellow }
function NameOf([string]$sid) { (New-Object System.Security.Principal.SecurityIdentifier($sid)).Translate([System.Security.Principal.NTAccount]).Value }

try {
    $Source = Split-Path -Parent $PSScriptRoot
    Write-Host "Sentinelle XDR — installation" -ForegroundColor Magenta
    Write-Host "Source : $Source"
    Write-Host "Cible  : $Target"

    # ------------------------------------------------------------------ 1. PHP
    Step "1. PHP et ses extensions"
    Import-Module WebAdministration -ErrorAction SilentlyContinue
    $phpCgi = $null
    try { $phpCgi = (Get-WebConfiguration -Filter 'system.webServer/fastCgi/application' -PSPath 'IIS:\' | Select-Object -First 1).fullPath } catch { }
    if (-not $phpCgi) { $c = Get-Command php.exe -ErrorAction SilentlyContinue; if ($c) { $phpCgi = $c.Source } }
    if (-not $phpCgi -or -not (Test-Path $phpCgi)) { throw "PHP introuvable. Installez d'abord IIS + PHP (votre script Installer-IIS-PHP.cmd)." }
    $phpDir = Split-Path -Parent $phpCgi
    $php = Join-Path $phpDir 'php.exe'
    if (-not (Test-Path $php)) { throw "php.exe introuvable dans $phpDir." }
    Ok ("PHP " + (& $php -r 'echo PHP_VERSION;') + " ($phpDir)")

    $iniLine = (& $php --ini | Select-String 'Loaded Configuration File').ToString()
    $ini = $iniLine.Split(':', 2)[1].Trim()
    if ($ini -eq '(none)' -or -not (Test-Path $ini)) {
        $ini = Join-Path $phpDir 'php.ini'
        $prod = Join-Path $phpDir 'php.ini-production'
        if (-not (Test-Path $ini) -and (Test-Path $prod)) { Copy-Item $prod $ini; Ok "php.ini créé à partir de php.ini-production" }
    }
    $iniChanged = $false
    $mods = (& $php -m) | ForEach-Object { $_.Trim().ToLower() }
    $missing = @('curl', 'pdo_sqlite', 'sqlite3', 'openssl', 'mbstring') | Where-Object { $mods -notcontains $_ }
    $content = if (Test-Path $ini) { Get-Content $ini -Raw -Encoding UTF8 } else { '' }
    if ($missing.Count -gt 0) {
        Copy-Item $ini "$ini.avant-sentinelle" -Force -ErrorAction SilentlyContinue
        if ($content -match '(?m)^\s*;\s*extension_dir\s*=\s*"ext"') { $content = $content -replace '(?m)^\s*;\s*(extension_dir\s*=\s*"ext")', '$1' }
        foreach ($ext in $missing) {
            if ($content -match "(?m)^\s*;\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") {
                $content = $content -replace "(?m)^\s*;\s*(extension\s*=\s*(php_)?$ext(\.dll)?)\s*$", '$1'
            } elseif ($content -notmatch "(?m)^\s*extension\s*=\s*(php_)?$ext(\.dll)?\s*$") {
                $content += "`r`nextension=$ext"
            }
        }
        $iniChanged = $true
        Ok ("Extensions activées : " + ($missing -join ', '))
    } else { Ok "Extensions curl, pdo_sqlite, openssl et mbstring déjà actives" }

    # Autorités de certification pour les connexions HTTPS de PHP (actualités, notifications)
    if ($content -notmatch '(?m)^\s*curl\.cainfo\s*=') {
        $sslDir = Join-Path $phpDir 'extras\ssl'
        New-Item -ItemType Directory -Force -Path $sslDir | Out-Null
        $pem = Join-Path $sslDir 'cacert.pem'
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -UseBasicParsing -Uri 'https://curl.se/ca/cacert.pem' -OutFile $pem
            $content += "`r`ncurl.cainfo = `"$pem`"`r`nopenssl.cafile = `"$pem`""
            $iniChanged = $true
            Ok "Certificats racines installés ($pem)"
        } catch { Warn "Téléchargement de cacert.pem impossible : les actualités pourront échouer en HTTPS. ($($_.Exception.Message))" }
    }
    if ($iniChanged) {
        if (-not (Test-Path "$ini.avant-sentinelle")) { Copy-Item $ini "$ini.avant-sentinelle" -Force -ErrorAction SilentlyContinue }
        $utf8 = New-Object System.Text.UTF8Encoding($false)
        [IO.File]::WriteAllText($ini, $content, $utf8)
        Ok "php.ini mis à jour (sauvegarde : $ini.avant-sentinelle)"
    }

    # ------------------------------------------------------------------ 2. Copie
    Step "2. Copie de Sentinelle"
    $srcFull = (Resolve-Path $Source).Path.TrimEnd('\')
    $dstFull = [IO.Path]::GetFullPath($Target).TrimEnd('\')
    if ($srcFull -ieq $dstFull) {
        Ok "Sentinelle est déjà à cet emplacement : pas de copie."
    } else {
        New-Item -ItemType Directory -Force -Path $dstFull | Out-Null
        robocopy $srcFull $dstFull /E /XD (Join-Path $srcFull 'data') /XF config.php /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw "Échec de la copie (robocopy, code $LASTEXITCODE)." }
        Ok "Fichiers copiés (vos données et config.php existants sont conservés)"
    }

    # ------------------------------------------------------------------ 3. Droits
    Step "3. Droits d'écriture du dossier data"
    $data = Join-Path $dstFull 'data'
    New-Item -ItemType Directory -Force -Path $data | Out-Null
    icacls $data /grant '*S-1-5-32-568:(OI)(CI)M' '*S-1-5-17:(OI)(CI)M' /T /Q | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "icacls a échoué (code $LASTEXITCODE)." }
    Ok "IIS_IUSRS et IUSR peuvent écrire dans $data"

    if ($iniChanged) {
        Step "Redémarrage d'IIS (prise en compte de php.ini)"
        iisreset /noforce | Out-Null
        Ok "IIS redémarré"
    }

    # ------------------------------------------------------------------ 4. Tâche planifiée
    Step "4. Surveillance continue (tâche planifiée)"
    $url = '/xdr'
    try {
        $sitePath = [Environment]::ExpandEnvironmentVariables((Get-Website -Name $Site).physicalPath).TrimEnd('\')
        if ($dstFull.ToLower().StartsWith($sitePath.ToLower() + '\')) {
            $url = '/' + $dstFull.Substring($sitePath.Length + 1).Replace('\', '/')
        } else { Warn "Le dossier n'est pas sous la racine du site « $Site » : créez une application IIS qui pointe dessus, alias « xdr »." }
        $port = (Get-WebBinding -Name $Site -Protocol http | Select-Object -First 1).bindingInformation.Split(':')[1]
    } catch { $port = '80' }
    $base = if ($port -and $port -ne '80') { "http://127.0.0.1:$port$url" } else { "http://127.0.0.1$url" }
    $key = (& $php (Join-Path $dstFull 'tools\setup.php') --cron-key | Select-Object -Last 1).Trim()
    icacls $data /grant '*S-1-5-32-568:(OI)(CI)M' '*S-1-5-17:(OI)(CI)M' /T /Q | Out-Null
    $cronUrl = "$base/api.php?action=cron&key=$key"
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    $action = New-ScheduledTaskAction -Execute $curl -Argument "-s -m 50 -o NUL `"$cronUrl`""
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 2) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    try {
        $principal = New-ScheduledTaskPrincipal -UserId (NameOf 'S-1-5-19') -LogonType ServiceAccount
        Register-ScheduledTask -TaskName 'Sentinelle XDR' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
        Ok "Tâche « Sentinelle XDR » créée (compte Service local, chaque minute)"
    } catch {
        $principal = New-ScheduledTaskPrincipal -UserId (NameOf 'S-1-5-18') -LogonType ServiceAccount
        Register-ScheduledTask -TaskName 'Sentinelle XDR' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
        Ok "Tâche « Sentinelle XDR » créée (compte Système, chaque minute)"
    }

    # ------------------------------------------------------------------ 5. Pare-feu
    if (-not $SkipFirewall) {
        Step "5. Pare-feu Windows : IIS limité au réseau local ?"
        $exposed = @()
        $rules = Get-NetFirewallPortFilter -Protocol TCP | Where-Object { ($_.LocalPort -contains '80') -or ($_.LocalPort -contains '443') } | Get-NetFirewallRule |
            Where-Object { $_.Direction -eq 'Inbound' -and $_.Enabled -eq 'True' -and $_.Action -eq 'Allow' }
        foreach ($r in $rules) {
            $remote = ($r | Get-NetFirewallAddressFilter).RemoteAddress
            if ($remote -contains 'Any') { $exposed += $r; Warn "La règle « $($r.DisplayName) » accepte TOUT Internet sur le port 80/443 (IPv6 compris)." }
        }
        if ($exposed.Count -gt 0) {
            $ans = Read-Host "   Limiter ces règles au réseau local (recommandé) ? O/N"
            if ($ans -match '^[oOyY]') {
                $exposed | Set-NetFirewallRule -RemoteAddress LocalSubnet
                Ok "Règles limitées au réseau local (LocalSubnet)."
            } else { Warn "Règles laissées telles quelles." }
        } else { Ok "Aucune règle n'ouvre IIS à tout Internet." }
    }

    # ------------------------------------------------------------------ 6. AdGuard Home (facultatif)
    Step "6. AdGuard Home : voir les applications utilisées par les enfants (facultatif)"
    $agDir = Join-Path $env:ProgramFiles 'AdGuardHome'
    $agExe = Join-Path $agDir 'AdGuardHome.exe'
    if (Test-Path $agExe) {
        Ok "AdGuard Home est déjà installé ($agDir)."
    } else {
        $ans = Read-Host "   Installer AdGuard Home (gratuit, officiel) sur ce PC ? O/N"
        if ($ans -match '^[oOyY]') {
            $zip = Join-Path $env:TEMP 'AdGuardHome_windows_amd64.zip'
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -UseBasicParsing -Uri 'https://github.com/AdguardTeam/AdGuardHome/releases/latest/download/AdGuardHome_windows_amd64.zip' -OutFile $zip
            Expand-Archive -Path $zip -DestinationPath $env:ProgramFiles -Force
            Remove-Item $zip -Force -ErrorAction SilentlyContinue
            & $agExe -s install | Out-Null
            Ok "AdGuard Home installé comme service Windows"
            foreach ($proto in 'UDP', 'TCP') {
                if (-not (Get-NetFirewallRule -DisplayName "AdGuard Home DNS ($proto, réseau local)" -ErrorAction SilentlyContinue)) {
                    New-NetFirewallRule -DisplayName "AdGuard Home DNS ($proto, réseau local)" -Direction Inbound -Protocol $proto -LocalPort 53 -RemoteAddress LocalSubnet -Action Allow | Out-Null
                }
            }
            Ok "Pare-feu : DNS (port 53) ouvert au seul réseau local"
            Warn "Dans l'assistant qui s'ouvre : interface d'administration sur le PORT 3000 (IIS occupe le port 80), DNS sur le port 53, puis créez un identifiant."
            Warn "Ensuite : Sentinelle › Paramètres › Navigation (DNS) : http://127.0.0.1:3000 + cet identifiant, puis l'onglet Enfants pour faire passer la maison par AdGuard Home."
            Start-Process 'http://127.0.0.1:3000/'
        } else { Ok "Étape passée : vous pourrez l'installer plus tard (voir le guide)." }
    }

    # ------------------------------------------------------------------ 7. Fin
    Step "Terminé"
    Ok "Ouverture de Sentinelle : choisissez votre mot de passe (la première fois, uniquement depuis ce PC)."
    Start-Process "http://localhost$url/"
    Write-Host ""
    Write-Host "Depuis un autre appareil de la maison : http://$env:COMPUTERNAME$url/" -ForegroundColor Magenta
} catch {
    Write-Host ""
    Write-Host "ERREUR : $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Consultez le guide (README.md, section Installation manuelle)." -ForegroundColor Red
    exit 1
}
