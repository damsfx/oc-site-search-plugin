<?php

namespace OFFLINE\SiteSearch\Classes\Providers;

use Cms\Classes\Controller;
use OFFLINE\SiteSearch\Classes\Result;
use OFFLINE\SiteSearch\Models\Settings;
use System\Facades\System;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Models\EntryRecord;

class TailorResultsProvider extends ResultsProvider
{
    public function search()
    {
        if (!class_exists('System') || version_compare(System::VERSION, '3', '<')) {
            return $this;
        }

        $controller = Controller::getController() ?? new Controller();
        $sections = BlueprintIndexer::instance()->listSections();

        foreach ($sections as $section) {
            if (!$section->siteSearch) {
                continue;
            }

            $query = EntryRecord::inSection($section->handle);
            $fields = $query->getBlueprintAttribute()->attributes['fields'];
            $searchFields = $section->siteSearch['searchFields'] ?? [];
            $resultFields = $section->siteSearch['resultFields'] ?? [];

            $query
                ->applyPublishedStatus()
                ->where(function ($q) use ($fields, $searchFields) {
                    foreach ($fields as $field => $definitions) {
                        if (!in_array($field, $searchFields, true)) {
                            continue;
                        }
                        $q->orWhere($field, 'like', "%{$this->query}%");
                    }
                })
                ->get()
                ->each(function ($item) use ($section, $controller, $resultFields) {
                    $urlParams = collect($section->siteSearch['urlParams'] ?? [])
                        ->mapWithKeys(function ($param, $key) use ($item) {
                            $resolvedParam = $param;
                            if (starts_with($param, '$')) {
                                $resolvedParam = $item->{str_replace('$', '', $param)};
                            }

                            return [$key => $resolvedParam];
                        })
                        ->toArray();

                    $urlResolver = $section->siteSearch['urlResolver'];
                    if ($urlResolver && is_callable($urlResolver)) {
                        $url = $urlResolver($controller, $item, $section);
                    } else {
                        $url = $controller->pageUrl(
                            $section->siteSearch['pageName'] ?? '',
                            $urlParams
                        );
                    }

                    $resultFieldTitle = array_get($resultFields, 'title', 'title');
                    $resultFieldText = array_get($resultFields, 'text', '');

                    $title = $item->{$resultFieldTitle};
                    $text = '';

                    if ($resultFieldText) {
                        $text = $item->{$resultFieldText};
                    }

                    $relevance = mb_stripos($title, $this->query) !== false ? 2 : 1;

                    $provider = $section->siteSearch['providerBadge'] ?? '';

                    $result = new Result($this->query, $relevance, $provider);
                    $result->title = $title;
                    $result->text = $text;
                    $result->url = $url;

                    $this->addResult($result);
                });

            return $this;
        }
    }

    /**
     * Checks if this provider is enabled
     * in the config.
     *
     * @return bool
     */
    protected function isEnabled()
    {
        return Settings::get('tailor_sections_enabled', false);
    }

    public function displayName()
    {
        return '';
    }

    public function identifier()
    {
        return 'Tailor';
    }
}
