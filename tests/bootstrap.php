<?php

/*
tests/bootstrap.php

QUOI : Amorce l’autoload Composer et charge les variables `.env` avant PHPUnit.

COMMENT : Préfère `config/bootstrap.php` s’il existe, sinon `Dotenv::bootEnv` sur `.env` à la racine.

OÙ : Référencé par `phpunit.xml.dist` comme bootstrap des tests.

POURQUOI : Exécuter les tests avec le même contexte d’environnement que l’application.
*/

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
