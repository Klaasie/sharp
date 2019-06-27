<?php

namespace Code16\Sharp\Utils\Filters;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Event;

trait HandleFilters
{
    /**
     * @var array
     */
    protected $filterHandlers = [];

    /**
     * @param string $filterName
     * @param string|Filter $filterHandler
     * @param Closure|null $callback
     * @return $this
     */
    protected function addFilter(string $filterName, $filterHandler, Closure $callback = null)
    {
        $this->filterHandlers[$filterName] = $filterHandler instanceof Filter
            ? $filterHandler
            : app($filterHandler);

        if($callback) {
            Event::listen("filter-{$filterName}-was-set", function ($value, $params) use($callback) {
                $callback($value, $params);
            });
        }

        return $this;
    }

    /**
     * @param array $config
     */
    protected function appendFiltersToConfig(array &$config)
    {
        foreach($this->filterHandlers as $filterName => $handler) {

            switch(true){
                case $handler instanceof SelectFilter:

                    $multiple = $handler instanceof SelectMultipleFilter;

                    $filterConfigData = [
                        "key" => $filterName,
                        "type" =>  'select',
                        "multiple" => $multiple,
                        "required" => !$multiple && $handler instanceof SelectRequiredFilter,
                        "default" => $this->getFilterDefaultValue($handler, $filterName),
                        "values" => $this->formatSelectFilterValues($handler),
                        "label" => method_exists($handler, "label") ? $handler->label() : $filterName,
                        "master" => method_exists($handler, "isMaster") ? $handler->isMaster() : false,
                        "searchable" => method_exists($handler, "isSearchable") ? $handler->isSearchable() : false,
                        "searchKeys" => method_exists($handler, "searchKeys") ? $handler->searchKeys() : ["label"],
                        "template" => $this->formatSelectFilterTemplate($handler)
                    ];
                    break;
                case $handler instanceof DateRangeFilter:

                    $filterConfigData = [
                        "key" => $filterName,
                        "type" =>  'dateRange',
                        "required" => $handler instanceof DateRangeRequiredFilter,
                        "default" => $this->getFilterDefaultValue($handler, $filterName),
                        "label" => method_exists($handler, "label") ? $handler->label() : $filterName,
                    ];
                    break;
            }

            $config["filters"][] = $filterConfigData;
        }
    }

    /**
     * @param SelectFilter $handler
     * @return array
     */
    protected function formatSelectFilterValues(SelectFilter $handler)
    {
        if(!method_exists($handler, "template")) {
            return collect($handler->values())->map(function ($label, $id) {
                return compact('id', 'label');
            })->values()->all();
        }

        // There is a user-defined template: just return the raw values() is this case
        return $handler->values();
    }

    /**
     * @param SelectFilter $handler
     * @return string
     */
    protected function formatSelectFilterTemplate(SelectFilter $handler)
    {
        if(!method_exists($handler, "template")) {
            return '{{label}}';
        }

        if(($template = $handler->template()) instanceof View) {
            return $template->render();
        }

        return $template;
    }

    /**
     * @return array
     */
    protected function getFilterDefaultValues()
    {
        return collect($this->filterHandlers)

            // Only filters which aren't in the request
            ->filter(function($handler, $attribute) {
                return !request()->has("filter_$attribute");
            })

            // Only required filters or retained filters with value saved in session
            ->filter(function($handler, $attribute) {
                return $handler instanceof SelectRequiredFilter
                    || $handler instanceof DateRangeRequiredFilter
                    || $this->isRetainedFilter($handler, $attribute, true);
            })

            ->map(function($handler, $attribute) {
                if($this->isRetainedFilter($handler, $attribute, true)) {
                    return [
                        "name" => $attribute,
                        "value" => session("_sharp_retained_filter_$attribute")
                    ];
                }

                return [
                    "name" => $attribute,
                    "value" => $handler->defaultValue()
                ];
            })
            ->pluck("value", "name")
            ->all();
    }

    /**
     * Save "retain" filter values in session. Retain filters
     * are those whose handler is defining a retainValueInSession()
     * function which returns true.
     */
    protected function putRetainedFilterValuesInSession()
    {
        collect($this->filterHandlers)
            // Only filters sent which are declared "retained"
            ->filter(function($handler, $attribute) {
                return request()->has("filter_$attribute")
                    && $this->isRetainedFilter($handler, $attribute);
            })
            ->each(function($handler, $attribute) {
                // Array case: we store a coma separated string
                // (to be consistent and only store strings on filter session)
                $value = is_array(request()->get("filter_$attribute"))
                    ? implode(",", request()->get("filter_$attribute"))
                    : request()->get("filter_$attribute");

                if(strlen(trim($value)) === 0) {
                    // No value, we have to unset the retained value
                    session()->forget("_sharp_retained_filter_$attribute");

                } else {
                    session()->put(
                        "_sharp_retained_filter_$attribute",
                        $value
                    );
                }
            });

        session()->save();
    }

    /**
     * @param $handler
     * @param $attribute
     * @param bool $onlyValued
     * @return bool
     */
    protected function isRetainedFilter($handler, $attribute, $onlyValued = false)
    {
        return method_exists($handler, "retainValueInSession")
            && $handler->retainValueInSession()
            && (!$onlyValued || session()->has("_sharp_retained_filter_$attribute"));
    }

    /**
     * @param $handler
     * @return bool
     */
    protected function isGlobalFilter($handler)
    {
        return $handler instanceof GlobalRequiredFilter;
    }

    /**
     * Return the filter default value, which can be, in that order:
     * - the retained value, if the filter is retained
     * - the default value is the filter is required
     * - or null
     *
     * @param $handler
     * @param string $attribute
     * @return int|string|array|null
     */
    protected function getFilterDefaultValue($handler, $attribute)
    {
        if($this->isGlobalFilter($handler)) {
            return session("_sharp_retained_global_filter_$attribute") ?: $handler->defaultValue();
        }

        if($this->isRetainedFilter($handler, $attribute, true)) {
            $sessionValue = session("_sharp_retained_filter_$attribute");

            if($handler instanceof SelectMultipleFilter){
                return explode(",", $sessionValue);
            }

            if($handler instanceof DateRangeFilter){
                $rangeValues = array_slice(
                    explode("..", $sessionValue),
                    0,
                    2
                );

                return [
                    "start" => $rangeValues[0],
                    "end" => $rangeValues[1]
                ];
            }

            return $sessionValue;
        }

        return $handler instanceof SelectRequiredFilter || $handler instanceof DateRangeRequiredFilter
            ? $handler->defaultValue()
            : null;
    }
}