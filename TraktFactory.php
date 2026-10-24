<?php

namespace Omnifilm\Trakt;

use Omnifilm\Config;
use Omnifilm\Http\JsonClient;
use Omnifilm\ProviderFactory;
use Omnifilm\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "trakt": options client_id (required: the application's, sent as
 * trakt-api-key), user_agent (Trakt asks every application to name itself),
 * api ("https://api.trakt.tv"), cast (20: the cast members kept).
 */
final class TraktFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnifilm.factory_name' => 'trakt',
            'omnifilm.factory_title' => 'Trakt',
            'omnifilm.required_options' => ['client_id', 'user_agent'],
            'api' => 'https://api.trakt.tv',
            'cast' => 20,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        return new TraktProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Trakt', [
            'Content-Type' => 'application/json',
            'trakt-api-version' => '2',
            'trakt-api-key' => (string) $c->string('client_id'),
            'User-Agent' => (string) $c->string('user_agent'),
        ]), rtrim((string) $c->string('api'), '/'), $c->int('cast', 20));
    }
}
