<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/*
Kernel

QUOI : Noyau HTTP de l’application Symfony, basé sur le `MicroKernelTrait`.

COMMENT : Enregistre bundles, charge la configuration (`config/`) et construit le conteneur selon l’environnement.

OÙ : Classe racine référencée par `public/index.php` et `bin/console`.

POURQUOI : Point unique d’entrée pour le cycle de vie du framework (routing, DI, bundles).
*/

class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
