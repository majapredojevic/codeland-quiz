[CmdletBinding()]
param(
    [ValidateRange(1, 500)]
    [int]$Students = 10,

    [ValidateRange(0, 20)]
    [int]$Sessions = 0,

    [ValidateSet('classroom', 'burst')]
    [string]$Mode = 'classroom',

    [ValidateRange(0, 100)]
    [double]$RegisteredPercent = 50,

    [ValidateRange(0, 100)]
    [double]$ReconnectPercent = 5,

    [ValidateRange(0, 1)]
    [double]$CorrectAnswerRatio = 0.70,

    [ValidateRange(0, 1)]
    [double]$BurstMajorityRatio = 0.85,

    [ValidateRange(0, [int]::MaxValue)]
    [int]$Seed = 0,

    [string]$TargetUrl = 'https://quiz.load.test',

    [switch]$AllowRemote,

    [switch]$Warmup,

    [switch]$KeepLoadTestFixtures,

    [switch]$KeepStack,

    [switch]$UseExistingStack,

    [switch]$PerformanceProfiling,

    [ValidateRange(1024, 65535)]
    [int]$HttpsPort = 8443
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
if ($env:KEEP_LOAD_TEST_FIXTURES -eq 'true') {
    $KeepLoadTestFixtures = $true
    $KeepStack = $true
}
$matrix = @{
    10 = 1
    30 = 2
    50 = 2
    100 = 5
    200 = 10
    300 = 20
    400 = 20
    500 = 20
}

if ($Sessions -eq 0) {
    if (-not $matrix.ContainsKey($Students)) {
        throw 'Sessions must be specified for a Student count outside the documented matrix.'
    }
    $Sessions = [int]$matrix[$Students]
}
if ($Sessions -gt $Students) { throw 'Sessions cannot exceed Students.' }
if ($KeepLoadTestFixtures -and -not $KeepStack) {
    throw '-KeepLoadTestFixtures requires -KeepStack so retained fixtures remain in their disposable database.'
}

try { $target = [Uri]$TargetUrl } catch { throw 'TargetUrl must be an absolute HTTPS URL.' }
if (-not $target.IsAbsoluteUri -or $target.Scheme -ne 'https') {
    throw 'TargetUrl must be an absolute HTTPS URL.'
}
$isApprovedLocal = $target.Host -eq 'quiz.load.test' -and $target.Port -eq 443
$remoteOptIn = $AllowRemote.IsPresent -or $env:LOAD_TEST_ALLOW_REMOTE -eq 'true'
if (-not $isApprovedLocal -and -not $remoteOptIn) {
    throw 'Remote target rejected. Use the exact local https://quiz.load.test target or deliberately opt in with -AllowRemote / LOAD_TEST_ALLOW_REMOTE=true.'
}

$repositoryRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$loadTestingRoot = Join-Path $repositoryRoot 'load-testing'
$runtimeRoot = Join-Path $loadTestingRoot '.runtime'
$resultsRoot = Join-Path $loadTestingRoot 'results'
$stackEnvironmentPath = Join-Path $runtimeRoot 'stack.env'
$productionCompose = Join-Path $repositoryRoot 'compose.production.yaml'
$loadCompose = Join-Path $loadTestingRoot 'compose.load-test.yaml'
$projectName = 'codeland-quiz-load-test'
$failures = [System.Collections.Generic.List[string]]::new()
$stackStarted = $false
$observerContainerName = $null
$statsJob = $null
$k6ExitCode = -1
$correctnessPassed = $false
$cleanupPassed = $false
$provisioned = $false

function New-RandomHex {
    param([int]$Bytes)
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return ([BitConverter]::ToString($buffer)).Replace('-', '').ToLowerInvariant()
}

function Write-Utf8NoBom {
    param([string]$Path, [string]$Contents)
    $encoding = New-Object Text.UTF8Encoding($false)
    [IO.File]::WriteAllText($Path, $Contents, $encoding)
}

function Invoke-Compose {
    param(
        [Parameter(Mandatory)]
        [string[]]$Arguments,
        [switch]$Capture
    )
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = & docker compose @composeBase @Arguments 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw "docker compose failed with exit code $exitCode`: $($output -join [Environment]::NewLine)"
    }
    if ($Capture) { return ($output -join [Environment]::NewLine).Trim() }
    foreach ($line in $output) { Write-Host ([string]$line) }
}

function Add-RunLog {
    param([string]$Message)
    $line = '{0} {1}' -f [DateTimeOffset]::UtcNow.ToString('o'), $Message
    [IO.File]::AppendAllText($script:runLogPath, $line + [Environment]::NewLine)
    Write-Host $Message
}

New-Item -ItemType Directory -Force -Path $runtimeRoot, $resultsRoot | Out-Null
$runId = New-RandomHex 12
if ($Seed -eq 0) {
    $seedBytes = New-Object byte[] 4
    $seedGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $seedGenerator.GetBytes($seedBytes) } finally { $seedGenerator.Dispose() }
    $Seed = ([BitConverter]::ToUInt32($seedBytes, 0) % [int]::MaxValue) + 1
}
$runDirectory = Join-Path $resultsRoot $runId
$runtimeDirectory = Join-Path $runDirectory 'runtime'
New-Item -ItemType Directory -Force -Path $runDirectory, $runtimeDirectory | Out-Null
$runLogPath = Join-Path $runDirectory 'run.log'
$manifestPath = Join-Path $runDirectory 'manifest.json'
$credentialsPath = Join-Path $runtimeDirectory 'credentials.json'
$correctnessPath = Join-Path $runDirectory 'correctness.json'
$cleanupPath = Join-Path $runDirectory 'cleanup.json'
$statusPath = Join-Path $runDirectory 'run-status.json'
$environmentPath = Join-Path $runDirectory 'environment.json'
$observerStopPath = Join-Path $runDirectory 'observer.stop'
$statsStopPath = Join-Path $runDirectory 'docker-stats.stop'
$dockerStatsPath = Join-Path $runDirectory 'docker-stats.csv'
$containerLogPath = Join-Path $runDirectory 'containers.log'
$profilePath = Join-Path $runDirectory 'application-profile.json'
$gitCommit = (& git -C $repositoryRoot rev-parse HEAD).Trim()
$gitDirty = -not [string]::IsNullOrWhiteSpace(((& git -C $repositoryRoot status --porcelain) -join "`n"))
$gitShort = $gitCommit.Substring(0, 12)
$imageTag = "phase4a-$gitShort"

if ($UseExistingStack) {
    if (-not (Test-Path -LiteralPath $stackEnvironmentPath)) {
        throw 'Cannot reuse the load-test stack because load-testing/.runtime/stack.env is absent.'
    }
    $expectedProfilingValue = 'false'
    if ($PerformanceProfiling) { $expectedProfilingValue = 'true' }
    $profilingSetting = Get-Content -LiteralPath $stackEnvironmentPath |
        Where-Object { $_ -match '^PERFORMANCE_PROFILING_ENABLED=' } |
        Select-Object -First 1
    if ($profilingSetting -ne "PERFORMANCE_PROFILING_ENABLED=$expectedProfilingValue") {
        throw 'The existing stack profiling mode does not match -PerformanceProfiling. Start a fresh stack for the requested mode.'
    }
} else {
    if (Test-Path -LiteralPath $stackEnvironmentPath) {
        throw 'A load-test runtime already exists. Reuse it explicitly or run stop-load-test-stack.ps1 first.'
    }
    $tlsDirectory = Join-Path $runtimeRoot 'tls'
    New-Item -ItemType Directory -Force -Path $tlsDirectory | Out-Null
    $certificatePath = (Join-Path $tlsDirectory 'fullchain.pem').Replace('\', '/')
    $privateKeyPath = (Join-Path $tlsDirectory 'privkey.pem').Replace('\', '/')
    $stackLines = @(
        'SERVER_NAME=quiz.load.test'
        "CODELAND_IMAGE_TAG=$imageTag"
        "TLS_CERTIFICATE_PATH=$certificatePath"
        "TLS_PRIVATE_KEY_PATH=$privateKeyPath"
        'APP_NAME=CodeLand Quiz'
        'APP_ENV=production'
        'APP_URL=https://quiz.load.test'
        'DB_DATABASE=codeland_quiz'
        'DB_USERNAME=codeland_load_test'
        "DB_PASSWORD=$(New-RandomHex 24)"
        "MYSQL_ROOT_PASSWORD=$(New-RandomHex 24)"
        'ACCESS_TOKEN_COOKIE_NAME=codeland_access'
        'REFRESH_TOKEN_COOKIE_NAME=codeland_refresh'
        'COOKIE_PATH=/'
        'CSRF_TOKEN_COOKIE_NAME=codeland_csrf'
        "REFRESH_TOKEN_HASH_KEY=$(New-RandomHex 32)"
        "PARTICIPANT_TOKEN_SECRET=$(New-RandomHex 32)"
        'PARTICIPANT_TOKEN_TTL_SECONDS=86400'
        "JWT_SECRET=$(New-RandomHex 32)"
        'JWT_ALGORITHM=HS256'
        'JWT_EXPIRATION_MINUTES=60'
        'REFRESH_TOKEN_EXPIRATION_DAYS=7'
        'CSRF_TOKEN_EXPIRATION_MINUTES=120'
        'LOGIN_ATTEMPT_LIMIT=5'
        'LOGIN_LOCK_DURATION_MINUTES=15'
        'LOGIN_IP_ATTEMPT_LIMIT=100'
        'TRUSTED_PROXY_CIDRS=172.30.0.10/32'
        'WS_ALLOWED_ORIGINS=https://quiz.load.test'
        'WS_GAMEPLAY_MAX_FRAME_BYTES=16384'
        'WS_AUTH_ATTEMPT_LIMIT=3'
        'WS_AUTH_IP_ATTEMPT_LIMIT=1000'
        'WS_AUTH_IP_WINDOW_SECONDS=60'
        'WS_ANSWER_ATTEMPT_LIMIT=8'
        'WS_ANSWER_ATTEMPT_WINDOW_SECONDS=10'
        'WS_CONNECTION_LIMIT=2000'
        'WS_PENDING_CONNECTION_LIMIT=750'
        'WS_CONNECTION_PER_IP_LIMIT=750'
        'WS_HEARTBEAT_INTERVAL_SECONDS=25'
        'WS_STALE_TIMEOUT_SECONDS=75'
        'OPENSWOOLE_MAX_CONNECTIONS=4096'
        'OPENSWOOLE_MAX_COROUTINES=4096'
        'OPENSWOOLE_TRANSPORT_HEARTBEAT_CHECK_INTERVAL_SECONDS=30'
        'OPENSWOOLE_TRANSPORT_HEARTBEAT_IDLE_SECONDS=120'
        'COOKIE_SECURE=true'
        'COOKIE_HTTP_ONLY=true'
        'COOKIE_SAME_SITE=Strict'
        'MAX_UPLOAD_SIZE_MB=5'
        'DEFAULT_QUIZ_QUESTION_TIME_LIMIT_SECONDS=30'
        'MAXIMUM_NICKNAME_LENGTH=100'
        'ALLOWED_IMAGE_EXTENSIONS=jpg,jpeg,png,webp'
        'DEFAULT_PAGE_SIZE=10'
        'MAX_PAGE_SIZE=20'
        "LOAD_TEST_HTTPS_PORT=$HttpsPort"
        'LOAD_TEST_WS_HEARTBEAT_INTERVAL_SECONDS=25'
        'LOAD_TEST_WS_STALE_TIMEOUT_SECONDS=75'
        'LOAD_TEST_TRANSPORT_HEARTBEAT_CHECK_INTERVAL_SECONDS=30'
        'LOAD_TEST_TRANSPORT_HEARTBEAT_IDLE_SECONDS=120'
        'LOAD_TEST_OBSERVER_INTERVAL_MS=1000'
        'LOAD_TEST_OBSERVER_MAX_SECONDS=900'
        "PERFORMANCE_PROFILING_ENABLED=$($PerformanceProfiling.IsPresent.ToString().ToLowerInvariant())"
    )
    Write-Utf8NoBom $stackEnvironmentPath (($stackLines -join "`n") + "`n")
}

$composeBase = @(
    '--env-file', $stackEnvironmentPath,
    '--project-name', $projectName,
    '--file', $productionCompose,
    '--file', $loadCompose
)

Add-RunLog "Starting load-test run ${runId}: $Students Students, $Sessions Sessions, $($Mode.ToUpperInvariant())."

try {
    if (-not $UseExistingStack) {
        Add-RunLog 'Building the pinned production backend and Nginx images.'
        Invoke-Compose -Arguments @('build', 'backend', 'nginx')

        Add-RunLog 'Generating the ephemeral quiz.load.test TLS certificate.'
        $certificateOutputMount = "$runtimeRoot`:/output"
        $loadTestingInputMount = "$loadTestingRoot`:/load-testing:ro"
        & docker run --rm --volume $loadTestingInputMount --volume $certificateOutputMount --entrypoint php "codeland-quiz-backend:$imageTag" /load-testing/scripts/generate-certificate.php /output/tls
        if ($LASTEXITCODE -ne 0) { throw 'Ephemeral TLS certificate generation failed.' }
    }

    Add-RunLog 'Starting the isolated production-like HTTPS/WSS stack.'
    $stackStarted = $true
    Invoke-Compose -Arguments @('up', '--detach', '--wait', 'mysql', 'backend', 'nginx')

    $k6VersionOutput = Invoke-Compose -Arguments @('--profile', 'load-test-tools', 'run', '--no-deps', '--rm', 'k6', 'version') -Capture
    $k6Version = @(($k6VersionOutput -split "`r?`n") | Where-Object { $_ -match '^k6 v' })[-1]
    if ([string]::IsNullOrWhiteSpace($k6Version)) { throw 'The pinned k6 version could not be identified.' }
    $phpVersion = Invoke-Compose -Arguments @('exec', '-T', 'backend', 'php', '-r', 'echo PHP_VERSION;') -Capture
    $openswooleVersion = Invoke-Compose -Arguments @('exec', '-T', 'backend', 'php', '-r', "echo phpversion('openswoole');") -Capture
    $mysqlVersion = Invoke-Compose -Arguments @('exec', '-T', 'mysql', 'mysql', '--version') -Capture
    $nginxVersion = Invoke-Compose -Arguments @('exec', '-T', 'nginx', 'nginx', '-v') -Capture
    $mysqlMaxConnectionsText = Invoke-Compose -Arguments @(
        'exec', '-T', 'backend', 'php', '-r',
        "`$pdo = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo `$pdo->query('SELECT @@GLOBAL.max_connections')->fetchColumn();"
    ) -Capture
    $runtimeSnapshotText = Invoke-Compose -Arguments @('exec', '-T', 'backend', 'php', '-r', "echo file_get_contents('http://127.0.0.1:9501/internal/metrics');") -Capture
    $runtimeSnapshot = $runtimeSnapshotText | ConvertFrom-Json
    $dockerVersion = (& docker version --format '{{json .}}') | ConvertFrom-Json
    $dockerInfo = (& docker info --format '{{json .}}') | ConvertFrom-Json
    $composeVersion = (& docker compose version --short).Trim()
    $environmentMetadata = [ordered]@{
        runId = $runId
        capturedAt = [DateTimeOffset]::UtcNow.ToString('o')
        gitCommit = $gitCommit
        gitDirty = $gitDirty
        versions = [ordered]@{
            dockerClient = $dockerVersion.Client.Version
            dockerServer = $dockerVersion.Server.Version
            dockerCompose = $composeVersion
            k6 = $k6Version
            php = $phpVersion
            openswoole = $openswooleVersion
            mysql = $mysqlVersion
            nginx = $nginxVersion
        }
        docker = [ordered]@{
            operatingSystem = $dockerInfo.OperatingSystem
            architecture = $dockerInfo.Architecture
            cpuCount = $dockerInfo.NCPU
            memoryBytes = $dockerInfo.MemTotal
        }
        runtime = [ordered]@{
            workerNum = $runtimeSnapshot.configuration.worker_num
            maxConn = $runtimeSnapshot.configuration.max_conn
            maxCoroutine = $runtimeSnapshot.configuration.max_coroutine
            performanceProfilingEnabled = $PerformanceProfiling.IsPresent
        }
        mysql = [ordered]@{
            maxConnections = [int]$mysqlMaxConnectionsText
        }
    }
    Write-Utf8NoBom $environmentPath (($environmentMetadata | ConvertTo-Json -Depth 8) + "`n")

    Add-RunLog 'Provisioning synthetic fixtures through backend repositories/domain services.'
    $insideManifest = "/load-testing-results/$runId/manifest.json"
    $insideCredentials = "/load-testing-results/$runId/runtime/credentials.json"
    Invoke-Compose -Arguments @(
        'exec', '-T', 'backend', 'php', '/load-testing/fixtures/manage-fixtures.php', 'provision',
        "--manifest=$insideManifest",
        "--credentials=$insideCredentials",
        "--students=$Students",
        "--sessions=$Sessions",
        "--mode=$($Mode.ToUpperInvariant())",
        "--run-id=$runId",
        "--seed=$Seed",
        "--registered-percent=$RegisteredPercent",
        "--reconnect-percent=$ReconnectPercent",
        "--correct-ratio=$CorrectAnswerRatio",
        "--burst-majority-ratio=$BurstMajorityRatio",
        "--target-url=$TargetUrl",
        "--local-self-signed=$($isApprovedLocal.ToString().ToLowerInvariant())"
    )
    $provisioned = $true

    if ($Warmup) {
        Add-RunLog 'Running a separate, unrecorded warm-up.'
        Invoke-Compose -Arguments @(
            '--profile', 'load-test-tools', 'run', '--rm',
            '-e', "MANIFEST_PATH=/results/$runId/manifest.json",
            '-e', "CREDENTIALS_PATH=/results/$runId/runtime/credentials.json",
            '-e', "TARGET_URL=$TargetUrl",
            'k6', 'run', '/scripts/warmup.js'
        )
    }

    Add-RunLog 'Resetting the private bounded application profile immediately before recorded load.'
    $profileResetText = Invoke-Compose -Arguments @(
        'exec', '-T', 'backend', 'php', '-r',
        "`$context = stream_context_create(['http' => ['method' => 'POST', 'content' => '']]); `$result = file_get_contents('http://127.0.0.1:9501/internal/profile/reset', false, `$context); if (`$result === false) { exit(1); } echo `$result;"
    ) -Capture
    $profileReset = $profileResetText | ConvertFrom-Json
    if ([bool]$profileReset.enabled -ne $PerformanceProfiling.IsPresent -or -not [bool]$profileReset.reset) {
        throw 'Application profile reset returned an unexpected profiling mode or reset state.'
    }

    Add-RunLog 'Starting private runtime/MySQL observation and host-side Docker stats.'
    $env:LOAD_TEST_RUN_ID = $runId
    $observerContainerName = "$projectName-observer-$($runId.Substring(0, 8))"
    $observerContainerId = Invoke-Compose -Arguments @(
        '--profile', 'load-test-tools', 'run', '--detach',
        '--name', $observerContainerName,
        'observer'
    ) -Capture
    $statsScript = Join-Path $PSScriptRoot 'collect-docker-stats.ps1'
    $statsJob = Start-Job -FilePath $statsScript -ArgumentList @(
        $projectName, $dockerStatsPath, $statsStopPath, 1, 900
    )

    $leadInMs = 15000
    $testStartEpochMs = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds() + $leadInMs
    $manifestForSchedule = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $manifestForSchedule | Add-Member -NotePropertyName scheduledStartEpochMs -NotePropertyValue $testStartEpochMs -Force
    $manifestForSchedule | Add-Member -NotePropertyName scheduledStartAt -NotePropertyValue ([DateTimeOffset]::FromUnixTimeMilliseconds($testStartEpochMs).ToString('o')) -Force
    Write-Utf8NoBom $manifestPath (($manifestForSchedule | ConvertTo-Json -Depth 30) + "`n")
    Add-RunLog "Running k6 through Nginx at $TargetUrl."
    $k6ContainerName = "$projectName-k6-$($runId.Substring(0, 8))"
    $k6Arguments = @(
        '--profile', 'load-test-tools', 'run', '--rm',
        '--name', $k6ContainerName,
        '-e', "RUN_ID=$runId",
        '-e', "MANIFEST_PATH=/results/$runId/manifest.json",
        '-e', "CREDENTIALS_PATH=/results/$runId/runtime/credentials.json",
        '-e', "SUMMARY_PATH=/results/$runId/k6-summary.json",
        '-e', "TARGET_URL=$TargetUrl",
        '-e', "TEST_START_EPOCH_MS=$testStartEpochMs",
        'k6', 'run', '--out', "json=/results/$runId/k6-raw.json", '/scripts/main.js'
    )
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $k6Output = & docker compose @composeBase @k6Arguments 2>&1
        $k6ExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    foreach ($line in $k6Output) {
        $lineText = [string]$line
        [IO.File]::AppendAllText($runLogPath, $lineText + [Environment]::NewLine)
        Write-Host $lineText
    }
    if ($k6ExitCode -ne 0) { $failures.Add("k6 exited with code $k6ExitCode.") }
} catch {
    $failures.Add($_.Exception.Message)
    Add-RunLog "Run-stage failure: $($_.Exception.Message)"
} finally {
    if ($stackStarted) {
        try {
            $profileSnapshotText = Invoke-Compose -Arguments @(
                'exec', '-T', 'backend', 'php', '-r',
                "echo file_get_contents('http://127.0.0.1:9501/internal/profile');"
            ) -Capture
            $profileSnapshot = $profileSnapshotText | ConvertFrom-Json
            if ([bool]$profileSnapshot.enabled -ne $PerformanceProfiling.IsPresent) {
                throw 'Captured application profile mode did not match the requested run mode.'
            }
            Write-Utf8NoBom $profilePath (($profileSnapshot | ConvertTo-Json -Depth 30) + "`n")
        } catch {
            $failures.Add("Application profile capture failed: $($_.Exception.Message)")
        }
    }

    if ($statsJob -ne $null) {
        Write-Utf8NoBom $statsStopPath "stop`n"
        Wait-Job -Job $statsJob -Timeout 20 | Out-Null
        Receive-Job -Job $statsJob -ErrorAction SilentlyContinue | Out-Null
        Remove-Job -Job $statsJob -Force -ErrorAction SilentlyContinue
    }
    if ($observerContainerName) {
        Write-Utf8NoBom $observerStopPath "stop`n"
        $deadline = [DateTimeOffset]::UtcNow.AddSeconds(20)
        do {
            Start-Sleep -Milliseconds 500
            $stillRunning = & docker ps --quiet --filter "name=^/$observerContainerName$"
        } while ($stillRunning -and [DateTimeOffset]::UtcNow -lt $deadline)
        if ($stillRunning) { & docker stop --time 5 $observerContainerName | Out-Null }
        $observerExists = & docker ps --all --quiet --filter "name=^/$observerContainerName$"
        if ($observerExists) {
            $observerExitCode = [int]((& docker inspect --format '{{.State.ExitCode}}' $observerContainerName).Trim())
            if ($observerExitCode -ne 0) {
                $observerLogs = & docker logs $observerContainerName 2>&1
                $failures.Add("Observer exited with code $observerExitCode`: $($observerLogs -join [Environment]::NewLine)")
            }
            & docker rm --force $observerContainerName | Out-Null
        } else {
            $failures.Add('Observer container disappeared before its exit status could be verified.')
        }
    }
    Remove-Item -LiteralPath $observerStopPath, $statsStopPath -Force -ErrorAction SilentlyContinue

    if ($stackStarted) {
        try {
            $logs = Invoke-Compose -Arguments @('logs', '--no-color', 'backend', 'nginx', 'mysql') -Capture
            Write-Utf8NoBom $containerLogPath (($logs -join [Environment]::NewLine) + [Environment]::NewLine)
        } catch { $failures.Add("Container diagnostics failed: $($_.Exception.Message)") }
    }

    if ($stackStarted -and (Test-Path -LiteralPath $manifestPath)) {
        $insideManifest = "/load-testing-results/$runId/manifest.json"
        try {
            Invoke-Compose -Arguments @('exec', '-T', 'backend', 'php', '/load-testing/fixtures/manage-fixtures.php', 'finalize', "--manifest=$insideManifest")
        } catch { $failures.Add("Manifest finalization failed: $($_.Exception.Message)") }
        try {
            Invoke-Compose -Arguments @(
                'exec', '-T', 'backend', 'php', '/load-testing/fixtures/verify-correctness.php',
                "--manifest=$insideManifest",
                "--output=/load-testing-results/$runId/correctness.json"
            )
            $correctnessPassed = $true
        } catch { $failures.Add("Correctness verification failed: $($_.Exception.Message)") }

        if (-not $KeepLoadTestFixtures) {
            try {
                Add-RunLog 'Removing only resource IDs listed in this run manifest.'
                Invoke-Compose -Arguments @('exec', '-T', 'backend', 'php', '/load-testing/fixtures/manage-fixtures.php', 'cleanup', "--manifest=$insideManifest")
                Invoke-Compose -Arguments @(
                    'exec', '-T', 'backend', 'php', '/load-testing/fixtures/manage-fixtures.php', 'verify-clean',
                    "--manifest=$insideManifest",
                    "--output=/load-testing-results/$runId/cleanup.json"
                )
                $cleanupPassed = $true
            } catch { $failures.Add("Fixture cleanup failed: $($_.Exception.Message)") }
        } else {
            Add-RunLog 'KEEP_LOAD_TEST_FIXTURES requested; synthetic fixtures were retained in the disposable load-test environment.'
        }
    }

    foreach ($artifact in @(
        $manifestPath,
        $environmentPath,
        (Join-Path $runDirectory 'k6-summary.json'),
        (Join-Path $runDirectory 'k6-raw.json'),
        (Join-Path $runDirectory 'runtime-metrics.csv'),
        (Join-Path $runDirectory 'mysql-metrics.csv'),
        $dockerStatsPath,
        $profilePath,
        $correctnessPath,
        $cleanupPath
    )) {
        if (-not (Test-Path -LiteralPath $artifact) -or (Get-Item -LiteralPath $artifact).Length -eq 0) {
            $failures.Add("Required artifact is absent or empty: $artifact")
        }
    }
    foreach ($csvName in @('runtime-metrics.csv', 'mysql-metrics.csv', 'docker-stats.csv')) {
        $csvPath = Join-Path $runDirectory $csvName
        if (Test-Path -LiteralPath $csvPath) {
            try {
                if (@(Import-Csv -LiteralPath $csvPath).Count -lt 1) {
                    $failures.Add("Required metric artifact contains no samples: $csvPath")
                }
            } catch {
                $failures.Add("Required metric artifact is unreadable: $csvPath")
            }
        }
    }
    if (Test-Path -LiteralPath $dockerStatsPath) {
        try {
            $observedServices = @(Import-Csv -LiteralPath $dockerStatsPath | Select-Object -ExpandProperty service -Unique)
            foreach ($requiredService in @('backend', 'mysql', 'nginx', 'k6')) {
                if ($requiredService -notin $observedServices) {
                    $failures.Add("Docker stats did not sample required service: $requiredService")
                }
            }
        } catch {
            $failures.Add("Docker stats service coverage could not be verified: $($_.Exception.Message)")
        }
    }

    $status = [ordered]@{
        runId = $runId
        completedAt = [DateTimeOffset]::UtcNow.ToString('o')
        k6ExitCode = $k6ExitCode
        correctnessPassed = $correctnessPassed
        cleanupPassed = $cleanupPassed
        fixturesKept = $KeepLoadTestFixtures.IsPresent
        warmed = $Warmup.IsPresent
        performanceProfilingEnabled = $PerformanceProfiling.IsPresent
        failures = @($failures)
    }
    Write-Utf8NoBom $statusPath (($status | ConvertTo-Json -Depth 5) + "`n")

    Remove-Item -LiteralPath $credentialsPath -Force -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $runtimeDirectory) {
        Remove-Item -LiteralPath $runtimeDirectory -Force -Recurse -ErrorAction SilentlyContinue
    }

    if ($stackStarted -and (Test-Path -LiteralPath $manifestPath)) {
        try {
            Invoke-Compose -Arguments @(
                'exec', '-T', 'backend', 'php', '/load-testing/report/generate-report.php',
                "/load-testing-results/$runId"
            )
        } catch { $failures.Add("Report generation failed: $($_.Exception.Message)") }
    }

    if ($stackStarted -and -not $KeepStack) {
        try { Invoke-Compose -Arguments @('down', '--volumes', '--remove-orphans') }
        catch { $failures.Add("Load-test stack shutdown failed: $($_.Exception.Message)") }
        $stackStarted = $false
    }
    if (-not $KeepStack) {
        Remove-Item -LiteralPath $runtimeRoot -Force -Recurse -ErrorAction SilentlyContinue
    }
    Remove-Item Env:LOAD_TEST_RUN_ID -ErrorAction SilentlyContinue

    $finalStatus = [ordered]@{
        runId = $runId
        completedAt = [DateTimeOffset]::UtcNow.ToString('o')
        k6ExitCode = $k6ExitCode
        correctnessPassed = $correctnessPassed
        cleanupPassed = $cleanupPassed
        fixturesKept = $KeepLoadTestFixtures.IsPresent
        warmed = $Warmup.IsPresent
        performanceProfilingEnabled = $PerformanceProfiling.IsPresent
        failures = @($failures)
    }
    Write-Utf8NoBom $statusPath (($finalStatus | ConvertTo-Json -Depth 5) + "`n")
}

$valid = $k6ExitCode -eq 0 -and $correctnessPassed -and $cleanupPassed -and $failures.Count -eq 0
if (-not $valid) {
    throw "Load-test run $runId is INVALID. Diagnostics and report are in $runDirectory."
}

Write-Host "Load-test run $runId completed successfully."
Write-Host "Result: $runDirectory"
