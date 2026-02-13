<?php

namespace App\Controller;

use App\Entity\Faction;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/faction')]
class FactionController extends AbstractController
{
    private function authenticateUser(Request $request, EntityManagerInterface $em): ?User
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        return $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);
    }

    #[Route('', name: 'api_faction_create', methods: ['POST'])]
    public function createFaction(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Authentification
        $user = $this->authenticateUser($request, $em);
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        // Vérifier si l'utilisateur a déjà une faction
        if ($user->getFaction()) {
            return $this->json(['error' => 'Vous êtes déjà dans une faction'], 400);
        }

        // Vérifier si l'utilisateur a assez de crédits
        if ($user->getCredits() < 1000) {
            return $this->json([
                'error' => 'Crédits insuffisants',
                'requis' => 1000,
                'disponibles' => $user->getCredits()
            ], 400);
        }

        // Récupérer les données
        $data = json_decode($request->getContent(), true);

        if (!isset($data['nom'])) {
            return $this->json(['error' => 'Nom de faction requis'], 400);
        }

        // Vérifier si le nom est déjà pris
        $existingFaction = $em->getRepository(Faction::class)->findOneBy(['nom' => $data['nom']]);
        if ($existingFaction) {
            return $this->json(['error' => 'Ce nom de faction est déjà utilisé'], 400);
        }

        // Créer la faction
        $faction = new Faction();
        $faction->setNom($data['nom']);
        $faction->setDescription($data['description'] ?? null);
        $faction->setPower(0);
        $faction->setChef($user);
        $faction->setDateCreation(new \DateTimeImmutable());

        // Déduire les crédits
        $user->setCredits($user->getCredits() - 1000);

        // Ajouter le créateur comme membre
        $user->setFaction($faction);

        $em->persist($faction);
        $em->flush();

        return $this->json([
            'message' => 'Faction créée avec succès',
            'faction' => [
                'id' => $faction->getId(),
                'nom' => $faction->getNom(),
                'description' => $faction->getDescription(),
                'power' => $faction->getPower(),
                'chef' => $faction->getChef()->getPseudoMinecraft()
            ],
            'credits_restants' => $user->getCredits()
        ], 201);
    }

    #[Route('s', name: 'api_factions_list', methods: ['GET'])]
    public function listFactions(EntityManagerInterface $em): JsonResponse
    {
        $factions = $em->getRepository(Faction::class)->findAll();

        $data = [];
        foreach ($factions as $faction) {
            // Compter les membres
            $membersCount = $em->getRepository(User::class)->count(['faction' => $faction]);

            $data[] = [
                'id' => $faction->getId(),
                'nom' => $faction->getNom(),
                'description' => $faction->getDescription(),
                'power' => $faction->getPower(),
                'chef' => $faction->getChef()->getPseudoMinecraft(),
                'membres' => $membersCount,
                'dateCreation' => $faction->getDateCreation()->format('Y-m-d H:i:s')
            ];
        }

        return $this->json($data);
    }

    #[Route('/join/{id}', name: 'api_faction_join', methods: ['POST'])]
    public function joinFaction(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Authentification
        $user = $this->authenticateUser($request, $em);
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        // Vérifier si l'utilisateur a déjà une faction
        if ($user->getFaction()) {
            return $this->json(['error' => 'Vous êtes déjà dans une faction'], 400);
        }

        // Chercher la faction
        $faction = $em->getRepository(Faction::class)->find($id);
        if (!$faction) {
            return $this->json(['error' => 'Faction introuvable'], 404);
        }

        // Rejoindre la faction
        $user->setFaction($faction);
        $em->flush();

        return $this->json([
            'message' => 'Vous avez rejoint la faction',
            'faction' => [
                'id' => $faction->getId(),
                'nom' => $faction->getNom(),
                'description' => $faction->getDescription()
            ]
        ]);
    }

    #[Route('/{id}', name: 'api_faction_delete', methods: ['DELETE'])]
    public function deleteFaction(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Authentification
        $user = $this->authenticateUser($request, $em);
        if (!$user) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        // Chercher la faction
        $faction = $em->getRepository(Faction::class)->find($id);
        if (!$faction) {
            return $this->json(['error' => 'Faction introuvable'], 404);
        }

        // Vérifier si l'utilisateur est le chef ou admin
        $isChef = $faction->getChef()->getId() === $user->getId();
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles());

        if (!$isChef && !$isAdmin) {
            return $this->json(['error' => 'Permission refusée'], 403);
        }

        // Retirer tous les membres de la faction
        $members = $em->getRepository(User::class)->findBy(['faction' => $faction]);
        foreach ($members as $member) {
            $member->setFaction(null);
        }

        // Supprimer la faction
        $em->remove($faction);
        $em->flush();

        return $this->json(['message' => 'Faction dissoute']);
    }
}
