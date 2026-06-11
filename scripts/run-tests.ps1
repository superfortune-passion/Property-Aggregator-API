# Run PHPUnit tests

Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host "Running tests..." -ForegroundColor Cyan
php vendor\bin\phpunit
