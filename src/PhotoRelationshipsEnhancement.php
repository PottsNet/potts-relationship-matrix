<?php

declare(strict_types=1);

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;

final class PottsRelationshipMatrixPhotoEnhancement
{
    private const MAX_PHOTO_PEOPLE = 16;

    public function __construct(private readonly LinkedRecordService $linked_record_service = new LinkedRecordService())
    {
    }

    /**
     * Resolve a media object and the visible individuals explicitly linked to it.
     * No filename, caption, note or face-recognition inference is used.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $media_xref, Tree $tree, Individual $route_individual, string $reference_xref = ''): array
    {
        $media_xref = trim($media_xref, " @\t\n\r\0\x0B");

        if ($media_xref === '') {
            return [
                'status' => 'none',
                'media' => null,
                'people' => [],
                'total_count' => 0,
                'truncated' => false,
                'reference_xref' => '',
            ];
        }

        $media = Registry::mediaFactory()->make($media_xref, $tree);
        if (!$media instanceof Media || !$media->canShow()) {
            return [
                'status' => 'not_found',
                'media' => null,
                'people' => [],
                'total_count' => 0,
                'truncated' => false,
                'reference_xref' => '',
            ];
        }

        $people = [];
        $seen = [];

        foreach ($this->linked_record_service->linkedIndividuals($media) as $individual) {
            if (!$individual instanceof Individual || !$individual->canShow() || isset($seen[$individual->xref()])) {
                continue;
            }

            $people[] = $individual;
            $seen[$individual->xref()] = true;
        }

        // Always normalise the list to zero-based numeric keys. More importantly,
        // do not assume a first person exists: a perfectly valid media record can
        // have no visible linked individuals for the current visitor.
        $people = array_values($people);
        $total_count = count($people);
        $reference_xref = trim($reference_xref);

        if ($reference_xref === '' || !isset($seen[$reference_xref])) {
            $first_person = $people[0] ?? null;
            $reference_xref = isset($seen[$route_individual->xref()])
                ? $route_individual->xref()
                : ($first_person instanceof Individual ? $first_person->xref() : '');
        }

        if ($reference_xref !== '') {
            usort($people, static function (Individual $a, Individual $b) use ($reference_xref): int {
                if ($a->xref() === $reference_xref) {
                    return -1;
                }
                if ($b->xref() === $reference_xref) {
                    return 1;
                }

                return strcasecmp(strip_tags($a->fullName()), strip_tags($b->fullName()));
            });
        }

        $truncated = count($people) > self::MAX_PHOTO_PEOPLE;
        if ($truncated) {
            $people = array_slice($people, 0, self::MAX_PHOTO_PEOPLE);
        }

        return [
            'status' => $people === [] ? 'no_people' : (count($people) < 2 ? 'one_person' : 'ok'),
            'media' => $media,
            'people' => $people,
            'total_count' => $total_count,
            'truncated' => $truncated,
            'reference_xref' => $reference_xref,
        ];
    }

    /**
     * Add the photo/media selector and photo context panel to the relationship page.
     * In photo mode the ordinary manual-person picker is hidden, because the
     * selected people come directly from the media links.
     *
     * @param array<string,mixed> $context
     */
    public function push(array $context, Tree $tree, string $base_url, string $scope, int $recursion): void
    {
        $media = $context['media'] ?? null;
        $people = is_array($context['people'] ?? null) ? $context['people'] : [];
        $status = (string) ($context['status'] ?? 'none');
        $reference_xref = (string) ($context['reference_xref'] ?? '');
        $total_count = (int) ($context['total_count'] ?? 0);
        $truncated = !empty($context['truncated']);

        $selector = view('components/select-media', [
            'name' => 'media',
            'id' => 'potts-rm-photo-media',
            'media' => $media instanceof Media ? $media : null,
            'tree' => $tree,
        ]);

        $reference_options = '';
        foreach ($people as $individual) {
            if (!$individual instanceof Individual) {
                continue;
            }

            $selected = $individual->xref() === $reference_xref ? ' selected' : '';
            $reference_options .= '<option value="' . e($individual->xref()) . '"' . $selected . '>'
                . e(strip_tags($individual->fullName())) . '</option>';
        }

        $scope_blood_selected = $scope === 'blood' ? ' selected' : '';
        $scope_all_selected = $scope === 'all' ? ' selected' : '';

        $summary = '';
        if ($media instanceof Media) {
            $summary .= '<div class="potts-rm-photo-current">';
            $summary .= '<strong>' . e(strip_tags($media->fullName())) . '</strong>';
            $summary .= ' <a href="' . e($media->url()) . '">' . I18N::translate('Open media record') . '</a>';
            $summary .= '</div>';

            if ($status === 'no_people') {
                $summary .= '<div class="alert alert-warning mt-2 mb-0">' . I18N::translate('No visible individuals are explicitly linked to this media record.') . '</div>';
            } elseif ($status === 'one_person') {
                $summary .= '<div class="alert alert-warning mt-2 mb-0">' . I18N::translate('Only one visible individual is linked to this media record, so a relationship matrix cannot yet be built.') . '</div>';
            } else {
                $summary .= '<div class="potts-rm-photo-count">' . I18N::translate('%s people linked to this media record', (string) $total_count) . '</div>';
            }

            if ($truncated) {
                $summary .= '<div class="alert alert-warning mt-2 mb-0">'
                    . I18N::translate('This development alpha analyses the first %s visible people in a media record. The photo contains more linked people.', (string) self::MAX_PHOTO_PEOPLE)
                    . '</div>';
            }

            if ($people !== []) {
                $summary .= '<div class="potts-rm-photo-people">';
                foreach ($people as $index => $individual) {
                    if (!$individual instanceof Individual) {
                        continue;
                    }
                    $label = ($index === 0 ? I18N::translate('Reference') . ': ' : '') . strip_tags($individual->fullName());
                    $summary .= '<span class="potts-rm-photo-person">' . e($label) . '</span>';
                }
                $summary .= '</div>';
            }
        } elseif ($status === 'not_found') {
            $summary .= '<div class="alert alert-warning mt-2 mb-0">' . I18N::translate('The selected media record could not be found or is not visible.') . '</div>';
        }

        $reference_control = $people === [] ? '' : (
            '<div class="potts-rm-photo-control"><label for="potts-rm-photo-reference">' . I18N::translate('Reference person') . '</label>'
            . '<select class="form-select" id="potts-rm-photo-reference" name="photo_ref">' . $reference_options . '</select></div>'
        );

        $clear = $media instanceof Media
            ? '<a class="btn btn-outline-secondary" href="' . e($base_url) . '">' . I18N::translate('Clear photo') . '</a>'
            : '';

        $html = '<section class="potts-rm-panel potts-rm-photo-panel">'
            . '<div class="potts-rm-photo-heading"><div><h3>' . I18N::translate('Relationships in a photo') . '</h3>'
            . '<p class="potts-rm-note mb-0">' . I18N::translate('Choose a media record. Potts Relationship Matrix will use only the individuals explicitly linked to that media record.') . '</p></div></div>'
            . '<form class="potts-rm-photo-form" method="get" action="' . e($base_url) . '">'
            . '<div class="potts-rm-photo-grid">'
            . '<div class="potts-rm-photo-control"><label for="potts-rm-photo-media">' . I18N::translate('Photo or media object') . '</label>' . $selector . '</div>'
            . $reference_control
            . '<div class="potts-rm-photo-control"><label for="potts-rm-photo-scope">' . I18N::translate('Relationship mode') . '</label>'
            . '<select class="form-select" id="potts-rm-photo-scope" name="scope">'
            . '<option value="blood"' . $scope_blood_selected . '>' . I18N::translate('Common ancestors (blood/ancestral)') . '</option>'
            . '<option value="all"' . $scope_all_selected . '>' . I18N::translate('All visible family links (includes spouses)') . '</option>'
            . '</select></div>'
            . '</div>'
            . '<input type="hidden" name="recursion" value="' . $recursion . '">'
            . '<input type="hidden" name="connected" value="1">'
            . '<div class="potts-rm-photo-actions"><button type="submit" class="btn btn-primary">' . I18N::translate('Show relationships in photo') . '</button>' . $clear . '</div>'
            . '</form>'
            . $summary
            . '</section>';

        $html_json = json_encode($html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $active = $media instanceof Media ? 'true' : 'false';

        View::push('styles');
        echo <<<'HTML'
<style>
.potts-rm-photo-panel{margin-top:1rem}.potts-rm-photo-heading{display:flex;justify-content:space-between;gap:1rem;align-items:start}.potts-rm-photo-heading h3{margin-bottom:.2rem}.potts-rm-photo-grid{display:grid;grid-template-columns:minmax(260px,2fr) minmax(220px,1fr) minmax(240px,1fr);gap:.8rem 1rem;margin-top:.9rem}.potts-rm-photo-control label{display:block;font-weight:600;margin-bottom:.3rem}.potts-rm-photo-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.85rem}.potts-rm-photo-current{margin-top:.9rem}.potts-rm-photo-count{margin-top:.25rem;color:var(--bs-secondary-color,#6c757d)}.potts-rm-photo-people{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.65rem}.potts-rm-photo-person{display:inline-block;padding:.18rem .55rem;border:1px solid rgba(110,110,110,.28);border-radius:999px;background:var(--bs-body-bg,#fff);font-size:.82rem}@media(max-width:900px){.potts-rm-photo-grid{grid-template-columns:1fr}}
</style>
HTML;
        View::endpush();

        View::push('javascript');
        echo '<script>(function(){"use strict";const html=' . $html_json . ';const active=' . $active . ';const page=document.querySelector(".potts-rm-page");if(!page)return;const intro=page.querySelector(".potts-rm-intro");if(intro){intro.insertAdjacentHTML("afterend",html)}else{page.insertAdjacentHTML("afterbegin",html)}if(active){page.classList.add("potts-rm-photo-mode");const forms=Array.from(page.querySelectorAll("form.potts-rm-panel"));forms.forEach(function(form){if(!form.classList.contains("potts-rm-photo-form")){form.style.display="none"}})}})();</script>';
        View::endpush();
    }
}
