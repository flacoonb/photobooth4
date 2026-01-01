<?php

/**
 * @var array{collage: array} $config
 */

use Photobooth\Enum\CollageLayoutEnum;
use Photobooth\Service\LanguageService;
use Photobooth\Collage;

/**
 * Generate SVG preview for collage layout
 */
function getLayoutPreviewSvg(CollageLayoutEnum $layout): string
{
    $svg = '<svg class="collageSelector__preview" viewBox="0 0 120 180" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';

    // All layouts use 120x180 viewBox (2:3 aspect ratio)
    switch ($layout) {
        case CollageLayoutEnum::TWO_PLUS_TWO_1:
        case CollageLayoutEnum::TWO_PLUS_TWO_2:
            // 2+2 Layout: 4 equal squares
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 1],
                ['x' => 60, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 2],
                ['x' => 0, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 3],
                ['x' => 60, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 4],
            ];
            break;

        case CollageLayoutEnum::ONE_PLUS_THREE_1:
            // 1+3 Layout Option 1: Top full width, bottom 3 equal
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 120, 'h' => 90, 'num' => 1],
                ['x' => 0, 'y' => 90, 'w' => 40, 'h' => 90, 'num' => 2],
                ['x' => 40, 'y' => 90, 'w' => 40, 'h' => 90, 'num' => 3],
                ['x' => 80, 'y' => 90, 'w' => 40, 'h' => 90, 'num' => 4],
            ];
            break;

        case CollageLayoutEnum::ONE_PLUS_THREE_2:
            // 1+3 Layout Option 2: Left full height, right stacked
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 40, 'h' => 180, 'num' => 1],
                ['x' => 40, 'y' => 0, 'w' => 40, 'h' => 90, 'num' => 2],
                ['x' => 80, 'y' => 0, 'w' => 40, 'h' => 90, 'num' => 3],
                ['x' => 40, 'y' => 90, 'w' => 80, 'h' => 90, 'num' => 4],
            ];
            break;

        case CollageLayoutEnum::THREE_PLUS_ONE_1:
            // 3+1 Layout: Top 3 equal, bottom full width
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 40, 'h' => 90, 'num' => 1],
                ['x' => 40, 'y' => 0, 'w' => 40, 'h' => 90, 'num' => 2],
                ['x' => 80, 'y' => 0, 'w' => 40, 'h' => 90, 'num' => 3],
                ['x' => 0, 'y' => 90, 'w' => 120, 'h' => 90, 'num' => 4],
            ];
            break;

        case CollageLayoutEnum::ONE_PLUS_TWO_1:
            // 1+2 Layout: Top full width, bottom 2 equal
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 120, 'h' => 90, 'num' => 1],
                ['x' => 0, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 2],
                ['x' => 60, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 3],
            ];
            break;

        case CollageLayoutEnum::TWO_PLUS_ONE_1:
            // 2+1 Layout: Top 2 equal, bottom full width
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 1],
                ['x' => 60, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 2],
                ['x' => 0, 'y' => 90, 'w' => 120, 'h' => 90, 'num' => 3],
            ];
            break;

        default:
            // Fallback for other layouts
            $positions = [
                ['x' => 0, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 1],
                ['x' => 60, 'y' => 0, 'w' => 60, 'h' => 90, 'num' => 2],
                ['x' => 0, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 3],
                ['x' => 60, 'y' => 90, 'w' => 60, 'h' => 90, 'num' => 4],
            ];
            break;
    }

    // Draw each position
    foreach ($positions as $pos) {
        // Rectangle with border
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" fill="#4A90E2" stroke="#FFFFFF" stroke-width="2" rx="2"/>',
            $pos['x'] + 2,
            $pos['y'] + 2,
            $pos['w'] - 4,
            $pos['h'] - 4
        );

        // Number text centered
        $centerX = $pos['x'] + $pos['w'] / 2;
        $centerY = $pos['y'] + $pos['h'] / 2;
        $svg .= sprintf(
            '<text x="%d" y="%d" text-anchor="middle" dominant-baseline="middle" fill="#FFFFFF" font-size="28" font-weight="bold" font-family="Arial, sans-serif">%d</text>',
            $centerX,
            $centerY + 2,
            $pos['num']
        );
    }

    $svg .= '</svg>';
    return $svg;
}

function renderCollageOptionsFromEnumWithLimit(array $collageConfig): string
{
    $languageService = LanguageService::getInstance();

    $html = '<div id="collageSelector">';
    $html .= '<div class="modal hidden" id="collageSelectorModal" aria-hidden="true" role="dialog" aria-labelledby="collageSelectorTitle">';
    $html .= '<div class="modal-inner">';
    $html .= '<div class="modal-body">';
    $html .= '<h3 id="collageSelectorTitle">' . $languageService->translate('selectCollageLayout') . '</h3>';
    $html .= '<div class="collageSelector__options">';

    foreach (CollageLayoutEnum::cases() as $layout) {
        if (in_array($layout, $collageConfig['layouts_enabled'])) {
            $collageConfig['layout'] = $layout->value;
            $limitData = Collage::calculateLimit($collageConfig);
            $limit = $limitData['limit'];

            $html .= sprintf(
                '<button type="button" class="collageSelector__option cursor-pointer" data-layout="%s" data-limit="%d">' .
                '<div class="collageSelector__preview-container">%s</div>' .
                '<div class="collageSelector__label">%s</div>' .
                '<span class="collageSelector__limit">' .
                $languageService->translate('pictures') . ': %d' .
                '</span>' .
                '</button>',
                $layout->value,
                $limit,
                getLayoutPreviewSvg($layout),
                $layout->label(),
                $limit
            );
        }
    }

    $html .= '</div>'; // options
    $html .= '</div>'; // body
    $html .= '<div class="modal-buttonbar">';
    $html .= '<button type="button" class="modal-button" id="collageSelectorClose">' . htmlspecialchars($languageService->translate('close'), ENT_QUOTES, 'UTF-8') . '</button>';
    $html .= '</div></div></div>';
    $html .= '</div>';

    return $html;
}

echo renderCollageOptionsFromEnumWithLimit($config['collage']);
