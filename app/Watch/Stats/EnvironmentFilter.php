<?php

namespace App\Watch\Stats;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

final class EnvironmentFilter
{
    /**
     * @param  list<string>  $ids
     * @param  list<string>  $slugs
     */
    public function __construct(
        public readonly array $ids = [],
        public readonly array $slugs = [],
        private readonly bool $includesDefault = false,
    ) {}

    public static function fromRequest(Request $request, Project $project): self
    {
        $selected = $project->environments->whereIn('slug', $request->array('env'), true);

        return new self(
            $selected->pluck('id')->all(),
            $selected->pluck('slug')->all(),
            $selected->contains('is_default', true),
        );
    }

    /**
     * @template TQuery of Builder|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder|QueryBuilder $query, string $column = 'environment_id'): Builder|QueryBuilder
    {
        if ($this->ids === []) {
            return $query;
        }

        return $query->where(function (Builder|QueryBuilder $nested) use ($column): void {
            $nested->whereIn($column, $this->ids);

            if ($this->includesDefault) {
                $nested->orWhereNull($column);
            }
        });
    }
}
