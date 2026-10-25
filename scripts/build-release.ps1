$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$outputName = 'AOV-subdomain-staging.zip'
$output = Join-Path $root "dist\$outputName"
$publicNames = @(
  '.htaccess','index.php','view.tpl','styles.css','app.js','admin.php','admin-api.php',
  'admin.css','admin.js','api.php','bootstrap.php','browser.php','chat-api.php',
  'chat-moderation.php','mail-api.php','news.php','local.php','local-data.php','reset.php','terms.php','channels.json',
  'channel-feed.php','channel-feed-images.php','retention.php','bulletins.php','on-aov.php','on-aov-seed.json','keywords.json'
)
$privateNames = @('config.example.php')
$blockedExtensions = @('.sqlite','.sqlite3','.db','.log','.env','.key','.pem')
$files = [System.Collections.Generic.List[object]]::new()
foreach ($name in $publicNames) {
  $source = Join-Path $root $name
  if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing public file: $name" }
  $files.Add(@{ source = $source; entry = "public/$name" })
}
foreach ($folder in @('assets','chat-engine','channel-configs')) {
  $base = Join-Path $root $folder
  if (!(Test-Path -LiteralPath $base -PathType Container)) { throw "Missing directory: $folder" }
  foreach ($file in Get-ChildItem -LiteralPath $base -Recurse -File) {
    if ($file.Extension.ToLowerInvariant() -in $blockedExtensions) { throw "Blocked file in public package: $($file.FullName)" }
    $relative = $file.FullName.Substring($root.Length + 1).Replace('\','/')
    $files.Add(@{ source = $file.FullName; entry = "public/$relative" })
  }
}
foreach ($name in $privateNames) {
  $source = Join-Path $root $name
  if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing private template: $name" }
  $files.Add(@{ source = $source; entry = "private-template/$name" })
}
$vendor = Join-Path $root 'vendor'
if (!(Test-Path -LiteralPath (Join-Path $vendor 'autoload.php') -PathType Leaf)) { throw 'Run Composer and the Argon2id patch locally before packaging' }
$patched = Get-Content -LiteralPath (Join-Path $vendor 'delight-im\auth\src\PasswordHash.php') -Raw
if (!$patched.Contains('private static function algorithm()')) { throw 'Patched authentication library is missing' }
foreach ($file in Get-ChildItem -LiteralPath $vendor -Recurse -File) {
  $relative = $file.FullName.Substring($root.Length + 1).Replace('\','/')
  if ($relative -match '(?i)(^|/)(tests?|examples?)/') { continue }
  if ($file.Extension.ToLowerInvariant() -in $blockedExtensions) { throw "Blocked file in private dependencies: $($file.FullName)" }
  $files.Add(@{ source = $file.FullName; entry = "private-template/$relative" })
}
foreach ($name in @('README.md','DEPLOYMENT.md','THIRD_PARTY.md','RELEASE_REVIEW.md','PHASE1E_REPORT.md','PHASE2_REPORT.md','migrations/chat.sql')) {
  $source = Join-Path $root $name
  if (!(Test-Path -LiteralPath $source -PathType Leaf)) { throw "Missing documentation: $name" }
  $files.Add(@{ source = $source; entry = 'install/' + $name.Replace('\','/') })
}
$seenEntries = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
foreach ($file in $files) {
  if (!$seenEntries.Add([string] $file.entry)) { throw "Duplicate path in package: $($file.entry)" }
}
New-Item -ItemType Directory -Force -Path (Split-Path $output) | Out-Null
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream = [System.IO.File]::Open($output, [System.IO.FileMode]::Create)
try {
  $archive = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
  try {
    foreach ($file in $files) {
      [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.source, $file.entry, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
  } finally { $archive.Dispose() }
} finally { $stream.Dispose() }
$zip = [System.IO.Compression.ZipFile]::OpenRead($output)
try {
  $entries = @($zip.Entries | ForEach-Object FullName)
  if ('public/index.php' -notin $entries -or 'private-template/vendor/autoload.php' -notin $entries -or 'private-template/config.example.php' -notin $entries) { throw 'Package verification failed' }
  if (@($entries | Where-Object { $_ -match '(?i)(\.(sqlite|sqlite3|db|log|env|key|pem)$|^public/config\.php$|^private-template/config\.php$|(^|/)sessions/|(^|/)\.git/)' }).Count) { throw 'Private data appears in the package' }
  if (@($entries | Where-Object { $_ -match '(?i)^public/assets/sounds/.*\.mp3$' }).Count -ne 6) { throw 'Expected six generated sound files in the package' }
  Write-Output "Created $output ($($entries.Count) files, $((Get-Item $output).Length) bytes)."
} finally { $zip.Dispose() }
