document.addEventListener('DOMContentLoaded', () => {
    const tabs = [...document.querySelectorAll('[data-profile-appearance-tab]')];
    const sections = [...document.querySelectorAll('[data-profile-appearance-section]')];
    const simpleForm = document.querySelector('[data-profile-customization-inline]');
    const advancedForm = document.querySelector('[data-profile-customization-advanced]');
    if (!tabs.length || !simpleForm || !advancedForm) return;

    const settingsField = simpleForm.querySelector('[data-inline-simple-settings]');
    const advancedSettings = advancedForm.querySelector('[data-inline-advanced-settings]');
    const widthField = simpleForm.querySelector('[data-inline-sidebar-width-value]');
    const advancedWidth = advancedForm.querySelector('[data-inline-advanced-width]');
    const widthControl = simpleForm.querySelector('[data-inline-sidebar-width]');
    const widthOutput = simpleForm.querySelector('[data-inline-sidebar-width-output]');
    const targetField = simpleForm.querySelector('[data-inline-style-target]');
    const appearancePanel = document.querySelector('[data-profile-appearance-panel]');
    const propertyFields = [...simpleForm.querySelectorAll('[data-inline-style-property]')];
    const gradientPreview = simpleForm.querySelector('[data-inline-gradient-preview]');
    const gradientSwap = simpleForm.querySelector('[data-inline-gradient-swap]');
    const gradientClear = simpleForm.querySelector('[data-inline-gradient-clear]');
    const simpleStatus = simpleForm.querySelector('[data-inline-customization-status]');
    const advancedStatus = advancedForm.querySelector('[data-inline-advanced-status]');
    const templateField = advancedForm.elements.template_html;
    const cssField = advancedForm.elements.custom_css;
    const cssHighlight = advancedForm.querySelector('[data-inline-css-highlight]');
    const cssTargetLabel = advancedForm.querySelector('[data-inline-css-target-label]');
    const cssTargetSelector = advancedForm.querySelector('[data-inline-css-target-selector]');
    const cssInsert = advancedForm.querySelector('[data-inline-css-insert]');
    const cssHoverLabel = document.querySelector('[data-profile-css-hover-label]');
    const removeWallpaperField = advancedForm.querySelector('[data-inline-remove-wallpaper]');
    const undoButtons = [...document.querySelectorAll('[data-inline-undo]')];
    const redoButtons = [...document.querySelectorAll('[data-inline-redo]')];
    let settings = {};
    let draftTimer;
    let draftRequest;
    let undoStack = [];
    let redoStack = [];
    let baseline;
    let lastHistoryKey = '';
    let lastHistoryTime = 0;
    let picking = false;
    let hoveredTarget = null;
    let activeCssTarget = '';
    let selectedCssTarget = '';
    let selectedCssElement = null;
    let highlightedElements = [];
    let highlightOverlays = [];
    const wallpaperCssSignature = (css) => {
        const targetMarkers = ['.profile-custom-content', 'layout.content', 'content.wallpaper', 'content.wallpaper-image'];
        const wallpaperProperties = /\b(?:background(?:-[a-z-]+)?|display|visibility|opacity|filter|content|animation(?:-[a-z-]+)?)\s*:/i;
        return [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)]
            .filter((match) => targetMarkers.some((marker) => match[1].includes(marker)) && wallpaperProperties.test(match[2]))
            .map((match) => `${match[1].trim()}{${match[2].trim()}}`)
            .join('\n');
    };
    const initialWallpaperCss = wallpaperCssSignature(cssField.value);
    try {
        settings = JSON.parse(settingsField.value || '{}');
        if (!settings || Array.isArray(settings) || typeof settings !== 'object') settings = {};
    } catch { settings = {}; }

    const showSection = (name) => {
        tabs.forEach((tab) => tab.setAttribute('aria-pressed', String(tab.dataset.profileAppearanceTab === name)));
        sections.forEach((section) => { section.hidden = section.dataset.profileAppearanceSection !== name; });
        window.localStorage.setItem('nexus-profile-appearance-section', name);
    };
    tabs.forEach((tab) => tab.addEventListener('click', () => showSection(tab.dataset.profileAppearanceTab)));
    const savedSection = window.localStorage.getItem('nexus-profile-appearance-section');
    if (tabs.some((tab) => tab.dataset.profileAppearanceTab === savedSection)) showSection(savedSection);

    const ensureStyle = (attribute) => {
        let style = document.head.querySelector(`style[${attribute}]`);
        if (!style) {
            style = document.createElement('style');
            style.setAttribute(attribute, '');
            document.head.append(style);
        }
        return style;
    };
    const fontFamilies = {
        Arial: 'Arial, sans-serif',
        Georgia: 'Georgia, serif',
        'Times New Roman': '"Times New Roman", serif',
        'Trebuchet MS': '"Trebuchet MS", sans-serif',
        Verdana: 'Verdana, sans-serif',
        'Courier New': '"Courier New", monospace',
    };
    const renderLocalSimpleCss = () => {
        const rules = Object.entries(settings).map(([target, values]) => {
            const declarations = [];
            if (fontFamilies[values.font_family]) declarations.push(`font-family: ${fontFamilies[values.font_family]}`);
            if (values.font_size !== undefined) declarations.push(`font-size: ${values.font_size}px`);
            if (values.font_weight !== undefined) declarations.push(`font-weight: ${values.font_weight}`);
            if (values.font_style !== undefined) declarations.push(`font-style: ${values.font_style}`);
            if (values.text_align !== undefined) declarations.push(`text-align: ${values.text_align}`);
            if (values.text_color !== undefined) declarations.push(`color: ${values.text_color}`);
            if (values.text_gradient_from !== undefined && values.text_gradient_to !== undefined) {
                declarations.push(`background-image: linear-gradient(${values.text_gradient_angle ?? 90}deg, ${values.text_gradient_from}, ${values.text_gradient_to})`);
                declarations.push('background-clip: text', '-webkit-background-clip: text', 'color: transparent');
            }
            if (values.background_color !== undefined) declarations.push(`background-color: ${values.background_color}`);
            if (values.border_color !== undefined) declarations.push(`border-color: ${values.border_color}`);
            if (values.border_width !== undefined) declarations.push(`border-width: ${values.border_width}px`);
            if (values.border_style !== undefined) declarations.push(`border-style: ${values.border_style}`);
            if (values.border_radius !== undefined) declarations.push(`border-radius: ${values.border_radius}px`);
            if (values.padding !== undefined) declarations.push(`padding: ${values.padding}px`);
            if (values.margin !== undefined) declarations.push(`margin: ${values.margin}px`);
            if (values.opacity !== undefined) declarations.push(`opacity: ${values.opacity / 100}`);
            if (values.animation !== undefined) declarations.push(`animation: nexus-profile-${values.animation} 1.8s ease-in-out infinite`);
            if (!declarations.length) return '';
            const selector = target === 'profile.page'
                ? '.profile-page[data-profile-style="profile.page"]'
                : `.profile-page [data-profile-style="${target}"]`;
            return `${selector} { ${declarations.join('; ')}; }`;
        }).filter(Boolean);
        rules.push(`.profile-page [data-profile-style="layout.root"] { --profile-sidebar-width: ${widthField.value}px; }`);
        ensureStyle('data-profile-simple-css').textContent = rules.join('\n');
    };
    const applyCss = (data) => {
        ensureStyle('data-profile-simple-css').textContent = data.simple_css || '';
        ensureStyle('data-profile-custom-css').textContent = data.custom_css || '';
    };
    const cssSelectorForTarget = (target) => ['profile.page', 'profile.sheet'].includes(target)
        ? '.profile-custom-content'
        : `[data-profile-style="${target}"]`;
    const cssTargetName = (target) => [...targetField.options].find((option) => option.value === target)?.textContent.trim() || target;
    const findCssRuleRange = (target) => {
        if (!target) return null;
        const markers = [cssSelectorForTarget(target), `[data-profile-style="${target}"]`];
        const markerMatches = markers.map((marker) => [marker, cssField.value.indexOf(marker)]).filter(([, index]) => index >= 0).sort((a, b) => a[1] - b[1]);
        const [marker, markerIndex] = markerMatches[0] || ['', -1];
        if (markerIndex < 0) return null;
        const openingBrace = cssField.value.indexOf('{', markerIndex + marker.length);
        if (openingBrace < 0) return null;
        const previousRuleEnd = cssField.value.lastIndexOf('}', markerIndex);
        let start = previousRuleEnd + 1;
        while (/\s/.test(cssField.value[start] || '')) start += 1;
        const endBrace = cssField.value.indexOf('}', openingBrace + 1);
        return [start, endBrace < 0 ? cssField.value.length : endBrace + 1];
    };
    const renderCssHighlight = (target = activeCssTarget, scrollToRule = false) => {
        if (!cssHighlight) return;
        const range = findCssRuleRange(target);
        const fragment = document.createDocumentFragment();
        if (range) {
            fragment.append(document.createTextNode(cssField.value.slice(0, range[0])));
            const mark = document.createElement('mark');
            mark.textContent = cssField.value.slice(range[0], range[1]);
            fragment.append(mark, document.createTextNode(cssField.value.slice(range[1]) + '\n'));
        } else {
            fragment.append(document.createTextNode(cssField.value + '\n'));
        }
        cssHighlight.replaceChildren(fragment);
        if (scrollToRule && range) {
            const line = cssField.value.slice(0, range[0]).split('\n').length - 1;
            cssField.scrollTop = Math.max(0, line * 15.95 - 24);
        }
        cssHighlight.scrollTop = cssField.scrollTop;
        cssHighlight.scrollLeft = cssField.scrollLeft;
        if (cssInsert) cssInsert.disabled = !target || Boolean(range);
    };
    const setCssTarget = (target, scrollToRule = true) => {
        activeCssTarget = target;
        if (cssTargetLabel) cssTargetLabel.textContent = target ? cssTargetName(target) : 'Hover over a profile part';
        if (cssTargetSelector) cssTargetSelector.textContent = target ? cssSelectorForTarget(target) : '';
        renderCssHighlight(target, scrollToRule);
    };
    const cssInspectorActive = () => Boolean(appearancePanel && !appearancePanel.hidden && !advancedForm.hidden);
    const syncSettings = () => {
        const serialized = JSON.stringify(settings);
        settingsField.value = serialized;
        advancedSettings.value = serialized;
        advancedWidth.value = widthField.value;
        simpleForm.elements.template_html.value = templateField.value;
        simpleForm.elements.custom_css.value = cssField.value;
    };
    const snapshot = () => ({
        settings: JSON.parse(JSON.stringify(settings)),
        width: widthField.value,
        template: templateField.value,
        css: cssField.value,
    });
    const snapshotSignature = (value) => JSON.stringify(value);
    const updateHistoryButtons = () => {
        undoButtons.forEach((button) => { button.disabled = undoStack.length === 0; });
        redoButtons.forEach((button) => { button.disabled = redoStack.length === 0; });
    };
    const remember = (key) => {
        const now = Date.now();
        if (baseline && (key !== lastHistoryKey || now - lastHistoryTime > 400)) {
            if (snapshotSignature(undoStack.at(-1)) !== snapshotSignature(baseline)) undoStack.push(baseline);
            if (undoStack.length > 50) undoStack.shift();
        }
        redoStack = [];
        lastHistoryKey = key;
        lastHistoryTime = now;
        updateHistoryButtons();
    };
    propertyFields.forEach((field) => { field.dataset.defaultValue = field.value; });
    const renderFields = () => {
        const values = settings[targetField.value] || {};
        propertyFields.forEach((field) => {
            const property = field.dataset.inlineStyleProperty;
            const value = values[property];
            if (field.type === 'color' || field.type === 'range') field.value = value ?? field.dataset.defaultValue;
            else field.value = value ?? (property === 'font_family' ? 'system' : property === 'animation' ? 'none' : '');
            field.closest('label')?.classList.toggle('is-customized', value !== undefined);
            const output = simpleForm.querySelector(`[data-inline-property-output="${property}"]`);
            if (output) output.textContent = value === undefined ? 'Default' : `${value}%`;
        });
        if (gradientPreview) {
            const hasGradient = values.text_gradient_from !== undefined && values.text_gradient_to !== undefined;
            const from = values.text_gradient_from ?? '#238bc5';
            const to = values.text_gradient_to ?? '#b51d6b';
            const angle = values.text_gradient_angle ?? 90;
            gradientPreview.classList.toggle('is-empty', !hasGradient);
            gradientPreview.style.backgroundImage = hasGradient ? `linear-gradient(${angle}deg, ${from}, ${to})` : '';
            gradientClear.disabled = !hasGradient;
        }
    };
    const updateContrastHighlights = () => {
        highlightOverlays.forEach((overlay, index) => {
            const element = highlightedElements[index];
            if (!element?.isConnected) {
                overlay.hidden = true;
                return;
            }
            const bounds = element.getBoundingClientRect();
            overlay.hidden = bounds.width <= 0 || bounds.height <= 0 || bounds.bottom < 0 || bounds.top > window.innerHeight || bounds.right < 0 || bounds.left > window.innerWidth;
            overlay.style.left = `${bounds.left - 2}px`;
            overlay.style.top = `${bounds.top - 2}px`;
            overlay.style.width = `${bounds.width + 4}px`;
            overlay.style.height = `${bounds.height + 4}px`;
        });
    };
    const showContrastHighlights = (elements) => {
        highlightedElements = [...elements];
        while (highlightOverlays.length < highlightedElements.length) {
            const overlay = document.createElement('div');
            overlay.className = 'profile-style-contrast-highlight';
            overlay.setAttribute('aria-hidden', 'true');
            document.body.append(overlay);
            highlightOverlays.push(overlay);
        }
        highlightOverlays.forEach((overlay, index) => { overlay.hidden = index >= highlightedElements.length; });
        updateContrastHighlights();
    };
    const clearTargetHighlights = () => {
        document.querySelectorAll('.is-profile-style-hovered, .is-profile-style-selected').forEach((element) => element.classList.remove('is-profile-style-hovered', 'is-profile-style-selected'));
        hoveredTarget = null;
        highlightedElements = [];
        highlightOverlays.forEach((overlay) => { overlay.hidden = true; });
    };
    const highlightSelectedTarget = () => {
        clearTargetHighlights();
        if (!picking || simpleForm.hidden) return;
        const selectedElements = [...document.querySelectorAll(`[data-profile-style="${targetField.value}"]`)].filter((element) => {
            if (!element.closest('[data-profile-appearance-panel]') && !element.matches('[data-profile-sidebar-resize]')) element.classList.add('is-profile-style-selected');
            return !element.closest('[data-profile-appearance-panel]') && !element.matches('[data-profile-sidebar-resize]');
        });
        showContrastHighlights(selectedElements);
    };
    const setPicking = (active) => {
        picking = active;
        document.body.classList.toggle('is-picking-profile-style', active);
        if (!active) clearTargetHighlights();
    };
    const syncPicking = () => {
        setPicking(Boolean(appearancePanel && !appearancePanel.hidden && !simpleForm.hidden));
        if (picking) highlightSelectedTarget();
    };
    const setProperty = (property, value) => {
        const target = targetField.value;
        settings[target] ||= {};
        if (value === '' || value === 'system' || value === 'none') delete settings[target][property];
        else settings[target][property] = value;
        if (property === 'text_color' && value !== '') {
            delete settings[target].text_gradient_from;
            delete settings[target].text_gradient_to;
            delete settings[target].text_gradient_angle;
        }
        if ((property === 'text_gradient_from' || property === 'text_gradient_to') && value !== '') delete settings[target].text_color;
        if (property === 'border_color' && value !== '') {
            settings[target].border_width ??= 1;
            settings[target].border_style ??= 'solid';
        }
        if (property === 'border_width' && Number(value) > 0) settings[target].border_style ??= 'solid';
        if (!Object.keys(settings[target]).length) delete settings[target];
        syncSettings();
        renderLocalSimpleCss();
    };

    const request = async (form, action, status) => {
        const body = new FormData(form);
        body.set('action', action);
        const response = await fetch(form.action, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: action === 'draft' ? draftRequest.signal : undefined });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.saved) throw new Error(data.error || 'Your customization could not be saved.');
        applyCss(data);
        const warnings = form === advancedForm
            ? [...(data.warnings?.css || [])]
            : [];
        status.textContent = warnings[0] || (action === 'draft' ? 'Previewing draft' : 'Saved');
        status.classList.toggle('is-error', warnings.length > 0);
        return data;
    };
    const scheduleDraft = (form = simpleForm, status = simpleStatus) => {
        window.clearTimeout(draftTimer);
        draftRequest?.abort();
        status.textContent = 'Updating preview...';
        draftTimer = window.setTimeout(async () => {
            draftRequest = new AbortController();
            try { await request(form, 'draft', status); }
            catch (error) { if (error.name !== 'AbortError') status.textContent = error.message; }
        }, 220);
    };

    propertyFields.forEach((field) => field.addEventListener('input', () => {
        const property = field.dataset.inlineStyleProperty;
        remember(`simple:${property}`);
        const value = field.type === 'number' || field.type === 'range' ? (field.value === '' ? '' : Number(field.value)) : field.value;
        setProperty(property, value);
        if (property === 'text_gradient_from' || property === 'text_gradient_to') {
            const values = settings[targetField.value] ||= {};
            values.text_gradient_from ??= '#238bc5';
            values.text_gradient_to ??= '#b51d6b';
            values.text_gradient_angle ??= 90;
            syncSettings();
            renderLocalSimpleCss();
        }
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    }));
    gradientSwap?.addEventListener('click', () => {
        remember('gradient-swap');
        const values = settings[targetField.value] ||= {};
        const from = values.text_gradient_from ?? '#238bc5';
        values.text_gradient_from = values.text_gradient_to ?? '#b51d6b';
        values.text_gradient_to = from;
        values.text_gradient_angle ??= 90;
        delete values.text_color;
        syncSettings();
        renderLocalSimpleCss();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    gradientClear?.addEventListener('click', () => {
        remember('gradient-clear');
        const values = settings[targetField.value];
        if (!values) return;
        delete values.text_gradient_from;
        delete values.text_gradient_to;
        delete values.text_gradient_angle;
        if (!Object.keys(values).length) delete settings[targetField.value];
        syncSettings();
        renderLocalSimpleCss();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    simpleForm.querySelectorAll('[data-inline-clear-property]').forEach((button) => button.addEventListener('click', () => {
        remember(`clear:${button.dataset.inlineClearProperty}`);
        setProperty(button.dataset.inlineClearProperty, '');
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    }));
    simpleForm.querySelector('[data-inline-reset-target]')?.addEventListener('click', () => {
        remember('reset-target');
        delete settings[targetField.value];
        syncSettings();
        renderLocalSimpleCss();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    simpleForm.querySelector('[data-inline-reset-all]')?.addEventListener('click', () => {
        remember('reset-all');
        settings = {};
        syncSettings();
        renderLocalSimpleCss();
        renderFields();
        baseline = snapshot();
        scheduleDraft();
    });
    targetField.addEventListener('change', () => {
        renderFields();
        highlightSelectedTarget();
    });
    tabs.forEach((tab) => tab.addEventListener('click', syncPicking));
    tabs.forEach((tab) => tab.addEventListener('click', () => {
        if (tab.dataset.profileAppearanceTab === 'advanced') {
            selectedCssElement?.classList.add('is-profile-style-selected');
            if (selectedCssElement) showContrastHighlights([selectedCssElement]);
            setCssTarget(selectedCssTarget || activeCssTarget, false);
        } else {
            selectedCssElement?.classList.remove('is-profile-style-selected');
            showContrastHighlights([]);
            if (cssHoverLabel) cssHoverLabel.hidden = true;
        }
    }));
    if (appearancePanel) new MutationObserver(syncPicking).observe(appearancePanel, { attributes: true, attributeFilter: ['hidden'] });
    if (appearancePanel) new MutationObserver(() => {
        if (!appearancePanel.hidden) return;
        selectedCssElement?.classList.remove('is-profile-style-selected');
        hoveredTarget?.classList.remove('is-profile-style-hovered');
        showContrastHighlights([]);
        if (cssHoverLabel) cssHoverLabel.hidden = true;
    }).observe(appearancePanel, { attributes: true, attributeFilter: ['hidden'] });
    document.addEventListener('mouseover', (event) => {
        if (!picking) return;
        if (event.target.closest?.('[data-profile-appearance-panel]')) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        if (!candidate || candidate.matches('[data-profile-sidebar-resize]')) return;
        if (hoveredTarget !== candidate) {
            hoveredTarget?.classList.remove('is-profile-style-hovered');
            hoveredTarget = candidate;
            hoveredTarget.classList.add('is-profile-style-hovered');
            showContrastHighlights([candidate]);
        }
    }, true);
    document.addEventListener('mouseout', (event) => {
        if (!picking || !hoveredTarget || hoveredTarget.contains(event.relatedTarget)) return;
        hoveredTarget.classList.remove('is-profile-style-hovered');
        hoveredTarget = null;
        highlightSelectedTarget();
    }, true);
    document.addEventListener('click', (event) => {
        if (!picking) return;
        if (event.target.closest?.('[data-profile-appearance-panel]')) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        const target = candidate?.dataset.profileStyle;
        if (!candidate || !target || candidate.matches('[data-profile-sidebar-resize]')) return;
        if (![...targetField.options].some((option) => option.value === target)) return;
        event.preventDefault();
        event.stopPropagation();
        window.getSelection()?.removeAllRanges();
        targetField.value = target;
        renderFields();
        highlightSelectedTarget();
        simpleForm.querySelector('.profile-inline-target-picker')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, true);
    document.addEventListener('mouseover', (event) => {
        if (!cssInspectorActive() || event.target.closest?.('[data-profile-appearance-panel]')) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        const target = candidate?.dataset.profileStyle;
        if (!candidate || !target || candidate.matches('[data-profile-sidebar-resize]')) return;
        hoveredTarget?.classList.remove('is-profile-style-hovered');
        hoveredTarget = candidate;
        hoveredTarget.classList.add('is-profile-style-hovered');
        showContrastHighlights([candidate]);
        setCssTarget(target);
        if (cssHoverLabel) {
            cssHoverLabel.textContent = cssTargetName(target);
            cssHoverLabel.hidden = false;
        }
    }, true);
    document.addEventListener('click', (event) => {
        if (!cssInspectorActive() || event.target.closest?.('[data-profile-appearance-panel]')) return;
        const candidate = event.target.closest?.('[data-profile-style]');
        const target = candidate?.dataset.profileStyle;
        if (!candidate || !target || candidate.matches('[data-profile-sidebar-resize]')) return;
        event.preventDefault();
        event.stopPropagation();
        window.getSelection()?.removeAllRanges();
        selectedCssElement?.classList.remove('is-profile-style-selected');
        selectedCssElement = candidate;
        selectedCssTarget = target;
        selectedCssElement.classList.add('is-profile-style-selected');
        showContrastHighlights([selectedCssElement]);
        setCssTarget(target, true);
    }, true);
    document.addEventListener('mousemove', (event) => {
        if (!cssInspectorActive() || !cssHoverLabel || cssHoverLabel.hidden) return;
        cssHoverLabel.style.left = `${Math.min(window.innerWidth - cssHoverLabel.offsetWidth - 8, event.clientX + 12)}px`;
        cssHoverLabel.style.top = `${Math.min(window.innerHeight - cssHoverLabel.offsetHeight - 8, event.clientY + 12)}px`;
    }, true);
    document.addEventListener('mouseout', (event) => {
        if (!cssInspectorActive() || !hoveredTarget || hoveredTarget.contains(event.relatedTarget)) return;
        hoveredTarget.classList.remove('is-profile-style-hovered');
        hoveredTarget = null;
        if (cssHoverLabel) cssHoverLabel.hidden = true;
        if (selectedCssTarget) {
            showContrastHighlights([selectedCssElement]);
            setCssTarget(selectedCssTarget, false);
        } else {
            showContrastHighlights([]);
        }
    }, true);
    window.addEventListener('resize', updateContrastHighlights);
    document.addEventListener('scroll', updateContrastHighlights, true);
    cssInsert?.addEventListener('click', () => {
        if (!activeCssTarget || findCssRuleRange(activeCssTarget)) return;
        const prefix = cssField.value.trimEnd();
        const rule = `${cssSelectorForTarget(activeCssTarget)} {\n    \n}`;
        cssField.value = `${prefix}${prefix ? '\n\n' : ''}${rule}\n`;
        const caret = cssField.value.indexOf('\n    \n', cssField.value.length - rule.length - 2) + 5;
        cssField.dispatchEvent(new Event('input', { bubbles: true }));
        cssField.focus();
        cssField.setSelectionRange(caret, caret);
        renderCssHighlight(activeCssTarget, true);
    });
    cssField.addEventListener('input', () => {
        renderCssHighlight(activeCssTarget);
        if (removeWallpaperField) {
            const currentWallpaperCss = wallpaperCssSignature(cssField.value);
            removeWallpaperField.value = currentWallpaperCss !== '' && currentWallpaperCss !== initialWallpaperCss ? '1' : '0';
        }
    });
    cssField.addEventListener('scroll', () => {
        if (!cssHighlight) return;
        cssHighlight.scrollTop = cssField.scrollTop;
        cssHighlight.scrollLeft = cssField.scrollLeft;
    });
    widthControl?.addEventListener('input', () => {
        remember('sidebar-width');
        widthField.value = widthControl.value;
        if (widthOutput) widthOutput.textContent = `${widthControl.value}px`;
        document.querySelector('[data-profile-style="layout.root"]')?.style.setProperty('--profile-sidebar-width', `${widthControl.value}px`);
        syncSettings();
        renderLocalSimpleCss();
        baseline = snapshot();
        scheduleDraft();
    });
    simpleForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        syncSettings();
        simpleStatus.textContent = 'Saving...';
        try {
            await request(simpleForm, 'save', simpleStatus);
            document.querySelector('[data-profile-appearance-close]')?.click();
        }
        catch (error) { simpleStatus.textContent = error.message; }
    });
    advancedForm.addEventListener('input', (event) => {
        remember(`advanced:${event.target.name || event.target.id}`);
        syncSettings();
        baseline = snapshot();
        scheduleDraft(advancedForm, advancedStatus);
    });
    advancedForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        advancedStatus.textContent = 'Saving...';
        try {
            await request(advancedForm, 'save', advancedStatus);
            window.location.reload();
        } catch (error) { advancedStatus.textContent = error.message; }
    });

    const applySnapshot = (value) => {
        settings = JSON.parse(JSON.stringify(value.settings));
        widthField.value = value.width;
        if (widthControl) widthControl.value = value.width;
        if (widthOutput) widthOutput.textContent = `${value.width}px`;
        templateField.value = value.template;
        cssField.value = value.css;
        document.querySelector('[data-profile-style="layout.root"]')?.style.setProperty('--profile-sidebar-width', `${value.width}px`);
        syncSettings();
        renderLocalSimpleCss();
        renderFields();
        baseline = snapshot();
        lastHistoryKey = '';
        updateHistoryButtons();
        scheduleDraft();
    };
    undoButtons.forEach((button) => button.addEventListener('click', () => {
        const previous = undoStack.pop();
        if (!previous) return;
        redoStack.push(snapshot());
        applySnapshot(previous);
    }));
    redoButtons.forEach((button) => button.addEventListener('click', () => {
        const next = redoStack.pop();
        if (!next) return;
        undoStack.push(snapshot());
        applySnapshot(next);
    }));
    syncSettings();
    renderFields();
    renderCssHighlight();
    syncPicking();
    baseline = snapshot();
    updateHistoryButtons();
});
