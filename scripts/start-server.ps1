# Start the Symfony API on http://localhost:8000
# Keep this window open while testing.

Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host "Starting Property Aggregator API..." -ForegroundColor Cyan
Write-Host "URL: http://127.0.0.1:8000/properties" -ForegroundColor Green
Write-Host "Press Ctrl+C to stop the server." -ForegroundColor Yellow
Write-Host ""

php -S 127.0.0.1:8000 -t public
