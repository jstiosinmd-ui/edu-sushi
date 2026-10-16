param(
    [Parameter(Mandatory=$true)][ValidatePattern('^[a-f0-9]{40}$')][string]$Commit,
    [Parameter(Mandatory=$true)][string]$ProjectId,
    [Parameter(Mandatory=$true)][string]$ServiceId,
    [string]$Railway = 'railway',
    [switch]$ApproveProduction
)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
function Checked-Git([string[]]$Arguments) {
    $result = & git @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Git check failed.' }
    return $result
}
if ((Checked-Git @('rev-parse','HEAD')).Trim() -ne $Commit) { throw 'Checkout does not match the approved commit.' }
if (Checked-Git @('status','--porcelain')) { throw 'Commit or remove working changes before releasing.' }
$origin = (Checked-Git @('remote','get-url','origin')).Trim()
if ($origin -notmatch 'github\.com[:/]jstiosinmd-ui/edu-sushi(\.git)?$') { throw 'Unexpected repository.' }
$remoteHead = Checked-Git @('ls-remote','origin','refs/heads/main')
if (($remoteHead -split '\s+')[0] -ne $Commit) { throw 'Origin main does not match the approved commit.' }
$runsJson = & gh api "repos/jstiosinmd-ui/edu-sushi/actions/workflows/laravel.yml/runs?head_sha=$Commit&per_page=20"
if ($LASTEXITCODE -ne 0) { throw 'Cannot verify CI status.' }
$run = ($runsJson | ConvertFrom-Json).workflow_runs | Where-Object { $_.head_sha -eq $Commit -and $_.event -eq 'push' } | Sort-Object id -Descending | Select-Object -First 1
if (-not $run -or $run.status -ne 'completed' -or $run.conclusion -ne 'success') { throw 'The latest CI run for this commit must pass.' }
$jobsJson = & gh api "repos/jstiosinmd-ui/edu-sushi/actions/runs/$($run.id)/attempts/$($run.run_attempt)/jobs"
if ($LASTEXITCODE -ne 0) { throw 'Cannot verify CI jobs.' }
$jobs = ($jobsJson | ConvertFrom-Json).jobs
foreach ($name in @('test','container')) {
    if (-not ($jobs | Where-Object { $_.name -eq $name -and $_.conclusion -eq 'success' })) { throw "Required CI job $name did not pass." }
}
Write-Output "Verified commit $Commit and CI run $($run.id)."
if (-not $ApproveProduction) { Write-Output 'Check only. Production approval was not supplied; no deployment started.'; exit 0 }
# Check the existing Railway link before uploading the exact tested checkout.
$statusJson = & $Railway status --json
if ($LASTEXITCODE -ne 0) { throw 'Railway status failed.' }
$status = $statusJson | ConvertFrom-Json
if ($status.id -ne $ProjectId) { throw 'Railway is linked to a different project.' }
if (-not ($status.services.edges | Where-Object { $_.node.id -eq $ServiceId })) { throw 'The service does not belong to this project.' }
& $Railway up --ci --service $ServiceId --environment production
if ($LASTEXITCODE -ne 0) { throw 'Railway deployment failed. Inspect the deployment before retrying.' }
