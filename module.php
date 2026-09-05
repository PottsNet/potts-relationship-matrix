<?php

/**
 * Potts Relationship Matrix for webtrees.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use Fisharebest\Algorithm\Dijkstra;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function http_build_query;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_match;
use function route;
use function sort;
use function strip_tags;
use function trim;

return new class extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface {
    use ModuleCustomTrait;
    use ModuleChartTrait;

    private const VERSION = '0.1.0-alpha.1';
    private const MAX_PEOPLE = 8;
    private const MAX_ANCESTOR_GENERATIONS = 12;
    private const MAX_ANCESTOR_PATHS = 2000;
    private const MAX_PATHS_PER_ANCESTOR = 32;
    private const MAX_PAIR_PATHS = 100;
    private const MAX_GRAPH_PATHS = 12;
    private const MAX_ALTERNATIVE_RECURSION = 2;

    private const SCOPE_BLOOD = 'blood';
    private const SCOPE_ALL = 'all';

    private const GITHUB_REPO_URL = 'https://github.com/PottsNet/potts-relationship-matrix';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts-relationship-matrix/main/latest-version.txt';

    /** @var array<string,Individual|null> */
    private array $individual_cache = [];

    /** @var array<string,Family|null> */
    private array $family_cache = [];

    /** @var array<string,array<string,array<int,array{nodes:array<int,string>,generations:int}>>> */
    private array $ancestor_cache = [];

    /** @var array<string,array{graph:array<string,array<string,int>>,roles:array<string,array<string,array<string,bool>>>}> */
    private array $graph_cache = [];

    private ?RelationshipService $relationship_service = null;

    public function title(): string
    {
        return I18N::translate('Relationship Matrix');
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

    public function boot(): void
    {
        View::registerNamespace('potts-relationship-matrix', $this->resourcesFolder() . 'views/');
    }

    public function chartMenuClass(): string
    {
        return 'menu-chart-relationship-matrix';
    }

    public function chartTitle(Individual $individual): string
    {
        return I18N::translate('Relationship Matrix');
    }

    public function chartMenu(Individual $individual): Menu
    {
        return new Menu(
            $this->title(),
            $this->chartUrl($individual),
            $this->chartMenuClass(),
            $this->chartUrlAttributes()
        );
    }

    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $route_xref = Validator::attributes($request)->isXref()->string('xref');
        $route_individual = Registry::individualFactory()->make($route_xref, $tree);

        if (!$route_individual instanceof Individual || !$route_individual->canShow()) {
            return response(I18N::translate('The individual could not be found.'))->withStatus(404);
        }

        $query = $request->getQueryParams();
        $scope = ($query['scope'] ?? self::SCOPE_BLOOD) === self::SCOPE_ALL ? self::SCOPE_ALL : self::SCOPE_BLOOD;
        $recursion = min(self::MAX_ALTERNATIVE_RECURSION, max(0, (int) ($query['recursion'] ?? 1)));

        [$slots, $selected] = $this->selectedIndividuals($tree, $route_individual, $query);

        $matrix_data = [
            'cells' => [],
            'pairs' => [],
        ];

        if (count($selected) >= 2) {
            $matrix_data = $this->calculateMatrix($selected, $tree, $scope, $recursion);
        }

        $pair_key = is_string($query['pair'] ?? null) ? (string) $query['pair'] : '';
        $detail = $matrix_data['pairs'][$pair_key] ?? null;
        $graph = is_array($detail) ? $this->graphData($detail, $tree) : null;

        $base_url = $this->chartUrl($route_individual);
        $query_values = $this->queryValues($slots, $scope, $recursion);

        $detail_urls = [];
        foreach (array_keys($matrix_data['pairs']) as $key) {
            $detail_urls[$key] = $base_url . '?' . http_build_query($query_values + ['pair' => $key], '', '&', PHP_QUERY_RFC3986);
        }

        $this->layout = 'layouts/default';

        return $this->viewResponse('potts-relationship-matrix::page', [
            'title' => $this->title(),
            'tree' => $tree,
            'route_individual' => $route_individual,
            'slots' => $slots,
            'selected' => $selected,
            'matrix' => $matrix_data['cells'],
            'scope' => $scope,
            'recursion' => $recursion,
            'max_people' => self::MAX_PEOPLE,
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
     * @param array<string,mixed> $query
     * @return array{0:array<int,Individual|null>,1:array<int,Individual>}
     */
    private function selectedIndividuals(Tree $tree, Individual $route_individual, array $query): array
    {
        $slots = [];
        $selected = [];
        $seen = [];

        for ($i = 1; $i <= self::MAX_PEOPLE; $i++) {
            $value = trim((string) ($query['p' . $i] ?? ''));

            if ($i === 1 && $value === '') {
                $individual = $route_individual;
            } else {
                $individual = $value === '' ? null : $this->visibleIndividual($value, $tree);
            }

            if ($individual instanceof Individual && !isset($seen[$individual->xref()])) {
                $slots[$i] = $individual;
                $selected[] = $individual;
                $seen[$individual->xref()] = true;
            } else {
                $slots[$i] = null;
            }
        }

        return [$slots, $selected];
    }

    /**
     * @param array<int,Individual|null> $slots
     * @return array<string,string|int>
     */
    private function queryValues(array $slots, string $scope, int $recursion): array
    {
        $values = [
            'scope' => $scope,
            'recursion' => $recursion,
        ];

        foreach ($slots as $index => $individual) {
            if ($individual instanceof Individual) {
                $values['p' . $index] = $individual->xref();
            }
        }

        return $values;
    }

    /**
     * @param array<int,Individual> $individuals
     * @return array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>}
     */
    private function calculateMatrix(array $individuals, Tree $tree, string $scope, int $recursion): array
    {
        $count = count($individuals);
        $cells = array_fill(0, $count, array_fill(0, $count, null));
        $pairs = [];

        for ($i = 0; $i < $count; $i++) {
            $cells[$i][$i] = [
                'self' => true,
                'name' => I18N::translate('Self'),
                'path_count' => 0,
                'steps' => 0,
                'notation' => '',
                'pair_key' => '',
            ];

            for ($j = $i + 1; $j < $count; $j++) {
                $key = $i . '-' . $j;
                $result = $scope === self::SCOPE_ALL
                    ? $this->calculateAllFamilyPair($individuals[$i], $individuals[$j], $tree, $recursion)
                    : $this->calculateBloodPair($individuals[$i], $individuals[$j], $tree);

                $pairs[$key] = $result;
                $cells[$i][$j] = $this->cellFromResult($result, $key);

                $reverse = $this->reverseResult($result, $tree);
                $cells[$j][$i] = $this->cellFromResult($reverse, $key);
            }
        }

        return [
            'cells' => $cells,
            'pairs' => $pairs,
        ];
    }

    /** @return array<string,mixed> */
    private function cellFromResult(array $result, string $key): array
    {
        $closest = $result['paths'][0] ?? null;

        if (!is_array($closest)) {
            return [
                'self' => false,
                'name' => I18N::translate('No relationship found'),
                'path_count' => 0,
                'steps' => null,
                'notation' => '',
                'pair_key' => $key,
            ];
        }

        $notation = '';
        if (isset($closest['l1'], $closest['l2']) && is_int($closest['l1']) && is_int($closest['l2'])) {
            $notation = $closest['l1'] . ' / ' . $closest['l2'];
        }

        return [
            'self' => false,
            'name' => $closest['name'],
            'path_count' => (int) $result['path_count'],
            'steps' => (int) $closest['steps'],
            'notation' => $notation,
            'pair_key' => $key,
        ];
    }

    /** @return array<string,mixed> */
    private function calculateBloodPair(Individual $first, Individual $second, Tree $tree): array
    {
        $first_ancestors = $this->ancestorPaths($first);
        $second_ancestors = $this->ancestorPaths($second);
        $common = array_values(array_intersect(array_keys($first_ancestors), array_keys($second_ancestors)));
        $paths = [];
        $dedupe = [];

        foreach ($common as $ancestor_xref) {
            foreach ($first_ancestors[$ancestor_xref] as $path1) {
                foreach ($second_ancestors[$ancestor_xref] as $path2) {
                    $tail = array_slice(array_reverse($path2['nodes']), 1);
                    $nodes = array_merge($path1['nodes'], $tail);

                    if (!$this->simpleIndividualPath($nodes)) {
                        continue;
                    }

                    $signature = implode('|', $nodes);
                    if (isset($dedupe[$signature])) {
                        continue;
                    }

                    $dedupe[$signature] = true;
                    $ancestor = $this->visibleIndividual($ancestor_xref, $tree);
                    $name = $this->relationshipName($nodes, $tree);

                    $paths[] = [
                        'nodes' => $nodes,
                        'name' => $name !== '' ? $name : I18N::translate('Related'),
                        'steps' => (int) ((count($nodes) - 1) / 2),
                        'l1' => $path1['generations'],
                        'l2' => $path2['generations'],
                        'common_ancestor_xref' => $ancestor_xref,
                        'common_ancestor_name' => $ancestor instanceof Individual ? strip_tags($ancestor->fullName()) : $ancestor_xref,
                        'contains_spouse_link' => false,
                    ];

                    if (count($paths) >= self::MAX_PAIR_PATHS) {
                        break 3;
                    }
                }
            }
        }

        $this->sortPaths($paths);

        return [
            'scope' => self::SCOPE_BLOOD,
            'first_xref' => $first->xref(),
            'first_name' => strip_tags($first->fullName()),
            'second_xref' => $second->xref(),
            'second_name' => strip_tags($second->fullName()),
            'paths' => $paths,
            'path_count' => count($paths),
            'truncated' => count($paths) >= self::MAX_PAIR_PATHS,
        ];
    }

    /** @return array<string,mixed> */
    private function calculateAllFamilyPair(Individual $first, Individual $second, Tree $tree, int $recursion): array
    {
        $index = $this->visibleGraphIndex($tree);
        $graph = $index['graph'];

        if (!isset($graph[$first->xref()], $graph[$second->xref()])) {
            return [
                'scope' => self::SCOPE_ALL,
                'first_xref' => $first->xref(),
                'first_name' => strip_tags($first->fullName()),
                'second_xref' => $second->xref(),
                'second_name' => strip_tags($second->fullName()),
                'paths' => [],
                'path_count' => 0,
                'truncated' => false,
            ];
        }

        $dijkstra = new Dijkstra($graph);
        $initial = $dijkstra->shortestPaths($first->xref(), $second->xref());
        $queue = [];
        $excluded_signatures = [];

        foreach ($initial as $path) {
            $queue[] = [
                'path' => array_map(static fn ($value): string => (string) $value, $path),
                'exclude' => [],
            ];
        }

        $cursor = 0;
        while (isset($queue[$cursor]) && count($queue) < self::MAX_PAIR_PATHS) {
            $entry = $queue[$cursor++];
            $path = $entry['path'];

            for ($n = count($path) - 2; $n >= 1; $n -= 2) {
                $exclude = $entry['exclude'];
                if (count($exclude) >= $recursion) {
                    continue;
                }

                $exclude[] = $path[$n];
                sort($exclude);
                $signature = implode('-', $exclude);

                if (isset($excluded_signatures[$signature])) {
                    continue;
                }

                $excluded_signatures[$signature] = true;

                foreach ($dijkstra->shortestPaths($first->xref(), $second->xref(), $exclude) as $new_path) {
                    $queue[] = [
                        'path' => array_map(static fn ($value): string => (string) $value, $new_path),
                        'exclude' => $exclude,
                    ];

                    if (count($queue) >= self::MAX_PAIR_PATHS) {
                        break 2;
                    }
                }
            }
        }

        $paths = [];
        $dedupe = [];
        foreach ($queue as $entry) {
            $nodes = $entry['path'];
            $signature = implode('|', $nodes);
            if (isset($dedupe[$signature])) {
                continue;
            }

            $dedupe[$signature] = true;
            $name = $this->relationshipName($nodes, $tree);

            $paths[] = [
                'nodes' => $nodes,
                'name' => $name !== '' ? $name : I18N::translate('Family connection'),
                'steps' => (int) ((count($nodes) - 1) / 2),
                'l1' => null,
                'l2' => null,
                'common_ancestor_xref' => null,
                'common_ancestor_name' => null,
                'contains_spouse_link' => $this->containsSpouseLink($nodes, $index['roles']),
            ];
        }

        $this->sortPaths($paths);

        return [
            'scope' => self::SCOPE_ALL,
            'first_xref' => $first->xref(),
            'first_name' => strip_tags($first->fullName()),
            'second_xref' => $second->xref(),
            'second_name' => strip_tags($second->fullName()),
            'paths' => $paths,
            'path_count' => count($paths),
            'truncated' => count($queue) >= self::MAX_PAIR_PATHS,
        ];
    }

    /**
     * @return array<string,array<int,array{nodes:array<int,string>,generations:int}>>
     */
    private function ancestorPaths(Individual $individual): array
    {
        $cache_key = $individual->tree()->id() . ':' . $individual->xref();
        if (isset($this->ancestor_cache[$cache_key])) {
            return $this->ancestor_cache[$cache_key];
        }

        $result = [
            $individual->xref() => [[
                'nodes' => [$individual->xref()],
                'generations' => 0,
            ]],
        ];

        $queue = [[
            'individual' => $individual,
            'nodes' => [$individual->xref()],
            'generations' => 0,
            'seen' => [$individual->xref() => true],
        ]];

        $cursor = 0;
        $total_paths = 1;

        while (isset($queue[$cursor]) && $total_paths < self::MAX_ANCESTOR_PATHS) {
            $state = $queue[$cursor++];
            if ($state['generations'] >= self::MAX_ANCESTOR_GENERATIONS) {
                continue;
            }

            /** @var Individual $current */
            $current = $state['individual'];

            foreach ($current->childFamilies() as $family) {
                if (!$family instanceof Family || !$family->canShow()) {
                    continue;
                }

                foreach ($family->spouses() as $parent) {
                    if (!$parent instanceof Individual || !$parent->canShow()) {
                        continue;
                    }

                    $xref = $parent->xref();
                    if (isset($state['seen'][$xref])) {
                        continue;
                    }

                    $nodes = array_merge($state['nodes'], [$family->xref(), $xref]);
                    $generation = $state['generations'] + 1;
                    $existing = $result[$xref] ?? [];

                    if (count($existing) >= self::MAX_PATHS_PER_ANCESTOR) {
                        continue;
                    }

                    $signature = implode('|', $nodes);
                    $already = false;
                    foreach ($existing as $known) {
                        if (implode('|', $known['nodes']) === $signature) {
                            $already = true;
                            break;
                        }
                    }

                    if ($already) {
                        continue;
                    }

                    $result[$xref][] = [
                        'nodes' => $nodes,
                        'generations' => $generation,
                    ];
                    $total_paths++;

                    $seen = $state['seen'];
                    $seen[$xref] = true;
                    $queue[] = [
                        'individual' => $parent,
                        'nodes' => $nodes,
                        'generations' => $generation,
                        'seen' => $seen,
                    ];

                    if ($total_paths >= self::MAX_ANCESTOR_PATHS) {
                        break 2;
                    }
                }
            }
        }

        $this->ancestor_cache[$cache_key] = $result;

        return $result;
    }

    /**
     * @return array{graph:array<string,array<string,int>>,roles:array<string,array<string,array<string,bool>>>}
     */
    private function visibleGraphIndex(Tree $tree): array
    {
        $cache_key = (string) $tree->id();
        if (isset($this->graph_cache[$cache_key])) {
            return $this->graph_cache[$cache_key];
        }

        $rows = DB::table('link')
            ->where('l_file', '=', $tree->id())
            ->whereIn('l_type', ['FAMS', 'FAMC'])
            ->select(['l_from', 'l_to', 'l_type'])
            ->get();

        $graph = [];
        $roles = [];

        foreach ($rows as $row) {
            $individual_xref = (string) $row->l_from;
            $family_xref = (string) $row->l_to;
            $type = (string) $row->l_type;

            $individual = $this->visibleIndividual($individual_xref, $tree);
            $family = $this->visibleFamily($family_xref, $tree);

            if (!$individual instanceof Individual || !$family instanceof Family) {
                continue;
            }

            $graph[$individual_xref][$family_xref] = 1;
            $graph[$family_xref][$individual_xref] = 1;
            $roles[$family_xref][$type][$individual_xref] = true;
        }

        $this->graph_cache[$cache_key] = [
            'graph' => $graph,
            'roles' => $roles,
        ];

        return $this->graph_cache[$cache_key];
    }

    /** @param array<string,array<string,array<string,bool>>> $roles */
    private function containsSpouseLink(array $nodes, array $roles): bool
    {
        for ($i = 1; $i < count($nodes) - 1; $i += 2) {
            $family = $nodes[$i];
            $previous = $nodes[$i - 1];
            $next = $nodes[$i + 1];

            if (isset($roles[$family]['FAMS'][$previous], $roles[$family]['FAMS'][$next])) {
                return true;
            }
        }

        return false;
    }

    private function simpleIndividualPath(array $nodes): bool
    {
        $individuals = [];
        for ($i = 0; $i < count($nodes); $i += 2) {
            $individuals[] = $nodes[$i];
        }

        return count($individuals) === count(array_unique($individuals));
    }

    private function relationshipName(array $nodes, Tree $tree): string
    {
        $records = [];

        foreach ($nodes as $index => $xref) {
            if ($index % 2 === 0) {
                $record = $this->visibleIndividual($xref, $tree);
            } else {
                $record = $this->visibleFamily($xref, $tree);
            }

            if ($record === null) {
                return '';
            }

            $records[] = $record;
        }

        return $this->relationshipService()->nameFromPath($records, I18N::language());
    }

    private function relationshipService(): RelationshipService
    {
        if (!$this->relationship_service instanceof RelationshipService) {
            $this->relationship_service = new RelationshipService();
        }

        return $this->relationship_service;
    }

    private function visibleIndividual(string $xref, Tree $tree): ?Individual
    {
        $key = $tree->id() . ':I:' . $xref;
        if (array_key_exists($key, $this->individual_cache)) {
            return $this->individual_cache[$key];
        }

        $individual = Registry::individualFactory()->make($xref, $tree);
        if (!$individual instanceof Individual || !$individual->canShow()) {
            $individual = null;
        }

        $this->individual_cache[$key] = $individual;

        return $individual;
    }

    private function visibleFamily(string $xref, Tree $tree): ?Family
    {
        $key = $tree->id() . ':F:' . $xref;
        if (array_key_exists($key, $this->family_cache)) {
            return $this->family_cache[$key];
        }

        $family = Registry::familyFactory()->make($xref, $tree);
        if (!$family instanceof Family || !$family->canShow()) {
            $family = null;
        }

        $this->family_cache[$key] = $family;

        return $family;
    }

    /** @param array<int,array<string,mixed>> $paths */
    private function sortPaths(array &$paths): void
    {
        usort($paths, static function (array $a, array $b): int {
            $steps = ((int) $a['steps']) <=> ((int) $b['steps']);
            if ($steps !== 0) {
                return $steps;
            }

            $spouse = ((int) ($a['contains_spouse_link'] ?? false)) <=> ((int) ($b['contains_spouse_link'] ?? false));
            if ($spouse !== 0) {
                return $spouse;
            }

            $a_l1 = is_int($a['l1'] ?? null) ? (int) $a['l1'] : 999;
            $b_l1 = is_int($b['l1'] ?? null) ? (int) $b['l1'] : 999;

            return $a_l1 <=> $b_l1;
        });
    }

    /** @return array<string,mixed> */
    private function reverseResult(array $result, Tree $tree): array
    {
        $paths = [];

        foreach ($result['paths'] as $path) {
            $nodes = array_reverse($path['nodes']);
            $name = $this->relationshipName($nodes, $tree);
            $l1 = $path['l2'] ?? null;
            $l2 = $path['l1'] ?? null;

            $copy = $path;
            $copy['nodes'] = $nodes;
            $copy['name'] = $name !== '' ? $name : $path['name'];
            $copy['l1'] = $l1;
            $copy['l2'] = $l2;
            $paths[] = $copy;
        }

        $copy = $result;
        $copy['first_xref'] = $result['second_xref'];
        $copy['first_name'] = $result['second_name'];
        $copy['second_xref'] = $result['first_xref'];
        $copy['second_name'] = $result['first_name'];
        $copy['paths'] = $paths;

        return $copy;
    }

    /** @return array<string,mixed> */
    private function graphData(array $result, Tree $tree): array
    {
        $nodes = [];
        $edges = [];
        $edge_seen = [];
        $common = [];
        $paths = array_slice($result['paths'], 0, self::MAX_GRAPH_PATHS);

        foreach ($paths as $path_index => $path) {
            if (is_string($path['common_ancestor_xref'] ?? null)) {
                $common[(string) $path['common_ancestor_xref']] = true;
            }

            foreach ($path['nodes'] as $index => $xref) {
                $type = $index % 2 === 0 ? 'individual' : 'family';
                $node_id = ($type === 'individual' ? 'I|' : 'F|') . $xref;

                if (!isset($nodes[$node_id])) {
                    if ($type === 'individual') {
                        $individual = $this->visibleIndividual($xref, $tree);
                        $nodes[$node_id] = [
                            'id' => $node_id,
                            'xref' => $xref,
                            'type' => 'individual',
                            'label' => $individual instanceof Individual ? strip_tags($individual->fullName()) : $xref,
                            'url' => $individual instanceof Individual ? $individual->url() : '',
                            'common' => isset($common[$xref]),
                            'endpoint' => in_array($xref, [$result['first_xref'], $result['second_xref']], true),
                        ];
                    } else {
                        $nodes[$node_id] = [
                            'id' => $node_id,
                            'xref' => $xref,
                            'type' => 'family',
                            'label' => I18N::translate('Family'),
                            'url' => '',
                            'common' => false,
                            'endpoint' => false,
                        ];
                    }
                } elseif ($type === 'individual' && isset($common[$xref])) {
                    $nodes[$node_id]['common'] = true;
                }

                if ($index > 0) {
                    $previous_type = ($index - 1) % 2 === 0 ? 'I|' : 'F|';
                    $previous_id = $previous_type . $path['nodes'][$index - 1];
                    $edge_key = $previous_id . '>' . $node_id;
                    $reverse_key = $node_id . '>' . $previous_id;

                    if (!isset($edge_seen[$edge_key], $edge_seen[$reverse_key])) {
                        $edges[] = [
                            'from' => $previous_id,
                            'to' => $node_id,
                            'path' => $path_index,
                        ];
                        $edge_seen[$edge_key] = true;
                    }
                }
            }
        }

        return [
            'first' => 'I|' . $result['first_xref'],
            'second' => 'I|' . $result['second_xref'],
            'nodes' => array_values($nodes),
            'edges' => $edges,
            'path_count_shown' => count($paths),
            'path_count_total' => (int) $result['path_count'],
        ];
    }
};
