<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$publicDir = $projectRoot . '/public';
$viewsDir = $projectRoot . '/resources/views';
$routesFile = $projectRoot . '/routes/web.php';
$baseUrl = 'https://thermenservice-buderus.at';
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36';

$assetDirectories = [
    $publicDir . '/assets/css',
    $publicDir . '/assets/js',
    $publicDir . '/assets/images',
    $publicDir . '/assets/fonts',
];

foreach ($assetDirectories as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create asset directory: {$directory}");
    }
}

if (! is_dir($viewsDir . '/layouts') && ! mkdir($viewsDir . '/layouts', 0777, true) && ! is_dir($viewsDir . '/layouts')) {
    throw new RuntimeException('Unable to create layouts directory.');
}

if (! is_dir($viewsDir . '/partials') && ! mkdir($viewsDir . '/partials', 0777, true) && ! is_dir($viewsDir . '/partials')) {
    throw new RuntimeException('Unable to create partials directory.');
}

$downloadedAssets = [];
$processedCss = [];

function httpGet(string $url, string $userAgent): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$userAgent}\r\nAccept: */*\r\n",
            'follow_location' => 1,
            'ignore_errors' => true,
            'timeout' => 60,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);

    if ($content === false) {
        throw new RuntimeException("Failed to fetch: {$url}");
    }

    return $content;
}

function normalizeUrl(string $url, string $baseUrl): string
{
    $url = str_replace(['&#038;', '&amp;'], '&', trim($url));

    if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, 'blob:') || str_starts_with($url, '#')) {
        return '';
    }

    if ($url === '//' || preg_match('~^//[?#]?$~', $url) === 1) {
        return rtrim($baseUrl, '/') . '/';
    }

    if (preg_match('~^//([^/?#]+)(.*)$~', $url, $matches) === 1) {
        $candidateHost = $matches[1];
        $candidateRest = $matches[2];

        if (! str_contains($candidateHost, '.') && ! str_contains($candidateHost, ':')) {
            return rtrim($baseUrl, '/') . '/' . ltrim($candidateHost . $candidateRest, '/');
        }
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (preg_match('~^https?://~i', $url) === 1) {
        return $url;
    }

    if (str_starts_with($url, '/')) {
        return rtrim($baseUrl, '/') . $url;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function pathForPageUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?: '/';

    return $path === '' ? '/' : $path;
}

function isInternalPageUrl(string $url, string $baseUrl): bool
{
    $normalized = normalizeUrl($url, $baseUrl);
    if ($normalized === '') {
        return false;
    }

    $baseHost = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?? '');
    $urlHost = strtolower(parse_url($normalized, PHP_URL_HOST) ?? '');
    if ($urlHost !== $baseHost) {
        return false;
    }

    $path = strtolower(parse_url($normalized, PHP_URL_PATH) ?? '/');
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if ($extension !== '') {
        return false;
    }

    if (
        str_starts_with($path, '/wp-admin')
        || str_starts_with($path, '/wp-json')
        || str_starts_with($path, '/feed')
        || str_starts_with($path, '/comments')
        || str_starts_with($path, '/xmlrpc')
    ) {
        return false;
    }

    return true;
}

function isAssetUrl(string $url): bool
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    $assetExtensions = [
        'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'ico',
    ];

    if (in_array($extension, $assetExtensions, true)) {
        return true;
    }

    if ($host === 'fonts.googleapis.com' && str_starts_with($path, '/css')) {
        return true;
    }

    if ($host === 'fonts.gstatic.com') {
        return true;
    }

    if ($host === 'pulse.clickguard.com') {
        return true;
    }

    if ($host === 'monitor.clickcease.com' && $path === '/stats/stats.aspx') {
        return true;
    }

    if ($host === 'secure.gravatar.com' && str_starts_with($path, '/avatar')) {
        return true;
    }

    if ($host === 'www.googletagmanager.com' && ($path === '/gtag/js' || $path === '/gtm.js' || $path === '/ns.html')) {
        return true;
    }

    if (str_contains($path, '/wp-content/') || str_contains($path, '/wp-includes/')) {
        return true;
    }

    return false;
}

function assetCategoryForUrl(string $url): ?string
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

    if ($extension === 'css' || ($host === 'fonts.googleapis.com' && str_starts_with($path, '/css'))) {
        return 'css';
    }

    if ($extension === 'js' || $path === '/gtag/js' || $path === '/gtm.js') {
        return 'js';
    }

    if ($host === 'pulse.clickguard.com') {
        return 'js';
    }

    if (in_array($extension, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true)) {
        return 'fonts';
    }

    if (
        in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico'], true)
        || $path === '/ns.html'
        || ($host === 'monitor.clickcease.com' && $path === '/stats/stats.aspx')
        || ($host === 'secure.gravatar.com' && str_starts_with($path, '/avatar'))
    ) {
        return 'images';
    }

    return null;
}

function localAssetPath(string $url, string $publicDir): ?string
{
    $category = assetCategoryForUrl($url);
    if ($category === null) {
        return null;
    }

    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $host = preg_replace('~[^a-z0-9]+~i', '-', strtolower(parse_url($url, PHP_URL_HOST) ?? 'asset'));
    $sourceHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    $basename = pathinfo($path, PATHINFO_FILENAME);
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    if ($basename === '' || $basename === '.') {
        $basename = trim(str_replace('/', '-', $path), '-');
    }

    if ($basename === '') {
        $basename = 'asset';
    }

    if ($sourceHost === 'monitor.clickcease.com' && $path === '/stats/stats.aspx') {
        $extension = 'jpg';
    }

    if ($extension === '') {
        if ($category === 'js') {
            $extension = 'js';
        } elseif ($category === 'css') {
            $extension = 'css';
        } elseif ($path === '/ns.html') {
            $extension = 'html';
        } elseif ($category === 'images') {
            $extension = 'jpg';
        }
    }

    $safeBase = preg_replace('~[^a-z0-9.-]+~i', '-', strtolower($basename));
    $hash = substr(sha1($url), 0, 10);
    $relative = "assets/{$category}/{$host}-{$safeBase}-{$hash}.{$extension}";

    return $publicDir . '/' . $relative;
}

function downloadAsset(string $url, string $baseUrl, string $publicDir, string $userAgent, array &$downloadedAssets, array &$processedCss): ?string
{
    $normalized = normalizeUrl($url, $baseUrl);

    if ($normalized === '' || ! isAssetUrl($normalized)) {
        return null;
    }

    if (isset($downloadedAssets[$normalized])) {
        return $downloadedAssets[$normalized];
    }

    $localFile = localAssetPath($normalized, $publicDir);
    if ($localFile === null) {
        return null;
    }

    $localRelative = str_replace('\\', '/', substr($localFile, strlen($publicDir) + 1));
    $directory = dirname($localFile);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create directory for asset: {$directory}");
    }

    try {
        $content = httpGet($normalized, $userAgent);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Skipping asset: {$normalized}\n");
        return null;
    }

    file_put_contents($localFile, $content);
    $downloadedAssets[$normalized] = $localRelative;

    if (str_ends_with($localRelative, '.css')) {
        processCssFile($localFile, $normalized, $baseUrl, $publicDir, $userAgent, $downloadedAssets, $processedCss);
    }

    return $localRelative;
}

function processCssFile(string $localFile, string $sourceUrl, string $baseUrl, string $publicDir, string $userAgent, array &$downloadedAssets, array &$processedCss): void
{
    if (isset($processedCss[$localFile])) {
        return;
    }

    $processedCss[$localFile] = true;
    $css = file_get_contents($localFile);
    if ($css === false) {
        throw new RuntimeException("Unable to read CSS file: {$localFile}");
    }

    $updated = preg_replace_callback(
        '~url\((["\']?)(?!data:|blob:|about:|#)([^)\"\']+)\1\)~i',
        static function (array $matches) use ($sourceUrl, $baseUrl, $publicDir, $userAgent, &$downloadedAssets, &$processedCss): string {
            $reference = trim($matches[2]);

            if ($reference === '') {
                return $matches[0];
            }

            if (preg_match('~^https?://~i', $reference) !== 1 && ! str_starts_with($reference, '//') && ! str_starts_with($reference, '/')) {
                $reference = dirname($sourceUrl) . '/' . $reference;
            }

            $assetPath = downloadAsset($reference, $baseUrl, $publicDir, $userAgent, $downloadedAssets, $processedCss);

            if ($assetPath === null) {
                return $matches[0];
            }

            return "url('/{$assetPath}')";
        },
        $css
    );

    if ($updated !== null) {
        file_put_contents($localFile, $updated);
    }
}

function collectUrls(string $html): array
{
    $urls = [];

    preg_match_all('~(?:src|href|poster|content)=["\']([^"\']+)["\']~i', $html, $attributeMatches);
    foreach ($attributeMatches[1] as $url) {
        $urls[] = $url;
    }

    preg_match_all('~srcset=["\']([^"\']+)["\']~i', $html, $srcsetMatches);
    foreach ($srcsetMatches[1] as $srcset) {
        foreach (explode(',', $srcset) as $candidate) {
            $parts = preg_split('~\s+~', trim($candidate));
            if (! empty($parts[0])) {
                $urls[] = $parts[0];
            }
        }
    }

    preg_match_all('~url\((["\']?)([^)\"\']+)\1\)~i', $html, $cssMatches);
    foreach ($cssMatches[2] as $url) {
        $urls[] = $url;
    }

    preg_match_all('~https?://[^\s"\'<>()]+|/(?:wp-content|wp-includes)/[^\s"\'<>()]+~i', $html, $rawMatches);
    foreach ($rawMatches[0] as $url) {
        $urls[] = $url;
    }

    return array_values(array_unique($urls));
}

function escapeForBlade(string $html): string
{
    $placeholders = [];

    $html = preg_replace_callback('~\{\{\s*asset\(\'([^\']+)\'\)\s*\}\}~', static function (array $matches) use (&$placeholders): string {
        $key = '__ASSET_PLACEHOLDER_' . count($placeholders) . '__';
        $placeholders[$key] = $matches[0];
        return $key;
    }, $html) ?? $html;

    $html = str_replace('@', '@@', $html);
    $html = str_replace('{{', '@{{', $html);

    return strtr($html, $placeholders);
}

function rewriteHtml(
    string $html,
    string $pageUrl,
    array $pageUrlMap,
    string $baseUrl,
    string $publicDir,
    string $userAgent,
    bool $rewritePageLinks,
    array &$downloadedAssets,
    array &$processedCss
): string {
    foreach (collectUrls($html) as $url) {
        $normalized = normalizeUrl($url, $baseUrl);

        if ($normalized === '') {
            continue;
        }

        $assetPath = downloadAsset($normalized, $baseUrl, $publicDir, $userAgent, $downloadedAssets, $processedCss);
        if ($assetPath !== null) {
            $replacement = "{{ asset('{$assetPath}') }}";
            $html = str_replace($url, $replacement, $html);
            $html = str_replace(str_replace('&', '&#038;', $url), $replacement, $html);
            continue;
        }

        if ($rewritePageLinks && isset($pageUrlMap[$normalized])) {
            $replacement = $pageUrlMap[$normalized] === '/' ? '/' : rtrim($pageUrlMap[$normalized], '/') . '/';
            $html = str_replace($url, $replacement, $html);
        }
    }

    $html = preg_replace_callback(
        '~(?P<prefix>(?:src|href|poster)=["\'])(?P<url>//[^"\']+)(?P<suffix>["\'])~i',
        static function (array $matches) use ($pageUrl, $pageUrlMap, $baseUrl, $publicDir, $userAgent, $rewritePageLinks, &$downloadedAssets, &$processedCss): string {
            $original = $matches['url'];
            $normalized = normalizeUrl($original, $baseUrl);

            if ($normalized === '') {
                return $matches[0];
            }

            $assetPath = downloadAsset($normalized, $baseUrl, $publicDir, $userAgent, $downloadedAssets, $processedCss);
            if ($assetPath !== null) {
                return $matches['prefix'] . "{{ asset('{$assetPath}') }}" . $matches['suffix'];
            }

            if ($rewritePageLinks && isset($pageUrlMap[$normalized])) {
                $replacement = $pageUrlMap[$normalized] === '/' ? '/' : rtrim($pageUrlMap[$normalized], '/') . '/';
                return $matches['prefix'] . $replacement . $matches['suffix'];
            }

            return $matches[0];
        },
        $html
    ) ?? $html;

    $html = preg_replace_callback(
        '~(?P<prefix>srcset=["\'])(?P<value>[^"\']*//[^"\']+[^"\']*)(?P<suffix>["\'])~i',
        static function (array $matches) use ($baseUrl, $publicDir, $userAgent, &$downloadedAssets, &$processedCss): string {
            $parts = array_map('trim', explode(',', $matches['value']));

            foreach ($parts as &$part) {
                if ($part === '') {
                    continue;
                }

                $segments = preg_split('~\s+~', $part, 2);
                $candidate = $segments[0] ?? '';
                $descriptor = $segments[1] ?? '';

                if (! str_starts_with($candidate, '//')) {
                    continue;
                }

                $normalized = normalizeUrl($candidate, $baseUrl);
                if ($normalized === '') {
                    continue;
                }

                $assetPath = downloadAsset($normalized, $baseUrl, $publicDir, $userAgent, $downloadedAssets, $processedCss);
                if ($assetPath === null) {
                    continue;
                }

                $part = "{{ asset('{$assetPath}') }}" . ($descriptor !== '' ? ' ' . $descriptor : '');
            }

            return $matches['prefix'] . implode(', ', $parts) . $matches['suffix'];
        },
        $html
    ) ?? $html;

    if ($rewritePageLinks) {
        $html = str_replace(['href="//"', "href='//'"], ['href="/"', "href='/'"], $html);
    }

    return escapeForBlade($html);
}

function buildViewContent(array $parts): string
{
    return <<<BLADE
@extends('layouts.app')

@section('html_attributes')
{$parts['html_attributes']}
@endsection

@section('head')
{$parts['head']}
@endsection

@section('body_attributes')
{$parts['body_attributes']}
@endsection

@section('body_start')
{$parts['body_start']}
@endsection

@section('header_html')
{$parts['header']}
@endsection

@section('content')
{$parts['content']}
@endsection

@section('footer_html')
{$parts['footer']}
@endsection

@section('body_end')
{$parts['body_end']}
@endsection

BLADE;
}

function routeNameFromPath(string $path): string
{
    if ($path === '/' || $path === '') {
        return 'home';
    }

    return trim($path, '/');
}

function viewPathFromRouteName(string $routeName): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $routeName) . '.blade.php';
}

function viewNameFromRouteName(string $routeName): string
{
    return str_replace('/', '.', $routeName);
}

function splitPage(string $html): array
{
    if (! preg_match('~<html([^>]*)>~is', $html, $htmlMatch)) {
        throw new RuntimeException('Missing html tag.');
    }

    if (! preg_match('~<head>(.*)</head>~isU', $html, $headMatch)) {
        throw new RuntimeException('Missing head tag.');
    }

    if (! preg_match('~<body([^>]*)>(.*)</body>~isU', $html, $bodyMatch)) {
        throw new RuntimeException('Missing body tag.');
    }

    $bodyInner = $bodyMatch[2];
    $header = '';
    $footer = '';
    $content = $bodyInner;
    $bodyStart = '';
    $bodyEnd = '';

    if (preg_match('~(<header\b.*?</header>)~is', $bodyInner, $headerMatch, PREG_OFFSET_CAPTURE) === 1) {
        $header = $headerMatch[1][0];
        $headerOffset = $headerMatch[1][1];
        $bodyStart = substr($bodyInner, 0, $headerOffset);
        $afterHeader = substr($bodyInner, $headerOffset + strlen($header));
        $content = $afterHeader;

        if (preg_match_all('~(<footer\b.*?</footer>)~is', $afterHeader, $footerMatches, PREG_OFFSET_CAPTURE) >= 1) {
            $lastFooter = end($footerMatches[1]);
            $footer = $lastFooter[0];
            $footerOffset = $lastFooter[1];
            $content = substr($afterHeader, 0, $footerOffset);
            $bodyEnd = substr($afterHeader, $footerOffset + strlen($footer));
        }
    }

    return [
        'html_attributes' => trim($htmlMatch[1]),
        'head' => trim($headMatch[1]),
        'body_attributes' => trim($bodyMatch[1]),
        'body_start' => trim($bodyStart),
        'header' => trim($header),
        'content' => trim($content),
        'footer' => trim($footer),
        'body_end' => trim($bodyEnd),
    ];
}

function discoverSitemapUrls(string $baseUrl, string $userAgent): array
{
    $sitemapIndex = httpGet($baseUrl . '/sitemap_index.xml', $userAgent);
    preg_match_all('~<loc>([^<]+)</loc>~i', $sitemapIndex, $sitemapMatches);

    return array_values(array_unique($sitemapMatches[1]));
}

function collectPageUrlsFromSitemaps(string $baseUrl, string $userAgent): array
{
    $pageUrls = [];

    foreach (discoverSitemapUrls($baseUrl, $userAgent) as $sitemapUrl) {
        $sitemapXml = httpGet($sitemapUrl, $userAgent);
        preg_match_all('~<loc>([^<]+)</loc>~i', $sitemapXml, $pageMatches);

        foreach ($pageMatches[1] as $pageUrl) {
            if (isInternalPageUrl($pageUrl, $baseUrl)) {
                $pageUrls[] = rtrim($pageUrl, '/') . '/';
            }
        }
    }

    $pageUrls[] = rtrim($baseUrl, '/') . '/';

    $pageUrls = array_values(array_unique($pageUrls));
    sort($pageUrls);

    return $pageUrls;
}

$pageUrls = collectPageUrlsFromSitemaps($baseUrl, $userAgent);

$pageUrlMap = [];
foreach ($pageUrls as $pageUrl) {
    $pageUrlMap[$pageUrl] = pathForPageUrl($pageUrl);
    $pageUrlMap[rtrim($pageUrl, '/')] = pathForPageUrl($pageUrl);
}
$pageUrlMap[$baseUrl] = '/';
$pageUrlMap[$baseUrl . '/'] = '/';

$viewsWritten = [];

foreach ($pageUrls as $pageUrl) {
    $html = httpGet($pageUrl, $userAgent);
    $parts = splitPage($html);

    foreach ($parts as $key => $value) {
        $parts[$key] = rewriteHtml(
            $value,
            $pageUrl,
            $pageUrlMap,
            $baseUrl,
            $publicDir,
            $userAgent,
            $key !== 'head',
            $downloadedAssets,
            $processedCss
        );
    }

    $routeName = routeNameFromPath(pathForPageUrl($pageUrl));
    $viewRelativePath = viewPathFromRouteName($routeName);
    $viewAbsolutePath = $viewsDir . '/' . $viewRelativePath;
    $viewDirectory = dirname($viewAbsolutePath);

    if (! is_dir($viewDirectory) && ! mkdir($viewDirectory, 0777, true) && ! is_dir($viewDirectory)) {
        throw new RuntimeException("Unable to create view directory: {$viewDirectory}");
    }

    file_put_contents($viewAbsolutePath, buildViewContent($parts));
    $viewsWritten[] = [$routeName, viewNameFromRouteName($routeName), pathForPageUrl($pageUrl)];
}

$layout = <<<'BLADE'
<!DOCTYPE html>
<html @yield('html_attributes')>
<head>
@yield('head')
</head>
<body @yield('body_attributes')>
@yield('body_start')
@include('partials.header')
@yield('content')
@include('partials.footer')
@yield('body_end')
</body>
</html>
BLADE;

$headerPartial = <<<'BLADE'
@yield('header_html')
BLADE;

$footerPartial = <<<'BLADE'
@yield('footer_html')
BLADE;

file_put_contents($viewsDir . '/layouts/app.blade.php', $layout);
file_put_contents($viewsDir . '/partials/header.blade.php', $headerPartial);
file_put_contents($viewsDir . '/partials/footer.blade.php', $footerPartial);

$routes = ["<?php", "", "use Illuminate\\Support\\Facades\\Route;", ""];
foreach ($viewsWritten as [$routeName, $viewName, $path]) {
    $uri = $path === '/' ? '/' : trim($path, '/');
    $routes[] = "Route::view('{$uri}', '{$viewName}');";
}

file_put_contents($routesFile, implode(PHP_EOL, $routes) . PHP_EOL);

echo 'Imported ' . count($viewsWritten) . ' pages and ' . count($downloadedAssets) . " assets.\n";
