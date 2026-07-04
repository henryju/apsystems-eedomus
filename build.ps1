# Builds dist/aps_eedomus_plugin.zip from the plugin sources.
#
# Uses System.IO.Compression directly (rather than Compress-Archive) so zip
# entries use forward slashes and img/ is written as its own explicit
# directory entry - required by the eedomus store, see SESSION_NOTES.md.
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

$rootFiles = @(
    "eedomus_plugin.json",
    "aps_solar.php",
    "aps_discover.php",
    "readme_en.md",
    "readme_fr.md"
)

$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $rootFiles) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, (Join-Path $PSScriptRoot $file), $file,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }

    $zip.CreateEntry("img/") | Out-Null

    Get-ChildItem (Join-Path $PSScriptRoot "img") -File | ForEach-Object {
        $entryName = "img/$($_.Name)"
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip, $_.FullName, $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
}
finally {
    $zip.Dispose()
}

Write-Host "Built dist/$zipName"
