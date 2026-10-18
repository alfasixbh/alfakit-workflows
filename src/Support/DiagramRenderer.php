<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitWorkflows\Support;

use AlfaSix\Alfakit\Localization\TextDirection;
use AlfaSix\AlfakitWorkflows\Workflow;

/**
 * Draws a workflow as an accessible SVG on the server: states as boxes laid out in columns
 * by their distance from the initial state, transitions as labelled arrows (approval
 * outcomes in success / danger colours), the current state highlighted and transitions
 * the record already took drawn solid. Colours are the theme's CSS variables, so it
 * follows light/dark mode and every theme; the layout mirrors for right-to-left locales.
 * A screen-reader list repeats the diagram as text.
 */
final class DiagramRenderer
{
    private const NODE_HEIGHT = 44;

    private const ROW_GAP = 32;

    private const PADDING = 24;

    private const FONT_SIZE = 13;

    private const LABEL_SIZE = 11;

    /**
     * @param  array<int, array{0: string, 1: string}>  $taken  `[from, to]` pairs the record went through
     */
    public static function render(Workflow $workflow, ?string $current = null, array $taken = [], ?bool $rtl = null): string
    {
        $rtl ??= TextDirection::detect(app()->getLocale())->isRtl();
        $id = 'alfakit-wf-'.bin2hex(random_bytes(4));
        $states = $workflow->getStates();
        $allEdges = $workflow->getEdges();
        // `fromAny()` transitions are drawn once, as a stub into their target, instead of
        // an arrow from every state.
        $edges = array_values(array_filter($allEdges, static fn (array $edge): bool => ! $edge['any']));
        $stubs = [];

        foreach ($allEdges as $edge) {
            if ($edge['any']) {
                $stubs[$edge['transition']] = ['to' => $edge['to'], 'label' => (string) __('alfakit-workflows::workflows.diagram.from_any', ['transition' => $edge['label']])];
            }
        }

        if ($states === []) {
            return '';
        }

        // ---------------------------------------------------------------- Columns

        $layers = self::layers($workflow, $edges);
        $columns = [];

        foreach (array_keys($states) as $name) {
            $columns[$layers[$name]][] = $name;
        }

        ksort($columns);
        $columns = array_values($columns);

        $longestState = max(array_map(static fn (string $name): int => mb_strlen((string) $workflow->getStateLabel($name)), array_keys($states)));
        $longestEdge = $edges === [] ? 0 : max(array_map(static fn (array $edge): int => mb_strlen($edge['label']), $edges));
        $longestStub = $stubs === [] ? 0 : max(array_map(static fn (array $stub): int => mb_strlen($stub['label']), $stubs));
        $nodeWidth = (int) min(220, max(120, $longestState * 7.6 + 40, $longestStub * 6.4 + 16));
        $gap = (int) min(200, max(80, $longestEdge * 6.6 + 32));
        $maxRows = max(array_map(count(...), $columns));
        $contentHeight = $maxRows * self::NODE_HEIGHT + ($maxRows - 1) * self::ROW_GAP;

        // ---------------------------------------------------------------- Edge routing (space above and below)

        $index = [];

        foreach ($columns as $column => $names) {
            foreach ($names as $row => $name) {
                $index[$name] = [$column, $row, count($names)];
            }
        }

        $above = 0;
        $below = 0;
        $routes = [];

        foreach ($edges as $edge) {
            [$fromColumn, $fromRow] = $index[$edge['from']] ?? [0, 0];
            [$toColumn, $toRow] = $index[$edge['to']] ?? [0, 0];

            if ($toColumn === $fromColumn + 1) {
                $routes[] = 'straight';
            } elseif ($toColumn === $fromColumn && abs($toRow - $fromRow) === 1) {
                $routes[] = 'vertical';
            } elseif ($toColumn > $fromColumn) {
                $routes[] = 'above:'.(++$above);
            } else {
                $routes[] = 'below:'.(++$below);
            }
        }

        // Stubs come from above into the top state of a column, from below otherwise.
        $stubsAbove = array_filter($stubs, static fn (array $stub): bool => ($index[$stub['to']][1] ?? 0) === 0);
        $stubsBelow = array_diff_key($stubs, $stubsAbove);
        $lane = 36;
        $top = self::PADDING + ($above > 0 ? $above * $lane + 8 : 0) + ($stubsAbove === [] ? 0 : 40);
        $width = self::PADDING * 2 + 28 + count($columns) * $nodeWidth + (count($columns) - 1) * $gap;
        $height = $top + $contentHeight + ($below > 0 ? $below * $lane + 8 : 0) + ($stubsBelow === [] ? 0 : 40) + self::PADDING;

        // ---------------------------------------------------------------- Node boxes

        $boxes = [];

        foreach ($columns as $column => $names) {
            $offset = (int) (($contentHeight - (count($names) * self::NODE_HEIGHT + (count($names) - 1) * self::ROW_GAP)) / 2);

            foreach ($names as $row => $name) {
                $x = self::PADDING + 28 + $column * ($nodeWidth + $gap);
                $x = $rtl ? $width - $x - $nodeWidth : $x;
                $boxes[$name] = [$x, $top + $offset + $row * (self::NODE_HEIGHT + self::ROW_GAP)];
            }
        }

        // ---------------------------------------------------------------- SVG

        $title = (string) __('alfakit-workflows::workflows.diagram.title', ['workflow' => $workflow->getLabel()]);
        $summary = (string) __('alfakit-workflows::workflows.diagram.summary', [
            'states' => (string) count($states),
            'transitions' => (string) count($workflow->getTransitions()),
        ]);

        if ($current !== null && $workflow->hasState($current)) {
            $summary .= ' '.__('alfakit-workflows::workflows.diagram.current', ['state' => (string) $workflow->getStateLabel($current)]);
        }

        $takenKeys = array_map(static fn (array $pair): string => $pair[0].'>'.$pair[1], $taken);
        $svg = [];
        $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'"'
            .' role="img" aria-labelledby="'.$id.'-title '.$id.'-desc" data-workflow-diagram="'.e($workflow->getName()).'"'
            .($rtl ? ' direction="rtl"' : '')
            .' style="display:block;width:100%;min-width:'.min($width, 520).'px;max-width:'.$width.'px;height:auto;font-family:inherit;overflow:visible">';
        $svg[] = '<title id="'.$id.'-title">'.e($title).'</title>';
        $svg[] = '<desc id="'.$id.'-desc">'.e($summary).'</desc>';
        $svg[] = '<defs>';

        foreach (['default' => 'var(--muted-foreground)', 'taken' => 'var(--foreground)', 'approved' => 'var(--success, var(--primary))', 'rejected' => 'var(--destructive)'] as $kind => $color) {
            $svg[] = '<marker id="'.$id.'-arrow-'.$kind.'" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">'
                .'<path d="M0,0 L10,5 L0,10 z" style="fill:'.$color.'"/></marker>';
        }

        $svg[] = '</defs>';

        // Start marker: a dot with an arrow into the initial state.
        $initial = $workflow->getInitialState();

        if ($initial !== null && isset($boxes[$initial])) {
            [$x, $y] = $boxes[$initial];
            $cy = $y + self::NODE_HEIGHT / 2;
            $dotX = $rtl ? $x + $nodeWidth + 22 : $x - 22;
            $endX = $rtl ? $x + $nodeWidth + 2 : $x - 2;
            $svg[] = '<g aria-hidden="true"><circle cx="'.$dotX.'" cy="'.$cy.'" r="5" style="fill:var(--foreground)"/>'
                .'<line x1="'.($rtl ? $dotX - 5 : $dotX + 5).'" y1="'.$cy.'" x2="'.$endX.'" y2="'.$cy.'" style="stroke:var(--foreground);stroke-width:1.5" marker-end="url(#'.$id.'-arrow-taken)"/></g>';
        }

        // Edges, then labels on top of every edge.
        $labels = [];

        foreach ($edges as $i => $edge) {
            if (! isset($boxes[$edge['from']], $boxes[$edge['to']])) {
                continue;
            }

            [$fx, $fy] = $boxes[$edge['from']];
            [$tx, $ty] = $boxes[$edge['to']];
            $route = $routes[$i];
            $isTaken = in_array($edge['from'].'>'.$edge['to'], $takenKeys, true);
            $kind = $edge['kind'] === 'transition' ? ($isTaken ? 'taken' : 'default') : $edge['kind'];
            $color = match ($kind) {
                'approved' => 'var(--success, var(--primary))',
                'rejected' => 'var(--destructive)',
                'taken' => 'var(--foreground)',
                default => 'var(--muted-foreground)',
            };
            $dash = $edge['kind'] === 'rejected' ? ';stroke-dasharray:5 4' : '';

            if ($route === 'vertical') {
                $sx = $fx + $nodeWidth / 2;
                $ex = $sx;
                $down = $ty > $fy;
                $sy = $down ? $fy + self::NODE_HEIGHT : $fy;
                $ey = $down ? $ty : $ty + self::NODE_HEIGHT;
                $d = 'M'.$sx.','.$sy.' L'.$ex.','.$ey;
                $lx = $sx;
                $ly = ($sy + $ey) / 2;
            } elseif ($route === 'straight') {
                $sx = $rtl ? $fx : $fx + $nodeWidth;
                $ex = $rtl ? $tx + $nodeWidth : $tx;
                $sy = $fy + self::NODE_HEIGHT / 2;
                $ey = $ty + self::NODE_HEIGHT / 2;
                $bend = ($ex - $sx) / 2;
                $d = 'M'.$sx.','.$sy.' C'.($sx + $bend).','.$sy.' '.($ex - $bend).','.$ey.' '.$ex.','.$ey;
                $lx = ($sx + $ex) / 2;
                $ly = ($sy + $ey) / 2;
            } else {
                [$side, $level] = explode(':', $route);
                $sx = $fx + $nodeWidth / 2 + ($side === 'below' ? 10 : -10) * ($rtl ? -1 : 1);
                $ex = $tx + $nodeWidth / 2 + ($side === 'below' ? -10 : 10) * ($rtl ? -1 : 1);

                if ($side === 'above') {
                    $sy = $fy;
                    $ey = $ty;
                    $laneY = $top - (int) $level * $lane - 4;
                } else {
                    $sy = $fy + self::NODE_HEIGHT;
                    $ey = $ty + self::NODE_HEIGHT;
                    $laneY = $top + $contentHeight + (int) $level * $lane + 4;
                }

                $d = 'M'.$sx.','.$sy.' C'.$sx.','.$laneY.' '.$ex.','.$laneY.' '.$ex.','.$ey;
                $lx = ($sx + $ex) / 2;
                $ly = ($sy + 3 * $laneY + 3 * $laneY + $ey) / 8;
            }

            $svg[] = '<path d="'.$d.'" aria-hidden="true" data-edge="'.e($edge['from'].'>'.$edge['to']).'" data-kind="'.$edge['kind'].'"'
                .' style="fill:none;stroke:'.$color.';stroke-width:'.($isTaken ? '2' : '1.5').$dash.'" marker-end="url(#'.$id.'-arrow-'.$kind.')"/>';

            $labelWidth = (int) (mb_strlen($edge['label']) * 6.4 + 12);
            $labels[] = '<g aria-hidden="true"><rect x="'.($lx - $labelWidth / 2).'" y="'.($ly - 9).'" width="'.$labelWidth.'" height="18" rx="9" style="fill:var(--card, var(--background));stroke:var(--border);stroke-width:1"/>'
                .'<text x="'.$lx.'" y="'.($ly + 4).'" text-anchor="middle" style="font-size:'.self::LABEL_SIZE.'px;fill:'.($edge['kind'] === 'transition' ? 'var(--foreground)' : $color).'">'.e($edge['label']).'</text></g>';
        }

        // `fromAny()` stubs: a short dashed arrow into the target, with its label.
        $stubCount = [];

        foreach ($stubs as $transitionName => $stub) {
            if (! isset($boxes[$stub['to']])) {
                continue;
            }

            [$x, $y] = $boxes[$stub['to']];
            $fromAbove = isset($stubsAbove[$transitionName]);
            $offset = ($stubCount[$stub['to']] = ($stubCount[$stub['to']] ?? 0) + 1) - 1;
            $cx = $x + $nodeWidth / 2;
            $edgeY = $fromAbove ? $y : $y + self::NODE_HEIGHT;
            $startY = $fromAbove ? $edgeY - 24 - $offset * 20 : $edgeY + 24 + $offset * 20;
            $labelY = $fromAbove ? $startY - 9 : $startY + 9;
            $labelWidth = (int) (mb_strlen($stub['label']) * 6.4 + 12);
            $svg[] = '<path d="M'.$cx.','.$startY.' L'.$cx.','.$edgeY.'" aria-hidden="true" data-edge="*&gt;'.e($stub['to']).'" data-kind="any"'
                .' style="fill:none;stroke:var(--muted-foreground);stroke-width:1.5;stroke-dasharray:3 3" marker-end="url(#'.$id.'-arrow-default)"/>';
            $labels[] = '<g aria-hidden="true"><rect x="'.($cx - $labelWidth / 2).'" y="'.($labelY - 9).'" width="'.$labelWidth.'" height="18" rx="9" style="fill:var(--card, var(--background));stroke:var(--border);stroke-width:1"/>'
                .'<text x="'.$cx.'" y="'.($labelY + 4).'" text-anchor="middle" style="font-size:'.self::LABEL_SIZE.'px;fill:var(--foreground)">'.e($stub['label']).'</text></g>';
        }

        array_push($svg, ...$labels);

        // States.
        foreach ($states as $name => $state) {
            [$x, $y] = $boxes[$name];
            $color = self::color($state->getColor());
            $isCurrent = $name === $current;
            $label = $state->getLabel();
            $maxChars = (int) floor(($nodeWidth - 36) / 7.2);
            $shown = mb_strlen($label) > $maxChars ? mb_substr($label, 0, max(1, $maxChars - 1)).'…' : $label;
            $fill = $isCurrent ? 'color-mix(in oklab, '.$color.' 16%, var(--card, var(--background)))' : 'var(--card, var(--background))';
            $stroke = $isCurrent ? $color : 'var(--border)';
            $dotX = $rtl ? $x + $nodeWidth - 16 : $x + 16;
            $textX = $x + $nodeWidth / 2 + ($rtl ? -6 : 6);

            $svg[] = '<g aria-hidden="true" data-state="'.e($name).'"'.($isCurrent ? ' data-current="true"' : '').'>'
                .'<title>'.e($label).'</title>'
                .'<rect x="'.$x.'" y="'.$y.'" width="'.$nodeWidth.'" height="'.self::NODE_HEIGHT.'" rx="10" style="fill:'.$fill.';stroke:'.$stroke.';stroke-width:'.($isCurrent ? '2' : '1').'"/>'
                .($state->isFinal() ? '<rect x="'.($x + 3).'" y="'.($y + 3).'" width="'.($nodeWidth - 6).'" height="'.(self::NODE_HEIGHT - 6).'" rx="8" style="fill:none;stroke:'.$stroke.';stroke-width:1"/>' : '')
                .'<circle cx="'.$dotX.'" cy="'.($y + self::NODE_HEIGHT / 2).'" r="4" style="fill:'.$color.'"/>'
                .'<text x="'.$textX.'" y="'.($y + self::NODE_HEIGHT / 2 + 4.5).'" text-anchor="middle" style="font-size:'.self::FONT_SIZE.'px;font-weight:'.($isCurrent ? '700' : '600').';fill:var(--foreground)">'.e($shown).'</text>'
                .'</g>';
        }

        $svg[] = '</svg>';

        // The same diagram as text for screen readers.
        $list = ['<ul class="sr-only">'];

        foreach ($states as $name => $state) {
            $outgoing = array_values(array_filter($allEdges, static fn (array $edge): bool => $edge['from'] === $name));
            $item = e($state->getLabel());

            if ($name === $initial) {
                $item .= ' ('.e((string) __('alfakit-workflows::workflows.diagram.initial')).')';
            }

            if ($name === $current) {
                $item .= ' ('.e((string) __('alfakit-workflows::workflows.diagram.current_short')).')';
            }

            $item .= $outgoing === []
                ? ': '.e((string) __('alfakit-workflows::workflows.diagram.no_transitions'))
                : ': '.implode('; ', array_map(static fn (array $edge): string => e($edge['label']).' → '.e((string) $workflow->getStateLabel($edge['to'])), $outgoing));

            $list[] = '<li>'.$item.'</li>';
        }

        $list[] = '</ul>';

        return '<figure data-alfakit-workflow-diagram style="margin:0">'
            .'<div tabindex="0" role="region" aria-label="'.e($title).'" style="overflow-x:auto;padding-block:4px">'.implode('', $svg).'</div>'
            .implode('', $list)
            .'</figure>';
    }

    /**
     * Columns: distance from the initial state along forward edges; unreachable states last.
     *
     * @param  array<int, array{from: string, to: string, label: string, kind: string, transition: string, any: bool}>  $edges
     * @return array<string, int>
     */
    private static function layers(Workflow $workflow, array $edges): array
    {
        $names = array_keys($workflow->getStates());
        $initial = $workflow->getInitialState() ?? $names[0];
        $layers = [$initial => 0];
        $queue = [$initial];

        while ($queue !== []) {
            $state = array_shift($queue);

            foreach ($edges as $edge) {
                if ($edge['from'] === $state && ! isset($layers[$edge['to']])) {
                    $layers[$edge['to']] = $layers[$state] + 1;
                    $queue[] = $edge['to'];
                }
            }
        }

        $next = max($layers) + 1;

        foreach ($names as $name) {
            $layers[$name] ??= $next;
        }

        return $layers;
    }

    private static function color(string $color): string
    {
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) === 1) {
            return $color;
        }

        return match ($color) {
            'success' => 'var(--success, var(--primary))',
            'warning' => 'var(--warning, var(--primary))',
            'danger' => 'var(--destructive)',
            'info' => 'var(--info, var(--primary))',
            'gray', 'neutral' => 'var(--muted-foreground)',
            'secondary' => 'var(--secondary-foreground)',
            default => 'var(--primary)',
        };
    }
}
