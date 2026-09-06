<?php

/**
 * Potts Relationship Matrix for webtrees.
 *
 * Public module wrapper. The relationship engine lives in
 * src/RelationshipMatrixCore.php while routing and presentation corrections
 * are kept here during the development alpha.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;

$core = require __DIR__ . '/src/RelationshipMatrixCore.php';

return new class($core) extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface {
    use ModuleCustomTrait;
    use ModuleChartTrait;

    private const VERSION = '0.1.0-alpha.7';
    private const GITHUB_REPO_URL = 'https://github.com/PottsNet/potts-relationship-matrix';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts-relationship-matrix/main/latest-version.txt';

    public function __construct(private readonly object $core)
    {
    }

    public function title(): string
    {
        return I18N::translate('Potts Relationship Matrix');
    }

    public function description(): string
    {
        return I18N::translate('Compare multiple relationships and display their paths in a relationship matrix and graph.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return self::VERSION;
    }

    public function customModuleSupportUrl(): string
    {
        return self::GITHUB_REPO_URL . '/issues';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    /** @return array<string,string> */
    public function customTranslations(string $language): array
    {
        return [
            'Relationship Matrix' => 'Potts Relationship Matrix',
        ];
    }

    public function boot(): void
    {
        $this->core->setName($this->name());
        $this->core->setEnabled($this->isEnabled());
        $this->core->boot();

        // RelationshipMatrixCore.php lives in /src, so its own __DIR__ points
        // one level below the real module resources folder.
        View::registerNamespace('potts-relationship-matrix', $this->resourcesFolder() . 'views/');
    }

    public function chartMenuClass(): string
    {
        return 'menu-chart-relationship-matrix';
    }

    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        $xref = $request->getAttribute('xref');

        // A chart can be opened from the main Charts menu without an individual
        // XREF. Use webtrees' significant/default person in that situation.
        if (!is_string($xref) || trim($xref) === '') {
            $individual = $tree->significantIndividual($user);

            if ($individual instanceof Individual && $individual->canShow()) {
                $request = $request->withAttribute('xref', $individual->xref());
            }
        }

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $route_xref = Validator::attributes($request)->isXref()->string('xref');
        $route_individual = Registry::individualFactory()->make($route_xref, $tree);

        if (!$route_individual instanceof Individual || !$route_individual->canShow()) {
            return response(I18N::translate('The individual could not be found.'))->withStatus(404);
        }

        $query = $request->getQueryParams();
        $scope = ($query['scope'] ?? 'blood') === 'all' ? 'all' : 'blood';
        $recursion = min(2, max(0, (int) ($query['recursion'] ?? 1)));

        /** @var array{0:array<int,Individual|null>,1:array<int,Individual>} $selection */
        $selection = $this->coreCall('selectedIndividuals', [$tree, $route_individual, $query]);
        [$slots, $selected] = $selection;

        $matrix_data = [
            'cells' => [],
            'pairs' => [],
        ];

        if (count($selected) >= 2) {
            /** @var array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data */
            $matrix_data = $this->coreCall('calculateMatrix', [$selected, $tree, $scope, $recursion]);

            if ($scope === 'blood') {
                $matrix_data = $this->normaliseBloodMatrix($matrix_data, $selected, $tree);
            }
        }

        $pair_key = is_string($query['pair'] ?? null) ? (string) $query['pair'] : '';
        $detail = $matrix_data['pairs'][$pair_key] ?? null;
        $graph = is_array($detail) ? $this->coreCall('graphData', [$detail, $tree]) : null;

        $base_url = $this->chartUrl($route_individual);
        /** @var array<string,string|int> $query_values */
        $query_values = $this->coreCall('queryValues', [$slots, $scope, $recursion]);

        $detail_urls = [];
        foreach (array_keys($matrix_data['pairs']) as $key) {
            $detail_urls[$key] = $base_url . '?' . http_build_query($query_values + ['pair' => $key], '', '&', PHP_QUERY_RFC3986);
        }

        $this->layout = 'layouts/default';
        $this->pushDisplayEnhancements($matrix_data, $scope, $detail);

        return $this->viewResponse('potts-relationship-matrix::page', [
            'title' => $this->title(),
            'tree' => $tree,
            'route_individual' => $route_individual,
            'slots' => $slots,
            'selected' => $selected,
            'matrix' => $matrix_data['cells'],
            'scope' => $scope,
            'recursion' => $recursion,
            'max_people' => 8,
            'action_url' => $base_url,
            'detail' => $detail,
            'detail_key' => $pair_key,
            'detail_urls' => $detail_urls,
            'clear_detail_url' => $base_url . '?' . http_build_query($query_values, '', '&', PHP_QUERY_RFC3986),
            'graph_json' => $graph === null ? 'null' : json_encode($graph, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
            'version' => self::VERSION,
        ]);
    }

    /**
     * Correct common-ancestor paths and group them into genealogical routes.
     *
     * Two paths through the two members of the same ancestral couple normally
     * represent one relationship route, not two independent relationships. Once
     * the common-ancestor pivot is normalised away, these paths have the same
     * sequence of descendant families/people. That normalised sequence is our
     * route signature.
     *
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     * @param array<int,Individual> $selected
     * @return array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>}
     */
    private function normaliseBloodMatrix(array $matrix_data, array $selected, Tree $tree): array
    {
        foreach ($matrix_data['pairs'] as $key => $result) {
            $paths = [];

            foreach ($result['paths'] as $path) {
                $ancestor_xref = is_string($path['common_ancestor_xref'] ?? null)
                    ? (string) $path['common_ancestor_xref']
                    : null;
                $name_nodes = $this->normaliseCommonAncestorNodes($path['nodes'], $ancestor_xref);
                $name = (string) $this->coreCall('relationshipName', [$name_nodes, $tree]);

                $path['name_nodes'] = $name_nodes;
                $path['name'] = $name !== '' ? $name : (string) $path['name'];
                $path['steps'] = intdiv(count($name_nodes) - 1, 2);
                $paths[] = $path;
            }

            usort($paths, static function (array $a, array $b): int {
                $steps = ((int) $a['steps']) <=> ((int) $b['steps']);
                if ($steps !== 0) {
                    return $steps;
                }

                $a_l1 = is_int($a['l1'] ?? null) ? (int) $a['l1'] : 999;
                $b_l1 = is_int($b['l1'] ?? null) ? (int) $b['l1'] : 999;

                return $a_l1 <=> $b_l1;
            });

            $routes = [];
            $route_by_signature = [];
            $all_common_ancestors = [];

            foreach ($paths as $path_index => &$path) {
                $name_nodes = is_array($path['name_nodes'] ?? null) ? $path['name_nodes'] : $path['nodes'];
                $signature = implode('|', $name_nodes);

                if (!array_key_exists($signature, $route_by_signature)) {
                    $route_index = count($routes);
                    $route_by_signature[$signature] = $route_index;
                    $family_xref = $this->routeFamilyXref($name_nodes, $path['l1'] ?? null, $path['l2'] ?? null);
                    [$left_branch_xref, $right_branch_xref] = $this->routeBranchXrefs($name_nodes, $path['l1'] ?? null, $path['l2'] ?? null);

                    $routes[$route_index] = [
                        'name' => (string) $path['name'],
                        'steps' => (int) $path['steps'],
                        'l1' => $path['l1'] ?? null,
                        'l2' => $path['l2'] ?? null,
                        'name_nodes' => $name_nodes,
                        'family_xref' => $family_xref,
                        'left_branch_xref' => $left_branch_xref,
                        'right_branch_xref' => $right_branch_xref,
                        'common_ancestors' => [],
                        'source_path_count' => 0,
                        'contains_spouse_link' => false,
                    ];
                }

                $route_index = $route_by_signature[$signature];
                $path['route_index'] = $route_index;
                $routes[$route_index]['source_path_count']++;

                $ancestor_xref = is_string($path['common_ancestor_xref'] ?? null)
                    ? (string) $path['common_ancestor_xref']
                    : '';
                $ancestor_name = is_string($path['common_ancestor_name'] ?? null)
                    ? (string) $path['common_ancestor_name']
                    : $ancestor_xref;

                if ($ancestor_xref !== '') {
                    $routes[$route_index]['common_ancestors'][$ancestor_xref] = [
                        'xref' => $ancestor_xref,
                        'name' => $ancestor_name,
                    ];
                    $all_common_ancestors[$ancestor_xref] = true;
                }
            }
            unset($path);

            foreach ($routes as &$route) {
                $route['common_ancestors'] = array_values($route['common_ancestors']);
                $route['common_ancestor_count'] = count($route['common_ancestors']);
            }
            unset($route);

            $result['paths'] = $paths;
            $result['routes'] = array_values($routes);
            $result['route_count'] = count($routes);
            $result['common_ancestor_count'] = count($all_common_ancestors);
            $result['raw_path_count'] = (int) ($result['path_count'] ?? count($paths));
            $matrix_data['pairs'][$key] = $result;

            [$left, $right] = array_map('intval', explode('-', $key, 2));
            if (!isset($selected[$left], $selected[$right])) {
                continue;
            }

            $matrix_data['cells'][$left][$right] = $this->cellForDirection($result, $key, $tree, false);
            $matrix_data['cells'][$right][$left] = $this->cellForDirection($result, $key, $tree, true);
        }

        return $matrix_data;
    }

    /** @param array<int,string> $nodes @return array<int,string> */
    private function normaliseCommonAncestorNodes(array $nodes, ?string $ancestor_xref): array
    {
        if ($ancestor_xref === null || count($nodes) < 5) {
            return $nodes;
        }

        // Individual records occupy even indexes and family records odd indexes.
        for ($i = 2; $i < count($nodes) - 2; $i += 2) {
            if (
                $nodes[$i] === $ancestor_xref
                && $nodes[$i - 1] === $nodes[$i + 1]
            ) {
                // Keep the family and remove "common ancestor, duplicate family".
                return array_merge(
                    array_slice($nodes, 0, $i),
                    array_slice($nodes, $i + 2)
                );
            }
        }

        return $nodes;
    }

    /** @param array<int,string> $name_nodes */
    private function routeFamilyXref(array $name_nodes, mixed $l1, mixed $l2): ?string
    {
        if (!is_int($l1) || !is_int($l2) || $l1 <= 0 || $l2 <= 0) {
            return null;
        }

        $family_index = 2 * $l1 - 1;
        if ($family_index < 0 || !isset($name_nodes[$family_index])) {
            return null;
        }

        return (string) $name_nodes[$family_index];
    }

    /**
     * Find the two descendant-side individuals immediately adjacent to the
     * shared ancestral family in the normalised relationship path.
     *
     * @param array<int,string> $name_nodes
     * @return array{0:?string,1:?string}
     */
    private function routeBranchXrefs(array $name_nodes, mixed $l1, mixed $l2): array
    {
        if (!is_int($l1) || !is_int($l2) || $l1 <= 0 || $l2 <= 0) {
            return [null, null];
        }

        $family_index = 2 * $l1 - 1;
        $left_index = $family_index - 1;
        $right_index = $family_index + 1;

        return [
            isset($name_nodes[$left_index]) ? (string) $name_nodes[$left_index] : null,
            isset($name_nodes[$right_index]) ? (string) $name_nodes[$right_index] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function cellForDirection(array $result, string $key, Tree $tree, bool $reverse): array
    {
        $closest = $result['paths'][0] ?? null;

        if (!is_array($closest)) {
            return [
                'self' => false,
                'name' => I18N::translate('No relationship found'),
                'path_count' => 0,
                'route_count' => 0,
                'common_ancestor_count' => 0,
                'steps' => null,
                'notation' => '',
                'pair_key' => $key,
            ];
        }

        $name_nodes = $closest['name_nodes'] ?? $closest['nodes'];
        $l1 = $closest['l1'] ?? null;
        $l2 = $closest['l2'] ?? null;

        if ($reverse) {
            $name_nodes = array_reverse($name_nodes);
            [$l1, $l2] = [$l2, $l1];
        }

        $name = (string) $this->coreCall('relationshipName', [$name_nodes, $tree]);
        if ($name === '') {
            $name = (string) $closest['name'];
        }

        $notation = is_int($l1) && is_int($l2) ? $l1 . ' / ' . $l2 : '';
        $route_count = (int) ($result['route_count'] ?? $result['path_count'] ?? 0);

        return [
            'self' => false,
            'name' => $name,
            'path_count' => $route_count,
            'route_count' => $route_count,
            'common_ancestor_count' => (int) ($result['common_ancestor_count'] ?? 0),
            'steps' => (int) $closest['steps'],
            'notation' => $notation,
            'pair_key' => $key,
        ];
    }

    /**
     * Enhance the alpha presentation while route-grouping behaviour is being
     * tested on real trees.
     *
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     */
    private function pushDisplayEnhancements(array $matrix_data, string $scope, ?array $detail): void
    {
        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-table thead th:not(:first-child),
.potts-rm-cell {
    min-width: 150px !important;
    max-width: 185px;
    white-space: normal;
    overflow-wrap: anywhere;
}
.potts-rm-table thead th:first-child,
.potts-rm-table tbody th {
    min-width: 185px !important;
    max-width: 215px !important;
}
.potts-rm-table-wrap {
    scrollbar-gutter: stable;
}
.potts-rm-family-unit-overlay {
    fill: none;
    stroke: var(--potts-rm-common, var(--bs-success, #198754));
    stroke-width: 2.2;
    stroke-linecap: round;
    stroke-linejoin: round;
    opacity: .9;
}
.potts-rm-family-unit-junction {
    fill: var(--potts-rm-common, var(--bs-success, #198754));
    opacity: .95;
}
.potts-rm-family-unit-label {
    fill: var(--bs-secondary-color, #6c757d);
    font-size: 12px;
    font-weight: 600;
    text-anchor: middle;
}
.potts-rm-pedigree-viewport {
    scroll-behavior: smooth;
    min-height: 0 !important;
    height: 320px;
}
.potts-rm-pedigree-sizer {
    min-height: 0 !important;
}
</style>
HTML;
        View::endpush();

        $matrix_meta = [];
        if ($scope === 'blood') {
            foreach ($matrix_data['cells'] as $row => $cells) {
                foreach ($cells as $column => $cell) {
                    if (!is_array($cell) || !empty($cell['self']) || (int) ($cell['route_count'] ?? 0) === 0) {
                        continue;
                    }

                    $route_count = (int) $cell['route_count'];
                    $ancestor_count = (int) ($cell['common_ancestor_count'] ?? 0);
                    $text = I18N::plural(
                        '%s relationship route',
                        '%s relationship routes',
                        $route_count,
                        I18N::number($route_count)
                    );

                    if ($ancestor_count > 0) {
                        $text .= ' · ' . I18N::plural(
                            '%s common ancestor',
                            '%s common ancestors',
                            $ancestor_count,
                            I18N::number($ancestor_count)
                        );
                    }

                    if ((string) ($cell['notation'] ?? '') !== '') {
                        $text .= ' · ' . (string) $cell['notation'];
                    }

                    $matrix_meta[$row][$column] = $text;
                }
            }
        }

        $detail_payload = null;
        if ($scope === 'blood' && is_array($detail) && (int) ($detail['route_count'] ?? 0) > 0) {
            $routes = [];
            foreach (($detail['routes'] ?? []) as $route_index => $route) {
                $ancestors = [];
                foreach (($route['common_ancestors'] ?? []) as $ancestor) {
                    if (is_array($ancestor)) {
                        $ancestors[] = [
                            'xref' => (string) ($ancestor['xref'] ?? ''),
                            'name' => (string) ($ancestor['name'] ?? ''),
                        ];
                    }
                }

                $routes[] = [
                    'index' => (int) $route_index,
                    'name' => (string) ($route['name'] ?? I18N::translate('Related')),
                    'steps' => (int) ($route['steps'] ?? 0),
                    'l1' => is_int($route['l1'] ?? null) ? (int) $route['l1'] : null,
                    'l2' => is_int($route['l2'] ?? null) ? (int) $route['l2'] : null,
                    'family_xref' => is_string($route['family_xref'] ?? null) ? (string) $route['family_xref'] : null,
                    'left_branch_xref' => is_string($route['left_branch_xref'] ?? null) ? (string) $route['left_branch_xref'] : null,
                    'right_branch_xref' => is_string($route['right_branch_xref'] ?? null) ? (string) $route['right_branch_xref'] : null,
                    'common_ancestors' => $ancestors,
                    'source_path_count' => (int) ($route['source_path_count'] ?? 1),
                ];
            }

            $route_count = (int) $detail['route_count'];
            $ancestor_count = (int) ($detail['common_ancestor_count'] ?? 0);
            $summary = I18N::plural(
                '%s relationship route',
                '%s relationship routes',
                $route_count,
                I18N::number($route_count)
            );
            if ($ancestor_count > 0) {
                $summary .= ' · ' . I18N::plural(
                    '%s common ancestor',
                    '%s common ancestors',
                    $ancestor_count,
                    I18N::number($ancestor_count)
                );
            }

            $detail_payload = [
                'first_name' => (string) ($detail['first_name'] ?? ''),
                'second_name' => (string) ($detail['second_name'] ?? ''),
                'summary' => $summary,
                'routes' => $routes,
                'labels' => [
                    'heading' => I18N::translate('Relationship routes'),
                    'routes_to_display' => I18N::translate('Routes to display'),
                    'all_routes' => I18N::translate('All grouped routes shown'),
                    'routes_heading' => I18N::translate('Relationship routes'),
                    'route' => I18N::translate('Route'),
                    'generation_up' => I18N::translate('generation up'),
                    'generations_up' => I18N::translate('generations up'),
                    'generation_down' => I18N::translate('generation down'),
                    'generations_down' => I18N::translate('generations down'),
                    'common_ancestor' => I18N::translate('Common ancestor'),
                    'common_ancestors' => I18N::translate('Common ancestors'),
                    'shared_family' => I18N::translate('Shared ancestral family'),
                ],
            ];
        }

        $matrix_json = json_encode($matrix_meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $detail_json = json_encode($detail_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        View::push('javascript');
        echo '<script>(function(){"use strict";';
        echo 'const matrixMeta=' . $matrix_json . ';';
        echo 'const routeDetail=' . $detail_json . ';';
        echo <<<'JS'

document.querySelectorAll('.potts-rm-table-wrap').forEach(function (matrix) {
    matrix.scrollLeft = 0;
});

if (matrixMeta && typeof matrixMeta === 'object') {
    const rows = document.querySelectorAll('.potts-rm-table tbody tr');
    Object.keys(matrixMeta).forEach(function (rowKey) {
        const row = rows[Number(rowKey)];
        if (!row) return;
        const cells = row.querySelectorAll('td');
        Object.keys(matrixMeta[rowKey]).forEach(function (columnKey) {
            const cell = cells[Number(columnKey)];
            if (!cell) return;
            const meta = cell.querySelector('.potts-rm-cell-meta');
            if (meta) meta.textContent = matrixMeta[rowKey][columnKey];
        });
    });
}

if (!routeDetail || !Array.isArray(routeDetail.routes)) {
    return;
}

const detail = document.getElementById('potts-rm-detail');
if (!detail) return;

const heading = detail.querySelector('.potts-rm-detail-head h3');
if (heading) {
    heading.textContent = routeDetail.labels.heading + ': ' + routeDetail.first_name + ' → ' + routeDetail.second_name;
}

const summary = detail.querySelector('.potts-rm-detail-summary');
if (summary) {
    summary.textContent = routeDetail.summary;
}

const selector = document.getElementById('potts-rm-chart-paths');
if (selector) {
    const label = detail.querySelector('label[for="potts-rm-chart-paths"]');
    if (label) label.textContent = routeDetail.labels.routes_to_display;
    selector.value = 'all';
    const mergedRoute = routeDetail.routes.some(route => Number(route.source_path_count || 0) > 1);
    if (mergedRoute) {
        selector.disabled = true;
        Array.from(selector.options).forEach(option => {
            if (option.value === 'all') option.textContent = routeDetail.labels.all_routes;
        });
    }
}

function generationText(route) {
    const parts = [];
    if (Number.isInteger(route.l1) && route.l1 > 0) {
        parts.push(route.l1 + ' ' + (route.l1 === 1 ? routeDetail.labels.generation_up : routeDetail.labels.generations_up));
    }
    if (Number.isInteger(route.l2) && route.l2 > 0) {
        parts.push(route.l2 + ' ' + (route.l2 === 1 ? routeDetail.labels.generation_down : routeDetail.labels.generations_down));
    }
    return parts.join(' · ');
}

const pathGrid = detail.querySelector('.potts-rm-paths');
if (pathGrid) {
    const sectionHeading = pathGrid.previousElementSibling;
    if (sectionHeading && /^H[1-6]$/.test(sectionHeading.tagName)) {
        sectionHeading.textContent = routeDetail.labels.routes_heading;
    }

    pathGrid.innerHTML = '';
    routeDetail.routes.forEach(function (route, index) {
        const article = document.createElement('article');
        article.className = 'potts-rm-path';

        const title = document.createElement('div');
        title.className = 'potts-rm-path-title';
        title.textContent = routeDetail.labels.route + ' ' + (index + 1) + ' — ' + route.name;
        article.appendChild(title);

        const generation = generationText(route);
        if (generation !== '') {
            const generationBadge = document.createElement('span');
            generationBadge.className = 'potts-rm-badge';
            generationBadge.textContent = generation;
            article.appendChild(generationBadge);
        }

        if (Array.isArray(route.common_ancestors) && route.common_ancestors.length > 0) {
            const ancestors = document.createElement('div');
            ancestors.className = 'mt-2';
            const strong = document.createElement('strong');
            strong.textContent = (route.common_ancestors.length === 1 ? routeDetail.labels.common_ancestor : routeDetail.labels.common_ancestors) + ': ';
            ancestors.appendChild(strong);
            ancestors.appendChild(document.createTextNode(route.common_ancestors.map(ancestor => ancestor.name).join(' & ')));
            article.appendChild(ancestors);
        }

        pathGrid.appendChild(article);
    });
}

function cardForXref(cardsLayer, xref) {
    if (!xref) return null;
    return cardsLayer.querySelector('[data-node-id="I|' + CSS.escape(xref) + '"]');
}

function visibleCardPosition(card) {
    if (!card || card.style.display === 'none') return null;
    return {
        x: card.offsetLeft,
        y: card.offsetTop,
        width: card.offsetWidth,
        height: card.offsetHeight,
        cx: card.offsetLeft + card.offsetWidth / 2,
        cy: card.offsetTop + card.offsetHeight / 2
    };
}

function appendPath(svg, d, className) {
    const NS = 'http://www.w3.org/2000/svg';
    const path = document.createElementNS(NS, 'path');
    path.setAttribute('class', className || 'potts-rm-family-unit-overlay');
    path.setAttribute('d', d);
    svg.appendChild(path);
    return path;
}

function appendJunction(svg, x, y) {
    const NS = 'http://www.w3.org/2000/svg';
    const junction = document.createElementNS(NS, 'circle');
    junction.setAttribute('class', 'potts-rm-family-unit-junction');
    junction.setAttribute('cx', String(x));
    junction.setAttribute('cy', String(y));
    junction.setAttribute('r', '4');
    svg.appendChild(junction);
}

function drawFamilyUnits() {
    const svg = document.getElementById('potts-rm-pedigree-connectors');
    const cardsLayer = document.getElementById('potts-rm-pedigree-cards');
    if (!svg || !cardsLayer) return;

    svg.querySelectorAll('.potts-rm-family-unit-overlay, .potts-rm-family-unit-junction, .potts-rm-family-unit-label').forEach(node => node.remove());
    const NS = 'http://www.w3.org/2000/svg';

    routeDetail.routes.forEach(function (route) {
        if (!route.family_xref || !Array.isArray(route.common_ancestors) || route.common_ancestors.length < 2) return;

        const parentCards = route.common_ancestors
            .map(ancestor => cardForXref(cardsLayer, ancestor.xref))
            .filter(card => card && card.style.display !== 'none');
        if (parentCards.length < 2) return;

        const parents = parentCards.map(visibleCardPosition).filter(Boolean);
        if (parents.length < 2) return;

        const minX = Math.min(...parents.map(pos => pos.x));
        const maxRight = Math.max(...parents.map(pos => pos.x + pos.width));
        const minY = Math.min(...parents.map(pos => pos.cy));
        const maxY = Math.max(...parents.map(pos => pos.cy));
        const middleY = (minY + maxY) / 2;
        const leftSpineX = minX - 18;
        const rightSpineX = maxRight + 18;

        appendPath(svg, 'M ' + leftSpineX + ' ' + minY + ' V ' + maxY);
        appendPath(svg, 'M ' + rightSpineX + ' ' + minY + ' V ' + maxY);

        parents.forEach(function (pos) {
            appendPath(svg, 'M ' + leftSpineX + ' ' + pos.cy + ' H ' + pos.x);
            appendPath(svg, 'M ' + (pos.x + pos.width) + ' ' + pos.cy + ' H ' + rightSpineX);
        });

        appendJunction(svg, leftSpineX, middleY);
        appendJunction(svg, rightSpineX, middleY);

        const leftBranch = visibleCardPosition(cardForXref(cardsLayer, route.left_branch_xref));
        const rightBranch = visibleCardPosition(cardForXref(cardsLayer, route.right_branch_xref));

        if (leftBranch) {
            const startX = leftBranch.x + leftBranch.width;
            const elbowX = Math.max(startX + 18, leftSpineX - 26);
            appendPath(svg, 'M ' + startX + ' ' + leftBranch.cy + ' H ' + elbowX + ' V ' + middleY + ' H ' + leftSpineX);
        }

        if (rightBranch) {
            const endX = rightBranch.x;
            const elbowX = Math.min(endX - 18, rightSpineX + 26);
            appendPath(svg, 'M ' + rightSpineX + ' ' + middleY + ' H ' + elbowX + ' V ' + rightBranch.cy + ' H ' + endX);
        }

        const label = document.createElementNS(NS, 'text');
        label.setAttribute('class', 'potts-rm-family-unit-label');
        label.setAttribute('x', String((minX + maxRight) / 2));
        label.setAttribute('y', String(Math.max(14, Math.min(...parents.map(pos => pos.y)) - 8)));
        label.textContent = routeDetail.labels.shared_family;
        svg.appendChild(label);
    });
}

let readableDefaultApplied = false;
function applyReadableDefault() {
    if (readableDefaultApplied) return;
    readableDefaultApplied = true;

    const fitWidth = document.getElementById('potts-rm-fit-width');
    const viewport = document.getElementById('potts-rm-pedigree-viewport');
    if (fitWidth && fitWidth.checked) {
        fitWidth.checked = false;
        fitWidth.dispatchEvent(new Event('change', {bubbles: true}));
    }
    if (viewport) {
        viewport.scrollLeft = 0;
    }
}

function currentScale(canvas) {
    if (!canvas) return 1;
    const match = /scale\(([^)]+)\)/.exec(canvas.style.transform || '');
    if (!match) return 1;
    const value = Number(match[1]);
    return Number.isFinite(value) && value > 0 ? value : 1;
}

function compactChartHeight() {
    const viewport = document.getElementById('potts-rm-pedigree-viewport');
    const sizer = document.getElementById('potts-rm-pedigree-sizer');
    const canvas = document.getElementById('potts-rm-pedigree-canvas');
    const cardsLayer = document.getElementById('potts-rm-pedigree-cards');
    if (!viewport || !sizer || !canvas || !cardsLayer) return;

    let maxBottom = 0;
    cardsLayer.querySelectorAll('[data-node-id]').forEach(function (card) {
        if (card.style.display === 'none') return;
        maxBottom = Math.max(maxBottom, card.offsetTop + card.offsetHeight);
    });

    const scale = currentScale(canvas);
    const desiredHeight = Math.max(220, Math.min(620, Math.ceil(maxBottom * scale + 52)));
    viewport.style.minHeight = '0';
    viewport.style.height = desiredHeight + 'px';
    sizer.style.minHeight = '0';
    sizer.style.height = desiredHeight + 'px';
}

function scheduleFamilyUnits() {
    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () {
            applyReadableDefault();
            compactChartHeight();
            drawFamilyUnits();
        });
    });
}

['potts-rm-show-photos', 'potts-rm-show-details', 'potts-rm-highlight-common', 'potts-rm-fit-width'].forEach(function (id) {
    const control = document.getElementById(id);
    if (control) control.addEventListener('change', scheduleFamilyUnits);
});
window.addEventListener('resize', scheduleFamilyUnits);
scheduleFamilyUnits();
})();</script>
JS;
        View::endpush();
    }

    private function coreCall(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($this->core, $method);

        return $reflection->invoke($this->core, ...$arguments);
    }
};
