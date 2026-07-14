<#
.SYNOPSIS
  Build the Chrome Web Store submission zip for the PhoneBurner extension.

.DESCRIPTION
  Zips the chrome-extension/ directory into "version X.Y.Z.zip" and drops it
  in the webstore-resources folder on the user's local machine. Reads the
  version from chrome-extension/manifest.json by default.

  Excludes files that aren't part of the extension runtime (e.g. STORE_LISTING.md,
  hidden files). Chrome Web Store rejects archives containing __MACOSX or other
  noise, so we copy a clean subset to a temp dir before compressing.

.PARAMETER Version
  Optional. The version string to use in the zip filename. If supplied, must match
  the version in chrome-extension/manifest.json — guards against accidentally
  shipping a zip whose filename lies about its contents. If omitted, the manifest
  version is used.

.PARAMETER OutputDir
  Optional. Directory where the zip is written. Defaults to Jeff's primary-machine
  webstore-resources folder. Pass an absolute path here when running on another
  machine.

.PARAMETER Force
  Overwrite an existing zip with the same name. Without -Force the script aborts
  if the target file already exists.

.EXAMPLE
  pwsh ./scripts/build-webstore-zip.ps1
  # Reads version from manifest.json, writes to default OutputDir.

.EXAMPLE
  pwsh ./scripts/build-webstore-zip.ps1 -Version 0.6.4
  # Same as above but asserts the manifest version is 0.6.4.

.EXAMPLE
  pwsh ./scripts/build-webstore-zip.ps1 -OutputDir "C:\temp" -Force
  # Build into a custom directory, overwriting any existing zip.

.NOTES
  After this script finishes, upload the zip at
  https://chrome.google.com/webstore/devconsole — choose the existing extension,
  click "Upload new package", and paste the updated description from
  chrome-extension/STORE_LISTING.md.
#>

[CmdletBinding()]
param(
    [string]$Version = "",
    [string]$OutputDir = "D:\Camtasia Studio\Phone Burner\webstore resources",
    [switch]$Force
)

$ErrorActionPreference = "Stop"

# -----------------------------------------------------------------------------
# Resolve paths
# -----------------------------------------------------------------------------
$repoRoot     = (Get-Item $PSScriptRoot).Parent.FullName
$extensionDir = Join-Path $repoRoot "chrome-extension"
$manifestPath = Join-Path $extensionDir "manifest.json"

if (-not (Test-Path $manifestPath)) {
    throw "manifest.json not found at $manifestPath"
}

# -----------------------------------------------------------------------------
# Read + validate version
# -----------------------------------------------------------------------------
$manifest        = Get-Content $manifestPath -Raw | ConvertFrom-Json
$manifestVersion = [string]$manifest.version

if ($Version -eq "") {
    $Version = $manifestVersion
    Write-Host "Version: $Version (from manifest.json)"
} elseif ($Version -ne $manifestVersion) {
    throw ("Version mismatch: -Version arg is '$Version' but manifest.json says '$manifestVersion'. " +
           "Bump manifest.json first, or run without -Version to use the manifest value.")
} else {
    Write-Host "Version: $Version (matches manifest.json)"
}

# -----------------------------------------------------------------------------
# Validate output directory
# -----------------------------------------------------------------------------
if (-not (Test-Path $OutputDir)) {
    throw ("Output directory does not exist: $OutputDir`n" +
           "Pass -OutputDir <path> to override the default, or create the directory first.")
}

$zipName = "version $Version.zip"
$zipPath = Join-Path $OutputDir $zipName

if ((Test-Path $zipPath) -and -not $Force) {
    throw "Zip already exists at $zipPath. Pass -Force to overwrite."
}

# -----------------------------------------------------------------------------
# Stage files into a temp dir so we control exactly what goes into the zip.
# Chrome Web Store rejects archives with __MACOSX, hidden files, or extraneous
# docs, so we copy only the runtime files explicitly.
#
# This list should match what's actually loaded by manifest.json — when you add
# a new top-level file or directory to chrome-extension/, add it here too.
# -----------------------------------------------------------------------------
$includeNames = @(
    "manifest.json",
    "background.js",
    "popup.html",
    "popup.js",
    "changelog.js",
    "content.js",
    "crm_config.js",
    "softphone_config.js",
    "icons"
)

# Confirm everything we want to include actually exists.
foreach ($name in $includeNames) {
    $src = Join-Path $extensionDir $name
    if (-not (Test-Path $src)) {
        throw "Expected file or directory missing from chrome-extension/: $name"
    }
}

# Sanity check: warn if there are top-level files in chrome-extension/ that we're
# NOT including. Avoids silently shipping an extension that's missing a new file.
$actualEntries = Get-ChildItem $extensionDir -Force | Where-Object { $_.Name -ne "STORE_LISTING.md" }
foreach ($entry in $actualEntries) {
    if ($includeNames -notcontains $entry.Name) {
        Write-Warning ("chrome-extension/ contains '$($entry.Name)' but it is NOT in the include list. " +
                       "If it's required at runtime, add it to `$includeNames in this script.")
    }
}

$stageDir = Join-Path ([System.IO.Path]::GetTempPath()) ("pb-extension-zip-" + [System.Guid]::NewGuid().ToString("N").Substring(0, 8))
New-Item -ItemType Directory -Path $stageDir | Out-Null

try {
    Write-Host "Staging in: $stageDir"
    foreach ($name in $includeNames) {
        $src = Join-Path $extensionDir $name
        $dst = Join-Path $stageDir $name
        if ((Get-Item $src).PSIsContainer) {
            Copy-Item -Path $src -Destination $dst -Recurse
        } else {
            Copy-Item -Path $src -Destination $dst
        }
    }

    # -------------------------------------------------------------------------
    # Compress staged files into the zip.
    # -DestinationPath is the .zip file itself. We compress the staged contents
    # without including the staging dir itself as a top-level folder.
    # -------------------------------------------------------------------------
    if (Test-Path $zipPath) {
        Remove-Item $zipPath -Force
    }
    Compress-Archive -Path (Join-Path $stageDir "*") -DestinationPath $zipPath -CompressionLevel Optimal
} finally {
    if (Test-Path $stageDir) {
        Remove-Item $stageDir -Recurse -Force
    }
}

# -----------------------------------------------------------------------------
# Report
# -----------------------------------------------------------------------------
$zipSize   = (Get-Item $zipPath).Length
$zipSizeKB = [math]::Round($zipSize / 1KB, 1)

Write-Host ""
Write-Host "Build complete:"
Write-Host "  Zip:  $zipPath"
Write-Host "  Size: $zipSizeKB KB"
Write-Host ""
Write-Host "Next steps:"
Write-Host "  1. Open https://chrome.google.com/webstore/devconsole"
Write-Host "  2. Choose the PhoneBurner Dial Session Companion listing"
Write-Host "  3. Click 'Upload new package' and select the zip above"
Write-Host "  4. Update the listing description from chrome-extension/STORE_LISTING.md"
Write-Host "  5. Submit for review"
