[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$ProjectName,

    [Parameter(Mandatory)]
    [string]$OutputPath,

    [Parameter(Mandatory)]
    [string]$StopPath,

    [ValidateRange(1, 60)]
    [int]$IntervalSeconds = 1,

    [ValidateRange(30, 3600)]
    [int]$MaximumSeconds = 900
)

$ErrorActionPreference = 'Stop'

function Convert-DockerSizeToBytes {
    param([string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) { return $null }
    if ($Value -notmatch '^\s*([0-9.]+)\s*([KMGT]?i?B)\s*$') { return $null }
    $number = [double]$Matches[1]
    $unit = $Matches[2]
    $multiplier = switch ($unit) {
        'B' { 1 }
        'kB' { 1000 }
        'KB' { 1000 }
        'KiB' { 1024 }
        'MB' { 1000 * 1000 }
        'MiB' { 1024 * 1024 }
        'GB' { 1000 * 1000 * 1000 }
        'GiB' { 1024 * 1024 * 1024 }
        'TB' { 1000L * 1000 * 1000 * 1000 }
        'TiB' { 1024L * 1024 * 1024 * 1024 }
        default { return $null }
    }
    return [long][math]::Round($number * $multiplier)
}

$rows = [System.Collections.Generic.List[object]]::new()
$startedAt = [DateTimeOffset]::UtcNow

while (-not (Test-Path -LiteralPath $StopPath) -and
       ([DateTimeOffset]::UtcNow - $startedAt).TotalSeconds -lt $MaximumSeconds) {
    $timestamp = [DateTimeOffset]::UtcNow.ToString('o')
    $lines = & docker stats --all --no-stream --format '{{json .}}' 2>$null

    foreach ($line in $lines) {
        if ([string]::IsNullOrWhiteSpace($line)) { continue }
        try { $sample = $line | ConvertFrom-Json } catch { continue }
        if ($sample.Name -notlike "$ProjectName-*") { continue }

        $service = switch -Regex ($sample.Name) {
            '-nginx-' { 'nginx'; break }
            '-backend-' { 'backend'; break }
            '-mysql-' { 'mysql'; break }
            '-k6-' { 'k6'; break }
            '-observer-' { 'observer'; break }
            default { 'unknown' }
        }
        $memoryParts = [string]$sample.MemUsage -split '\s*/\s*'
        $rows.Add([pscustomobject]@{
            timestamp = $timestamp
            service = $service
            containerName = [string]$sample.Name
            containerId = [string]$sample.Container
            cpuPercent = ([string]$sample.CPUPerc).TrimEnd('%')
            memoryUsageBytes = Convert-DockerSizeToBytes $memoryParts[0]
            memoryLimitBytes = if ($memoryParts.Count -gt 1) { Convert-DockerSizeToBytes $memoryParts[1] } else { $null }
            memoryPercent = ([string]$sample.MemPerc).TrimEnd('%')
            networkIo = [string]$sample.NetIO
            blockIo = [string]$sample.BlockIO
            pids = [string]$sample.PIDs
        })
    }

    Start-Sleep -Seconds $IntervalSeconds
}

$rows | Export-Csv -LiteralPath $OutputPath -NoTypeInformation -Encoding utf8
