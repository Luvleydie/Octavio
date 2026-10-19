param([int]$Puerto = 8088, [switch]$InstalarDemo)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if ($Puerto -lt 1024 -or $Puerto -gt 65535) { throw 'Usa un puerto entre 1024 y 65535.' }

# Comprueba el puerto antes de instalar datos demo o iniciar otro servidor.
$portProbe = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $Puerto)
try {
    $portProbe.Server.ExclusiveAddressUse = $true
    $portProbe.Start()
} catch {
    throw "El puerto $Puerto no esta disponible. Si el proyecto ya esta abierto, usa http://127.0.0.1:$Puerto; para otra instancia indica -Puerto con un puerto libre."
} finally {
    $portProbe.Stop()
}

$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
if (-not (Test-Path -LiteralPath $phpPath)) {
    throw 'No se encontró PHP. Instala XAMPP o agrega PHP a PATH.'
}
if ($InstalarDemo) {
    & $phpPath scripts/diagnostico.php --solo-dependencias
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    Write-Host 'Preparando los datos de demostracion...'
    # El instalador muestra credenciales demo: no las repetimos en el arranque.
    $previousErrorPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $phpPath scripts/install.php --demo *> $null
        $installExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorPreference
    }
    if ($installExitCode -ne 0) {
        throw 'No se pudo preparar la demostracion. Revisa MySQL en XAMPP y config.php; ejecuta php scripts/diagnostico.php para identificar el problema.'
    }
    Write-Host 'Datos de demostracion listos.'
}
& $phpPath scripts/diagnostico.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Write-Host "Nómina Clara: http://127.0.0.1:$Puerto" -ForegroundColor Cyan
Write-Host "GraphQL: POST http://127.0.0.1:$Puerto/api/graphql.php"
Write-Host 'Deja esta terminal abierta. Ctrl+C detiene el servidor.'
& $phpPath -S "127.0.0.1:$Puerto" -t public
exit $LASTEXITCODE
