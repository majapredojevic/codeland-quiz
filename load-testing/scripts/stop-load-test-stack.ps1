[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$runtimeRoot = Join-Path $repositoryRoot 'load-testing\.runtime'
$stackEnvironmentPath = Join-Path $runtimeRoot 'stack.env'

if (-not (Test-Path -LiteralPath $stackEnvironmentPath)) {
    Write-Host 'No retained CodeLand Quiz load-test stack runtime was found.'
    return
}

& docker compose `
    --env-file $stackEnvironmentPath `
    --project-name codeland-quiz-load-test `
    --file (Join-Path $repositoryRoot 'compose.production.yaml') `
    --file (Join-Path $repositoryRoot 'load-testing\compose.load-test.yaml') `
    down --volumes --remove-orphans

if ($LASTEXITCODE -ne 0) { throw 'The retained load-test stack could not be stopped.' }
Remove-Item -LiteralPath $runtimeRoot -Recurse -Force
Write-Host 'The isolated load-test stack, volumes, certificate, and runtime secrets were removed.'
