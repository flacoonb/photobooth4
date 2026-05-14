<?php

use Photobooth\Service\AssetService;
use Photobooth\Service\LanguageService;
use Photobooth\Utility\AdminKeypad;

if (!($config['camera_quicksettings']['enabled'] ?? false)) {
    return;
}

$cqsPin = $config['camera_quicksettings']['pin'] ?? null;
if (!is_string($cqsPin) || $cqsPin === '') {
    // Feature is enabled but no PIN configured — refuse to render the trigger
    // so an unprotected entry-point is never exposed at the kiosk.
    return;
}

$languageService = LanguageService::getInstance();
$assetService = AssetService::getInstance();

$cqsPosition = $config['camera_quicksettings']['position'] ?? 'bottom-left';
$cqsAdminIcon = $config['icons']['admin'] ?? 'fa fa-cog';
$cqsPinLength = AdminKeypad::pinLength($cqsPin);
if ($cqsPinLength < 4 || $cqsPinLength > 8) {
    $cqsPinLength = 4;
}

?>
<button
    type="button"
    id="cqsTrigger"
    class="cqs-trigger cqs-trigger--<?= htmlspecialchars($cqsPosition, ENT_QUOTES) ?>"
    aria-label="<?= htmlspecialchars($languageService->translate('camera_quicksettings:trigger_label'), ENT_QUOTES) ?>"
>
    <i class="<?= htmlspecialchars($cqsAdminIcon, ENT_QUOTES) ?>" aria-hidden="true"></i>
</button>

<div id="cqsPinModal" class="cqs-modal" hidden>
    <div class="cqs-modal__inner cqs-modal__inner--narrow" role="dialog" aria-modal="true" aria-labelledby="cqsPinTitle">
        <div class="cqs-modal__body">
            <h2 id="cqsPinTitle" class="cqs-modal__title">
                <?= htmlspecialchars($languageService->translate('camera_quicksettings:pin_title'), ENT_QUOTES) ?>
            </h2>
            <p class="cqs-modal__hint">
                <?= htmlspecialchars($languageService->translate('camera_quicksettings:pin_hint'), ENT_QUOTES) ?>
            </p>
            <div id="cqsPinDots" class="cqs-pin-dots" data-pin-length="<?= (int) $cqsPinLength ?>"></div>
            <div id="cqsPinMessage" class="cqs-message"></div>
            <div class="cqs-keypad">
                <button type="button" class="cqs-key" data-cqs-key="1">1</button>
                <button type="button" class="cqs-key" data-cqs-key="2">2</button>
                <button type="button" class="cqs-key" data-cqs-key="3">3</button>
                <button type="button" class="cqs-key" data-cqs-key="4">4</button>
                <button type="button" class="cqs-key" data-cqs-key="5">5</button>
                <button type="button" class="cqs-key" data-cqs-key="6">6</button>
                <button type="button" class="cqs-key" data-cqs-key="7">7</button>
                <button type="button" class="cqs-key" data-cqs-key="8">8</button>
                <button type="button" class="cqs-key" data-cqs-key="9">9</button>
                <button type="button" class="cqs-key cqs-key--secondary" data-cqs-action="clear">
                    <i class="fa fa-eraser" aria-hidden="true"></i>
                </button>
                <button type="button" class="cqs-key" data-cqs-key="0">0</button>
                <button type="button" class="cqs-key cqs-key--secondary" data-cqs-action="back">
                    <i class="fa fa-delete-left" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="cqs-modal__buttonbar">
            <button type="button" class="cqs-modal__button cqs-modal__button--secondary" data-cqs-action="cancel">
                <?= htmlspecialchars($languageService->translate('cancel'), ENT_QUOTES) ?>
            </button>
            <button type="button" class="cqs-modal__button cqs-modal__button--primary" data-cqs-action="submit">
                <?= htmlspecialchars($languageService->translate('camera_quicksettings:unlock'), ENT_QUOTES) ?>
            </button>
        </div>
    </div>
</div>

<div id="cqsSettingsModal" class="cqs-modal" hidden>
    <div class="cqs-modal__inner" role="dialog" aria-modal="true" aria-labelledby="cqsSettingsTitle">
        <div class="cqs-modal__body">
            <h2 id="cqsSettingsTitle" class="cqs-modal__title">
                <?= htmlspecialchars($languageService->translate('camera_quicksettings:title'), ENT_QUOTES) ?>
            </h2>
            <div id="cqsCameraInfo" class="cqs-camera-info"></div>

            <div id="cqsLoading" class="cqs-loading">
                <i class="fa fa-circle-notch fa-spin" aria-hidden="true"></i>
                <span><?= htmlspecialchars($languageService->translate('camera_quicksettings:loading'), ENT_QUOTES) ?></span>
            </div>

            <div id="cqsSettingsList" class="cqs-settings" hidden></div>

            <div id="cqsApplyMessage" class="cqs-message"></div>
        </div>
        <div class="cqs-modal__buttonbar">
            <button type="button" class="cqs-modal__button cqs-modal__button--secondary" data-cqs-action="close">
                <?= htmlspecialchars($languageService->translate('close'), ENT_QUOTES) ?>
            </button>
            <button type="button" class="cqs-modal__button cqs-modal__button--primary" data-cqs-action="apply">
                <?= htmlspecialchars($languageService->translate('camera_quicksettings:apply'), ENT_QUOTES) ?>
            </button>
        </div>
    </div>
</div>

<script src="<?= $assetService->getUrl('resources/js/cameraQuickSettings.js') ?>"></script>
