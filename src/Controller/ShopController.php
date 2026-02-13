<?php

namespace App\Controller;

use App\Entity\Item;
use App\Entity\Inventory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/shop')]
class ShopController extends AbstractController
{
    #[Route('', name: 'api_shop_list', methods: ['GET'])]
    public function listItems(EntityManagerInterface $em): JsonResponse
    {
        $items = $em->getRepository(Item::class)->findAll();

        $data = [];
        foreach ($items as $item) {
            $data[] = [
                'id' => $item->getId(),
                'nom' => $item->getNom(),
                'description' => $item->getDescription(),
                'prix' => $item->getPrix(),
                'rarete' => $item->getRarete()
            ];
        }

        return $this->json($data);
    }

    #[Route('/buy/{id}', name: 'api_shop_buy', methods: ['POST'])]
    public function buyItem(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Authentification
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Token manquant'], 401);
        }

        $token = substr($authHeader, 7);
        $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);

        if (!$user) {
            return $this->json(['error' => 'Token invalide'], 401);
        }

        // Chercher l'item
        $item = $em->getRepository(Item::class)->find($id);

        if (!$item) {
            return $this->json(['error' => 'Item introuvable'], 404);
        }

        // Vérifier si le joueur a assez de crédits
        if ($user->getCredits() < $item->getPrix()) {
            return $this->json([
                'error' => 'Crédits insuffisants',
                'credits_disponibles' => $user->getCredits(),
                'prix_item' => $item->getPrix()
            ], 400);
        }

        // Déduire l'argent
        $user->setCredits($user->getCredits() - $item->getPrix());

        // Ajouter l'item à l'inventaire
        $inventory = new Inventory();
        $inventory->setUser($user);
        $inventory->setItem($item);
        $inventory->setQuantite(1);
        $inventory->setDateAchat(new \DateTimeImmutable());

        $em->persist($inventory);
        $em->flush();

        return $this->json([
            'message' => 'Achat réussi !',
            'item' => $item->getNom(),
            'credits_restants' => $user->getCredits()
        ], 200);
    }

    #[Route('/inventory', name: 'api_inventory', methods: ['GET'])]
    public function viewInventory(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Authentification
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Token manquant'], 401);
        }

        $token = substr($authHeader, 7);
        $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);

        if (!$user) {
            return $this->json(['error' => 'Token invalide'], 401);
        }

        // Récupérer l'inventaire de l'utilisateur
        $inventoryItems = $em->getRepository(Inventory::class)->findBy(['user' => $user]);

        $data = [];
        foreach ($inventoryItems as $invItem) {
            $data[] = [
                'item' => [
                    'id' => $invItem->getItem()->getId(),
                    'nom' => $invItem->getItem()->getNom(),
                    'description' => $invItem->getItem()->getDescription(),
                    'rarete' => $invItem->getItem()->getRarete()
                ],
                'quantite' => $invItem->getQuantite(),
                'dateAchat' => $invItem->getDateAchat()->format('Y-m-d H:i:s')
            ];
        }

        return $this->json([
            'credits' => $user->getCredits(),
            'inventaire' => $data
        ]);
    }
}
