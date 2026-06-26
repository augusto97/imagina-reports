<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\Datasets\DatasetEngine;
use App\Reports\Datasets\DatasetFilter;
use App\Reports\Datasets\DatasetQuery;
use Tests\TestCase;

class DatasetEngineTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function geoRows(): array
    {
        return [
            ['country' => 'Colombia', 'city' => 'Bogotá', 'sessions' => 120, 'users' => 90],
            ['country' => 'Colombia', 'city' => 'Medellín', 'sessions' => 80, 'users' => 60],
            ['country' => 'Colombia', 'city' => 'Bogotá', 'sessions' => 30, 'users' => 20],
            ['country' => 'México', 'city' => 'CDMX', 'sessions' => 200, 'users' => 150],
            ['country' => 'México', 'city' => 'Monterrey', 'sessions' => 50, 'users' => 40],
        ];
    }

    public function test_breakdown_filters_groups_sorts_and_limits(): void
    {
        // "Ciudades de Colombia": break by city, filter country=Colombia, top 2 by sessions.
        $query = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'breakdown' => 'city',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'Colombia']],
            'limit' => 2,
        ]);

        $result = (new DatasetEngine)->shape($this->geoRows(), $query);

        // Bogotá's two rows are summed (120 + 30 = 150), Medellín = 80; México excluded.
        $this->assertSame([
            ['label' => 'Bogotá', 'value' => 150],
            ['label' => 'Medellín', 'value' => 80],
        ], $result);
    }

    public function test_no_breakdown_aggregates_to_a_scalar(): void
    {
        // "Sesiones de México" KPI: filter to a value, no breakdown → single number.
        $query = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'México']],
        ]);

        $this->assertSame(250, (new DatasetEngine)->shape($this->geoRows(), $query));
    }

    public function test_filter_on_absent_dimension_does_not_exclude_rows(): void
    {
        // A page filter on a dimension the dataset lacks must not blank the block.
        $query = DatasetQuery::fromBinding(['measure' => 'sessions', 'breakdown' => 'city']);
        $pageFilters = [new DatasetFilter('source', 'is', 'facebook')]; // geo rows have no 'source'

        $result = (new DatasetEngine)->shape($this->geoRows(), $query, $pageFilters);

        $this->assertCount(4, $result); // all four distinct cities still present
    }

    public function test_block_filter_overrides_page_filter_on_the_same_dimension(): void
    {
        // Page says country=México, block says country=Colombia → block wins.
        $query = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'breakdown' => 'city',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'Colombia']],
        ]);
        $pageFilters = [new DatasetFilter('country', 'is', 'México')];

        $result = (new DatasetEngine)->shape($this->geoRows(), $query, $pageFilters);
        $labels = array_column($result, 'label');

        $this->assertContains('Bogotá', $labels);
        $this->assertNotContains('CDMX', $labels);
    }

    public function test_contains_and_in_operators(): void
    {
        $rows = [
            ['source' => 'google / organic', 'sessions' => 10],
            ['source' => 'facebook / referral', 'sessions' => 20],
            ['source' => 'm.facebook.com', 'sessions' => 5],
            ['source' => 'newsletter', 'sessions' => 3],
        ];

        $contains = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'filters' => [['dimension' => 'source', 'op' => 'contains', 'value' => 'facebook']],
        ]);
        $this->assertSame(25, (new DatasetEngine)->shape($rows, $contains));

        $in = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'filters' => [['dimension' => 'source', 'op' => 'in', 'value' => ['newsletter', 'google / organic']]],
        ]);
        $this->assertSame(13, (new DatasetEngine)->shape($rows, $in));
    }

    public function test_sort_ascending_by_label(): void
    {
        $query = DatasetQuery::fromBinding([
            'measure' => 'sessions',
            'breakdown' => 'city',
            'filters' => [['dimension' => 'country', 'op' => 'is', 'value' => 'México']],
            'sort' => ['by' => 'label', 'dir' => 'asc'],
        ]);

        $result = (new DatasetEngine)->shape($this->geoRows(), $query);

        $this->assertSame(['CDMX', 'Monterrey'], array_column($result, 'label'));
    }

    public function test_is_dataset_only_when_breakdown_or_measure_present(): void
    {
        $this->assertFalse(DatasetQuery::fromBinding(['source' => 'ga4', 'metric' => 'sessions'])->isDataset());
        $this->assertTrue(DatasetQuery::fromBinding(['measure' => 'sessions'])->isDataset());
        $this->assertTrue(DatasetQuery::fromBinding(['breakdown' => 'city'])->isDataset());
    }
}
