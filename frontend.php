<?php

declare(strict_types=1);

function render_frontend_page(string $htmlFile): void
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . $htmlFile;

    if (!is_file($path)) {
        http_response_code(404);
        echo 'Page not found';
        return;
    }

    $html = file_get_contents($path);
    if ($html === false) {
        http_response_code(500);
        echo 'Could not load page';
        return;
    }

    $phpPages = [
        'index.html' => 'index.php',
        'browse.html' => 'browse.php',
        'product.html' => 'product.php',
        'auctions.html' => 'auctions.php',
    ];

    header('Content-Type: text/html; charset=UTF-8');
    echo str_replace(array_keys($phpPages), array_values($phpPages), $html);
}
