<?php

namespace App\Controller;

use App\Dto\PageQuery;
use App\Dto\UserInput;
use App\Dto\UserUpdateInput;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AuditSubscriber;
use App\Security\Role;
use App\Security\UserView;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Comptes, réservés aux administrateurs (ADR 0010). Garde-fous : on ne supprime ni ne rétrograde son
 * propre compte, et il reste toujours au moins un administrateur.
 */
#[Route('/api/users')]
#[IsGranted('ROLE_ADMIN')]
final class UserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        #[Target('audit')]
        private readonly LoggerInterface $audit,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(#[MapQueryString(validationFailedStatusCode: 422)] PageQuery $page = new PageQuery()): JsonResponse
    {
        return new JsonResponse($page->envelope(
            array_map(UserView::of(...), $this->users->findBy([], ['username' => 'ASC'], $page->pageSize, $page->offset())),
            $this->users->count([]),
        ) + ['roles' => array_map(static fn (Role $r) => ['id' => $r->value, 'label' => $r->label()], Role::cases())]);
    }

    #[Route('', methods: ['POST'])]
    public function create(#[CurrentUser] User $admin, #[MapRequestPayload] UserInput $input, Request $request): JsonResponse
    {
        if (null !== $this->users->findOneBy(['username' => $input->username])) {
            throw new ConflictHttpException(\sprintf('L\'identifiant « %s » est déjà pris.', $input->username));
        }
        $user = new User($input->username, $input->role);
        $user->setPassword($this->hasher->hashPassword($user, $input->password));
        $this->em->persist($user);
        $this->em->flush();
        $this->audit->info('Compte créé', AuditSubscriber::context($request, $admin->getUsername()) + ['account' => $user->getUsername(), 'role' => $user->getRole()->value]);

        return new JsonResponse(UserView::of($user), 201);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => Requirement::UUID_V7])]
    public function update(#[CurrentUser] User $admin, User $user, #[MapRequestPayload] UserUpdateInput $input, Request $request): JsonResponse
    {
        if (null !== $input->role && $input->role !== $user->getRole()) {
            if ($user === $admin) {
                throw new ConflictHttpException('Vous ne pouvez pas changer votre propre rôle : demandez-le à un autre administrateur.');
            }
            $this->keepAnAdmin($user);
            $this->audit->notice('Rôle modifié', AuditSubscriber::context($request, $admin->getUsername()) + ['account' => $user->getUsername(), 'from' => $user->getRole()->value, 'to' => $input->role->value]);
            $user->setRole($input->role);
        }
        if (null !== $input->password && '' !== $input->password) {
            $user->setPassword($this->hasher->hashPassword($user, $input->password));
            $this->audit->notice('Mot de passe réinitialisé par un administrateur', AuditSubscriber::context($request, $admin->getUsername()) + ['account' => $user->getUsername()]);
        }
        $this->em->flush();

        return new JsonResponse(UserView::of($user));
    }

    /** Téléphone perdu : le titulaire se reconnecte avec son seul mot de passe, puis réactive la double authentification. */
    #[Route('/{id}/2fa', methods: ['DELETE'], requirements: ['id' => Requirement::UUID_V7])]
    public function resetTwoFactor(#[CurrentUser] User $admin, User $user, Request $request): JsonResponse
    {
        $user->disableTotp();
        $this->em->flush();
        $this->audit->notice('Double authentification réinitialisée par un administrateur', AuditSubscriber::context($request, $admin->getUsername()) + ['account' => $user->getUsername()]);

        return new JsonResponse(UserView::of($user));
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => Requirement::UUID_V7])]
    public function delete(#[CurrentUser] User $admin, User $user, Request $request): JsonResponse
    {
        if ($user === $admin) {
            throw new ConflictHttpException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        $this->keepAnAdmin($user);
        $this->em->remove($user);
        $this->em->flush();
        $this->audit->notice('Compte supprimé', AuditSubscriber::context($request, $admin->getUsername()) + ['account' => $user->getUsername()]);

        return new JsonResponse(null, 204);
    }

    private function keepAnAdmin(User $user): void
    {
        if (Role::Admin === $user->getRole() && $this->users->count(['role' => Role::Admin]) <= 1) {
            throw new ConflictHttpException('Il doit rester au moins un administrateur.');
        }
    }
}
