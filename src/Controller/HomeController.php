<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/home', name: 'home')]
    public function index(Request $request): Response
    {
        // Optionnel : Si vous souhaitez afficher le nom à partir de l'URL (?name=...)
        $name = $request->query->get('name', 'inconnu');

        // Vous pouvez utiliser 'render' pour rendre un template Twig.
        return $this->render('home/index.html.twig', [
            'name' => $name,
        ]);
    }
}