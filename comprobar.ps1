$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
& $phpPath scripts/diagnostico.php --solo-dependencias
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
foreach ($file in (Get-ChildItem -Path 'src','public','scripts','tests' -Recurse -Filter '*.php' -File)) {
    & $phpPath -l $file.FullName
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
& $phpPath tests/payroll_test.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
if (Get-Command node -ErrorAction SilentlyContinue) {
    node --check public/assets/app.js
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    node --check public/assets/graphql.js
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    node --check public/assets/ui.js
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    node --test tests/ui_test.cjs
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} else {
    Write-Host 'Node no está instalado; se omite la comprobación de sintaxis JavaScript.'
}
python tests/test_api.py
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
python tests/test_graphql.py
exit $LASTEXITCODE
