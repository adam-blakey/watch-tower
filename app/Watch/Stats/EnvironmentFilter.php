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
    ) {}

    public static function fromRequest(Request $request, Project $project): self
    {
        $selected = $project->environments->whereIn('slug', $request->array('env'), true);

        return new self(
            $selected->pluck('id')->all(),
            $selected->pluck('slug')->all(),
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
        return $this->ids === [] ? $query : $query->whereIn($column, $this->ids);
    }
}
