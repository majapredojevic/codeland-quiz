[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$composePath = Join-Path $repositoryRoot 'compose.production.yaml'
$nonce = [Guid]::NewGuid().ToString('N')
$env:SERVER_NAME = 'quiz.validation.test'
$env:CODELAND_IMAGE_TAG = 'validation'
$env:TLS_CERTIFICATE_PATH = Join-Path $repositoryRoot '.local-production/validation/fullchain.pem'
$env:TLS_PRIVATE_KEY_PATH = Join-Path $repositoryRoot '.local-production/validation/privkey.pem'
$env:APP_URL = 'https://quiz.validation.test'
$env:DB_DATABASE = 'codeland_quiz'
$env:DB_USERNAME = 'validation_user'
$env:DB_PASSWORD = "validation-db-$nonce"
$env:MYSQL_ROOT_PASSWORD = "validation-root-$nonce"
$env:REFRESH_TOKEN_HASH_KEY = "refresh-$nonce"
$env:PARTICIPANT_TOKEN_SECRET = "participant-$nonce"
$env:JWT_SECRET = "jwt-$nonce"
$env:WS_ALLOWED_ORIGINS = 'https://quiz.validation.test'

$resolvedText = & docker compose -f $composePath config --format json

if ($LASTEXITCODE -ne 0) {
    throw 'Production Compose could not be resolved.'
}

$resolved = ($resolvedText -join "`n") | ConvertFrom-Json
$serviceNames = @($resolved.services.PSObject.Properties.Name | Sort-Object)

if (($serviceNames -join ',') -ne 'backend,mysql,nginx') {
    throw "Unexpected production services: $($serviceNames -join ', ')."
}

$nginxPorts = @(
    $resolved.services.nginx.ports |
        ForEach-Object { [int]$_.published } |
        Sort-Object
)

if (($nginxPorts -join ',') -ne '80,443') {
    throw "Unexpected production edge ports: $($nginxPorts -join ', ')."
}

foreach ($internalService in @('backend', 'mysql')) {
    $publishedPorts = $resolved.services.$internalService.ports

    if ($null -ne $publishedPorts -and @($publishedPorts).Count -ne 0) {
        throw "$internalService unexpectedly publishes a host port."
    }
}

$mysqlMounts = @($resolved.services.mysql.volumes)
$schemaMounts = @(
    $mysqlMounts |
        Where-Object {
            $_.target -eq '/docker-entrypoint-initdb.d/001_schema.sql'
        }
)
$expectedSchemaSource = [IO.Path]::GetFullPath(
    (Join-Path $repositoryRoot 'docker/mysql/init/001_schema.sql')
)

if (
    $schemaMounts.Count -ne 1 -or
    [IO.Path]::GetFullPath([string]$schemaMounts[0].source) -ne $expectedSchemaSource -or
    -not [bool]$schemaMounts[0].read_only
) {
    throw 'Production MySQL does not have exactly one read-only schema mount.'
}

foreach ($mount in $mysqlMounts) {
    $source = [string]$mount.source
    $target = [string]$mount.target

    if (
        $source -match '002_seed_admin\.sql' -or
        $target -match '002_seed_admin\.sql' -or
        $target -eq '/docker-entrypoint-initdb.d'
    ) {
        throw 'Production MySQL can still initialize the development Admin seed.'
    }
}

if (
    [string]$resolved.services.backend.environment.PERFORMANCE_PROFILING_ENABLED -ne 'false'
) {
    throw 'Production profiling is not forced off.'
}

Write-Output 'Resolved production Compose verification passed.'
