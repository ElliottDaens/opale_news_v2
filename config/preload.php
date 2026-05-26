<?php

/*
preload.php

QUOI : Précharge les classes PHP compilées en production via le fichier de préchargement OPcache du conteneur Symfony.

COMMENT : Si `var/cache/prod/App_KernelProdContainer.preload.php` existe après warmup, il est requis pour peupler l’OPcache avec les stubs du conteneur.

OÙ : Inclus depuis la config OPcache (`opcache.preload`) ou références dans la doc déploiement ; hors cycle requête HTTP classique.

POURQUOI : Réduire le temps de chargement et l’empreinte mémoire en prod en prélevant les fichiers les plus utilisés du conteneur compilé.
*/

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
