<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Entity\Book;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;

// Controller va demander au manager (ici BookRepository) de faire la recherche en bdd
class BookController extends AbstractController
{
    // Route pour récupérer l'ensemble des livres
    #[Route('/api/books', name: 'app_book')]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer): JsonResponse
    {

        $bookList = $bookRepository->findAll();

        // Une fois les données récupérées, il est nécessaire de les sérialiser (les transformer en json)
        $jsonBookList = $serializer->serialize($bookList, 'json');

        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    // Route pour récupérer un livre identifié par son id
    // Le bundle param converter nous permet de ne même pas avoir besoin de récupérer notre id
    // On peut passer immédiatement à la sérialisation sans même avoir à questionner le manager (repository)
    #[Route('api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book $book, SerializerInterface $serializer)
    {
        $jsonBook = $serializer->serialize($book, 'json');
        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }
}
