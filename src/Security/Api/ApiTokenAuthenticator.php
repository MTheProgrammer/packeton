<?php

declare(strict_types=1);

namespace Packeton\Security\Api;

use Packeton\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ApiTokenAuthenticator implements AuthenticatorInterface, AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly UserProviderInterface $userProvider,
        private readonly UserCheckerInterface $userChecker,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param ApiUsernamePasswordToken $token
     *
     * {@inheritdoc}
     */
    public function authenticate(Request $request): Passport
    {
        if (!$credentials = $this->getCredentials($request)) {
            throw new AuthenticationException('The token is not supported by this authentication provider.');
        }

        [$username, $token] = $credentials;

        return new SelfValidatingPassport(
            new UserBadge($username, fn() => $this->validateAndLoadUser($username, $token)),
        );
    }

    /**
     * {@inheritdoc}
     */
    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        $message = $authException->getMessage();
        if ($authException instanceof AuthenticationCredentialsNotFoundException) {
            $message = 'Authorization Required';
        }

        return new JsonResponse(['status' => 'error', 'message' => $message], 401);
    }

    /**
     * @param string $username
     * @param string $token
     * @return User
     */
    public function validateAndLoadUser(string $username, $token)
    {
        $user = $this->retrieveUser($username);
        if ($user->getApiToken() && \strlen($user->getApiToken()) > 6 && $user->getApiToken() === $token) {
            return $user;
        }

        throw new BadCredentialsException('Bad credentials.');
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request): bool
    {
        if ($request->headers->has('PHP_AUTH_USER')
            || ($request->query->get('apiToken') && $request->query->get('username'))
        ) {
            return true;
        }

        if ($username = $request->query->get('token')) {
            $username = \explode(':', $username);
            if (2 === \count($username)) {
                return true;
            }
        }

        return false;
    }

    private function getCredentials(Request $request)
    {
        if ($username = $request->headers->get('PHP_AUTH_USER')) {
            $credentials = $request->headers->get('PHP_AUTH_PW');
            $this->logger->info('Basic authentication Authorization header found for user.', ['username' => $username]);
        } elseif ($request->query->get('apiToken') && $request->query->get('username')) {
            $credentials = $request->query->get('apiToken');
            $username = $request->query->get('username');
        } elseif ($username = $request->query->get('token')) {
            $username = \explode(':', $username);
            if (2 !== \count($username)) {
                return null;
            }

            list($username, $credentials) = $username;
            $this->logger->info('Api authorization header found for user.', ['username' => $username]);
        } else {
            return null;
        }

        return [$username, $credentials];
    }

    /**
     * @param string $username
     * @param TokenInterface $token
     *
     * @return User
     */
    protected function retrieveUser(string $username): User
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
            $this->userChecker->checkPreAuth($user);
        } catch (AuthenticationException $e) {
            throw new BadCredentialsException('Bad credentials.', 0, $e);
        } catch (\Exception $e) {
            throw new AuthenticationServiceException($e->getMessage(), 0, $e);
        }

        if (!$user instanceof User) {
            throw new AuthenticationServiceException('The user provider must return a UserInterface object.');
        }

        return $user;
    }

    /**
     * {@inheritdoc}
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        return new ApiUsernamePasswordToken($passport->getUser(), $firewallName, $passport->getUser()->getRoles());
    }

    /**
     * @param Passport $passport
     * {@inheritdoc}
     */
    public function createAuthenticatedToken($passport, string $firewallName): TokenInterface
    {
        return $this->createToken($passport, $firewallName);
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->info('Composer authentication failed for user.', ['exception' => $exception]);

        return $this->start($request, $exception);
    }
}
