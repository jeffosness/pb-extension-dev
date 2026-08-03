# Replays a captured PhoneBurner dial-session payload to Salt local
# for shape-verification testing (2026-08-03 arc).
#
# Usage:
#   1. Launch a dial session from HubSpot with the extension pointed at dev.
#      The dev backend writes /tmp/pb_dialsession_last.json (see PR #199).
#   2. SCP the file to your local machine:
#        scp <user>@<dev-host>:/tmp/pb_dialsession_last.json .
#   3. Run this script:
#        .\replay-dialsession-to-salt.ps1 -PayloadFile .\pb_dialsession_last.json -Pat "yzjeSPlD6KhLeU1ozlFj1UYHBxNOS8QXXMXBI1i4"
#
# The script strips the {source, payload} debug wrapper and POSTs just the
# inner payload (exactly what our backend sends to PB) to Salt local.

param(
    [Parameter(Mandatory=$true)]
    [string]$PayloadFile,

    [Parameter(Mandatory=$true)]
    [string]$Pat,

    [string]$TargetUrl = "https://local-dev.phoneburner.com/rest/1/dialsession"
)

if (-not (Test-Path $PayloadFile)) {
    Write-Error "Payload file not found: $PayloadFile"
    exit 1
}

$raw = Get-Content $PayloadFile -Raw
$wrapped = $raw | ConvertFrom-Json

if (-not $wrapped.payload) {
    Write-Error "File does not contain the expected {source, payload} wrapper. Got keys: $($wrapped.PSObject.Properties.Name -join ', ')"
    exit 1
}

$innerJson = $wrapped.payload | ConvertTo-Json -Depth 20 -Compress:$false

Write-Host ""
Write-Host "Source (which HS endpoint captured this): $($wrapped.source)"
Write-Host "Target URL: $TargetUrl"
Write-Host "Payload keys: $($wrapped.payload.PSObject.Properties.Name -join ', ')"
if ($wrapped.payload.custom_data) {
    Write-Host "custom_data keys: $($wrapped.payload.custom_data.PSObject.Properties.Name -join ', ')"
    if ($wrapped.payload.custom_data.hs_owner_id) {
        Write-Host "  hs_owner_id: $($wrapped.payload.custom_data.hs_owner_id)"
    }
    if ($wrapped.payload.custom_data.hs_hub_id) {
        Write-Host "  hs_hub_id:   $($wrapped.payload.custom_data.hs_hub_id)"
    }
}
Write-Host ""

$headers = @{
    'Authorization' = "Bearer $Pat"
    'Content-Type'  = 'application/json'
    'Accept'        = 'application/json'
}

Write-Host "POSTing to $TargetUrl ..."
try {
    $response = Invoke-WebRequest -Uri $TargetUrl -Method POST -Headers $headers -Body $innerJson -UseBasicParsing
    Write-Host ""
    Write-Host "Status: $($response.StatusCode) $($response.StatusDescription)"
    Write-Host "Response body:"
    Write-Host $response.Content
} catch {
    Write-Host ""
    Write-Host "Request FAILED"
    if ($_.Exception.Response) {
        $statusCode = [int]$_.Exception.Response.StatusCode
        Write-Host "HTTP status: $statusCode"
        $stream = $_.Exception.Response.GetResponseStream()
        $reader = New-Object System.IO.StreamReader($stream)
        $body = $reader.ReadToEnd()
        Write-Host "Response body:"
        Write-Host $body
    } else {
        Write-Host $_.Exception.Message
    }
    exit 1
}
