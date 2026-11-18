<?php

namespace App\Watch\Stats;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The environments of a project selected with `?env[]=slug`; none selected means all of them.
 *
 * Telemetry rows without an `environment_id` belong to the default environment, so selecting that one also matches
 * those rows.
 */
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

    /**
     * Unknown slugs are dropped.
     */
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
     * Restrict a query on a table with an `environment_id` column; does nothing when no environment is selected.
     *
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
