<?php declare(strict_types=1);

namespace App\Core;

use Nette;
use Nette\Application\Routers\RouteList;


final class RouterFactory
{
    use Nette\StaticClass;

    public static function createRouter(): RouteList
    {
        $router = new RouteList;

        // Logged-in user area under the panel/ prefix (Panel module). Must precede
        // the public catch-all route below.
        $router->withModule('Panel')
            ->addRoute('panel[/<presenter>[/<action>[/<id>]]]', 'Dashboard:default');

        // Public part. Fully-optional segments so default presenter/action collapse
        // cleanly (no trailing-slash canonical redirect).
        $router->addRoute('[<presenter>[/<action>[/<id>]]]', 'Home:default');

        return $router;
    }
}
