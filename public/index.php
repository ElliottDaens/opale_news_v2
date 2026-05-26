<?php

use App\Kernel;

/*
index.php

QUOI : Front controller HTTP — point d’entrée unique des requêtes web vers le `Kernel` Symfony.

COMMENT : Charge `vendor/autoload_runtime.php` puis retourne une closure statique qui instancie `Kernel` avec `APP_ENV` et `APP_DEBUG` issus du contexte runtime.

OÙ : Répertoire `public/` servi par le serveur web (document root).

POURQUOI : Découpler le bootstrap PHP du code métier et profiter du Symfony Runtime (env, debug, early cache) avant la construction du conteneur.
*/

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
