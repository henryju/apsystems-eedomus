# Builds dist/aps_eedomus_plugin.zip from the plugin sources.
#
# Uses System.IO.Compression directly (rather than Compress-Archive) so zip
# entries use forward slashes and img/ is written as its own explicit
# directory entry - required by the eedomus store, see SESSION_NOTES.md.
#
# readme_en.md/readme_fr.md are kept in UTF-8 in the repo for easy editing,
# but eedomus's doc.php help viewer expects ISO-8859-1, like the .php
# scripts (see SESSION_NOTES.md Piege n5/n10) - convert only at build time,
# into the zip, never touching the UTF-8 sources.
$ErrorActionPreference = "Stop"

Set-Location $PSScriptRoot

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipName = "aps_eedomus_plugin.zip"
$distDir = Join-Path $PSScriptRoot "dist"
$zipPath = Join-Path $distDir $zipName

if (Test-Path $distDir) {
    Remove-Item -Recurse -Force $distDir
}
New-Item -ItemType Directory -Path $distDir | Out-Null

$plainFiles = @(
    "eedomus_plugin.json",
    "aps_solar.php"
)
$readmeFiles = @("readme_en.md", "readme_fr.md")

$latin1 = [System.Text.Encoding]::GetEncoding(
    "ISO-8859-1",
    [System.Text.EncoderFallback]::ExceptionFallback,
    [System.Text.DecoderFallback]::ExceptionFallback)

foreach ($name in $readmeFiles) {
    $srcPath = Join-Path $PSScriptRoot $name
    $utf8Text = [System.Text.Encoding]::UTF8.GetString([System.IO.File]::ReadAllBytes($srcPath))
    try {
        $latin1Bytes = $latin1.GetBytes($utf8Text)
    }
    catch {
        throw "error: $name contains a character that cannot be represented in ISO-8859-1 (needed for eedomus's doc viewer, see SESSION_NOTES.md Piege n10). $_"
    }
    [System.IO.File]::WriteAllBytes((Join-Path $distDir $name), $latin1Bytes)
}

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $plainFiles) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, (Join-Path $PSScriptRoot $file), $file,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }

    foreach ($file in $readmeFiles) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, (Join-Path $distDir $file), $file,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }

    $zip.CreateEntry("img/") | Out-Null

    # Only the icon goes in the zip's img/ - the eedomus Store validates
    # every file under img/ as an icon candidate (must be 128x128), so the
    # doc screenshots (referenced via GitHub raw URLs in the readmes, not
    # bundled) must NOT be included here.
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $zip, (Join-Path $PSScriptRoot "img/apsolar.png"), "img/apsolar.png",
        [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
}
finally {
    $zip.Dispose()
}

foreach ($file in $readmeFiles) {
    Remove-Item (Join-Path $distDir $file)
}

Write-Host "Built dist/$zipName"
