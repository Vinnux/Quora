<?php

namespace App\Controller;

use App\Repository\QuestionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(QuestionRepository $questionRepository): Response
    {
        //utilisation de la méthode crée pour diminuer les requetes
        $questions = $questionRepository->findAllWithAuthors();
        
        return $this->render('home/index.html.twig', [
            'questions' => $questions
        ]);
    }
}
