<?php

declare(strict_types=1);

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;

final class PottsRelationshipMatrixConnectedEnhancement
{
    public function __construct(private readonly PottsRelationshipMatrixSupport $support)
    {
    }

    /**
     * Build one visible family network by merging the best route from Person 1
     * to every other selected person. Blood routes are preferred; the wider
     * visible family graph is used when no blood route exists.
     *
     * @param array<int,Individual> $selected
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     * @return array<string,mixed>
     */
    public function calculate(array $selected, Tree $tree, array $matrix_data): array
    {
        if (count($selected) < 2) {
            return ['status' => 'too_few', 'selected_count' => count($selected)];
        }

        $reference = $selected[0];
        /** @var array{graph:array<string,array<string,int>>,roles:array<string,array<string,array<string,bool>>>} $graph_index */
        $graph_index = $this->support->call('visibleGraphIndex', [$tree]);
        $roles = $graph_index['roles'] ?? [];

        $people = [];
        $family_xrefs = [];
        $connections = [];
        $path_signatures = [];

        $this->addPerson($people, $reference, 0, true, I18N::translate('Reference person'));

        foreach ($selected as $target_index => $target) {
            if ($target_index === 0) {
                continue;
            }

            $pair_key = '0-' . $target_index;
            $pair = $matrix_data['pairs'][$pair_key] ?? null;
            $relationship = (string) ($matrix_data['cells'][0][$target_index]['name'] ?? I18N::translate('Related'));
            $paths = [];
            $source = 'blood';

            if (is_array($pair) && !empty($pair['paths'])) {
                // Keep all raw paths from the closest grouped route so both
                // members of an ancestral couple can remain visible.
                $route_index = (int) ($pair['paths'][0]['route_index'] ?? 0);
                foreach ($pair['paths'] as $candidate) {
                    if ((int) ($candidate['route_index'] ?? 0) === $route_index) {
                        $paths[] = $candidate;
                    }
                    if (count($paths) >= 4) {
                        break;
                    }
                }
            }

            if ($paths === []) {
                /** @var array<string,mixed> $family_pair */
                $family_pair = $this->support->call('calculateAllFamilyPair', [$reference, $target, $tree, 1]);
                if (!empty($family_pair['paths'][0]) && is_array($family_pair['paths'][0])) {
                    $paths[] = $family_pair['paths'][0];
                    $relationship = (string) ($family_pair['paths'][0]['name'] ?? I18N::translate('Family connection'));
                    $source = 'family';
                }
            }

            if ($paths === []) {
                $this->addPerson($people, $target, $target_index, false, I18N::translate('No connection found'));
                $connections[] = [
                    'person_index' => $target_index,
                    'xref' => $target->xref(),
                    'name' => strip_tags($target->fullName()),
                    'relationship' => I18N::translate('No connection found'),
                    'source' => 'none',
                    'via_xref' => null,
                    'via_name' => null,
                    'connected' => false,
                ];
                continue;
            }

            $via_xref = null;
            $via_name = null;

            foreach ($paths as $path) {
                $nodes = is_array($path['nodes'] ?? null) ? $path['nodes'] : [];
                if ($nodes === []) {
                    continue;
                }

                $signature = implode('|', $nodes);
                if (isset($path_signatures[$signature])) {
                    continue;
                }
                $path_signatures[$signature] = true;

                if ($via_xref === null && isset($nodes[2]) && is_string($nodes[2])) {
                    $candidate = Registry::individualFactory()->make((string) $nodes[2], $tree);
                    if ($candidate instanceof Individual && $candidate->canShow() && $candidate->xref() !== $target->xref()) {
                        $via_xref = $candidate->xref();
                        $via_name = strip_tags($candidate->fullName());
                    }
                }

                foreach ($nodes as $position => $xref) {
                    if ($position % 2 === 0) {
                        $individual = Registry::individualFactory()->make((string) $xref, $tree);
                        if (!$individual instanceof Individual || !$individual->canShow()) {
                            continue;
                        }

                        if (!isset($people[$individual->xref()])) {
                            $people[$individual->xref()] = [
                                'id' => 'I|' . $individual->xref(),
                                'xref' => $individual->xref(),
                                'name' => strip_tags($individual->fullName()),
                                'selected' => false,
                                'person_number' => null,
                                'relationship' => null,
                                'targets' => [],
                                'connected' => true,
                                'bridge' => false,
                            ];
                        }
                        $people[$individual->xref()]['targets'][$target_index] = true;
                        $people[$individual->xref()]['connected'] = true;
                    } else {
                        $family_xrefs[(string) $xref] = true;
                    }
                }
            }

            $this->addPerson($people, $target, $target_index, true, $relationship);
            if ($via_xref !== null && isset($people[$via_xref])) {
                $people[$via_xref]['bridge'] = true;
            }

            $connections[] = [
                'person_index' => $target_index,
                'xref' => $target->xref(),
                'name' => strip_tags($target->fullName()),
                'relationship' => $relationship,
                'source' => $source,
                'via_xref' => $via_xref,
                'via_name' => $via_name,
                'connected' => true,
            ];
        }

        // Keep each endpoint in the graph. Preserve a broader-family fallback
        // label if one was already calculated instead of overwriting it with
        // "No relationship found" from the blood matrix.
        foreach ($selected as $index => $individual) {
            $existing_relationship = $people[$individual->xref()]['relationship'] ?? null;
            $relationship = $index === 0
                ? I18N::translate('Reference person')
                : (is_string($existing_relationship) && $existing_relationship !== ''
                    ? $existing_relationship
                    : (string) ($matrix_data['cells'][0][$index]['name'] ?? I18N::translate('Related')));
            $this->addPerson(
                $people,
                $individual,
                $index,
                isset($people[$individual->xref()]) && ($people[$individual->xref()]['connected'] ?? false),
                $relationship
            );
        }

        $families = [];
        foreach (array_keys($family_xrefs) as $family_xref) {
            $family = Registry::familyFactory()->make($family_xref, $tree);
            if (!$family instanceof Family || !$family->canShow()) {
                continue;
            }

            $spouses = [];
            foreach (array_keys($roles[$family_xref]['FAMS'] ?? []) as $xref) {
                if (isset($people[$xref])) {
                    $spouses[] = 'I|' . $xref;
                }
            }

            $children = [];
            foreach (array_keys($roles[$family_xref]['FAMC'] ?? []) as $xref) {
                if (isset($people[$xref])) {
                    $children[] = 'I|' . $xref;
                }
            }

            if (count($spouses) + count($children) < 2) {
                continue;
            }

            $families[] = [
                'id' => 'F|' . $family_xref,
                'xref' => $family_xref,
                'spouses' => array_values(array_unique($spouses)),
                'children' => array_values(array_unique($children)),
            ];
        }

        foreach ($people as &$person) {
            $person['targets'] = array_map('intval', array_keys($person['targets']));
        }
        unset($person);

        $connected_count = 1;
        foreach ($connections as $connection) {
            if (!empty($connection['connected'])) {
                $connected_count++;
            }
        }

        return [
            'status' => $connected_count > 1 ? 'ok' : 'none',
            'reference' => ['xref' => $reference->xref(), 'name' => strip_tags($reference->fullName())],
            'selected_count' => count($selected),
            'connected_count' => $connected_count,
            'nodes' => array_values($people),
            'families' => $families,
            'connections' => $connections,
        ];
    }

    /**
     * Add the button on every matrix page and render the connected-family graph
     * when requested. This view permits spouse/partner links and is deliberately
     * kept separate from the strict shared-ancestry graph.
     */
    public function push(?array $graph, Tree $tree): void
    {
        $html = is_array($graph) ? $this->panelHtml($graph, $tree) : '';
        $payload = is_array($graph) && ($graph['status'] ?? '') === 'ok'
            ? [
                'reference' => $graph['reference'] ?? null,
                'nodes' => $graph['nodes'] ?? [],
                'families' => $graph['families'] ?? [],
            ]
            : null;

        $html_json = json_encode($html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $payload_json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-connected-toolbar{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:center;margin:.8rem 0;padding:.75rem;border:1px solid rgba(110,110,110,.28);border-radius:.65rem;background:rgba(127,127,127,.08)}
.potts-rm-connected-summary{display:flex;flex-wrap:wrap;gap:.5rem;margin:.65rem 0}.potts-rm-connected-chip{border:1px solid rgba(110,110,110,.28);border-radius:999px;padding:.25rem .65rem;font-size:.83rem;background:var(--bs-body-bg,#fff)}
.potts-rm-connected-viewport{position:relative;width:100%;height:460px;min-height:300px;overflow:auto;border:1px solid rgba(110,110,110,.28);border-radius:.8rem;background:var(--bs-body-bg,#fff)}
.potts-rm-connected-sizer{position:relative;min-width:100%;height:100%}.potts-rm-connected-canvas{position:absolute;left:0;top:0;transform-origin:0 0}.potts-rm-connected-svg,.potts-rm-connected-cards{position:absolute;inset:0}.potts-rm-connected-svg{overflow:visible;pointer-events:none}
.potts-rm-connected-card{position:absolute;width:310px;z-index:2}.potts-rm-connected-card .wt-chart-box{width:310px;max-width:310px;min-height:84px;background:var(--bs-body-bg,#fff);box-shadow:0 .18rem .55rem rgba(0,0,0,.08)}
.potts-rm-connected-card.is-selected .wt-chart-box{outline:3px solid var(--bs-primary,#0d6efd);outline-offset:1px;border-radius:.45rem}.potts-rm-connected-card.is-bridge:not(.is-selected) .wt-chart-box{outline:2px solid var(--bs-warning,#ffc107);outline-offset:1px;border-radius:.45rem}
.potts-rm-connected-tag{display:inline-block;position:relative;z-index:3;margin:0 .28rem .32rem .15rem;padding:.12rem .48rem;border-radius:999px;font-size:.72rem;font-weight:700;background:var(--bs-body-bg,#fff);border:1px solid rgba(110,110,110,.28)}
.potts-rm-connected-tag.relationship{border-color:var(--bs-primary,#0d6efd)}.potts-rm-connected-tag.bridge{border-color:var(--bs-warning,#ffc107)}
.potts-rm-connected-descent{fill:none;stroke:var(--bs-secondary-color,#6c757d);stroke-width:2.15;stroke-linecap:round;stroke-linejoin:round;opacity:.86}.potts-rm-connected-partner{fill:none;stroke:var(--bs-info,#0dcaf0);stroke-width:2.4;stroke-dasharray:8 5;stroke-linecap:round;opacity:.95}.potts-rm-connected-junction{fill:var(--bs-secondary-color,#6c757d)}
.potts-rm-connected-legend{display:flex;flex-wrap:wrap;gap:1rem;margin-top:.65rem;font-size:.82rem;color:var(--bs-secondary-color,#6c757d)}.potts-rm-connected-legend-line{display:inline-block;width:26px;vertical-align:middle;margin-right:.35rem;border-top:2px solid var(--bs-secondary-color,#6c757d)}.potts-rm-connected-legend-line.partner{border-top:2px dashed var(--bs-info,#0dcaf0)}
.potts-rm-connected-viewport.hide-photos .wt-chart-box-thumbnail{display:none!important}.potts-rm-connected-viewport.hide-details .wt-chart-box-lifespan,.potts-rm-connected-viewport.hide-details .wt-chart-box-facts{display:none!important}
@media(max-width:900px){.potts-rm-connected-card,.potts-rm-connected-card .wt-chart-box{width:280px;max-width:280px}}
</style>
HTML;
        View::endpush();

        View::push('javascript');
        echo '<script>(function(){"use strict";const connectedHtml=' . $html_json . ';const connectedGraph=' . $payload_json . ';';
        echo <<<'JS'
const mainForm=document.querySelector('.potts-rm-page form.potts-rm-panel');
if(mainForm){
    const build=mainForm.querySelector('button[type="submit"]');
    if(build&&!document.getElementById('potts-rm-connected-submit')){
        const button=document.createElement('button');button.type='submit';button.name='connected';button.value='1';button.id='potts-rm-connected-submit';button.className='btn btn-outline-secondary ms-2';button.textContent='Graph connected relationships';build.parentElement.appendChild(button);
    }
}

if(connectedHtml){
    const panels=document.querySelectorAll('.potts-rm-page > .potts-rm-panel');let anchor=null;
    panels.forEach(panel=>{if(panel.querySelector('.potts-rm-table'))anchor=panel});
    if(anchor)anchor.insertAdjacentHTML('afterend',connectedHtml);
}
if(!connectedGraph)return;

const viewport=document.getElementById('potts-rm-connected-viewport');
const sizer=document.getElementById('potts-rm-connected-sizer');
const canvas=document.getElementById('potts-rm-connected-canvas');
const svg=document.getElementById('potts-rm-connected-svg');
const cardsLayer=document.getElementById('potts-rm-connected-cards');
const fit=document.getElementById('potts-rm-connected-fit');
const photos=document.getElementById('potts-rm-connected-photos');
const details=document.getElementById('potts-rm-connected-details');
const labels=document.getElementById('potts-rm-connected-labels');
if(!viewport||!sizer||!canvas||!svg||!cardsLayer)return;

const NS='http://www.w3.org/2000/svg';
const cards=new Map();cardsLayer.querySelectorAll('[data-connected-node]').forEach(card=>cards.set(card.dataset.connectedNode,card));
let naturalWidth=900,naturalHeight=420;

function displayOptions(){
    viewport.classList.toggle('hide-photos',photos&&!photos.checked);
    viewport.classList.toggle('hide-details',details&&!details.checked);
    cardsLayer.querySelectorAll('.potts-rm-connected-tag.relationship').forEach(tag=>{tag.style.display=labels&&labels.checked?'':'none'});
}
function path(d,className){const item=document.createElementNS(NS,'path');item.setAttribute('class',className);item.setAttribute('d',d);svg.appendChild(item);return item}
function dot(x,y){const item=document.createElementNS(NS,'circle');item.setAttribute('class','potts-rm-connected-junction');item.setAttribute('cx',String(x));item.setAttribute('cy',String(y));item.setAttribute('r','3.8');svg.appendChild(item)}

function build(){
    displayOptions();

    // Solve generation rows from Person 1. Parent -> child is +1 generation;
    // spouses/partners are 0. This keeps two ancestral branches aligned when
    // they meet in a marriage (e.g. a Potts branch and a Madill branch).
    const adjacency=new Map(connectedGraph.nodes.map(node=>[node.id,[]]));
    connectedGraph.families.forEach(family=>{
        const spouses=(family.spouses||[]).filter(id=>adjacency.has(id));
        const children=(family.children||[]).filter(id=>adjacency.has(id));
        for(let i=0;i<spouses.length;i++)for(let j=i+1;j<spouses.length;j++){
            adjacency.get(spouses[i]).push({id:spouses[j],delta:0});adjacency.get(spouses[j]).push({id:spouses[i],delta:0});
        }
        spouses.forEach(spouse=>children.forEach(child=>{
            adjacency.get(spouse).push({id:child,delta:1});adjacency.get(child).push({id:spouse,delta:-1});
        }));
        if(!spouses.length&&children.length>1){
            for(let i=0;i<children.length;i++)for(let j=i+1;j<children.length;j++){
                adjacency.get(children[i]).push({id:children[j],delta:0});adjacency.get(children[j]).push({id:children[i],delta:0});
            }
        }
    });

    const levels=new Map();const referenceId=connectedGraph.reference&&connectedGraph.reference.xref?'I|'+connectedGraph.reference.xref:null;const queue=[];
    if(referenceId&&adjacency.has(referenceId)){levels.set(referenceId,0);queue.push(referenceId)}
    for(let cursor=0;cursor<queue.length;cursor++){
        const current=queue[cursor],currentLevel=levels.get(current)||0;
        (adjacency.get(current)||[]).forEach(link=>{if(!levels.has(link.id)){levels.set(link.id,currentLevel+link.delta);queue.push(link.id)}});
    }

    let maxKnown=levels.size?Math.max(...Array.from(levels.values())):0;
    connectedGraph.nodes.forEach(node=>{if(!levels.has(node.id)){maxKnown+=1;levels.set(node.id,maxKnown)}});
    const minLevel=Math.min(...Array.from(levels.values()));if(minLevel<0)levels.forEach((value,key)=>levels.set(key,value-minLevel));

    const rows=new Map();
    connectedGraph.nodes.forEach(node=>{if(!cards.has(node.id))return;const level=levels.get(node.id)||0;if(!rows.has(level))rows.set(level,[]);rows.get(level).push(node)});
    rows.forEach(nodes=>nodes.sort((a,b)=>{
        const at=Math.min(...(a.targets&&a.targets.length?a.targets:[999])),bt=Math.min(...(b.targets&&b.targets.length?b.targets:[999]));
        if(at!==bt)return at-bt;if(a.selected!==b.selected)return a.selected?1:-1;return String(a.name).localeCompare(String(b.name));
    }));

    const cardWidth=matchMedia('(max-width:900px)').matches?280:310;
    const horizontalGap=58,verticalGap=102,paddingX=42,paddingY=38;
    let maxRowWidth=0;rows.forEach(nodes=>{maxRowWidth=Math.max(maxRowWidth,nodes.length*cardWidth+Math.max(0,nodes.length-1)*horizontalGap)});naturalWidth=Math.max(900,maxRowWidth+paddingX*2);

    const positions=new Map();let y=paddingY;
    Array.from(rows.keys()).sort((a,b)=>a-b).forEach(level=>{
        const nodes=rows.get(level)||[];const rowWidth=nodes.length*cardWidth+Math.max(0,nodes.length-1)*horizontalGap;let x=Math.max(paddingX,(naturalWidth-rowWidth)/2);let rowHeight=0;
        nodes.forEach(node=>{const card=cards.get(node.id);card.style.left=x+'px';card.style.top=y+'px';const height=Math.max(90,card.offsetHeight);rowHeight=Math.max(rowHeight,height);positions.set(node.id,{x,y,width:cardWidth,height,cx:x+cardWidth/2,bottom:y+height,top:y});x+=cardWidth+horizontalGap});
        y+=rowHeight+verticalGap;
    });
    naturalHeight=Math.max(320,y-verticalGap+paddingY);

    canvas.style.width=naturalWidth+'px';canvas.style.height=naturalHeight+'px';cardsLayer.style.width=naturalWidth+'px';cardsLayer.style.height=naturalHeight+'px';svg.setAttribute('width',String(naturalWidth));svg.setAttribute('height',String(naturalHeight));svg.setAttribute('viewBox','0 0 '+naturalWidth+' '+naturalHeight);svg.innerHTML='';

    connectedGraph.families.forEach(family=>{
        const spouses=(family.spouses||[]).map(id=>positions.get(id)).filter(Boolean);
        const children=(family.children||[]).map(id=>positions.get(id)).filter(Boolean);
        if(!spouses.length&&!children.length)return;

        let junctionX=0,junctionY=0;
        if(spouses.length>=2){
            const left=Math.min(...spouses.map(item=>item.cx)),right=Math.max(...spouses.map(item=>item.cx));
            const partnerY=Math.max(...spouses.map(item=>item.bottom))+22;
            spouses.forEach(item=>path('M '+item.cx+' '+item.bottom+' V '+partnerY,'potts-rm-connected-partner'));
            path('M '+left+' '+partnerY+' H '+right,'potts-rm-connected-partner');junctionX=(left+right)/2;junctionY=partnerY;dot(junctionX,junctionY);
        }else if(spouses.length===1){junctionX=spouses[0].cx;junctionY=spouses[0].bottom}
        else{const childTop=Math.min(...children.map(item=>item.top));junctionX=children.reduce((sum,item)=>sum+item.cx,0)/children.length;junctionY=childTop-48}

        if(children.length){
            const minChildTop=Math.min(...children.map(item=>item.top));const busY=junctionY+Math.max(28,(minChildTop-junctionY)/2);
            path('M '+junctionX+' '+junctionY+' V '+busY,'potts-rm-connected-descent');
            const left=Math.min(junctionX,...children.map(item=>item.cx)),right=Math.max(junctionX,...children.map(item=>item.cx));if(children.length>1)path('M '+left+' '+busY+' H '+right,'potts-rm-connected-descent');children.forEach(child=>path('M '+child.cx+' '+busY+' V '+child.top,'potts-rm-connected-descent'));
        }
    });

    applyScale();
}

function applyScale(){let scale=1;if(fit&&fit.checked&&naturalWidth>0)scale=Math.min(1,Math.max(.42,(viewport.clientWidth-22)/naturalWidth));canvas.style.transform='scale('+scale+')';sizer.style.width=Math.max(viewport.clientWidth,naturalWidth*scale)+'px';const scaledHeight=Math.ceil(naturalHeight*scale+20);const visibleHeight=Math.max(320,Math.min(920,scaledHeight));sizer.style.height=scaledHeight+'px';viewport.style.height=visibleHeight+'px'}
function schedule(){requestAnimationFrame(()=>requestAnimationFrame(build))}
[photos,details,labels].forEach(control=>{if(control)control.addEventListener('change',schedule)});if(fit)fit.addEventListener('change',()=>requestAnimationFrame(applyScale));window.addEventListener('resize',schedule);schedule();
})();</script>
JS;
        View::endpush();
    }

    /** @param array<string,array<string,mixed>> $people */
    private function addPerson(array &$people, Individual $individual, int $index, bool $connected, string $relationship): void
    {
        $xref = $individual->xref();
        if (!isset($people[$xref])) {
            $people[$xref] = [
                'id' => 'I|' . $xref,
                'xref' => $xref,
                'name' => strip_tags($individual->fullName()),
                'selected' => true,
                'person_number' => $index + 1,
                'relationship' => $relationship,
                'targets' => [],
                'connected' => $connected,
                'bridge' => false,
            ];
        }

        $people[$xref]['selected'] = true;
        $people[$xref]['person_number'] = $index + 1;
        $people[$xref]['relationship'] = $relationship;
        $people[$xref]['connected'] = $people[$xref]['connected'] || $connected;
        $people[$xref]['targets'][$index] = true;
    }

    private function panelHtml(array $graph, Tree $tree): string
    {
        ob_start();
        ?>
        <section class="potts-rm-panel" id="potts-rm-connected">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <h3 class="h4 mb-1"><?= I18N::translate('Connected relationship graph') ?></h3>
                    <div class="potts-rm-note"><?= I18N::translate('Connects the selected people through visible parent-child and spouse/partner links. Unlike the shared ancestry graph, this view does not require one ancestor to be shared by everyone.') ?></div>
                </div>
            </div>

            <?php if (($graph['status'] ?? '') === 'too_few') : ?>
                <div class="alert alert-info mt-3 mb-0"><?= I18N::translate('Select at least two people to build a connected relationship graph.') ?></div>
            <?php elseif (($graph['status'] ?? '') !== 'ok') : ?>
                <div class="alert alert-warning mt-3 mb-0"><?= I18N::translate('No visible family connection was found from Person 1 to the other selected people within the current search limits.') ?></div>
            <?php else : ?>
                <div class="potts-rm-connected-toolbar d-print-none">
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-connected-photos" checked><label class="form-check-label" for="potts-rm-connected-photos"><?= I18N::translate('Show photos') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-connected-details" checked><label class="form-check-label" for="potts-rm-connected-details"><?= I18N::translate('Show dates and places') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-connected-labels" checked><label class="form-check-label" for="potts-rm-connected-labels"><?= I18N::translate('Show relationship labels') ?></label></div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="potts-rm-connected-fit"><label class="form-check-label" for="potts-rm-connected-fit"><?= I18N::translate('Fit chart to width') ?></label></div>
                    <div class="small text-muted w-100"><?= I18N::translate('Relationship labels are shown relative to Person 1. Dashed lines are spouse/partner links; solid lines are parent-child descent.') ?></div>
                </div>

                <div class="potts-rm-connected-summary">
                    <span class="potts-rm-connected-chip"><strong><?= I18N::translate('Reference person') ?>:</strong> <?= htmlspecialchars((string) ($graph['reference']['name'] ?? ''), ENT_QUOTES) ?></span>
                    <?php foreach (($graph['connections'] ?? []) as $connection) : ?>
                        <span class="potts-rm-connected-chip"><strong><?= htmlspecialchars((string) $connection['name'], ENT_QUOTES) ?>:</strong> <?= htmlspecialchars((string) $connection['relationship'], ENT_QUOTES) ?><?php if (!empty($connection['via_name'])) : ?> · <?= I18N::translate('via %s', htmlspecialchars((string) $connection['via_name'], ENT_QUOTES)) ?><?php endif ?></span>
                    <?php endforeach ?>
                </div>

                <div class="potts-rm-connected-viewport" id="potts-rm-connected-viewport">
                    <div class="potts-rm-connected-sizer" id="potts-rm-connected-sizer">
                        <div class="potts-rm-connected-canvas" id="potts-rm-connected-canvas">
                            <svg class="potts-rm-connected-svg" id="potts-rm-connected-svg" aria-hidden="true"></svg>
                            <div class="potts-rm-connected-cards" id="potts-rm-connected-cards">
                                <?php foreach (($graph['nodes'] ?? []) as $node) : ?>
                                    <?php $individual = Registry::individualFactory()->make((string) $node['xref'], $tree); ?>
                                    <?php if (!$individual instanceof Individual || !$individual->canShow()) continue; ?>
                                    <div class="potts-rm-connected-card<?= !empty($node['selected']) ? ' is-selected' : '' ?><?= !empty($node['bridge']) ? ' is-bridge' : '' ?>" data-connected-node="<?= htmlspecialchars((string) $node['id'], ENT_QUOTES) ?>">
                                        <?php if (!empty($node['selected'])) : ?><span class="potts-rm-connected-tag"><?= I18N::translate('Person %s', I18N::number((int) $node['person_number'])) ?></span><span class="potts-rm-connected-tag relationship"><?= htmlspecialchars((string) $node['relationship'], ENT_QUOTES) ?></span><?php elseif (!empty($node['bridge'])) : ?><span class="potts-rm-connected-tag bridge"><?= I18N::translate('Connection branch') ?></span><?php endif ?>
                                        <?= view('chart-box', ['individual' => $individual]) ?>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="potts-rm-connected-legend"><span><span class="potts-rm-connected-legend-line"></span><?= I18N::translate('Parent-child descent') ?></span><span><span class="potts-rm-connected-legend-line partner"></span><?= I18N::translate('Spouse/partner link') ?></span></div>
            <?php endif ?>
        </section>
        <?php

        return (string) ob_get_clean();
    }
}
