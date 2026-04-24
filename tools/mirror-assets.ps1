$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$viewsDir = Join-Path $projectRoot 'resources\views'
$publicDir = Join-Path $projectRoot 'public'
$assetRoot = Join-Path $publicDir 'assets\external'

$downloaded = @{}
$processedCss = @{}

function Get-LocalRelativePath {
    param(
        [Parameter(Mandatory = $true)]
        [uri]$Uri
    )

    $hostName = $Uri.Host.ToLowerInvariant()
    $path = $Uri.AbsolutePath.TrimStart('/')

    if ([string]::IsNullOrWhiteSpace($path)) {
        $path = 'index.html'
    }

    $extension = [System.IO.Path]::GetExtension($path)
    if ([string]::IsNullOrWhiteSpace($extension)) {
        if ($path -like 'gtag/js' -or $path -like 'gtm.js') {
            $path = "$path.js"
        }
        elseif ($path -like '*.css' -or $Uri.Query -match 'css') {
            $path = "$path.css"
        }
        elseif ($path -like '*.js' -or $Uri.Query -match 'js') {
            $path = "$path.js"
        }
        else {
            $path = "$path.html"
        }
    }

    return ('assets/external/{0}/{1}' -f $hostName, $path.Replace('/', '\'))
}

function Should-DownloadAsset {
    param(
        [Parameter(Mandatory = $true)]
        [uri]$Uri
    )

    $assetExtensions = @('.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.webp', '.woff', '.woff2', '.ttf', '.eot', '.ico')
    $path = $Uri.AbsolutePath.ToLowerInvariant()
    $hostName = $Uri.Host.ToLowerInvariant()
    $extension = [System.IO.Path]::GetExtension($path)

    if ($assetExtensions -contains $extension) {
        return $true
    }

    if ($hostName -in @('www.buderus-notdienst.at', 'buderus-notdienst.at', 'www.googletagmanager.com', 's.w.org')) {
        if ($path -match '^/wp-content/' -or $path -match '^/wp-includes/' -or $path -match '^/gtag/js' -or $path -match '^/gtm.js') {
            return $true
        }
    }

    return $false
}

function Normalize-UrlText {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Text
    )

    return $Text.Replace('&#038;', '&').Replace('&amp;', '&')
}

function Download-Asset {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Url
    )

    $normalizedUrl = Normalize-UrlText -Text $Url
    if ($normalizedUrl.StartsWith('//')) {
        $normalizedUrl = 'https:' + $normalizedUrl
    }

    $uri = [uri]$normalizedUrl
    if (-not (Should-DownloadAsset -Uri $uri)) {
        return $null
    }

    $localRelativePath = Get-LocalRelativePath -Uri $uri
    if ($downloaded.ContainsKey($normalizedUrl)) {
        return $localRelativePath.Replace('\', '/')
    }

    $localPath = Join-Path $publicDir $localRelativePath
    $localDirectory = Split-Path -Parent $localPath
    if (-not (Test-Path $localDirectory)) {
        New-Item -ItemType Directory -Path $localDirectory -Force | Out-Null
    }

    if (-not (Test-Path $localPath)) {
        try {
            Invoke-WebRequest -Uri $normalizedUrl -OutFile $localPath
        }
        catch {
            Write-Warning "Skipping asset download: $normalizedUrl"
            return $null
        }
    }

    $downloaded[$normalizedUrl] = $localPath

    if ([System.IO.Path]::GetExtension($localPath).ToLowerInvariant() -eq '.css') {
        Process-CssFile -CssPath $localPath -SourceUrl $normalizedUrl
    }

    return $localRelativePath.Replace('\', '/')
}

function Process-CssFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$CssPath,
        [Parameter(Mandatory = $true)]
        [string]$SourceUrl
    )

    if ($processedCss.ContainsKey($CssPath)) {
        return
    }

    $processedCss[$CssPath] = $true
    $css = Get-Content $CssPath -Raw
    $regex = [regex]'url\((["'']?)(?!data:|#|about:|blob:)([^)"'']+)\1\)'
    $baseUri = [uri]$SourceUrl

    $updatedCss = $regex.Replace($css, {
        param($match)

        $originalReference = $match.Groups[2].Value.Trim()
        $absoluteUrl = $null

        if ($originalReference.StartsWith('//')) {
            $absoluteUrl = 'https:' + $originalReference
        }
        elseif ($originalReference -match '^https?://') {
            $absoluteUrl = $originalReference
        }
        else {
            $absoluteUrl = [uri]::new($baseUri, $originalReference).AbsoluteUri
        }

        $localRelative = Download-Asset -Url $absoluteUrl
        if ([string]::IsNullOrWhiteSpace($localRelative)) {
            return $match.Value
        }

        return "url('/$localRelative')"
    })

    if ($updatedCss -ne $css) {
        Set-Content -Path $CssPath -Value $updatedCss -NoNewline
    }
}

$viewFiles = Get-ChildItem -Path $viewsDir -Filter '*.blade.php'
$regexes = @(
    [regex]'https?://[^"''\s<>()]+',
    [regex]'//[^"''\s<>()]+'
)

$replacementMap = @{}

foreach ($viewFile in $viewFiles) {
    $content = Get-Content $viewFile.FullName -Raw

    foreach ($regex in $regexes) {
        foreach ($match in $regex.Matches($content)) {
            $url = $match.Value
            if ($url -match '^//www\.w3\.org/' -or $url -eq '//') {
                continue
            }

            $localRelative = Download-Asset -Url $url
            if ([string]::IsNullOrWhiteSpace($localRelative)) {
                continue
            }

            $replacementMap[$url] = "/$localRelative"

            $normalizedUrl = Normalize-UrlText -Text $url
            if ($normalizedUrl -ne $url) {
                $replacementMap[$normalizedUrl] = "/$localRelative"
            }

            $escapedOriginal = $url.Replace('/', '\/')
            $escapedReplacement = ("/$localRelative").Replace('/', '\/')
            $replacementMap[$escapedOriginal] = $escapedReplacement

            $escapedNormalized = $normalizedUrl.Replace('/', '\/')
            $replacementMap[$escapedNormalized] = $escapedReplacement
        }
    }
}

foreach ($viewFile in $viewFiles) {
    $content = Get-Content $viewFile.FullName -Raw

    foreach ($entry in $replacementMap.GetEnumerator() | Sort-Object { $_.Key.Length } -Descending) {
        $content = $content.Replace($entry.Key, $entry.Value)
    }

    Set-Content -Path $viewFile.FullName -Value $content -NoNewline
}
