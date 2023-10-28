<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Entity\Book;
use App\Repository\AuthorRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
// Dans le but de rendre notre API autodécouvrable, on utilisra un autre sérializer
// use Symfony\Component\Serializer\SerializerInterface;
use JMS\Serializer\SerializerInterface; // Le nouveau serializer
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Annotations as OA;

// Controller va demander au manager (ici BookRepository) de faire la recherche en bdd
class BookController extends AbstractController
{
    /**
     * Cette méthode permet de récupérer l'ensemble des livres.
     *
     * @OA\Response(
     *     response=200,
     *     description="Retourne la liste des livres",
     *     @OA\JsonContent(
     *        type="array",
     *        @OA\Items(ref=@Model(type=Book::class, groups={"getBooks"}))
     *     )
     * )
     * @OA\Parameter(
     *     name="page",
     *     in="query",
     *     description="La page que l'on veut récupérer",
     *     @OA\Schema(type="int")
     * )
     *
     * @OA\Parameter(
     *     name="limit",
     *     in="query",
     *     description="Le nombre d'éléments que l'on veut récupérer",
     *     @OA\Schema(type="int")
     * )
     * @OA\Tag(name="Books")
     *
     * @param BookRepository $bookRepository
     * @param SerializerInterface $serializer
     * @param Request $request
     * @return JsonResponse
     */

    #[Route('/api/books', name: 'app_book', methods: ['GET'])]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache): JsonResponse
    {
        $page = $request->get('page', 1); // Par défaut c'est la page 1 qui sera choisi
        $limit = $request->get('limit', 3);

        $idCache = "getAllBooks-" . $page . "-" . $limit;  // Id représentant la requête reçue

        // On entre dans la fonction que si l'élément n'est pas encore présent dans le cache
        // Ici on met en cache la version déjà converti et dans laquelle l'auteur a bien été spécifié (permet d'éviter le lazy loading)
        // IL EXISTE UNE AUTRE TECHNIQUE PLUS SIMPLE MAIS MOINS OPTIMISÉE (VOIR COURS)
        $JsonBookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            echo ("L'ELEMENT N'EST PAS ENCORE EN CACHE !\n");
            $item->tag("booksCache");
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            // Nécessaire pour la sérialization
            $context = SerializationContext::create()->setGroups(["getBooks"]);
            // Une fois les données récupérées, il est nécessaire de les sérialiser (les transformer en json)
            return $serializer->serialize($bookList, 'json', $context);
        });

        return new JsonResponse($JsonBookList, Response::HTTP_OK, [], true);
    }

    /**
     * Méthode temporaire pour vider le cache. 
     *
     * @param TagAwareCacheInterface $cache
     * @return void
     */
    #[Route('/api/books/clearCache', name: "clearCache", methods: ['GET'])]
    public function clearCache(TagAwareCacheInterface $cache)
    {
        $cache->invalidateTags(["booksCache"]);
        return new JsonResponse("Cache Vidé", JsonResponse::HTTP_OK);
    }

    // Route pour récupérer un livre identifié par son id
    // Le bundle param converter nous permet de ne même pas avoir besoin de récupérer notre id
    // On peut passer immédiatement à la sérialisation sans même avoir à questionner le manager (repository)
    #[Route('api/books/{id}', name: 'detailBook', methods: ['GET'])]
    public function getDetailBook(Book $book, SerializerInterface $serializer, VersioningService $versioningService)
    {
        // Récupération de la version depuis le header grâce à notre fichier VersioningService
        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(["getBooks"]);
        $context->setVersion($version);

        // Plutôt que de spécifier la version souhaitée ici, on utilisera à présent les header
        // $context->setVersion("2.0");  Si la version spécifié ici n'est pas conforme à celle nécessaire pour un champ, alors ce dernier n'apparaîtra pas

        $jsonBook = $serializer->serialize($book, 'json', $context);
        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    // Ici on a besoin de l'entitymanager pour faire une opération sur les données (suppression)
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour supprimer un livre')]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cache): JsonResponse
    {
        // À chaque opération sur une donnée mise en cache il faut invalider ces dernières pour permettre leur recalcul (avant ou après le flush)
        $cache->invalidateTags(["booksCache"]);
        $em->remove($book);
        $em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/books', name: "createBook", methods: ['POST'])]
    // Grâce à la mise en place de la sécurité, je peux maintenant facilement restreindre certaines de mes routes
    #[IsGranted('ROLE_ADMIN', message: 'Vous n\'avez pas les droits suffisants pour créer un livre')]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');

        // On vérifie les erreurs
        $errors = $validator->validate($book);

        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;

        $book->setAuthor($authorRepository->find($idAuthor));

        $em->persist($book);
        $em->flush();

        $cache->invalidateTags(["booksCache"]);

        $context = SerializationContext::create()->setGroups(["getBooks"]);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    #[Route('/api/books/{id}', name: "updateBook", methods: ['PUT'])]
    // CurrentBook représente l'état du livre avant sa modification
    public function updateBook(Request $request, SerializerInterface $serializer, Book $currentBook, EntityManagerInterface $em, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache)
    {
        // Je crée un livre qui prend la forme de l'entité book rempli avec les paramètres de la requête
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');

        // Le livre qui va être modifié récupère les nouvelles informations définies dans newbook
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());

        // On vérifie les erreurs
        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        // Il nous faudra à nouveau rechercher l'auteur associé à l'id passé en requête
        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;

        $currentBook->setAuthor($authorRepository->find($idAuthor));

        $em->persist($currentBook);
        $em->flush();

        $cache->invalidateTags(["booksCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
