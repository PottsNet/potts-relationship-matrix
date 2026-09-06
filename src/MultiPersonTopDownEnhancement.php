<?php

declare(strict_types=1);

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\View;

final class PottsRelationshipMatrixTopDownEnhancement
{
    /**
     * Draw the multi-person shared ancestry graph from ancestors at the top to
     * selected descendants at the bottom and label selected people relative to
     * Person 1.
     *
     * @param array<string,mixed>|null $multi_graph
     * @param array{cells:array<int,array<int,array<string,mixed>|null>>,pairs:array<string,array<string,mixed>>} $matrix_data
     * @param array<int,Individual> $selected
     */
    public function push(?array $multi_graph, array $matrix_data, array $selected): void
    {
        if (!is_array($multi_graph) || ($multi_graph['status'] ?? '') !== 'ok' || count($selected) < 3) {
            return;
        }

        $relationships = [];
        foreach ($selected as $index => $individual) {
            $relationship = $index === 0
                ? I18N::translate('Reference person')
                : (string) ($matrix_data['cells'][0][$index]['name'] ?? I18N::translate('Related'));

            $relationships[] = [
                'xref' => $individual->xref(),
                'person_number' => $index + 1,
                'relationship' => $relationship,
            ];
        }

        $payload = [
            'nodes' => $multi_graph['nodes'] ?? [],
            'edges' => $multi_graph['edges'] ?? [],
            'roots' => $multi_graph['roots'] ?? [],
            'family_units' => $multi_graph['family_units'] ?? [],
            'relationships' => $relationships,
            'labels' => [
                'relationship_to_person_1' => I18N::translate('Relationships shown relative to Person 1'),
                'show_relationships' => I18N::translate('Show relationship labels'),
            ],
        ];

        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-multi-card .potts-rm-multi-relation {
    border-color: var(--bs-primary, #0d6efd);
    background: color-mix(in srgb, var(--bs-primary, #0d6efd) 8%, var(--bs-body-bg, #fff));
    font-weight: 700;
}
.potts-rm-multi-reference-note {
    width: 100%;
    font-size: .84rem;
    color: var(--bs-secondary-color, #6c757d);
}
.potts-rm-multi-edge-topdown {
    fill: none;
    stroke: var(--bs-secondary-color, #6c757d);
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    opacity: .82;
}
.potts-rm-multi-family-topdown {
    fill: none;
    stroke: var(--bs-success, #198754);
    stroke-width: 2.4;
    stroke-linecap: round;
    stroke-linejoin: round;
    opacity: .92;
}
.potts-rm-multi-family-junction-topdown {
    fill: var(--bs-success, #198754);
}
</style>
HTML;
        View::endpush();

        View::push('javascript');
        echo '<script>(function(){"use strict";const topDown=' . $json . ';';
        echo <<<'JS'
const viewport=document.getElementById('potts-rm-multi-viewport');
const sizer=document.getElementById('potts-rm-multi-sizer');
const canvas=document.getElementById('potts-rm-multi-canvas');
const svg=document.getElementById('potts-rm-multi-svg');
const cardsLayer=document.getElementById('potts-rm-multi-cards');
const fit=document.getElementById('potts-rm-multi-fit');
const photos=document.getElementById('potts-rm-multi-photos');
const details=document.getElementById('potts-rm-multi-details');
const highlight=document.getElementById('potts-rm-multi-highlight');
if(!viewport||!sizer||!canvas||!svg||!cardsLayer)return;

const cards=new Map();
cardsLayer.querySelectorAll('[data-multi-node]').forEach(card=>cards.set(card.dataset.multiNode,card));
const relationshipByXref=new Map(topDown.relationships.map(item=>[item.xref,item]));

const toolbar=document.querySelector('#potts-rm-multi .potts-rm-multi-toolbar');
let showRelationships=document.getElementById('potts-rm-multi-relationships');
if(toolbar&&!showRelationships){
    const wrapper=document.createElement('div');wrapper.className='form-check';
    showRelationships=document.createElement('input');showRelationships.className='form-check-input';showRelationships.type='checkbox';showRelationships.id='potts-rm-multi-relationships';showRelationships.checked=true;
    const label=document.createElement('label');label.className='form-check-label';label.htmlFor=showRelationships.id;label.textContent=topDown.labels.show_relationships;
    wrapper.appendChild(showRelationships);wrapper.appendChild(label);toolbar.appendChild(wrapper);
    const note=document.createElement('div');note.className='potts-rm-multi-reference-note';note.textContent=topDown.labels.relationship_to_person_1;toolbar.appendChild(note);
}

cards.forEach((card,id)=>{
    const xref=id.startsWith('I|')?id.slice(2):id;
    const info=relationshipByXref.get(xref);
    if(!info)return;
    let badge=card.querySelector('.potts-rm-multi-relation');
    if(!badge){
        badge=document.createElement('span');
        badge.className='potts-rm-multi-tag potts-rm-multi-relation';
        badge.title=topDown.labels.relationship_to_person_1;
        const chartBox=card.querySelector('.wt-chart-box');
        if(chartBox)card.insertBefore(badge,chartBox);else card.appendChild(badge);
    }
    badge.textContent=info.relationship;
});

let naturalWidth=900,naturalHeight=420;
const NS='http://www.w3.org/2000/svg';

function displayOptions(){
    viewport.classList.toggle('hide-photos',photos&&!photos.checked);
    viewport.classList.toggle('hide-details',details&&!details.checked);
    viewport.classList.toggle('no-shared-highlight',highlight&&!highlight.checked);
    cardsLayer.querySelectorAll('.potts-rm-multi-relation').forEach(badge=>{badge.style.display=showRelationships&&showRelationships.checked?'':'none'});
}

function appendPath(d,className){
    const path=document.createElementNS(NS,'path');path.setAttribute('class',className);path.setAttribute('d',d);svg.appendChild(path);return path;
}
function appendDot(x,y){
    const dot=document.createElementNS(NS,'circle');dot.setAttribute('class','potts-rm-multi-family-junction-topdown');dot.setAttribute('cx',String(x));dot.setAttribute('cy',String(y));dot.setAttribute('r','4');svg.appendChild(dot);
}

function buildTopDown(){
    displayOptions();

    const outgoing=new Map();
    topDown.nodes.forEach(node=>outgoing.set(node.id,[]));
    topDown.edges.forEach(edge=>{if(outgoing.has(edge.from))outgoing.get(edge.from).push(edge.to)});

    const levels=new Map();
    const queue=[];
    topDown.roots.forEach(root=>{levels.set(root,0);queue.push(root)});
    for(let cursor=0;cursor<queue.length;cursor++){
        const current=queue[cursor],level=levels.get(current)||0;
        (outgoing.get(current)||[]).forEach(next=>{
            const candidate=level+1;
            if(!levels.has(next)||candidate<levels.get(next)){levels.set(next,candidate);queue.push(next)}
        });
    }
    let fallback=Math.max(0,...Array.from(levels.values()));
    topDown.nodes.forEach(node=>{if(!levels.has(node.id)){fallback+=1;levels.set(node.id,fallback)}});

    const rows=new Map();
    topDown.nodes.forEach(node=>{
        if(!cards.has(node.id))return;
        const level=levels.get(node.id)||0;
        if(!rows.has(level))rows.set(level,[]);
        rows.get(level).push(node);
    });
    rows.forEach(nodes=>nodes.sort((a,b)=>{
        const at=Math.min(...(a.targets||[999])),bt=Math.min(...(b.targets||[999]));
        if(at!==bt)return at-bt;
        if(a.shared!==b.shared)return a.shared?-1:1;
        return String(a.xref).localeCompare(String(b.xref));
    }));

    const cardWidth=matchMedia('(max-width:900px)').matches?280:310;
    const horizontalGap=58,verticalGap=92,paddingX=38,paddingY=34;
    let maxRowWidth=0;
    Array.from(rows.values()).forEach(nodes=>{maxRowWidth=Math.max(maxRowWidth,nodes.length*cardWidth+Math.max(0,nodes.length-1)*horizontalGap)});
    naturalWidth=Math.max(900,maxRowWidth+paddingX*2);

    const positions=new Map();
    let y=paddingY;
    Array.from(rows.keys()).sort((a,b)=>a-b).forEach(level=>{
        const nodes=rows.get(level)||[];
        const rowWidth=nodes.length*cardWidth+Math.max(0,nodes.length-1)*horizontalGap;
        let x=Math.max(paddingX,(naturalWidth-rowWidth)/2);
        let rowHeight=0;
        nodes.forEach(node=>{
            const card=cards.get(node.id);
            card.style.left=x+'px';card.style.top=y+'px';
            const height=Math.max(90,card.offsetHeight);
            rowHeight=Math.max(rowHeight,height);
            positions.set(node.id,{x,y,width:cardWidth,height,cx:x+cardWidth/2,bottom:y+height});
            x+=cardWidth+horizontalGap;
        });
        y+=rowHeight+verticalGap;
    });
    naturalHeight=Math.max(300,y-verticalGap+paddingY);

    canvas.style.width=naturalWidth+'px';canvas.style.height=naturalHeight+'px';
    cardsLayer.style.width=naturalWidth+'px';cardsLayer.style.height=naturalHeight+'px';
    svg.setAttribute('width',String(naturalWidth));svg.setAttribute('height',String(naturalHeight));svg.setAttribute('viewBox','0 0 '+naturalWidth+' '+naturalHeight);svg.innerHTML='';

    const familyUnits=new Map();
    (topDown.family_units||[]).forEach(unit=>familyUnits.set(String(unit.family),unit));
    const familyJunctions=new Map();

    familyUnits.forEach((unit,family)=>{
        const parents=(unit.ancestors||[]).map(xref=>positions.get('I|'+xref)).filter(Boolean);
        if(parents.length<2)return;
        const left=Math.min(...parents.map(p=>p.cx)),right=Math.max(...parents.map(p=>p.cx));
        const lineY=Math.max(...parents.map(p=>p.bottom))+24;
        parents.forEach(parent=>appendPath('M '+parent.cx+' '+parent.bottom+' V '+lineY,'potts-rm-multi-family-topdown'));
        appendPath('M '+left+' '+lineY+' H '+right,'potts-rm-multi-family-topdown');
        const junctionX=(left+right)/2;appendDot(junctionX,lineY);familyJunctions.set(family,{x:junctionX,y:lineY,ancestors:new Set((unit.ancestors||[]).map(x=>'I|'+x))});
    });

    const drawnFamilyChildren=new Set();
    topDown.edges.forEach(edge=>{
        const from=positions.get(edge.from),to=positions.get(edge.to);if(!from||!to)return;
        const family=familyJunctions.get(String(edge.family));
        let x1=from.cx,y1=from.bottom;
        if(family&&family.ancestors.has(edge.from)){
            const key=String(edge.family)+'|'+edge.to;
            if(drawnFamilyChildren.has(key))return;
            drawnFamilyChildren.add(key);x1=family.x;y1=family.y;
        }
        const x2=to.cx,y2=to.y;
        const middleY=y1+Math.max(30,(y2-y1)/2);
        appendPath('M '+x1+' '+y1+' V '+middleY+' H '+x2+' V '+y2,'potts-rm-multi-edge-topdown');
    });

    applyScale();
}

function applyScale(){
    let scale=1;
    if(fit&&fit.checked&&naturalWidth>0)scale=Math.min(1,Math.max(.42,(viewport.clientWidth-22)/naturalWidth));
    canvas.style.transform='scale('+scale+')';
    sizer.style.width=Math.max(viewport.clientWidth,naturalWidth*scale)+'px';
    const scaledHeight=Math.ceil(naturalHeight*scale+20);
    const visibleHeight=Math.max(300,Math.min(900,scaledHeight));
    sizer.style.height=scaledHeight+'px';viewport.style.height=visibleHeight+'px';
}

function schedule(){requestAnimationFrame(()=>requestAnimationFrame(buildTopDown))}
[photos,details,highlight,showRelationships].forEach(control=>{if(control)control.addEventListener('change',schedule)});
if(fit)fit.addEventListener('change',()=>requestAnimationFrame(applyScale));
window.addEventListener('resize',schedule);
schedule();
})();</script>
JS;
        View::endpush();
    }
}
