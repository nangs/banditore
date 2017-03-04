<?php

namespace AppBundle\Github;

use AppBundle\Repository\UserRepository;
use Cache\Adapter\Predis\PredisCachePool;
use Github\Client as GithubClient;
use Http\Client\Exception\HttpException;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * This class aim to find the best authenticated method to avoid hitting the Github rate limit.
 * We first try with the default application authentication.
 * And if it fails, we'll try each user until we find one with enough rate limit.
 * In fact, the more user in database, the bigger chance to never hit the rate limit.
 */
class ClientDiscovery
{
    const THRESHOLD_BAD_AUTH = 50;

    private $userRepository;
    private $redis;
    private $clientId;
    private $clientSecret;
    private $logger;
    private $client;

    public function __construct(UserRepository $userRepository, RedisClient $redis, $clientId, $clientSecret, LoggerInterface $logger)
    {
        $this->userRepository = $userRepository;
        $this->redis = $redis;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->logger = $logger;

        $this->client = new GithubClient();
    }

    /**
     * Allow to override Github client.
     * Only used in test.
     *
     * @param GithubClient $client
     */
    public function setGithubClient(GithubClient $client)
    {
        $this->client = $client;
    }

    /**
     * Find the best authentication to use:
     *     - check the rate limit of the application default client (which should be used in most case)
     *     - if the rate limit is too low for the application client, loop on all user to check their rate limit
     *     - if none client have enough rate limit, we'll have a problem to perform further request, stop every thing !
     *
     * @return GithubClient
     */
    public function find()
    {
        // attache the cache in anycase
        $this->client->addCache(
            new PredisCachePool($this->redis),
            [
                // the default config include "private" to avoid caching request with this header
                // since we can use a user token, Github will return a "private" but we want to cache that request
                // it's safe because we don't require critical user value
                'respect_response_cache_directives' => ['no-cache', 'max-age', 'no-store'],
            ]
        );

        // try with the application default client
        $this->client->authenticate($this->clientId, $this->clientSecret, GithubClient::AUTH_URL_CLIENT_ID);

        if ($this->getRateLimits() >= self::THRESHOLD_BAD_AUTH) {
            $this->logger->info('RateLimit ok with default application');

            return $this->client;
        }

        // if it doesn't work, try with all user tokens
        // when at least one is ok, use it!
        $users = $this->userRepository->findAllTokens();
        foreach ($users as $user) {
            $this->client->authenticate($user['accessToken'], null, GithubClient::AUTH_HTTP_TOKEN);

            if ($this->getRateLimits() >= self::THRESHOLD_BAD_AUTH) {
                $this->logger->info('RateLimit ok with user: ' . $user['username']);

                return $this->client;
            }
        }

        throw new \RuntimeException('No way to authenticate a client with enough rate limit remaining :(');
    }

    /**
     * Retrieve rate limit for the current authenticated client.
     * It's in a separate method to be able to catch error in case of glimpse on the Github side.
     *
     * @return false|int
     */
    private function getRateLimits()
    {
        try {
            $rateLimit = $this->client->api('rate_limit')->getRateLimits();

            return $rateLimit['resources']['core']['remaining'];
        } catch (HttpException $e) {
            $this->logger->error('RateLimit call goes bad.', ['exception' => $e]);

            return false;
        }
    }
}