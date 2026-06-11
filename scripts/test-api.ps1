# Test the API (no login required)
# Usage:
#   .\scripts\test-api.ps1
#   .\scripts\test-api.ps1 -Page 2 -Limit 5

param(
    [string]$BaseUrl = "http://127.0.0.1:8000",
    [int]$Page = 1,
    [int]$Limit = 5
)

Write-Host "Fetching /properties?page=$Page&limit=$Limit ..." -ForegroundColor Cyan

try {
    $properties = Invoke-RestMethod -Uri "$BaseUrl/properties?page=$Page&limit=$Limit"
} catch {
    Write-Host "Could not connect to $BaseUrl" -ForegroundColor Red
    Write-Host "Start the server first: .\scripts\start-server.ps1" -ForegroundColor Yellow
    exit 1
}

$properties | ConvertTo-Json -Depth 6

Write-Host ""
Write-Host "Summary:" -ForegroundColor Cyan
Write-Host "  Items returned : $($properties.data.Count)"
Write-Host "  Total          : $($properties.meta.total)"
Write-Host "  Cached         : $($properties.meta.cached)"
Write-Host "  Sources loaded : $($properties.meta.sources_loaded -join ', ')"
