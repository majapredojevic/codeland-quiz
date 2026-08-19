[CmdletBinding()]
param(
    [int[]]$ClassroomLevels = @(10, 30, 50, 100, 200, 300, 400, 500),

    [switch]$IncludeBurst,

    [int[]]$BurstLevels = @(100, 300, 500),

    [ValidateRange(0, 900)]
    [int]$CooldownSeconds = 30,

    [switch]$Warmup,

    [ValidateRange(0, 100)]
    [double]$RegisteredPercent = 50,

    [ValidateRange(0, 100)]
    [double]$ReconnectPercent = 5,

    [ValidateRange(0, 1)]
    [double]$CorrectAnswerRatio = 0.70,

    [ValidateRange(0, 1)]
    [double]$BurstMajorityRatio = 0.85
)

$ErrorActionPreference = 'Stop'
$supportedLevels = @(10, 30, 50, 100, 200, 300, 400, 500)
$runs = [System.Collections.Generic.List[object]]::new()

foreach ($level in $ClassroomLevels) {
    if ($level -notin $supportedLevels) {
        throw "Unsupported CLASSROOM preset: $level. Use run-load-test.ps1 with explicit -Students/-Sessions for experiments."
    }
    $runs.Add([pscustomobject]@{ Students = $level; Mode = 'classroom' })
}
if ($IncludeBurst) {
    foreach ($level in $BurstLevels) {
        if ($level -notin $supportedLevels) {
            throw "Unsupported BURST preset: $level. Use run-load-test.ps1 with explicit -Students/-Sessions for experiments."
        }
        $runs.Add([pscustomobject]@{ Students = $level; Mode = 'burst' })
    }
}
if ($runs.Count -eq 0) { throw 'The selected matrix contains no runs.' }

$singleRunner = Join-Path $PSScriptRoot 'run-load-test.ps1'
$stopRunner = Join-Path $PSScriptRoot 'stop-load-test-stack.ps1'
$stackCreated = $false

try {
    for ($index = 0; $index -lt $runs.Count; $index++) {
        $run = $runs[$index]
        $isLast = $index -eq $runs.Count - 1
        $arguments = @{
            Students = $run.Students
            Mode = $run.Mode
            RegisteredPercent = $RegisteredPercent
            ReconnectPercent = $ReconnectPercent
            CorrectAnswerRatio = $CorrectAnswerRatio
            BurstMajorityRatio = $BurstMajorityRatio
        }
        if ($Warmup) { $arguments.Warmup = $true }
        if ($stackCreated) { $arguments.UseExistingStack = $true }
        if (-not $isLast) { $arguments.KeepStack = $true }

        Write-Host "Matrix step $($index + 1)/$($runs.Count): $($run.Mode.ToUpperInvariant()) $($run.Students) Students."
        & $singleRunner @arguments
        if ($LASTEXITCODE -ne 0) {
            throw "Matrix stopped because $($run.Mode) $($run.Students) failed."
        }
        $stackCreated = -not $isLast

        if (-not $isLast -and $CooldownSeconds -gt 0) {
            Write-Host "Cooling down for $CooldownSeconds seconds without restarting the application."
            Start-Sleep -Seconds $CooldownSeconds
        }
    }
} catch {
    Write-Warning $_.Exception.Message
    if (Test-Path -LiteralPath (Join-Path $PSScriptRoot '..\.runtime\stack.env')) {
        & $stopRunner
    }
    exit 1
}

Write-Host 'Selected matrix completed; every level passed correctness and cleanup before the next began.'
