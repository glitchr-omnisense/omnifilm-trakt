# omnifilm/trakt

Trakt for [omnisense/omnifilm](https://github.com/glitchr-omnisense/omnifilm), through Trakt's
public API (`https://api.trakt.tv`) with an application's client id, no user signed in: search, a
film's or a show's record and its people, a work or an episode by its Trakt, IMDb, TMDB or TheTVDB
number, a season's episodes. The provider calls:

| Operation | Trakt API |
|---|---|
| search | `GET /search/movie`, `/search/show` or `/search/movie,show` with `query`, `years`, `extended=full`, `limit` |
| by another register | `GET /search/{imdb\|tmdb\|tvdb}/{id}?type=movie\|show\|episode` |
| a film, a show | `GET /movies/{id}` or `/shows/{id}` with `extended=full`, then `/people` |
| an episode | the search above, then `GET /shows/{show}/seasons/{n}/episodes/{m}?extended=full` |
| a season | `GET /shows/{id}/seasons/{n}?extended=full` |

Every request sends `trakt-api-version: 2`, `trakt-api-key: <client id>`, `Content-Type:
application/json` and the configured User-Agent. Trakt serves no pictures: its records carry the
TMDB and TheTVDB numbers that lead to them (`Registry::work()` with omnifilm/tmdb). No client taking
the application's HTTP client without a framework was found to reuse.

## Installation

```sh
composer require omnifilm/trakt
```

Requires PHP 8.2, `omnisense/omnifilm` (1.x) and `symfony/http-client` (6.4, 7 or 8).

## Credentials

An **application's client id**: an application registered on Trakt (its API apps). Only the client
id is used - no OAuth, no user. Trakt asks every application to send a User-Agent naming it.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | sent as `trakt-api-key` |
| `user_agent` | string | - | yes | `MySite/1.0 (+https://example.org)` |
| `api` | string | `https://api.trakt.tv` | no | the API's base URL |
| `cast` | int | `20` | no | the cast members kept |

No language: Trakt's records are read untranslated, and the `language` arguments are ignored.

## Capabilities

| Contract | |
|---|---|
| `SearchInterface` | films, series |
| `LookupInterface` | film: trakt, imdb, tmdb; series and episode: trakt, imdb, tmdb, tvdb |
| `EpisodesInterface` | yes |
| `AvailabilityInterface` | no |
| images | no; credits yes; a client id is needed |

`attribution`: `Data from Trakt (trakt.tv)`.

## Behaviour

**Search.** Trakt answers a list of `{type, movie|show}`; films and shows are kept, cut to the
limit. With `extended=full` each carries its numbers, title, year, overview, genres, runtime and
rating; no people.

**A record.**

| Field | Read from |
|---|---|
| `title`, `year` | `title`; `year`, else the release's |
| `released` | a film: `released`; a show: `first_aired` |
| `runtime`, `overview` | `runtime`, `overview` |
| `genres` | `genres` (Trakt's slugs: `science-fiction`) |
| `countries` | `country`, upper-cased |
| `languages` | `languages`, else `language` |
| `identifiers` | `ids`: Trakt, IMDb, TMDB, TheTVDB numbers (the slug is not kept) |
| `credits` | from `/people`: crew of the `directing`, `writing` departments, of `production` with the job `Producer`, of `sound` with a composer's job (`Original Music Composer`, `Music`, `Composer`), each with its jobs joined; then the cast, `cast` at most, characters joined by ` / `, order their rank; each person's Trakt, IMDb, TMDB, TheTVDB numbers |
| `ratings` | `trakt`, `rating` out of 10 with `votes`, when there are votes |
| `url` | `https://trakt.tv/movies/<slug>` or `/shows/<slug>` |

`season` is not set on a show. An episode: title (else `Episode <n>`), `first_aired`, runtime,
overview, rating, its numbers, `series` the show's numbers (only its Trakt number in a season's
list), season and number, page `https://trakt.tv/shows/<slug>/seasons/<n>/episodes/<m>`.

**A season.** One call for a show named by its Trakt number; another register is looked up first
(two or three calls). Episodes come in Trakt's order. A ref whose type is not `series` gives `[]`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifilm\Model\Role;
use Omnifilm\Model\WorkRef;
use Omnifilm\Trakt\TraktFactory;
use Symfony\Component\HttpClient\HttpClient;

$trakt = (new TraktFactory(HttpClient::create()))->create([
    'client_id' => getenv('TRAKT_CLIENT_ID'),
    'user_agent' => 'MySite/1.0 (+https://example.org)',
]);

$matrix = $trakt->work(WorkRef::imdb('tt0133093'));
echo $matrix->title, ' - TMDB ', $matrix->identifiers->get('tmdb'), "\n";      // the key to its pictures at TMDB
foreach ($matrix->credits(Role::Cast) as $credit) {
    echo $credit->name, ' as ', $credit->character, "\n";
}
```

The record read from the package's test answers (written after Trakt's reference; no client id was
used), in the core's harness on 2026-10-26 - each field of the `Work`, then the calls made:

```
The Matrix / -, 1999, 1999-03-31, 136 min, season -
  ids {"imdb":"tt0133093","tmdb":"603","trakt":"481"}
  genres ["action","science-fiction"] countries ["US"] languages ["en"]
  director: Lana Wachowski [Director]
  writer: Lilly Wachowski [Writer]
  producer: Joel Silver [Producer]
  composer: Don Davis [Original Music Composer]
  cast: Keanu Reeves as Thomas A. Anderson / Neo #0
  cast: Laurence Fishburne as Morpheus #1
  rating trakt 8.4/10 (60000)
  https://trakt.tv/movies/the-matrix-1999
  > GET https://api.trakt.tv/search/imdb/tt0133093 {"type":"movie"}
  > GET https://api.trakt.tv/movies/481 {"extended":"full"}
  > GET https://api.trakt.tv/movies/481/people []
```

In Symfony:

```yaml
services:
    Omnifilm\Trakt\TraktFactory:
        arguments: ['@http_client']
    Omnifilm\Registry:
        arguments:
            $factories: ['@Omnifilm\Trakt\TraktFactory']
            $config:
                trakt: { factory: trakt, options: { client_id: '%env(TRAKT_CLIENT_ID)%', user_agent: 'MySite/1.0 (+https://example.org)' } }
```

## Errors

- a status of 400 or more but 404 (a 403 for a client id refused, a 429 past the rate limit):
  `ProviderException` naming the path; a 404: `null`; a network error: `UnreachableException`
  (`Trakt could not be reached for GET /movies/481: ...`; the client id travels in a header, not in
  the URL);
- a missing `client_id` or `user_agent`: `InvalidConfigException` (`The "trakt" provider needs:
  client_id, user_agent.`);
- a film by its TheTVDB number, any Wikidata number: `NotSupportedException` before any call.

## Verified, and not

Tested offline (`Tests/TraktProviderTest.php`, in the core's harness, PHP 8.4): a search with the
application's headers, a film by its IMDb number with its people, an episode by its IMDb number and
a season, not found as `null` and a refusal as an error, the User-Agent required. The answers are
shaped after Trakt's API reference and written by hand. `Tests/BareTest.php` loads every class with
the package's requirements alone.

Not verified: no client id was used; nothing was called on Trakt. Its reference did not render for
a re-reading on 2026-10-24 (README).

## Responsibility

An independent, unofficial client, affiliated with Trakt in no way. Whoever runs it keeps the
limits of Trakt's [API reference](https://trakt.docs.apiary.io) (1,000 GET calls every 5 minutes
per application, as documented) and its [terms](https://trakt.tv/terms): see the README.
