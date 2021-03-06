<?php

namespace AppBundle\Security;

use AppBundle\Entity\User;
use AppBundle\Entity\Version;
use Doctrine\ORM\EntityManagerInterface;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Client\Provider\GithubClient;
use KnpU\OAuth2ClientBundle\Security\Authenticator\SocialAuthenticator;
use Swarrot\Broker\Message;
use Swarrot\SwarrotBundle\Broker\Publisher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class GithubAuthenticator extends SocialAuthenticator
{
    private $clientRegistry;
    private $em;
    private $router;
    private $publisher;

    public function __construct(ClientRegistry $clientRegistry, EntityManagerInterface $em, RouterInterface $router, Publisher $publisher)
    {
        $this->clientRegistry = $clientRegistry;
        $this->em = $em;
        $this->router = $router;
        $this->publisher = $publisher;
    }

    public function supports(Request $request)
    {
        return 'github_callback' === $request->attributes->get('_route');
    }

    public function getCredentials(Request $request)
    {
        return $this->fetchAccessToken($this->getGithubClient());
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $githubUser = $this->getGithubClient()->fetchUserFromToken($credentials);

        $user = $this->em->getRepository(User::class)->find($githubUser->getId());

        // always update user information at login
        if (null === $user) {
            $user = new User();
        }

        $user->setAccessToken($credentials->getToken());
        $user->hydrateFromGithub($githubUser);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception)
    {
        // failure, what failure?
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        $versions = $this->em->getRepository(Version::class)->findForUser($token->getUser()->getId());

        // if no versions were found, it means the user logged in for the first time
        // and we need to display an explanation message
        $message = 'Successfully logged in!';
        if (empty($versions)) {
            $message = 'Successfully logged in. Your starred repos will soon be synced!';
        }

        $request->getSession()->getFlashBag()->add('info', $message);

        $message = [
            'user_id' => $token->getUser()->getId(),
        ];

        $this->publisher->publish(
            'banditore.sync_starred_repos.publisher',
            new Message(json_encode($message))
        );

        return new RedirectResponse($this->router->generate('dashboard'));
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        return new RedirectResponse($this->router->generate('connect'));
    }

    /**
     * @return GithubClient
     */
    private function getGithubClient()
    {
        return $this->clientRegistry->getClient('github');
    }
}
