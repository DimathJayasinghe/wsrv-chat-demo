<?php

declare(strict_types=1);

namespace App\Controllers;

final class PageController
{
    /** GET /: the single page. The view is app/Views/home.php. */
    public function home(): array
    {
        $webSocketPort = (int) (getenv('DEMO_WS_PORT') ?: 8081);
        ob_start();
        require __DIR__ . '/../Views/home.php';
        return [200, (string) ob_get_clean()];
    }
}
