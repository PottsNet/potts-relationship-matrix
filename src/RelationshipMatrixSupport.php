<?php

declare(strict_types=1);

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use ReflectionMethod;

final class PottsRelationshipMatrixSupport
{
    public function __construct(private readonly object $core)
    {
    }

    public function call(string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($this->core, $method);

        return $reflection->invoke($this->core, ...$arguments);
    }

    /**
     * Correct common-ancestor paths and group the two members of an ancestral
     * couple into one genealogical relationship route where appropriate.
     *
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     * @param array<int,Individual> $selected
     * @return array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>}
     */
    public function normaliseBloodMatrix(array $matrix_data, array $selected, Tree $tree): array
    {
        foreach ($matrix_data['pairs'] as $key => $result) {
            $paths = [];

            foreach ($result['paths'] as $path) {
                $ancestor_xref = is_string($path['common_ancestor_xref'] ?? null)
                    ? (string) $path['common_ancestor_xref']
                    : null;
                $name_nodes = $this->normaliseCommonAncestorNodes($path['nodes'], $ancestor_xref);
                $name = (string) $this->call('relationshipName', [$name_nodes, $tree]);

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

            foreach ($paths as &$path) {
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

    /**
     * Build a merged descendant graph for three or more selected people.
     *
     * Ancestors are grouped when every selected person reaches them through the
     * same descendant-family prefix. This naturally groups an ancestral couple
     * into one shared ancestral family while preserving genuinely different
     * shared-ancestor routes.
     *
     * @param array<int,Individual> $selected
     * @return array<string,mixed>
     */
    public function calculateMultiPersonGraph(array $selected, Tree $tree, string $mode = 'nearest'): array
    {
        if (count($selected) < 3) {
            return [
                'status' => 'too_few',
                'mode' => $mode,
                'selected_count' => count($selected),
            ];
        }

        $ancestor_sets = [];
        foreach ($selected as $individual) {
            /** @var array<string,array<int,array{nodes:array<int,string>,generations:int}>> $paths */
            $paths = $this->call('ancestorPaths', [$individual]);
            $ancestor_sets[] = $paths;
        }

        $shared = array_keys($ancestor_sets[0]);
        for ($i = 1; $i < count($ancestor_sets); $i++) {
            $shared = array_values(array_intersect($shared, array_keys($ancestor_sets[$i])));
        }

        if ($shared === []) {
            return [
                'status' => 'none',
                'mode' => $mode,
                'selected_count' => count($selected),
                'selected' => $this->selectedSummary($selected),
            ];
        }

        $groups = [];
        foreach ($shared as $ancestor_xref) {
            $ancestor = Registry::individualFactory()->make((string) $ancestor_xref, $tree);
            if (!$ancestor instanceof Individual || !$ancestor->canShow()) {
                continue;
            }

            $paths_by_person = [];
            $prefixes = [];
            $generations = [];
            $valid = true;

            foreach ($ancestor_sets as $person_index => $set) {
                $candidates = $set[$ancestor_xref] ?? [];
                if ($candidates === []) {
                    $valid = false;
                    break;
                }

                usort($candidates, static function (array $a, array $b): int {
                    $generation_compare = ((int) $a['generations']) <=> ((int) $b['generations']);
                    if ($generation_compare !== 0) {
                        return $generation_compare;
                    }

                    return implode('|', $a['nodes']) <=> implode('|', $b['nodes']);
                });

                $best = $candidates[0];
                $paths_by_person[$person_index] = $best;
                $generations[$person_index] = (int) $best['generations'];
                $prefixes[$person_index] = implode('|', array_slice($best['nodes'], 0, -1));
            }

            if (!$valid) {
                continue;
            }

            $signature = implode('||', $prefixes);
            if (!isset($groups[$signature])) {
                $groups[$signature] = [
                    'ancestors' => [],
                    'paths' => $paths_by_person,
                    'generations' => $generations,
                    'max_generation' => max($generations),
                    'sum_generation' => array_sum($generations),
                ];
            }

            $groups[$signature]['ancestors'][$ancestor_xref] = [
                'xref' => (string) $ancestor_xref,
                'name' => strip_tags($ancestor->fullName()),
                'paths' => $paths_by_person,
            ];
        }

        $groups = array_values($groups);
        usort($groups, static function (array $a, array $b): int {
            $max_compare = ((int) $a['max_generation']) <=> ((int) $b['max_generation']);
            if ($max_compare !== 0) {
                return $max_compare;
            }

            return ((int) $a['sum_generation']) <=> ((int) $b['sum_generation']);
        });

        if ($groups === []) {
            return [
                'status' => 'none',
                'mode' => $mode,
                'selected_count' => count($selected),
                'selected' => $this->selectedSummary($selected),
            ];
        }

        if ($mode === 'all') {
            $groups = array_slice($groups, 0, 6);
        } else {
            $first_max = (int) $groups[0]['max_generation'];
            $first_sum = (int) $groups[0]['sum_generation'];
            $groups = array_values(array_filter(
                $groups,
                static fn (array $group): bool => (int) $group['max_generation'] === $first_max
                    && (int) $group['sum_generation'] === $first_sum
            ));
            $groups = array_slice($groups, 0, 3);
        }

        $nodes = [];
        $edges = [];
        $roots = [];
        $family_units = [];

        foreach ($groups as $group_index => &$group) {
            $group['ancestors'] = array_values($group['ancestors']);
            $ancestor_xrefs = array_map(static fn (array $ancestor): string => (string) $ancestor['xref'], $group['ancestors']);

            foreach ($ancestor_xrefs as $ancestor_xref) {
                $roots['I|' . $ancestor_xref] = true;
            }

            $family_xref = null;
            if (count($ancestor_xrefs) > 1 && isset($group['paths'][0]['nodes'])) {
                $first_nodes = $group['paths'][0]['nodes'];
                if (count($first_nodes) >= 2) {
                    $family_xref = (string) $first_nodes[count($first_nodes) - 2];
                }
            }

            if ($family_xref !== null) {
                $family_units[] = [
                    'family' => $family_xref,
                    'ancestors' => $ancestor_xrefs,
                    'group' => $group_index,
                ];
            }

            foreach ($group['ancestors'] as $ancestor) {
                foreach ($ancestor['paths'] as $person_index => $path) {
                    $path_nodes = $path['nodes'];

                    for ($i = 0; $i < count($path_nodes); $i += 2) {
                        $xref = (string) $path_nodes[$i];
                        $node_id = 'I|' . $xref;
                        $individual = Registry::individualFactory()->make($xref, $tree);

                        if (!$individual instanceof Individual || !$individual->canShow()) {
                            continue;
                        }

                        if (!isset($nodes[$node_id])) {
                            $nodes[$node_id] = [
                                'id' => $node_id,
                                'xref' => $xref,
                                'name' => strip_tags($individual->fullName()),
                                'endpoint' => false,
                                'endpoint_number' => null,
                                'shared' => false,
                                'targets' => [],
                                'groups' => [],
                            ];
                        }

                        $nodes[$node_id]['targets'][$person_index] = true;
                        $nodes[$node_id]['groups'][$group_index] = true;

                        foreach ($selected as $selected_index => $selected_person) {
                            if ($selected_person->xref() === $xref) {
                                $nodes[$node_id]['endpoint'] = true;
                                $nodes[$node_id]['endpoint_number'] = $selected_index + 1;
                            }
                        }

                        if (in_array($xref, $ancestor_xrefs, true)) {
                            $nodes[$node_id]['shared'] = true;
                        }

                        if ($i + 2 >= count($path_nodes)) {
                            continue;
                        }

                        $family = (string) $path_nodes[$i + 1];
                        $parent_xref = (string) $path_nodes[$i + 2];
                        $parent_id = 'I|' . $parent_xref;
                        $child_id = $node_id;
                        $edge_key = $parent_id . '|' . $child_id . '|' . $family;

                        if (!isset($edges[$edge_key])) {
                            $edges[$edge_key] = [
                                'from' => $parent_id,
                                'to' => $child_id,
                                'family' => $family,
                                'targets' => [],
                                'groups' => [],
                            ];
                        }

                        $edges[$edge_key]['targets'][$person_index] = true;
                        $edges[$edge_key]['groups'][$group_index] = true;
                    }
                }
            }
        }
        unset($group);

        foreach ($nodes as &$node) {
            $node['targets'] = array_map('intval', array_keys($node['targets']));
            $node['groups'] = array_map('intval', array_keys($node['groups']));
        }
        unset($node);

        foreach ($edges as &$edge) {
            $edge['targets'] = array_map('intval', array_keys($edge['targets']));
            $edge['groups'] = array_map('intval', array_keys($edge['groups']));
        }
        unset($edge);

        foreach ($groups as &$group) {
            $group['ancestor_count'] = count($group['ancestors']);
        }
        unset($group);

        return [
            'status' => 'ok',
            'mode' => $mode,
            'selected_count' => count($selected),
            'selected' => $this->selectedSummary($selected),
            'groups' => $groups,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'roots' => array_keys($roots),
            'family_units' => $family_units,
        ];
    }

    /**
     * Add the alpha's presentation corrections and the multi-person shared
     * ancestry panel. The main view remains deliberately stable while these
     * features are tested on a live tree.
     *
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     * @param array<int,Individual> $selected
     * @param array<string,string|int> $query_values
     * @param array<string,mixed>|null $multi_graph
     */
    public function pushDisplayEnhancements(
        array $matrix_data,
        string $scope,
        ?array $detail,
        array $selected,
        Tree $tree,
        ?array $multi_graph,
        array $query_values,
        string $base_url
    ): void {
        $matrix_meta = $this->matrixMeta($matrix_data, $scope);
        $detail_payload = $this->detailPayload($detail, $scope);
        $multi_html = $multi_graph === null ? '' : $this->multiPanelHtml($multi_graph, $tree, $query_values, $base_url);
        $multi_payload = $multi_graph === null ? null : $this->multiClientPayload($multi_graph);

        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-table thead th:not(:first-child), .potts-rm-cell {min-width:150px!important;max-width:185px;white-space:normal;overflow-wrap:anywhere}
.potts-rm-table thead th:first-child,.potts-rm-table tbody th{min-width:185px!important;max-width:215px!important}
.potts-rm-table-wrap{scrollbar-gutter:stable}
.potts-rm-family-unit-overlay{fill:none;stroke:var(--potts-rm-common,var(--bs-success,#198754));stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round;opacity:.9}
.potts-rm-family-unit-junction{fill:var(--potts-rm-common,var(--bs-success,#198754));opacity:.95}
.potts-rm-family-unit-label{fill:var(--bs-secondary-color,#6c757d);font-size:12px;font-weight:600;text-anchor:middle}
.potts-rm-pedigree-viewport{scroll-behavior:smooth;min-height:0!important;height:320px}.potts-rm-pedigree-sizer{min-height:0!important}
.potts-rm-multi-toolbar{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:center;margin:.8rem 0;padding:.75rem;border:1px solid rgba(110,110,110,.28);border-radius:.65rem;background:rgba(127,127,127,.08)}
.potts-rm-multi-viewport{position:relative;width:100%;height:420px;min-height:260px;overflow:auto;border:1px solid rgba(110,110,110,.28);border-radius:.8rem;background:var(--bs-body-bg,#fff)}
.potts-rm-multi-sizer{position:relative;min-width:100%;height:100%}.potts-rm-multi-canvas{position:absolute;left:0;top:0;transform-origin:0 0}.potts-rm-multi-svg,.potts-rm-multi-cards{position:absolute;inset:0}.potts-rm-multi-svg{overflow:visible;pointer-events:none}
.potts-rm-multi-card{position:absolute;width:310px;z-index:2}.potts-rm-multi-card .wt-chart-box{width:310px;max-width:310px;min-height:84px;background:var(--bs-body-bg,#fff);box-shadow:0 .18rem .55rem rgba(0,0,0,.08)}
.potts-rm-multi-card.is-endpoint .wt-chart-box{outline:3px solid var(--bs-primary,#0d6efd);outline-offset:1px;border-radius:.45rem}.potts-rm-multi-card.is-shared .wt-chart-box{outline:3px solid var(--bs-success,#198754);outline-offset:1px;border-radius:.45rem}
.potts-rm-multi-tag{display:inline-block;position:relative;z-index:3;margin:0 0 .32rem .15rem;padding:.12rem .48rem;border-radius:999px;font-size:.72rem;font-weight:700;background:var(--bs-body-bg,#fff);border:1px solid rgba(110,110,110,.28)}
.potts-rm-multi-card.is-endpoint .potts-rm-multi-tag.endpoint{border-color:var(--bs-primary,#0d6efd)}.potts-rm-multi-card.is-shared .potts-rm-multi-tag.shared{border-color:var(--bs-success,#198754)}
.potts-rm-multi-edge{fill:none;stroke:var(--bs-secondary-color,#6c757d);stroke-width:2;stroke-linecap:round;stroke-linejoin:round;opacity:.8}.potts-rm-multi-family{fill:none;stroke:var(--bs-success,#198754);stroke-width:2.2;opacity:.9}
.potts-rm-multi-summary{display:flex;flex-wrap:wrap;gap:.5rem;margin:.65rem 0}.potts-rm-multi-chip{border:1px solid rgba(110,110,110,.28);border-radius:999px;padding:.22rem .6rem;font-size:.83rem;background:var(--bs-body-bg,#fff)}
.potts-rm-multi-viewport.hide-photos .wt-chart-box-thumbnail{display:none!important}.potts-rm-multi-viewport.hide-details .wt-chart-box-lifespan,.potts-rm-multi-viewport.hide-details .wt-chart-box-facts{display:none!important}.potts-rm-multi-viewport.no-shared-highlight .potts-rm-multi-card.is-shared .wt-chart-box{outline:none}
@media(max-width:900px){.potts-rm-multi-card,.potts-rm-multi-card .wt-chart-box{width:280px;max-width:280px}}
</style>
HTML;
        View::endpush();

        $matrix_json = json_encode($matrix_meta, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $detail_json = json_encode($detail_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $multi_json = json_encode($multi_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $multi_html_json = json_encode($multi_html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        View::push('javascript');
        echo '<script>(function(){"use strict";';
        echo 'const matrixMeta=' . $matrix_json . ';';
        echo 'const routeDetail=' . $detail_json . ';';
        echo 'const multiGraph=' . $multi_json . ';';
        echo 'const multiHtml=' . $multi_html_json . ';';
        echo <<<'JS'

const mainForm=document.querySelector('.potts-rm-page form.potts-rm-panel');
if(mainForm){const build=mainForm.querySelector('button[type="submit"]');if(build&&!document.getElementById('potts-rm-multi-submit')){const button=document.createElement('button');button.type='submit';button.name='multi';button.value='nearest';button.id='potts-rm-multi-submit';button.className='btn btn-outline-primary ms-2';button.textContent='Graph selected people';build.parentElement.appendChild(button)}}

document.querySelectorAll('.potts-rm-table-wrap').forEach(matrix=>{matrix.scrollLeft=0});
if(matrixMeta&&typeof matrixMeta==='object'){const rows=document.querySelectorAll('.potts-rm-table tbody tr');Object.keys(matrixMeta).forEach(rowKey=>{const row=rows[Number(rowKey)];if(!row)return;const cells=row.querySelectorAll('td');Object.keys(matrixMeta[rowKey]).forEach(columnKey=>{const cell=cells[Number(columnKey)];if(!cell)return;const meta=cell.querySelector('.potts-rm-cell-meta');if(meta)meta.textContent=matrixMeta[rowKey][columnKey]})})}

if(routeDetail&&Array.isArray(routeDetail.routes)){const detail=document.getElementById('potts-rm-detail');if(detail){const heading=detail.querySelector('.potts-rm-detail-head h3');if(heading)heading.textContent=routeDetail.labels.heading+': '+routeDetail.first_name+' → '+routeDetail.second_name;const summary=detail.querySelector('.potts-rm-detail-summary');if(summary)summary.textContent=routeDetail.summary;const selector=document.getElementById('potts-rm-chart-paths');if(selector){const label=detail.querySelector('label[for="potts-rm-chart-paths"]');if(label)label.textContent=routeDetail.labels.routes_to_display;selector.value='all';const merged=routeDetail.routes.some(route=>Number(route.source_path_count||0)>1);if(merged){selector.disabled=true;Array.from(selector.options).forEach(option=>{if(option.value==='all')option.textContent=routeDetail.labels.all_routes})}}const grid=detail.querySelector('.potts-rm-paths');if(grid){const sectionHeading=grid.previousElementSibling;if(sectionHeading&&/^H[1-6]$/.test(sectionHeading.tagName))sectionHeading.textContent=routeDetail.labels.routes_heading;grid.innerHTML='';routeDetail.routes.forEach((route,index)=>{const article=document.createElement('article');article.className='potts-rm-path';const title=document.createElement('div');title.className='potts-rm-path-title';title.textContent=routeDetail.labels.route+' '+(index+1)+' — '+route.name;article.appendChild(title);const parts=[];if(Number.isInteger(route.l1)&&route.l1>0)parts.push(route.l1+' '+(route.l1===1?routeDetail.labels.generation_up:routeDetail.labels.generations_up));if(Number.isInteger(route.l2)&&route.l2>0)parts.push(route.l2+' '+(route.l2===1?routeDetail.labels.generation_down:routeDetail.labels.generations_down));if(parts.length){const badge=document.createElement('span');badge.className='potts-rm-badge';badge.textContent=parts.join(' · ');article.appendChild(badge)}if(Array.isArray(route.common_ancestors)&&route.common_ancestors.length){const ancestors=document.createElement('div');ancestors.className='mt-2';const strong=document.createElement('strong');strong.textContent=(route.common_ancestors.length===1?routeDetail.labels.common_ancestor:routeDetail.labels.common_ancestors)+': ';ancestors.appendChild(strong);ancestors.appendChild(document.createTextNode(route.common_ancestors.map(a=>a.name).join(' & ')));article.appendChild(ancestors)}grid.appendChild(article)})}}

let readableDefaultApplied=false;function applyPairReadableDefault(){if(readableDefaultApplied)return;readableDefaultApplied=true;const fit=document.getElementById('potts-rm-fit-width');const viewport=document.getElementById('potts-rm-pedigree-viewport');if(fit&&fit.checked){fit.checked=false;fit.dispatchEvent(new Event('change',{bubbles:true}))}if(viewport)viewport.scrollLeft=0}function compactPairHeight(){const viewport=document.getElementById('potts-rm-pedigree-viewport');const sizer=document.getElementById('potts-rm-pedigree-sizer');const canvas=document.getElementById('potts-rm-pedigree-canvas');const cards=document.getElementById('potts-rm-pedigree-cards');if(!viewport||!sizer||!canvas||!cards)return;let maxBottom=0;cards.querySelectorAll('[data-node-id]').forEach(card=>{if(card.style.display==='none')return;maxBottom=Math.max(maxBottom,card.offsetTop+card.offsetHeight)});const match=/scale\(([^)]+)\)/.exec(canvas.style.transform||'');const scale=match?Number(match[1])||1:1;const desired=Math.max(220,Math.min(620,Math.ceil(maxBottom*scale+52)));viewport.style.minHeight='0';viewport.style.height=desired+'px';sizer.style.minHeight='0';sizer.style.height=desired+'px'}function pairSchedule(){requestAnimationFrame(()=>requestAnimationFrame(()=>{applyPairReadableDefault();compactPairHeight()}))}['potts-rm-show-photos','potts-rm-show-details','potts-rm-highlight-common','potts-rm-fit-width'].forEach(id=>{const control=document.getElementById(id);if(control)control.addEventListener('change',pairSchedule)});window.addEventListener('resize',pairSchedule);pairSchedule()}

if(multiHtml){const panels=document.querySelectorAll('.potts-rm-page > .potts-rm-panel');let anchor=null;panels.forEach(panel=>{if(panel.querySelector('.potts-rm-table'))anchor=panel});if(anchor){anchor.insertAdjacentHTML('afterend',multiHtml)}}

if(multiGraph&&multiGraph.status==='ok'){const viewport=document.getElementById('potts-rm-multi-viewport');const sizer=document.getElementById('potts-rm-multi-sizer');const canvas=document.getElementById('potts-rm-multi-canvas');const svg=document.getElementById('potts-rm-multi-svg');const cardsLayer=document.getElementById('potts-rm-multi-cards');const fit=document.getElementById('potts-rm-multi-fit');const photos=document.getElementById('potts-rm-multi-photos');const details=document.getElementById('potts-rm-multi-details');const highlight=document.getElementById('potts-rm-multi-highlight');if(viewport&&sizer&&canvas&&svg&&cardsLayer){const cards=new Map();cardsLayer.querySelectorAll('[data-multi-node]').forEach(card=>cards.set(card.dataset.multiNode,card));let naturalWidth=900,naturalHeight=320;function displayOptions(){viewport.classList.toggle('hide-photos',photos&&!photos.checked);viewport.classList.toggle('hide-details',details&&!details.checked);viewport.classList.toggle('no-shared-highlight',highlight&&!highlight.checked)}function build(){displayOptions();const outgoing=new Map();multiGraph.nodes.forEach(node=>outgoing.set(node.id,[]));multiGraph.edges.forEach(edge=>{if(outgoing.has(edge.from))outgoing.get(edge.from).push(edge.to)});const levels=new Map();const queue=[];multiGraph.roots.forEach(root=>{levels.set(root,0);queue.push(root)});for(let cursor=0;cursor<queue.length;cursor++){const current=queue[cursor];const level=levels.get(current)||0;(outgoing.get(current)||[]).forEach(next=>{const candidate=level+1;if(!levels.has(next)||candidate<levels.get(next)){levels.set(next,candidate);queue.push(next)}})}let fallback=Math.max(0,...Array.from(levels.values()));multiGraph.nodes.forEach(node=>{if(!levels.has(node.id)){fallback++;levels.set(node.id,fallback)}});const columns=new Map();multiGraph.nodes.forEach(node=>{if(!cards.has(node.id))return;const level=levels.get(node.id)||0;if(!columns.has(level))columns.set(level,[]);columns.get(level).push(node)});columns.forEach(nodes=>nodes.sort((a,b)=>{const at=Math.min(...(a.targets||[999]));const bt=Math.min(...(b.targets||[999]));if(at!==bt)return at-bt;if(a.shared!==b.shared)return a.shared?-1:1;return String(a.xref).localeCompare(String(b.xref))}));const cardWidth=matchMedia('(max-width:900px)').matches?280:310;const gap=105,columnWidth=cardWidth+gap,top=34,left=34,rowGap=28;const positions=new Map();let maxHeight=0,maxLevel=0;Array.from(columns.keys()).sort((a,b)=>a-b).forEach(level=>{maxLevel=Math.max(maxLevel,level);let y=top;(columns.get(level)||[]).forEach(node=>{const card=cards.get(node.id);card.style.left=(left+level*columnWidth)+'px';card.style.top=y+'px';const height=Math.max(90,card.offsetHeight);positions.set(node.id,{x:left+level*columnWidth,y,width:cardWidth,height,cy:y+height/2});y+=height+rowGap});maxHeight=Math.max(maxHeight,y+top)});naturalWidth=Math.max(900,left*2+(maxLevel+1)*cardWidth+maxLevel*gap);naturalHeight=Math.max(260,maxHeight);canvas.style.width=naturalWidth+'px';canvas.style.height=naturalHeight+'px';cardsLayer.style.width=naturalWidth+'px';cardsLayer.style.height=naturalHeight+'px';svg.setAttribute('width',String(naturalWidth));svg.setAttribute('height',String(naturalHeight));svg.setAttribute('viewBox','0 0 '+naturalWidth+' '+naturalHeight);svg.innerHTML='';const NS='http://www.w3.org/2000/svg';multiGraph.edges.forEach(edge=>{const from=positions.get(edge.from),to=positions.get(edge.to);if(!from||!to)return;const x1=from.x+from.width,y1=from.cy,x2=to.x,y2=to.cy,middle=x1+Math.max(28,(x2-x1)/2);const path=document.createElementNS(NS,'path');path.setAttribute('class','potts-rm-multi-edge');path.setAttribute('d','M '+x1+' '+y1+' H '+middle+' V '+y2+' H '+x2);svg.appendChild(path)});(multiGraph.family_units||[]).forEach(unit=>{const parents=(unit.ancestors||[]).map(xref=>positions.get('I|'+xref)).filter(Boolean);if(parents.length<2)return;const minX=Math.min(...parents.map(p=>p.x)),maxRight=Math.max(...parents.map(p=>p.x+p.width)),minY=Math.min(...parents.map(p=>p.cy)),maxY=Math.max(...parents.map(p=>p.cy)),midY=(minY+maxY)/2,spine=maxRight+18;const path=document.createElementNS(NS,'path');path.setAttribute('class','potts-rm-multi-family');let d='M '+spine+' '+minY+' V '+maxY;parents.forEach(p=>{d+=' M '+(p.x+p.width)+' '+p.cy+' H '+spine});path.setAttribute('d',d);svg.appendChild(path);const dot=document.createElementNS(NS,'circle');dot.setAttribute('cx',String(spine));dot.setAttribute('cy',String(midY));dot.setAttribute('r','4');dot.setAttribute('fill','var(--bs-success,#198754)');svg.appendChild(dot)});applyScale()}function applyScale(){let scale=1;if(fit&&fit.checked&&naturalWidth>0)scale=Math.min(1,Math.max(.35,(viewport.clientWidth-22)/naturalWidth));canvas.style.transform='scale('+scale+')';sizer.style.width=Math.max(viewport.clientWidth,naturalWidth*scale)+'px';const height=Math.max(260,Math.min(700,Math.ceil(naturalHeight*scale+20)));sizer.style.height=height+'px';viewport.style.height=height+'px'}[photos,details,highlight].forEach(control=>{if(control)control.addEventListener('change',()=>requestAnimationFrame(build))});if(fit)fit.addEventListener('change',applyScale);window.addEventListener('resize',()=>requestAnimationFrame(build));requestAnimationFrame(build)}}
})();</script>
JS;
        View::endpush();
    }

    /** @param array<int,string> $nodes @return array<int,string> */
    private function normaliseCommonAncestorNodes(array $nodes, ?string $ancestor_xref): array
    {
        if ($ancestor_xref === null || count($nodes) < 5) {
            return $nodes;
        }

        for ($i = 2; $i < count($nodes) - 2; $i += 2) {
            if ($nodes[$i] === $ancestor_xref && $nodes[$i - 1] === $nodes[$i + 1]) {
                return array_merge(array_slice($nodes, 0, $i), array_slice($nodes, $i + 2));
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

        return isset($name_nodes[$family_index]) ? (string) $name_nodes[$family_index] : null;
    }

    /** @param array<int,string> $name_nodes @return array{0:?string,1:?string} */
    private function routeBranchXrefs(array $name_nodes, mixed $l1, mixed $l2): array
    {
        if (!is_int($l1) || !is_int($l2) || $l1 <= 0 || $l2 <= 0) {
            return [null, null];
        }

        $family_index = 2 * $l1 - 1;

        return [
            isset($name_nodes[$family_index - 1]) ? (string) $name_nodes[$family_index - 1] : null,
            isset($name_nodes[$family_index + 1]) ? (string) $name_nodes[$family_index + 1] : null,
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

        $name = (string) $this->call('relationshipName', [$name_nodes, $tree]);
        if ($name === '') {
            $name = (string) $closest['name'];
        }

        return [
            'self' => false,
            'name' => $name,
            'path_count' => (int) ($result['route_count'] ?? $result['path_count'] ?? 0),
            'route_count' => (int) ($result['route_count'] ?? $result['path_count'] ?? 0),
            'common_ancestor_count' => (int) ($result['common_ancestor_count'] ?? 0),
            'steps' => (int) $closest['steps'],
            'notation' => is_int($l1) && is_int($l2) ? $l1 . ' / ' . $l2 : '',
            'pair_key' => $key,
        ];
    }

    /** @param array<int,Individual> $selected @return array<int,array{xref:string,name:string}> */
    private function selectedSummary(array $selected): array
    {
        return array_map(
            static fn (Individual $individual): array => [
                'xref' => $individual->xref(),
                'name' => strip_tags($individual->fullName()),
            ],
            $selected
        );
    }

    /** @return array<int,array<int,string>> */
    private function matrixMeta(array $matrix_data, string $scope): array
    {
        $meta = [];
        if ($scope !== 'blood') {
            return $meta;
        }

        foreach ($matrix_data['cells'] as $row => $cells) {
            foreach ($cells as $column => $cell) {
                if (!is_array($cell) || !empty($cell['self']) || (int) ($cell['route_count'] ?? 0) === 0) {
                    continue;
                }

                $route_count = (int) $cell['route_count'];
                $ancestor_count = (int) ($cell['common_ancestor_count'] ?? 0);
                $text = I18N::plural('%s relationship route', '%s relationship routes', $route_count, I18N::number($route_count));
                if ($ancestor_count > 0) {
                    $text .= ' · ' . I18N::plural('%s common ancestor', '%s common ancestors', $ancestor_count, I18N::number($ancestor_count));
                }
                if ((string) ($cell['notation'] ?? '') !== '') {
                    $text .= ' · ' . (string) $cell['notation'];
                }
                $meta[$row][$column] = $text;
            }
        }

        return $meta;
    }

    /** @return array<string,mixed>|null */
    private function detailPayload(?array $detail, string $scope): ?array
    {
        if ($scope !== 'blood' || !is_array($detail) || (int) ($detail['route_count'] ?? 0) === 0) {
            return null;
        }

        $routes = [];
        foreach (($detail['routes'] ?? []) as $route_index => $route) {
            $routes[] = [
                'index' => (int) $route_index,
                'name' => (string) ($route['name'] ?? I18N::translate('Related')),
                'l1' => is_int($route['l1'] ?? null) ? (int) $route['l1'] : null,
                'l2' => is_int($route['l2'] ?? null) ? (int) $route['l2'] : null,
                'source_path_count' => (int) ($route['source_path_count'] ?? 1),
                'common_ancestors' => array_values($route['common_ancestors'] ?? []),
            ];
        }

        $route_count = (int) $detail['route_count'];
        $ancestor_count = (int) ($detail['common_ancestor_count'] ?? 0);
        $summary = I18N::plural('%s relationship route', '%s relationship routes', $route_count, I18N::number($route_count));
        if ($ancestor_count > 0) {
            $summary .= ' · ' . I18N::plural('%s common ancestor', '%s common ancestors', $ancestor_count, I18N::number($ancestor_count));
        }

        return [
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
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function multiClientPayload(array $multi_graph): ?array
    {
        if (($multi_graph['status'] ?? '') !== 'ok') {
            return $multi_graph;
        }

        return [
            'status' => 'ok',
            'mode' => $multi_graph['mode'],
            'nodes' => $multi_graph['nodes'],
            'edges' => $multi_graph['edges'],
            'roots' => $multi_graph['roots'],
            'family_units' => $multi_graph['family_units'],
        ];
    }

    private function multiPanelHtml(array $multi_graph, Tree $tree, array $query_values, string $base_url): string
    {
        $nearest_url = $base_url . '?' . http_build_query($query_values + ['multi' => 'nearest'], '', '&', PHP_QUERY_RFC3986) . '#potts-rm-multi';
        $all_url = $base_url . '?' . http_build_query($query_values + ['multi' => 'all'], '', '&', PHP_QUERY_RFC3986) . '#potts-rm-multi';

        ob_start();
        ?>
        <section class="potts-rm-panel" id="potts-rm-multi">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h3 class="h4 mb-1"><?= I18N::translate('Shared ancestry graph') ?></h3>
                    <div class="potts-rm-note">
                        <?= I18N::translate('Shows the ancestor or ancestral family shared by all selected people and merges their descendant branches.') ?>
                    </div>
                </div>
            </div>

            <?php if (($multi_graph['status'] ?? '') === 'too_few') : ?>
                <div class="alert alert-info mt-3 mb-0"><?= I18N::translate('Select at least three people to build a shared ancestry graph.') ?></div>
            <?php elseif (($multi_graph['status'] ?? '') === 'none') : ?>
                <div class="alert alert-warning mt-3 mb-0"><?= I18N::translate('No ancestor shared by all selected people was found within the current ancestor search limit.') ?></div>
            <?php else : ?>
                <div class="potts-rm-multi-toolbar d-print-none">
                    <a class="btn btn-sm <?= ($multi_graph['mode'] ?? 'nearest') === 'nearest' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($nearest_url, ENT_QUOTES) ?>"><?= I18N::translate('Nearest shared ancestor') ?></a>
                    <a class="btn btn-sm <?= ($multi_graph['mode'] ?? 'nearest') === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars($all_url, ENT_QUOTES) ?>"><?= I18N::translate('More shared ancestors') ?></a>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-multi-photos" checked><label class="form-check-label" for="potts-rm-multi-photos"><?= I18N::translate('Show photos') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-multi-details" checked><label class="form-check-label" for="potts-rm-multi-details"><?= I18N::translate('Show dates and places') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-multi-highlight" checked><label class="form-check-label" for="potts-rm-multi-highlight"><?= I18N::translate('Highlight shared ancestors') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-multi-fit"><label class="form-check-label" for="potts-rm-multi-fit"><?= I18N::translate('Fit chart to width') ?></label></div>
                </div>

                <?php foreach (($multi_graph['groups'] ?? []) as $group_index => $group) : ?>
                    <?php if ($group_index > 0 && ($multi_graph['mode'] ?? 'nearest') === 'nearest') break; ?>
                    <div class="potts-rm-multi-summary">
                        <span class="potts-rm-multi-chip"><strong><?= I18N::translate('Shared ancestor') ?>:</strong> <?= htmlspecialchars(implode(' & ', array_map(static fn (array $ancestor): string => (string) $ancestor['name'], $group['ancestors'])), ENT_QUOTES) ?></span>
                        <?php foreach (($multi_graph['selected'] ?? []) as $index => $person) : ?>
                            <span class="potts-rm-multi-chip"><?= htmlspecialchars((string) $person['name'], ENT_QUOTES) ?>: <?= I18N::plural('%s generation', '%s generations', (int) ($group['generations'][$index] ?? 0), I18N::number((int) ($group['generations'][$index] ?? 0))) ?></span>
                        <?php endforeach ?>
                    </div>
                <?php endforeach ?>

                <div class="potts-rm-multi-viewport" id="potts-rm-multi-viewport">
                    <div class="potts-rm-multi-sizer" id="potts-rm-multi-sizer">
                        <div class="potts-rm-multi-canvas" id="potts-rm-multi-canvas">
                            <svg class="potts-rm-multi-svg" id="potts-rm-multi-svg" aria-hidden="true"></svg>
                            <div class="potts-rm-multi-cards" id="potts-rm-multi-cards">
                                <?php foreach (($multi_graph['nodes'] ?? []) as $node) : ?>
                                    <?php $individual = Registry::individualFactory()->make((string) $node['xref'], $tree); ?>
                                    <?php if (!$individual instanceof Individual || !$individual->canShow()) continue; ?>
                                    <div class="potts-rm-multi-card<?= !empty($node['endpoint']) ? ' is-endpoint' : '' ?><?= !empty($node['shared']) ? ' is-shared' : '' ?>" data-multi-node="<?= htmlspecialchars((string) $node['id'], ENT_QUOTES) ?>">
                                        <?php if (!empty($node['endpoint'])) : ?><span class="potts-rm-multi-tag endpoint"><?= I18N::translate('Person %s', I18N::number((int) $node['endpoint_number'])) ?></span><?php endif ?>
                                        <?php if (!empty($node['shared'])) : ?><span class="potts-rm-multi-tag shared"><?= I18N::translate('Shared ancestor') ?></span><?php endif ?>
                                        <?= view('chart-box', ['individual' => $individual]) ?>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}
