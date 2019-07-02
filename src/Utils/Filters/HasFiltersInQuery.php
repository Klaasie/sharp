<?php

namespace Code16\Sharp\Utils\Filters;

use Carbon\Carbon;

trait HasFiltersInQuery
{
    /**
     * @var array
     */
    protected $filters;

    /**
     * @param string $filterName
     * @return mixed|null
     */
    public function filterFor(string $filterName)
    {
        if(isset($this->filters["/forced/$filterName"])) {
            return $this->filterFor("/forced/$filterName");
        }

        if(!isset($this->filters[$filterName])) {
            return null;
        }

        if(str_contains($this->filters[$filterName], "..")) {
            list($start, $end) = explode("..", $this->filters[$filterName]);

            return [
                "start" => Carbon::createFromFormat('Ymd', $start)->hours(0)->minutes(0)->seconds(0)->microseconds(0),
                "end" => Carbon::createFromFormat('Ymd', $end)->hours(23)->minutes(59)->seconds(59)->microseconds(999),
            ];
        }

        if(str_contains($this->filters[$filterName], ",")){
            return explode(",", $this->filters[$filterName]);
        }

        return $this->filters[$filterName];
    }

    /**
     * @param array $filters
     * @return $this
     */
    public function setDefaultFilters($filters)
    {
        collect((array) $filters)->each(function($value, $filter) {
            $this->setFilterValue($filter, $value);
        });

        return $this;
    }

    /**
     * @param array $query
     */
    protected function fillFilterWithRequest(array $query = null)
    {
        collect($query)
            ->filter(function($value, $name) {
                return starts_with($name, "filter_");

            })->each(function($value, $name) {
                $this->setFilterValue(substr($name, strlen("filter_")), $value);
            });
    }

    /**
     * @param string $filter
     * @param string $value
     */
    public function forceFilterValue(string $filter, $value)
    {
        $this->filters["/forced/$filter"] = $value;
    }

    /**
     * @param string $filter
     * @param string $value
     */
    protected function setFilterValue(string $filter, $value)
    {
        if(is_array($value)) {
            // Force all filter values to be string, to be consistent with all use cases
            // (filter in EntityList or in Command)
            if(empty($value)) {
                $value = null;

            } elseif(isset($value["start"]) && $value["start"] instanceof Carbon) {
                // RangeFilter case
                $value = collect($value)->map->format("Ymd")->implode("..");

            } else {
                // Multiple filter case
                $value = implode(',', $value);
            }
        }

        $this->filters[$filter] = $value;

        event("filter-{$filter}-was-set", [$value, $this]);
    }
}