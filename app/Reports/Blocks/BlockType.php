<?php

declare(strict_types=1);

namespace App\Reports\Blocks;

/**
 * The block types a report is composed of (CLAUDE.md §10.3). The set is
 * extensible — adding a type means a new case here plus a renderer + editor
 * control. Stored as the `type` of each block in the templates/definitions JSON.
 */
enum BlockType: string
{
    case Header = 'header';
    case Kpi = 'kpi';
    case Chart = 'chart';
    case Table = 'table';
    case Narrative = 'narrative';
    case HealthScore = 'healthscore';
    case SecurityShield = 'security_shield';
    case WorklogTimeline = 'worklog_timeline';
    case Image = 'image';
    case Divider = 'divider';
    case PageBreak = 'pagebreak';
    case SalesSummary = 'sales_summary';
    case Goal = 'goal';
    case Cta = 'cta';
    case Comments = 'comments';
    case Custom = 'custom';

    /**
     * Block types that must bind to a source metric to render (CLAUDE.md §10.2).
     *
     * @return list<self>
     */
    public static function dataBound(): array
    {
        return [self::Kpi, self::Chart, self::Table, self::SalesSummary, self::Goal];
    }

    public function requiresBinding(): bool
    {
        return in_array($this, self::dataBound(), true);
    }
}
