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

    private const VERSION = '0.1.0-alpha.4';
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
        // The core object is not registered as a separate webtrees module, so it
        // needs the same internal module name before it generates routes or
        // checks component access.
        $this->core->setName($this->name());
        $this->core->setEnabled($this->isEnabled());
        $this->core->boot();

        // RelationshipMatrixCore.php lives in /src, so its own __DIR__ points
        // one level below the real module resources folder. Re-register the
        // namespace from the public wrapper.
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
        $this->pushMatrixDisplayFixes();

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
     * Correct common-ancestor paths before asking webtrees to name them.
     *
     * When two descendants reach the same ancestor through the same family, the
     * raw ancestral merge contains FAM -> ancestor -> same FAM. For relationship
     * naming that must collapse to one FAM link. Leaving the duplicate in place
     * can turn full siblings into "half-brother" and produce unnecessarily long
     * descriptions such as "grandfather's granddaughter".
     *
     * The raw nodes remain in $path['nodes'] so the graphical view can still show
     * the common ancestor. $path['name_nodes'] contains the simplified path used
     * only for relationship naming and step counts.
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

            $result['paths'] = $paths;
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
                // Keep the first family and remove "ancestor, duplicate family".
                return array_merge(
                    array_slice($nodes, 0, $i),
                    array_slice($nodes, $i + 2)
                );
            }
        }

        return $nodes;
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

        return [
            'self' => false,
            'name' => $name,
            'path_count' => (int) $result['path_count'],
            'steps' => (int) $closest['steps'],
            'notation' => $notation,
            'pair_key' => $key,
        ];
    }

    /**
     * Keep four-person matrices readable on laptop-width screens and prevent
     * browsers from restoring a previous horizontal scroll position that hides
     * the first relationship columns behind the sticky person-name column.
     */
    private function pushMatrixDisplayFixes(): void
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
</style>
HTML;
        View::endpush();

        View::push('javascript');
        echo <<<'HTML'
<script>
document.querySelectorAll('.potts-rm-table-wrap').forEach(function (matrix) {
    matrix.scrollLeft = 0;
});
</script>
HTML;
        View::endpush();
    }

    private function coreCall(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($this->core, $method);

        return $reflection->invoke($this->core, ...$arguments);
    }
};
