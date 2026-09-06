<?php

/**
 * Potts Relationship Matrix for webtrees.
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
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

$core = require __DIR__ . '/src/RelationshipMatrixCore.php';
require_once __DIR__ . '/src/RelationshipMatrixSupport.php';
require_once __DIR__ . '/src/MultiPersonTopDownEnhancement.php';
require_once __DIR__ . '/src/ConnectedRelationshipsEnhancement.php';
$support = new PottsRelationshipMatrixSupport($core);
$top_down = new PottsRelationshipMatrixTopDownEnhancement();
$connected = new PottsRelationshipMatrixConnectedEnhancement($support);

return new class($core, $support, $top_down, $connected) extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface {
    use ModuleCustomTrait;
    use ModuleChartTrait;

    private const VERSION = '0.1.0-alpha.10';
    private const GITHUB_REPO_URL = 'https://github.com/PottsNet/potts-relationship-matrix';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts-relationship-matrix/main/latest-version.txt';

    public function __construct(
        private readonly object $core,
        private readonly PottsRelationshipMatrixSupport $support,
        private readonly PottsRelationshipMatrixTopDownEnhancement $top_down,
        private readonly PottsRelationshipMatrixConnectedEnhancement $connected
    ) {
    }

    public function title(): string
    {
        return I18N::translate('Potts Relationship Matrix');
    }

    public function description(): string
    {
        return I18N::translate('Compare multiple relationships and display pair, shared-ancestry and connected-family graphs.');
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

        // The relationship engine lives in /src, while the public views live in
        // the module-level /resources folder.
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

        // Charts can be opened from the main menu without an individual XREF.
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
        $selection = $this->support->call('selectedIndividuals', [$tree, $route_individual, $query]);
        [$slots, $selected] = $selection;

        $matrix_data = [
            'cells' => [],
            'pairs' => [],
        ];

        if (count($selected) >= 2) {
            /** @var array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data */
            $matrix_data = $this->support->call('calculateMatrix', [$selected, $tree, $scope, $recursion]);

            if ($scope === 'blood') {
                $matrix_data = $this->support->normaliseBloodMatrix($matrix_data, $selected, $tree);
            }
        }

        $pair_key = is_string($query['pair'] ?? null) ? (string) $query['pair'] : '';
        $detail = $matrix_data['pairs'][$pair_key] ?? null;
        $graph = is_array($detail) ? $this->support->call('graphData', [$detail, $tree]) : null;

        $base_url = $this->chartUrl($route_individual);
        /** @var array<string,string|int> $query_values */
        $query_values = $this->support->call('queryValues', [$slots, $scope, $recursion]);

        $detail_urls = [];
        foreach (array_keys($matrix_data['pairs']) as $key) {
            $detail_urls[$key] = $base_url . '?' . http_build_query($query_values + ['pair' => $key], '', '&', PHP_QUERY_RFC3986);
        }

        $multi_mode = '';
        if (is_string($query['multi'] ?? null)) {
            $multi_mode = (string) $query['multi'] === 'all' ? 'all' : 'nearest';
        }

        $multi_graph = $multi_mode === ''
            ? null
            : $this->support->calculateMultiPersonGraph($selected, $tree, $multi_mode);

        $connected_requested = isset($query['connected']) && (string) $query['connected'] !== '';
        $connected_graph = $connected_requested
            ? $this->connected->calculate($selected, $tree, $matrix_data)
            : null;

        $this->layout = 'layouts/default';
        $this->support->pushDisplayEnhancements(
            $matrix_data,
            $scope,
            $detail,
            $selected,
            $tree,
            $multi_graph,
            $query_values,
            $base_url
        );
        $this->top_down->push($multi_graph, $matrix_data, $selected);
        $this->connected->push($connected_graph, $tree);

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
};
