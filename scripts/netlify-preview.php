<?php

function renderNetlifyPreview(string $appDir, bool $demo = false): string
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

    $noticeText = $demo
        ? 'Hosted demo: use fictional records and synthetic images only. Model output is not medical advice.'
        : 'Static preview: sign-in, registration, and the portals are not available here.';
    $notice = $document->createElement('p', $noticeText);
    $notice->setAttribute('id', 'static-preview');
    $notice->setAttribute('class', 'alert alert-info mb-4');
    $notice->setAttribute('style', 'scroll-margin-top: 6rem;');
    $notice->setAttribute('tabindex', '-1');
    $hero = $xpath->query('//*[@id="overview"]/div')->item(0);
    $hero->insertBefore($notice, $hero->firstChild);

    foreach ($xpath->query('//a[starts-with(@href, "/auth/")]') as $link) {
        $href = $link->getAttribute('href');
        $link->setAttribute('href', $demo ? ($href === '/auth/register.php' ? '/portal/?view=register' : '/portal/') : '#static-preview');
    }
    foreach ($xpath->query('//form[@action="/includes/set_lang.php"]') as $form) {
        $form->parentNode->removeChild($form);
    }
    foreach ($xpath->query('//img[starts-with(@src, "/uploads/")]') as $image) {
        $placeholder = $document->createElement('span', $demo ? 'A synthetic sample is available in the doctor portal.' : 'Scan images are not included in this preview.');
        $placeholder->setAttribute('class', 'small text-light');
        $image->parentNode->replaceChild($placeholder, $image);
    }
    if ($demo) {
        $portalLinks = $xpath->query('//*[@id="portals"]//a[@href="/portal/"]');
        if ($portalLinks->length === 3) {
            foreach (['doctor', 'patient', 'admin'] as $index => $role) {
                $portalLinks->item($index)->setAttribute('href', '/portal/?role=' . $role);
            }
        }
        $callback = $document->createElement('script');
        $callback->appendChild($document->createTextNode('if (/^#(?:confirmation_token|recovery_token|invite_token|email_change_token)=/.test(location.hash)) location.replace("/portal/" + location.hash);'));
        $document->getElementsByTagName('head')->item(0)->appendChild($callback);
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
    if (($argv[1] ?? '') === '--demo') {
        echo renderNetlifyPreview($root . '/healthcare_platform', true);
    } else {
        writeNetlifyPreview($root . '/healthcare_platform', $root . '/dist');
    }
}
