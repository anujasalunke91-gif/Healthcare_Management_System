<?php

function renderNetlifyPreview(string $appDir): string
{
    define('BASE_URL', '');
    $_SERVER['REQUEST_URI'] = '/';

    ob_start();
    require $appDir . '/index.php';
    $html = ob_get_clean();

    $document = new DOMDocument();
    if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('Could not render the landing page.');
    }
    $xpath = new DOMXPath($document);

    $notice = $document->createElement('p', 'Static preview: sign-in, registration, and the portals are not available here.');
    $notice->setAttribute('id', 'static-preview');
    $notice->setAttribute('class', 'alert alert-info mb-4');
    $notice->setAttribute('style', 'scroll-margin-top: 6rem;');
    $notice->setAttribute('tabindex', '-1');
    $hero = $xpath->query('//*[@id="overview"]/div')->item(0);
    $hero->insertBefore($notice, $hero->firstChild);

    foreach ($xpath->query('//a[starts-with(@href, "/auth/")]') as $link) {
        $link->setAttribute('href', '#static-preview');
    }
    foreach ($xpath->query('//form[@action="/includes/set_lang.php"]') as $form) {
        $form->parentNode->removeChild($form);
    }
    foreach ($xpath->query('//img[starts-with(@src, "/uploads/")]') as $image) {
        $placeholder = $document->createElement('span', 'Scan images are not included in this preview.');
        $placeholder->setAttribute('class', 'small text-light');
        $image->parentNode->replaceChild($placeholder, $image);
    }

    return $document->saveHTML();
}

function writeNetlifyPreview(string $appDir, string $publishDir): void
{
    $html = renderNetlifyPreview($appDir);
    foreach (['assets/css/custom.css', 'assets/js/main.js'] as $asset) {
        $destination = $publishDir . '/' . $asset;
        if (!mkdir(dirname($destination), 0777, true) || !copy($appDir . '/' . $asset, $destination)) {
            throw new RuntimeException('Could not publish ' . $asset);
        }
    }
    if (file_put_contents($publishDir . '/index.html', $html) === false) {
        throw new RuntimeException('Could not write index.html.');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    writeNetlifyPreview($root . '/healthcare_platform', $root . '/dist');
}
