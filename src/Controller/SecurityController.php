<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/*
SecurityController

QUOI : Pages d’authentification admin (login) et point de sortie logout.

COMMENT : Login rend le Twig avec erreurs Symfony Security ; logout lève une exception volontairement (géré par le firewall).

OÙ : Routes `/admin/login` et `/admin/logout` déclarées dans `security.yaml`.

POURQUOI : Brancher le template de connexion sur le mécanisme form_login sans logique métier additionnelle.
*/

final class SecurityController extends AbstractController
{
    #[Route('/admin/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $auth): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_admin_dashboard');
        }

        return $this->render('admin/login.html.twig', [
            'last_username' => $auth->getLastUsername(),
            'error' => $auth->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall.');
    }
}
