<?php

declare(strict_types=1);

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;

final class PottsRelationshipMatrixConnectedTopDownEnhancement
{
    /**
     * Redraw the connected-family graph top-down and surface explicit GEDCOM
     * event associations (_ASSO/ASSO) as historical/context links.
     *
     * Historical links are never inferred from matching names. They are shown
     * only when an event explicitly associates a visible individual who is
     * already present in the connected graph.
     *
     * @param array<string,mixed>|null $graph
     */
    public function push(?array $graph, Tree $tree): void
    {
        if (!is_array($graph) || ($graph['status'] ?? '') !== 'ok') {
            return;
        }

        $events = $this->historicalEvents($graph, $tree);
        $payload = [
            'reference' => $graph['reference'] ?? null,
            'nodes' => $graph['nodes'] ?? [],
            'families' => $graph['families'] ?? [],
            'events' => $events,
            'labels' => [
                'historical_association' => I18N::translate('Historical association'),
                'event_association' => I18N::translate('Event association'),
            ],
        ];

        $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);

        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-connected-event-card{
    position:absolute;z-index:4;width:245px;min-height:58px;padding:.45rem .6rem;
    border:2px solid var(--bs-warning,#ffc107);border-radius:.65rem;
    background:var(--bs-body-bg,#fff);box-shadow:0 .16rem .45rem rgba(0,0,0,.10);
    font-size:.82rem;line-height:1.25
}
.potts-rm-connected-event-card strong{display:block;font-size:.88rem;margin-bottom:.12rem}
.potts-rm-connected-event-meta{color:var(--bs-secondary-color,#6c757d)}
.potts-rm-connected-history{fill:none;stroke:var(--bs-warning,#d39e00);stroke-width:2.3;stroke-dasharray:3 6;stroke-linecap:round;opacity:.95}
.potts-rm-connected-history-label{fill:var(--bs-body-color,#212529);font-size:12px;font-weight:700;paint-order:stroke;stroke:var(--bs-body-bg,#fff);stroke-width:4px;stroke-linejoin:round}
.potts-rm-connected-family-line-topdown{fill:none;stroke:var(--bs-secondary-color,#6c757d);stroke-width:2.15;stroke-linecap:round;stroke-linejoin:round;opacity:.88}
.potts-rm-connected-partner-topdown{fill:none;stroke:var(--bs-info,#0dcaf0);stroke-width:2.5;stroke-dasharray:8 5;stroke-linecap:round;opacity:.95}
.potts-rm-connected-family-dot-topdown{fill:var(--bs-secondary-color,#6c757d)}
</style>
HTML;
        View::endpush();

        View::push('javascript');
        echo '<script>(function(){"use strict";const connectedTopDown=' . $json . ';';
        echo <<<'JS'
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
const cards=new Map();
cardsLayer.querySelectorAll('[data-connected-node]').forEach(card=>cards.set(card.dataset.connectedNode,card));
const referenceId=connectedTopDown.reference&&connectedTopDown.reference.xref?'I|'+connectedTopDown.reference.xref:null;
let naturalWidth=1000,naturalHeight=500;
let eventLayer=document.getElementById('potts-rm-connected-events');
if(!eventLayer){eventLayer=document.createElement('div');eventLayer.id='potts-rm-connected-events';eventLayer.style.position='absolute';eventLayer.style.inset='0';eventLayer.style.zIndex='3';canvas.appendChild(eventLayer)}

function appendPath(d,className){const path=document.createElementNS(NS,'path');path.setAttribute('class',className);path.setAttribute('d',d);svg.appendChild(path);return path}
function appendCircle(x,y){const dot=document.createElementNS(NS,'circle');dot.setAttribute('class','potts-rm-connected-family-dot-topdown');dot.setAttribute('cx',String(x));dot.setAttribute('cy',String(y));dot.setAttribute('r','4');svg.appendChild(dot)}
function appendText(x,y,text){if(!text)return;const label=document.createElementNS(NS,'text');label.setAttribute('class','potts-rm-connected-history-label');label.setAttribute('x',String(x));label.setAttribute('y',String(y));label.setAttribute('text-anchor','middle');label.textContent=text;svg.appendChild(label)}

function computeGenerations(){
    const generations=new Map();
    if(referenceId)generations.set(referenceId,0);
    for(let pass=0;pass<80;pass++){
        let changed=false;
        connectedTopDown.families.forEach(family=>{
            const spouses=(family.spouses||[]).filter(id=>cards.has(id));
            const children=(family.children||[]).filter(id=>cards.has(id));
            let spouseGen=null,childGen=null;
            spouses.forEach(id=>{if(generations.has(id)&&spouseGen===null)spouseGen=generations.get(id)});
            children.forEach(id=>{if(generations.has(id)&&childGen===null)childGen=generations.get(id)});
            if(spouseGen!==null){
                spouses.forEach(id=>{if(!generations.has(id)){generations.set(id,spouseGen);changed=true}});
                children.forEach(id=>{if(!generations.has(id)){generations.set(id,spouseGen+1);changed=true}});
            }else if(childGen!==null){
                spouses.forEach(id=>{if(!generations.has(id)){generations.set(id,childGen-1);changed=true}});
            }
        });
        if(!changed)break;
    }
    let fallback=0;
    cards.forEach((card,id)=>{if(!generations.has(id)){generations.set(id,fallback);fallback++}});
    const min=Math.min(...Array.from(generations.values()));
    if(min<0)generations.forEach((value,key)=>generations.set(key,value-min));
    return generations;
}

function sortRows(rows){
    const targetOrder=new Map();
    connectedTopDown.nodes.forEach(node=>{const targets=Array.isArray(node.targets)?node.targets:[];targetOrder.set(node.id,targets.length?Math.min(...targets):999)});
    rows.forEach(nodes=>nodes.sort((a,b)=>{
        const at=targetOrder.get(a)||999,bt=targetOrder.get(b)||999;if(at!==bt)return at-bt;
        return String(a).localeCompare(String(b));
    }));
    connectedTopDown.families.forEach(family=>{
        const spouses=(family.spouses||[]).filter(id=>cards.has(id));if(spouses.length<2)return;
        rows.forEach(nodes=>{const first=nodes.indexOf(spouses[0]),second=nodes.indexOf(spouses[1]);if(first<0||second<0||Math.abs(first-second)===1)return;const moved=nodes.splice(second,1)[0];const insertAt=nodes.indexOf(spouses[0])+1;nodes.splice(insertAt,0,moved)});
    });
}

function buildTopDown(){
    viewport.classList.toggle('hide-photos',photos&&!photos.checked);
    viewport.classList.toggle('hide-details',details&&!details.checked);
    const generations=computeGenerations();
    const rows=new Map();
    cards.forEach((card,id)=>{const level=generations.get(id)||0;if(!rows.has(level))rows.set(level,[]);rows.get(level).push(id)});
    sortRows(rows);

    const cardWidth=matchMedia('(max-width:900px)').matches?280:310;
    const hGap=54,vGap=(connectedTopDown.events||[]).length?150:105,padX=40,padY=34;
    let maxRowWidth=0;
    rows.forEach(ids=>{maxRowWidth=Math.max(maxRowWidth,ids.length*cardWidth+Math.max(0,ids.length-1)*hGap)});
    naturalWidth=Math.max(1000,maxRowWidth+padX*2);
    const positions=new Map();
    let y=padY;
    Array.from(rows.keys()).sort((a,b)=>a-b).forEach(level=>{
        const ids=rows.get(level)||[];const rowWidth=ids.length*cardWidth+Math.max(0,ids.length-1)*hGap;let x=Math.max(padX,(naturalWidth-rowWidth)/2),rowHeight=0;
        ids.forEach(id=>{const card=cards.get(id);card.style.left=x+'px';card.style.top=y+'px';const height=Math.max(90,card.offsetHeight);rowHeight=Math.max(rowHeight,height);positions.set(id,{x,y,width:cardWidth,height,cx:x+cardWidth/2,cy:y+height/2,bottom:y+height});x+=cardWidth+hGap});
        y+=rowHeight+vGap;
    });
    naturalHeight=Math.max(360,y-vGap+padY+90);
    canvas.style.width=naturalWidth+'px';canvas.style.height=naturalHeight+'px';cardsLayer.style.width=naturalWidth+'px';cardsLayer.style.height=naturalHeight+'px';eventLayer.style.width=naturalWidth+'px';eventLayer.style.height=naturalHeight+'px';
    svg.setAttribute('width',String(naturalWidth));svg.setAttribute('height',String(naturalHeight));svg.setAttribute('viewBox','0 0 '+naturalWidth+' '+naturalHeight);svg.innerHTML='';eventLayer.innerHTML='';

    const familyPoints=new Map();
    connectedTopDown.families.forEach(family=>{
        const spouses=(family.spouses||[]).map(id=>positions.get(id)).filter(Boolean);
        const children=(family.children||[]).map(id=>positions.get(id)).filter(Boolean);
        if(spouses.length===0&&children.length===0)return;
        let junctionX,junctionY;
        if(spouses.length>=2){
            const left=Math.min(...spouses.map(p=>p.cx)),right=Math.max(...spouses.map(p=>p.cx));junctionY=Math.max(...spouses.map(p=>p.bottom))+24;junctionX=(left+right)/2;
            spouses.forEach(p=>appendPath('M '+p.cx+' '+p.bottom+' V '+junctionY,'potts-rm-connected-family-line-topdown'));
            appendPath('M '+left+' '+junctionY+' H '+right,'potts-rm-connected-partner-topdown');appendCircle(junctionX,junctionY);
        }else if(spouses.length===1){junctionX=spouses[0].cx;junctionY=spouses[0].bottom;}
        else{junctionX=children.reduce((s,p)=>s+p.cx,0)/children.length;junctionY=Math.min(...children.map(p=>p.y))-42;}
        if(children.length){
            const childY=Math.min(...children.map(p=>p.y));const branchY=junctionY+Math.max(34,(childY-junctionY)/2);appendPath('M '+junctionX+' '+junctionY+' V '+branchY,'potts-rm-connected-family-line-topdown');
            const xs=children.map(p=>p.cx);if(children.length>1)appendPath('M '+Math.min(...xs)+' '+branchY+' H '+Math.max(...xs),'potts-rm-connected-family-line-topdown');
            children.forEach(p=>appendPath('M '+p.cx+' '+branchY+' V '+p.y,'potts-rm-connected-family-line-topdown'));
        }
        familyPoints.set(String(family.xref),{x:junctionX,y:junctionY,spouses,children});
    });

    (connectedTopDown.events||[]).forEach((event,index)=>{
        const family=familyPoints.get(String(event.family_xref));if(!family)return;
        const card=document.createElement('div');card.className='potts-rm-connected-event-card';card.dataset.eventId=event.id;
        const title=document.createElement('strong');title.textContent=event.label;card.appendChild(title);
        const meta=[];if(event.date)meta.push(event.date);if(event.place)meta.push(event.place);if(meta.length){const div=document.createElement('div');div.className='potts-rm-connected-event-meta';div.textContent=meta.join(' — ');card.appendChild(div)}
        const offset=(index%2===0?1:-1)*(145+Math.floor(index/2)*35);const x=Math.max(8,Math.min(naturalWidth-253,family.x+offset-122));const y=Math.max(8,family.y+24);card.style.left=x+'px';card.style.top=y+'px';eventLayer.appendChild(card);
        const eventPoint={x:x+122.5,y:y+card.offsetHeight/2};
        appendPath('M '+family.x+' '+family.y+' L '+eventPoint.x+' '+eventPoint.y,'potts-rm-connected-history');
        (event.associations||[]).forEach(association=>{const person=positions.get('I|'+association.xref);if(!person)return;const x1=person.cx,y1=person.bottom,x2=eventPoint.x,y2=eventPoint.y;const midY=y1+(y2-y1)/2;appendPath('M '+x1+' '+y1+' V '+midY+' H '+x2+' V '+y2,'potts-rm-connected-history');appendText((x1+x2)/2,midY-6,association.role||connectedTopDown.labels.event_association)});
    });

    const legend=document.querySelector('#potts-rm-connected .potts-rm-connected-legend');
    if(legend&&(connectedTopDown.events||[]).length&&!legend.querySelector('.historical')){const item=document.createElement('span');item.className='historical';item.innerHTML='<span class="potts-rm-connected-legend-line" style="border-top-style:dotted;border-top-color:var(--bs-warning,#d39e00)"></span>'+connectedTopDown.labels.historical_association;legend.appendChild(item)}
    applyScale();
}

function applyScale(){let scale=1;if(fit&&fit.checked&&naturalWidth>0)scale=Math.min(1,Math.max(.40,(viewport.clientWidth-22)/naturalWidth));canvas.style.transform='scale('+scale+')';sizer.style.width=Math.max(viewport.clientWidth,naturalWidth*scale)+'px';const scaledHeight=Math.ceil(naturalHeight*scale+20);sizer.style.height=scaledHeight+'px';viewport.style.height=Math.max(320,Math.min(980,scaledHeight))+'px'}
function schedule(){requestAnimationFrame(()=>requestAnimationFrame(buildTopDown))}
[photos,details,labels].forEach(control=>{if(control)control.addEventListener('change',()=>setTimeout(schedule,0))});
if(fit)fit.addEventListener('change',()=>setTimeout(applyScale,0));window.addEventListener('resize',schedule);schedule();
})();</script>
JS;
        View::endpush();
    }

    /**
     * @param array<string,mixed> $graph
     * @return array<int,array<string,mixed>>
     */
    private function historicalEvents(array $graph, Tree $tree): array
    {
        $present = [];
        foreach (($graph['nodes'] ?? []) as $node) {
            if (is_string($node['xref'] ?? null)) {
                $present[(string) $node['xref']] = true;
            }
        }

        $events = [];
        foreach (($graph['families'] ?? []) as $family_data) {
            $family_xref = is_string($family_data['xref'] ?? null) ? (string) $family_data['xref'] : '';
            if ($family_xref === '') {
                continue;
            }

            $family = Registry::familyFactory()->make($family_xref, $tree);
            if (!$family instanceof Family || !$family->canShow()) {
                continue;
            }

            foreach ($family->facts() as $fact) {
                if (!$fact->canShow()) {
                    continue;
                }

                $gedcom = $fact->gedcom();
                if (!preg_match_all('/(?:^|\n)2 (?:_ASSO|ASSO) @([^@\n]+)@((?:\n3 [^\n]*)*)/', $gedcom, $matches, PREG_SET_ORDER)) {
                    continue;
                }

                $associations = [];
                foreach ($matches as $match) {
                    $xref = trim((string) ($match[1] ?? ''));
                    if ($xref === '' || !isset($present[$xref])) {
                        continue;
                    }

                    $individual = Registry::individualFactory()->make($xref, $tree);
                    if ($individual === null || !$individual->canShow()) {
                        continue;
                    }

                    $block = (string) ($match[2] ?? '');
                    $role = '';
                    if (preg_match('/\n3 RELA ?([^\n]*)/', $block, $role_match)) {
                        $role = trim((string) ($role_match[1] ?? ''));
                    }

                    $associations[] = [
                        'xref' => $xref,
                        'name' => strip_tags($individual->fullName()),
                        'role' => $role !== '' ? $role : I18N::translate('Associated with event'),
                    ];
                }

                if ($associations === []) {
                    continue;
                }

                $events[] = [
                    'id' => 'E|' . $family_xref . '|' . $fact->id(),
                    'family_xref' => $family_xref,
                    'label' => strip_tags($fact->label()),
                    'date' => $fact->attribute('DATE'),
                    'place' => $fact->attribute('PLAC'),
                    'associations' => $associations,
                ];
            }
        }

        return array_slice($events, 0, 20);
    }
}
