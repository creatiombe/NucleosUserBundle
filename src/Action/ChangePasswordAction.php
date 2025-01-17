<?php

declare(strict_types=1);

/*
 * This file is part of the NucleosUserBundle package.
 *
 * (c) Christian Gripp <mail@core23.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nucleos\UserBundle\Action;

use Nucleos\UserBundle\Event\FilterUserResponseEvent;
use Nucleos\UserBundle\Event\FormEvent;
use Nucleos\UserBundle\Event\GetResponseUserEvent;
use Nucleos\UserBundle\Form\Model\ChangePassword;
use Nucleos\UserBundle\Form\Type\ChangePasswordFormType;
use Nucleos\UserBundle\Model\UserInterface;
use Nucleos\UserBundle\Model\UserManager;
use Nucleos\UserBundle\NucleosUserEvents;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

final class ChangePasswordAction
{
    private Environment $twig;

    private RouterInterface $router;

    private Security $security;

    private EventDispatcherInterface $eventDispatcher;

    private FormFactoryInterface $formFactory;

    private UserManager $userManager;

    private UserPasswordHasherInterface $passwordHasher;

    private string $loggedinRoute;

    public function __construct(
        Environment $twig,
        RouterInterface $router,
        Security $security,
        EventDispatcherInterface $eventDispatcher,
        FormFactoryInterface $formFactory,
        UserManager $userManager,
        UserPasswordHasherInterface $passwordHasher,
        string $loggedinRoute
    ) {
        $this->twig            = $twig;
        $this->router          = $router;
        $this->security        = $security;
        $this->eventDispatcher = $eventDispatcher;
        $this->formFactory     = $formFactory;
        $this->userManager     = $userManager;
        $this->passwordHasher  = $passwordHasher;
        $this->loggedinRoute   = $loggedinRoute;
    }

    /**
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();

        if (!\is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        $event = new GetResponseUserEvent($user, $request);
        $this->eventDispatcher->dispatch($event, NucleosUserEvents::CHANGE_PASSWORD_INITIALIZE);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }

        $form = $this->createForm($formModel = new ChangePassword());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $event = new FormEvent($form, $request);
            $this->eventDispatcher->dispatch($event, NucleosUserEvents::CHANGE_PASSWORD_SUCCESS);

            $this->updatePassword($user, $formModel);

            if (null === $response = $event->getResponse()) {
                $url      = $this->router->generate($this->loggedinRoute);
                $response = new RedirectResponse($url);
            }

            $this->eventDispatcher->dispatch(new FilterUserResponseEvent($user, $request, $response), NucleosUserEvents::CHANGE_PASSWORD_COMPLETED);

            return $response;
        }

        return new Response($this->twig->render('@NucleosUser/ChangePassword/change_password.html.twig', [
            'form' => $form->createView(),
        ]));
    }

    private function createForm(ChangePassword $model): FormInterface
    {
        return $this->formFactory
            ->create(ChangePasswordFormType::class, $model, [
                'validation_groups' => ['ChangePassword', 'Default'],
            ])
            ->add('save', SubmitType::class, [
                'label'  => 'change_password.submit',
            ])
        ;
    }

    private function updatePassword(UserInterface $user, ChangePassword $model): void
    {
        if (null === $model->getPlainPassword()) {
            return;
        }

        $user->setPassword(
            $this->passwordHasher->hashPassword($user, $model->getPlainPassword())
        );

        $this->userManager->updateUser($user);
    }
}
