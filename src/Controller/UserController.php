<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class UserController extends AbstractController
{
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Récupérer les données JSON
        $data = json_decode($request->getContent(), true);

        // Validation basique
        if (!isset($data['email']) || !isset($data['password'])) {
            return $this->json(['error' => 'Email et password requis'], 400);
        }

        // Vérifier si l'utilisateur existe déjà
        $existingUser = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['error' => 'Cet email est déjà utilisé'], 400);
        }

        // Créer un nouvel utilisateur
        $user = new User();
        $user->setEmail($data['email']);

        // Hasher le mot de passe (MD5 comme dans le cours)
        $hashedPassword = md5($data['password']);
        $user->setPassword($hashedPassword);

        // Définir les rôles
        $user->setRoles(['ROLE_USER']);

        // Pseudo Minecraft optionnel
        if (isset($data['pseudoMinecraft'])) {
            $user->setPseudoMinecraft($data['pseudoMinecraft']);
        }

        // UUID Minecraft optionnel
        if (isset($data['uuidMinecraft'])) {
            $user->setUuidMinecraft($data['uuidMinecraft']);
        }

        // Crédits initiaux
        $user->setCredits(1000); // Bonus de départ

        // Date d'inscription
        $user->setDateInscription(new \DateTimeImmutable());

        // Sauvegarder en base
        $em->persist($user);
        $em->flush();

        return $this->json([
            'message' => 'Inscription réussie',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'pseudoMinecraft' => $user->getPseudoMinecraft(),
                'credits' => $user->getCredits()
            ]
        ], 201);
    }

    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Récupérer les données JSON
        $data = json_decode($request->getContent(), true);

        // Validation
        if (!isset($data['email']) || !isset($data['password'])) {
            return $this->json(['error' => 'Email et password requis'], 400);
        }

        // Chercher l'utilisateur
        $user = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user) {
            return $this->json(['error' => 'Identifiants incorrects'], 401);
        }

        // Vérifier le mot de passe
        $hashedPassword = md5($data['password']);
        if ($user->getPassword() !== $hashedPassword) {
            return $this->json(['error' => 'Identifiants incorrects'], 401);
        }

        // Générer un token unique
        $token = bin2hex(random_bytes(32));

        // Stocker le token (pour simplifier, on le met dans un champ de l'entité User)
        // Note : Dans un vrai projet, il faudrait une entité Token séparée
        $user->setApiToken($token);
        $em->flush();

        return $this->json([
            'message' => 'Connexion réussie',
            'token' => $token,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'pseudoMinecraft' => $user->getPseudoMinecraft(),
                'credits' => $user->getCredits()
            ]
        ]);
    }

    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(Request $request, EntityManagerInterface $em): JsonResponse
    {
        // Récupérer le token depuis le header Authorization
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Token manquant'], 401);
        }

        // Extraire le token
        $token = substr($authHeader, 7); // Enlever "Bearer "

        // Chercher l'utilisateur par le token
        $user = $em->getRepository(User::class)->findOneBy(['apiToken' => $token]);

        if (!$user) {
            return $this->json(['error' => 'Token invalide'], 401);
        }

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'pseudoMinecraft' => $user->getPseudoMinecraft(),
            'uuidMinecraft' => $user->getUuidMinecraft(),
            'credits' => $user->getCredits(),
            'dateInscription' => $user->getDateInscription()->format('Y-m-d H:i:s'),
            'roles' => $user->getRoles()
        ]);
    }

    #[Route('/me', name: 'api_update_me', methods: ['PUT'])]
    public function updateMe(Request $request, EntityManagerInterface $em): JsonResponse
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

        // Récupérer les données à modifier
        $data = json_decode($request->getContent(), true);

        // Mise à jour du pseudo Minecraft
        if (isset($data['pseudoMinecraft'])) {
            $user->setPseudoMinecraft($data['pseudoMinecraft']);
        }

        // Mise à jour de l'UUID Minecraft
        if (isset($data['uuidMinecraft'])) {
            $user->setUuidMinecraft($data['uuidMinecraft']);
        }

        // Sauvegarder
        $em->flush();

        return $this->json([
            'message' => 'Profil mis à jour',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'pseudoMinecraft' => $user->getPseudoMinecraft(),
                'uuidMinecraft' => $user->getUuidMinecraft(),
                'credits' => $user->getCredits()
            ]
        ]);
    }
}
