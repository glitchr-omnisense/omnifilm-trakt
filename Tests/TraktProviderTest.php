<?php

namespace Omnifilm\Trakt\Tests;

use Omnifilm\Exception\InvalidConfigException;
use Omnifilm\Exception\ProviderException;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Query;
use Omnifilm\Model\Role;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\Testing\RecordedHttpClient;
use Omnifilm\Trakt\TraktFactory;
use Omnifilm\Trakt\TraktProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trakt's answers shaped after its API reference (trakt.docs.apiary.io),
 * values written by hand: no client id was used, nothing here was captured
 * from Trakt.
 */
final class TraktProviderTest extends TestCase
{
    private const OPTIONS = ['client_id' => 'not-a-real-trakt-client-id', 'user_agent' => 'omnifilm-tests/1.0 (+https://github.com/glitchr-studio/omnifilm)'];

    public function testASearchWithTheApplicationsHeaders(): void
    {
        $http = self::http();
        $works = self::provider($http)->search(new Query('matrix', year: 1999));

        self::assertSame([['The Matrix', 'movie', 1999], ['The Matrix Show', 'series', null]], array_map(static fn (Work $w) => [$w->title, $w->type->value, $w->year], $works));
        self::assertSame(['imdb' => 'tt0133093', 'tmdb' => '603', 'trakt' => '481'], $works[0]->identifiers->toArray());
        self::assertSame(['US', ['en'], ['action', 'science-fiction']], [$works[0]->countries[0] ?? null, $works[0]->languages, $works[0]->genres]);
        self::assertSame('https://api.trakt.tv/search/movie,show', $http->requests[0]['url']);
        self::assertSame(['2', 'not-a-real-trakt-client-id'], [$http->header('trakt-api-version'), $http->header('trakt-api-key')]);
        self::assertSame([], $works[0]->images, 'Trakt serves no pictures');
    }

    public function testAFilmByItsImdbNumberWithItsPeople(): void
    {
        $work = self::provider(self::http())->work(WorkRef::imdb('tt0133093'));

        self::assertNotNull($work);
        self::assertSame(['The Matrix', '1999-03-31', 136, 'https://trakt.tv/movies/the-matrix-1999'], [$work->title, $work->released?->format('Y-m-d'), $work->runtime, $work->url]);
        self::assertSame([['Keanu Reeves', 'Thomas A. Anderson / Neo', 0], ['Laurence Fishburne', 'Morpheus', 1]], array_map(static fn (Credit $c) => [$c->name, $c->character, $c->order], $work->credits(Role::Cast)));
        self::assertSame(['Lana Wachowski'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Director)));
        self::assertSame(['Joel Silver'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Producer)));
        self::assertSame(['Don Davis'], array_map(static fn (Credit $c) => $c->name, $work->credits(Role::Composer)));
        self::assertSame('nm0000206', $work->credits(Role::Cast)[0]->identifiers->get('imdb'));
        self::assertSame([8.4, 60000], [$work->ratings[0]->value ?? null, $work->ratings[0]->votes ?? null]);
    }

    public function testAnEpisodeByItsImdbNumberAndASeason(): void
    {
        $provider = self::provider(self::http());
        $episode = $provider->work(WorkRef::imdb('tt0000001', WorkType::Episode));

        self::assertNotNull($episode);
        self::assertSame(['Pilot', 1, 1, '1990-04-09', '70533', 'https://trakt.tv/shows/twin-peaks/seasons/1/episodes/1'], [$episode->title, $episode->season, $episode->number, $episode->released?->format('Y-m-d'), $episode->series?->get('tvdb'), $episode->url]);

        $episodes = $provider->episodes(WorkRef::trakt(1390, WorkType::Series), 1);
        self::assertSame([[1, 'Pilot', 1], [2, 'Traces to Nowhere', 0]], array_map(static fn (Work $w) => [$w->number, $w->title, \count($w->ratings)], $episodes));
    }

    public function testNotFoundIsNullAndARefusalAnError(): void
    {
        self::assertNull(self::provider(self::http())->work(WorkRef::trakt(1)));
        self::assertNull(self::provider(self::http())->work(WorkRef::imdb('tt0000000')));

        $http = RecordedHttpClient::routes(['~~' => [403, '']]);
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Trakt answered 403 for GET /movies/481.');
        self::provider($http)->work(WorkRef::trakt(481));
    }

    public function testAUserAgentIsRequired(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new TraktFactory(self::http()))->create(['client_id' => 'x']);
    }

    private static function http(): RecordedHttpClient
    {
        $f = '@'.__DIR__.'/Fixtures/';
        $api = '~^GET https://api\.trakt\.tv/';

        return RecordedHttpClient::routes([
            $api.'search/movie,show\?query=matrix&years=1999~' => [200, $f.'search.json'],
            $api.'search/imdb/tt0133093\?type=movie~' => [200, $f.'find-imdb.json'],
            $api.'search/imdb/tt0000001\?type=episode~' => [200, $f.'find-episode.json'],
            $api.'search/~' => [200, '[]'],
            $api.'movies/481/people~' => [200, $f.'people-481.json'],
            $api.'movies/481\?~' => [200, $f.'movie-481.json'],
            $api.'shows/1390/seasons/1/episodes/1\?~' => [200, $f.'episode.json'],
            $api.'shows/1390/seasons/1\?~' => [200, $f.'season.json'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private static function provider(RecordedHttpClient $http, array $options = []): TraktProvider
    {
        $provider = (new TraktFactory($http))->create($options + self::OPTIONS);
        self::assertInstanceOf(TraktProvider::class, $provider);

        return $provider;
    }
}
