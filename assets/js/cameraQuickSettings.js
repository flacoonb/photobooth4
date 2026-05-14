/* eslint n/no-unsupported-features/node-builtins: "off" */
/* eslint no-redeclare: "off" */
/* globals photoboothTools csrf config environment */
function cqsInit() {
    'use strict';

    if (typeof config === 'undefined' || !config.camera_quicksettings || !config.camera_quicksettings.enabled) {
        return;
    }

    const apiBase = environment.publicFolders.api;

    const trigger = document.getElementById('cqsTrigger');
    const pinModal = document.getElementById('cqsPinModal');
    const pinDots = document.getElementById('cqsPinDots');
    const pinMessage = document.getElementById('cqsPinMessage');
    const settingsModal = document.getElementById('cqsSettingsModal');
    const settingsList = document.getElementById('cqsSettingsList');
    const cameraInfo = document.getElementById('cqsCameraInfo');
    const loading = document.getElementById('cqsLoading');
    const applyMessage = document.getElementById('cqsApplyMessage');

    if (!trigger || !pinModal || !settingsModal) {
        return;
    }

    const pinLength = parseInt(pinDots.dataset.pinLength, 10) || 4;
    let pinValue = '';
    let cameraSettings = {};
    const pendingChanges = {};

    const t = (key) => (photoboothTools && photoboothTools.getTranslation ? photoboothTools.getTranslation(key) : key);

    function appendCsrf(formData) {
        if (typeof csrf !== 'undefined' && csrf && csrf.key && csrf.token) {
            formData.append(csrf.key, csrf.token);
        }
    }

    function setMessage(node, text, state) {
        node.textContent = text || '';
        node.classList.remove('is-error', 'is-success');
        if (state === 'error') {
            node.classList.add('is-error');
        } else if (state === 'success') {
            node.classList.add('is-success');
        }
    }

    function renderPinDots() {
        pinDots.innerHTML = '';
        for (let i = 0; i < pinLength; i += 1) {
            const dot = document.createElement('span');
            dot.className = 'cqs-pin-dot';
            if (i < pinValue.length) {
                dot.classList.add('is-filled');
            }
            pinDots.appendChild(dot);
        }
    }

    function openPinModal() {
        pinValue = '';
        renderPinDots();
        setMessage(pinMessage, '', '');
        pinModal.hidden = false;
    }

    function closePinModal() {
        pinModal.hidden = true;
        pinValue = '';
    }

    function shake(node) {
        node.classList.remove('cqs-pin-shake');
        // force reflow so the animation can replay
        void node.offsetWidth;
        node.classList.add('cqs-pin-shake');
    }

    function pinSubmit() {
        if (pinValue.length === 0) {
            return;
        }

        const fd = new FormData();
        fd.append('pin', pinValue);
        appendCsrf(fd);

        setMessage(pinMessage, t('camera_quicksettings:checking'), '');

        fetch(apiBase + '/cameraQuickSettingsPin.php', {
            method: 'POST',
            body: fd,
            cache: 'no-store'
        })
            .then((res) => res.json().then((data) => ({ ok: res.ok, status: res.status, data })))
            .then((res) => {
                if (res.ok && res.data.success) {
                    if (res.data.csrf && typeof csrf !== 'undefined') {
                        csrf.token = res.data.csrf;
                    }
                    closePinModal();
                    openSettingsModal();
                    return;
                }
                shake(pinModal.querySelector('.cqs-modal__inner'));
                pinValue = '';
                renderPinDots();
                if (res.status === 429) {
                    setMessage(pinMessage, t('camera_quicksettings:pin_too_many'), 'error');
                } else {
                    setMessage(pinMessage, t('camera_quicksettings:pin_invalid'), 'error');
                }
            })
            .catch(() => {
                setMessage(pinMessage, t('camera_quicksettings:network_error'), 'error');
            });
    }

    function openSettingsModal() {
        settingsModal.hidden = false;
        settingsList.hidden = true;
        settingsList.innerHTML = '';
        cameraInfo.textContent = '';
        cameraSettings = {};
        Object.keys(pendingChanges).forEach((k) => delete pendingChanges[k]);
        setMessage(applyMessage, '', '');
        loading.hidden = false;
        loading.querySelector('span').textContent = t('camera_quicksettings:loading');

        fetch(apiBase + '/cameraQuickSettings.php', { cache: 'no-store' })
            .then((res) => res.json().then((data) => ({ ok: res.ok, status: res.status, data })))
            .then((res) => {
                if (!res.ok || !res.data.success) {
                    const err = (res.data && res.data.error) || t('camera_quicksettings:load_error');
                    loading.hidden = true;
                    setMessage(applyMessage, err, 'error');
                    return;
                }
                cameraSettings = res.data.settings || {};
                renderCameraInfo(res.data.camera);
                renderSettings(cameraSettings);
                loading.hidden = true;
                settingsList.hidden = false;
            })
            .catch(() => {
                loading.hidden = true;
                setMessage(applyMessage, t('camera_quicksettings:network_error'), 'error');
            });
    }

    function closeSettingsModal() {
        settingsModal.hidden = true;
    }

    function renderCameraInfo(camera) {
        if (!camera || !camera.model) {
            cameraInfo.textContent = t('camera_quicksettings:camera_unknown');
            return;
        }
        cameraInfo.textContent = camera.model;
    }

    function renderSettings(settings) {
        settingsList.innerHTML = '';
        const order = ['iso', 'aperture', 'shutterspeed', 'whitebalance'];
        order.forEach((logical) => {
            if (!Object.prototype.hasOwnProperty.call(settings, logical)) {
                return;
            }
            const data = settings[logical];
            const wrapper = document.createElement('div');
            wrapper.className = 'cqs-setting';
            wrapper.dataset.setting = logical;

            const header = document.createElement('div');
            header.className = 'cqs-setting__header';

            const label = document.createElement('span');
            label.className = 'cqs-setting__label';
            label.textContent = t('camera_quicksettings:setting_' + logical);
            header.appendChild(label);

            const valueDisplay = document.createElement('span');
            valueDisplay.className = 'cqs-setting__value';
            header.appendChild(valueDisplay);

            wrapper.appendChild(header);

            if (!data.available || !Array.isArray(data.choices) || data.choices.length === 0) {
                wrapper.classList.add('is-unavailable');
                valueDisplay.textContent = '—';
                const note = document.createElement('div');
                note.className = 'cqs-setting__error';
                note.textContent = data.error || t('camera_quicksettings:setting_unavailable');
                wrapper.appendChild(note);
                settingsList.appendChild(wrapper);
                return;
            }

            const choices = data.choices;
            const currentIdx = findCurrentIndex(choices, data.current);
            valueDisplay.textContent = formatChoice(choices[currentIdx].value);

            if (logical === 'whitebalance') {
                const grid = document.createElement('div');
                grid.className = 'cqs-setting__choices';
                choices.forEach((choice, idx) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'cqs-setting__choice';
                    btn.dataset.choiceIndex = String(idx);
                    btn.textContent = formatChoice(choice.value);
                    if (idx === currentIdx) {
                        btn.classList.add('is-active');
                    }
                    btn.addEventListener('click', () => {
                        grid.querySelectorAll('.cqs-setting__choice').forEach((c) => c.classList.remove('is-active'));
                        btn.classList.add('is-active');
                        valueDisplay.textContent = formatChoice(choice.value);
                        recordChange(logical, data.key, choice.value, choices[currentIdx].value);
                    });
                    grid.appendChild(btn);
                });
                wrapper.appendChild(grid);
            } else {
                if (choices.length > 1) {
                    const ticks = document.createElement('div');
                    ticks.className = 'cqs-setting__ticks';
                    choices.forEach((choice, idx) => {
                        const tick = document.createElement('span');
                        tick.className = 'cqs-setting__tick';
                        if (choices.length > 8 && idx % 2 !== 0 && idx !== choices.length - 1) {
                            tick.classList.add('is-hidden');
                        }
                        tick.textContent = formatChoice(choice.value);
                        ticks.appendChild(tick);
                    });
                    wrapper.appendChild(ticks);
                }

                const slider = document.createElement('input');
                slider.type = 'range';
                slider.className = 'cqs-setting__slider';
                slider.min = '0';
                slider.max = String(choices.length - 1);
                slider.step = '1';
                slider.value = String(currentIdx);
                slider.addEventListener('input', () => {
                    const idx = parseInt(slider.value, 10);
                    valueDisplay.textContent = formatChoice(choices[idx].value);
                    recordChange(logical, data.key, choices[idx].value, choices[currentIdx].value);
                });
                wrapper.appendChild(slider);
            }

            settingsList.appendChild(wrapper);
        });
    }

    function findCurrentIndex(choices, currentValue) {
        const target = String(currentValue || '');
        for (let i = 0; i < choices.length; i += 1) {
            if (String(choices[i].value) === target || String(choices[i].index) === target) {
                return i;
            }
        }
        return 0;
    }

    function formatChoice(value) {
        return String(value).replace('.', ',');
    }

    function recordChange(setting, key, value, originalValue) {
        if (String(value) === String(originalValue)) {
            delete pendingChanges[setting];
        } else {
            pendingChanges[setting] = { setting, key, value };
        }
    }

    function applyChanges() {
        const changes = Object.values(pendingChanges);
        if (changes.length === 0) {
            closeSettingsModal();
            return;
        }

        setMessage(applyMessage, t('camera_quicksettings:saving'), '');
        const fd = new FormData();
        fd.append('changes', JSON.stringify(changes));
        appendCsrf(fd);

        fetch(apiBase + '/cameraQuickSettingsApply.php', {
            method: 'POST',
            body: fd,
            cache: 'no-store'
        })
            .then((res) => res.json().then((data) => ({ ok: res.ok, status: res.status, data })))
            .then((res) => {
                if (res.data && Array.isArray(res.data.errors) && res.data.errors.length > 0) {
                    const summary = res.data.errors
                        .map((e) => t('camera_quicksettings:setting_' + e.setting) + ': ' + e.error)
                        .join(' · ');
                    setMessage(applyMessage, summary, 'error');
                    return;
                }
                if (res.ok && res.data.success) {
                    setMessage(applyMessage, t('camera_quicksettings:saved'), 'success');
                    Object.keys(pendingChanges).forEach((k) => delete pendingChanges[k]);
                    setTimeout(closeSettingsModal, 1200);
                    return;
                }
                const err = (res.data && res.data.error) || t('camera_quicksettings:save_error');
                setMessage(applyMessage, err, 'error');
            })
            .catch(() => {
                setMessage(applyMessage, t('camera_quicksettings:network_error'), 'error');
            });
    }

    /* Event wiring */

    trigger.addEventListener('click', openPinModal);

    pinModal.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const key = target.closest('[data-cqs-key]');
        if (key) {
            if (pinValue.length < pinLength) {
                pinValue += key.getAttribute('data-cqs-key');
                renderPinDots();
                if (pinValue.length === pinLength) {
                    setTimeout(pinSubmit, 150);
                }
            }
            return;
        }
        const action = target.closest('[data-cqs-action]');
        if (action) {
            switch (action.getAttribute('data-cqs-action')) {
                case 'back':
                    pinValue = pinValue.slice(0, -1);
                    renderPinDots();
                    break;
                case 'clear':
                    pinValue = '';
                    renderPinDots();
                    break;
                case 'cancel':
                    closePinModal();
                    break;
                case 'submit':
                    pinSubmit();
                    break;
                default:
                    break;
            }
        }
    });

    settingsModal.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        const action = target.closest('[data-cqs-action]');
        if (!action) {
            return;
        }
        switch (action.getAttribute('data-cqs-action')) {
            case 'close':
                closeSettingsModal();
                break;
            case 'apply':
                applyChanges();
                break;
            default:
                break;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', cqsInit);
} else {
    cqsInit();
}
