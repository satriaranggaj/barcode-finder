<#
.SYNOPSIS
    Rebuild the Lensku object-centric FAISS index on Windows.

.DESCRIPTION
    Runs the ai-service index builder with the project virtualenv (falls back to
    the system Python). Publishing is atomic and failure-safe: a failed build
    leaves the currently published generation untouched.

.PARAMETER Dataset
    Path to the reference dataset (default: dataset).

.PARAMETER Append
    Incremental append instead of a full rebuild. The build still refuses
    incompatible pipeline/model changes.

.PARAMETER ReferenceLimitPerSku
    Optional cap on photos per SKU (anti-count-bias for ablations).

.EXAMPLE
    .\rebuild-index.ps1

.EXAMPLE
    .\rebuild-index.ps1 -Dataset D:\data\lensku-dataset -Append
#>
[CmdletBinding()]
param(
    [string]$Dataset = 'dataset',
    [switch]$Append,
    [int]$ReferenceLimitPerSku = 0
)

$ErrorActionPreference = 'Stop'
Set-Location -Path (Split-Path -Parent $MyInvocation.MyCommand.Path)

$venvPython = Join-Path $PWD 'venv\Scripts\python.exe'
$python = if (Test-Path $venvPython) { $venvPython } else { 'python' }

$arguments = @('-m', 'app.scripts.build_index', '--dataset', $Dataset)
if (-not $Append) { $arguments += '--rebuild' }
if ($ReferenceLimitPerSku -gt 0) { $arguments += '--reference-limit-per-sku', "$ReferenceLimitPerSku" }

Write-Host "Running: $python $($arguments -join ' ')"
& $python @arguments
if ($LASTEXITCODE -ne 0) {
    Write-Error "Index build failed (exit code $LASTEXITCODE). The previously published index remains in use."
    exit $LASTEXITCODE
}
Write-Host 'Index build completed; CURRENT now points to the new verified generation.'
