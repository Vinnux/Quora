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
        $questions = $questionRepository->findAll();
        // $questions = [
        //     [
        //         'id' => 1,
        //         'title' => 'Je suis une question',
        //         'content' => 'Lorem ipsum dolor, sit amet consectetur adipisicing elit. Atque magnam incidunt eveniet neque et veniam! Minus assumenda minima, voluptates ut deleniti sint odio maxime blanditiis perferendis autem tenetur harum quaerat!',
        //         'rating' => 4,
        //         'author' => [
        //             'name' => 'Virginie B',
        //             'avatar' => "https://randomuser.me/api/portraits/lego/6.jpg"
        //         ],
        //         'nbResponse' => 15
        //     ],
        //     [
        //         'id' => 2,
        //         'title' => 'Je suis une deuxieme question',
        //         'content' => 'Lorem ipsum dolor, sit amet consectetur adipisicing elit. Atque magnam incidunt eveniet neque et veniam! Minus assumenda minima, voluptates ut deleniti sint odio maxime blanditiis perferendis autem tenetur harum quaerat!',
        //         'rating' => 18,
        //         'author' => [
        //             'name' => 'Julie V',
        //             'avatar' => "https://randomuser.me/api/portraits/women/81.jpg"
        //         ],
        //         'nbResponse' => 6
        //     ]
        // ];
        return $this->render('home/index.html.twig', [
            'questions' => $questions,
        ]);
    }
}
