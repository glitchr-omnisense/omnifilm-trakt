<?php

namespace Omnifilm\Trakt;

use Omnifilm\Capabilities;
use Omnifilm\EpisodesInterface;
use Omnifilm\Exception\NotSupportedException;
use Omnifilm\Http\Data;
use Omnifilm\Http\JsonClient;
use Omnifilm\LookupInterface;
use Omnifilm\Model\Credit;
use Omnifilm\Model\Identifiers;
use Omnifilm\Model\Query;
use Omnifilm\Model\Rating;
use Omnifilm\Model\Role;
use Omnifilm\Model\Scheme;
use Omnifilm\Model\Work;
use Omnifilm\Model\WorkRef;
use Omnifilm\Model\WorkType;
use Omnifilm\ProviderInterface;
use Omnifilm\SearchInterface;

/**
 * Trakt's public API with an application's client id (no user signed in):
 * search, a film's or a show's record (extended=full) and its people, a
 * work by its Trakt, IMDb, TMDB or TheTVDB number, an episode, a season's
 * episodes. Trakt serves no pictures: its records carry the TMDB and
 * TheTVDB numbers that lead to them.
 */
final class TraktProvider implements ProviderInterface, SearchInterface, LookupInterface, EpisodesInterface
{
    private const CREW = ['directing' => Role::Director, 'writing' => Role::Writer, 'production' => Role::Producer, 'sound' => Role::Composer];

    public function __construct(private readonly JsonClient $json, private readonly string $api = 'https://api.trakt.tv', private readonly int $cast = 20)
    {
    }

    public function getName(): string
    {
        return 'trakt';
    }

    public function getTitle(): string
    {
        return 'Trakt';
    }

    public function capabilities(): Capabilities
    {
        $all = [Scheme::Trakt, Scheme::Imdb, Scheme::Tmdb, Scheme::Tvdb];

        return new Capabilities(
            search: true,
            lookup: true,
            episodes: true,
            types: [WorkType::Movie, WorkType::Series, WorkType::Episode],
            lookupBy: ['movie' => [Scheme::Trakt, Scheme::Imdb, Scheme::Tmdb], 'series' => $all, 'episode' => $all],
            credits: true,
            attribution: 'Data from Trakt (trakt.tv)',
            terms: ['https://trakt.docs.apiary.io', 'https://trakt.tv/terms'],
        );
    }

    public function search(Query $query): array
    {
        $types = match ($query->type) {
            WorkType::Movie => 'movie',
            WorkType::Series => 'show',
            default => 'movie,show',
        };
        $answer = $this->json->get($this->api.'/search/'.$types, ['query' => $query->text, 'years' => $query->year, 'extended' => 'full', 'limit' => $query->limit]);
        $works = [];
        foreach ($answer?->items() ?? [] as $result) {
            $type = $result->string('type');
            if ('movie' === $type || 'show' === $type) {
                $works[] = $this->work0($result->data($type), 'movie' === $type ? WorkType::Movie : WorkType::Series);
            }
        }

        return \array_slice($works, 0, $query->limit);
    }

    public function work(WorkRef $ref, ?string $language = null): ?Work
    {
        if (!$this->capabilities()->resolves($ref)) {
            throw NotSupportedException::ref($this->getTitle(), $ref);
        }
        $kind = match ($ref->type) {
            WorkType::Movie => 'movie',
            WorkType::Series => 'show',
            WorkType::Episode => 'episode',
        };
        if (WorkType::Episode === $ref->type) {
            $found = $this->json->get($this->api.'/search/'.$ref->scheme->value.'/'.rawurlencode($ref->id), ['type' => 'episode'])?->items()[0] ?? null;
            $show = $found?->string('show.ids.trakt');
            $season = $found?->int('episode.season');
            $number = $found?->int('episode.number');
            if (null === $show || null === $season || null === $number) {
                return null;
            }
            $episode = $this->json->get($this->api.'/shows/'.$show.'/seasons/'.$season.'/episodes/'.$number, ['extended' => 'full']);

            return null === $episode ? null : $this->episode($episode, $found->data('show.ids'));
        }
        $id = Scheme::Trakt === $ref->scheme ? $ref->id : ($this->json->get($this->api.'/search/'.$ref->scheme->value.'/'.rawurlencode($ref->id), ['type' => $kind])?->items()[0] ?? null)?->string($kind.'.ids.trakt');
        if (null === $id) {
            return null;
        }
        $path = $this->api.'/'.$kind.'s/'.rawurlencode($id);
        $data = $this->json->get($path, ['extended' => 'full']);
        if (null === $data) {
            return null;
        }

        return $this->work0($data, $ref->type, $this->json->get($path.'/people') ?? new Data([]));
    }

    public function episodes(WorkRef $series, int $season, ?string $language = null): array
    {
        if (WorkType::Series !== $series->type) {
            return [];
        }
        $show = Scheme::Trakt === $series->scheme ? $series->id : $this->work($series)?->identifiers->get(Scheme::Trakt);
        if (null === $show) {
            return [];
        }
        $answer = $this->json->get($this->api.'/shows/'.rawurlencode($show).'/seasons/'.$season, ['extended' => 'full']);
        $ids = new Data(['trakt' => $show]);

        return array_map(fn (Data $episode) => $this->episode($episode, $ids), $answer?->items() ?? []);
    }

    private function work0(Data $data, WorkType $type, ?Data $people = null): Work
    {
        $movie = WorkType::Movie === $type;
        $released = $data->date($movie ? 'released' : 'first_aired');
        $credits = [];
        foreach (self::CREW as $department => $role) {
            foreach ($people?->list('crew.'.$department) ?? [] as $member) {
                $jobs = $member->strings('jobs');
                if (Role::Composer === $role && !array_intersect($jobs, ['Original Music Composer', 'Music', 'Composer'])) {
                    continue;
                }
                if (Role::Producer === $role && !\in_array('Producer', $jobs, true)) {
                    continue;
                }
                if (null !== $name = $member->string('person.name')) {
                    $credits[] = new Credit($name, $role, null, null, self::ids($member->data('person.ids')), implode(', ', $jobs) ?: null);
                }
            }
        }
        foreach (\array_slice($people?->list('cast') ?? [], 0, $this->cast) as $order => $member) {
            if (null !== $name = $member->string('person.name')) {
                $credits[] = new Credit($name, Role::Cast, implode(' / ', $member->strings('characters')) ?: null, $order, self::ids($member->data('person.ids')));
            }
        }
        $slug = $data->string('ids.slug');

        return new Work(
            type: $type,
            title: (string) $data->string('title'),
            identifiers: self::ids($data->data('ids')),
            source: 'trakt',
            year: $data->int('year') ?? (null !== $released ? (int) $released->format('Y') : null),
            released: $released,
            runtime: $data->int('runtime'),
            overview: $data->string('overview'),
            genres: $data->strings('genres'),
            countries: null === ($country = $data->string('country')) ? [] : [strtoupper($country)],
            languages: $data->strings('languages') ?: (null === ($language = $data->string('language')) ? [] : [$language]),
            credits: $credits,
            ratings: self::rating($data),
            url: null === $slug ? null : 'https://trakt.tv/'.($movie ? 'movies' : 'shows').'/'.$slug,
        );
    }

    private function episode(Data $data, Data $show): Work
    {
        $released = $data->date('first_aired');
        $season = $data->int('season');
        $number = $data->int('number');
        $slug = $show->string('slug') ?? $show->string('trakt');

        return new Work(
            type: WorkType::Episode,
            title: (string) ($data->string('title') ?? 'Episode '.$number),
            identifiers: self::ids($data->data('ids')),
            source: 'trakt',
            year: null !== $released ? (int) $released->format('Y') : null,
            released: $released,
            runtime: $data->int('runtime'),
            overview: $data->string('overview'),
            ratings: self::rating($data),
            url: null === $slug ? null : 'https://trakt.tv/shows/'.$slug.'/seasons/'.$season.'/episodes/'.$number,
            series: self::ids($show),
            season: $season,
            number: $number,
        );
    }

    private static function ids(Data $ids): Identifiers
    {
        return Identifiers::of(['trakt' => $ids->string('trakt'), 'imdb' => $ids->string('imdb'), 'tmdb' => $ids->string('tmdb'), 'tvdb' => $ids->string('tvdb')]);
    }

    /** @return list<Rating> */
    private static function rating(Data $data): array
    {
        $votes = $data->int('votes');

        return null !== $votes && $votes > 0 ? [new Rating('trakt', (float) $data->float('rating'), 10.0, $votes)] : [];
    }
}
