(function () {
    'use strict';

    var storageKey = 'pplDeeplV3BackendScroll';

    function readJsonElementText(element) {
        if (!element) {
            return '';
        }

        if (element.content && typeof element.content.textContent === 'string') {
            return element.content.textContent || '';
        }

        return element.textContent || '';
    }

    function readBackendData() {
        var element = document.getElementById('pplDeeplV3BackendData');
        try {
            return JSON.parse(readJsonElementText(element) || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function getLabels(data) {
        return data.labels || {};
    }

    function getLabel(labels, name) {
        return labels[name] || '';
    }

    function normalizeGlossaryLanguage(language) {
        var normalizedLanguage = String(language || '').toUpperCase();

        if (normalizedLanguage === 'DE-DE') {
            return 'DE';
        }

        if (normalizedLanguage === 'EN-GB' || normalizedLanguage === 'EN-US') {
            return 'EN';
        }

        if (normalizedLanguage.indexOf('ES-') === 0) {
            return 'ES';
        }

        if (normalizedLanguage === 'PT-PT' || normalizedLanguage === 'PT-BR') {
            return 'PT';
        }

        if (normalizedLanguage === 'ZH-HANS' || normalizedLanguage === 'ZH-HANT') {
            return 'ZH';
        }

        return normalizedLanguage;
    }

    function normalizeStyleRuleLanguage(language) {
        var normalizedLanguage = String(language || '').toUpperCase();

        if (normalizedLanguage.indexOf('EN') === 0) {
            return 'EN';
        }

        if (normalizedLanguage === 'DE' || normalizedLanguage === 'DE-DE') {
            return 'DE';
        }

        if (normalizedLanguage.indexOf('ES') === 0) {
            return 'ES';
        }

        if (normalizedLanguage.indexOf('FR') === 0) {
            return 'FR';
        }

        if (normalizedLanguage.indexOf('IT') === 0) {
            return 'IT';
        }

        if (normalizedLanguage.indexOf('JA') === 0) {
            return 'JA';
        }

        if (normalizedLanguage.indexOf('KO') === 0) {
            return 'KO';
        }

        if (normalizedLanguage.indexOf('ZH') === 0) {
            return 'ZH';
        }

        return normalizedLanguage.split('-')[0] || normalizedLanguage;
    }

    function isSameLanguageValue(sourceLanguage, targetLanguage) {
        return sourceLanguage !== ''
            && targetLanguage !== ''
            && normalizeGlossaryLanguage(sourceLanguage) === normalizeGlossaryLanguage(targetLanguage);
    }

    function updateTargetLanguageAvailability(source, target) {
        var selectedValue;
        var fallbackValue = '';

        if (!source || !target) {
            return;
        }

        selectedValue = target.value;
        Array.prototype.forEach.call(target.options, function (option) {
            var blocked = isSameLanguageValue(source.value, option.value);
            option.disabled = blocked;
            option.hidden = blocked;
            if (blocked) {
                option.setAttribute('aria-disabled', 'true');
            } else {
                option.removeAttribute('aria-disabled');
                if (option.value !== '' && fallbackValue === '') {
                    fallbackValue = option.value;
                }
            }
        });

        if (isSameLanguageValue(source.value, selectedValue) && fallbackValue !== '') {
            target.value = fallbackValue;
        }
    }

    function updateLanguageGuard(form, labels) {
        var source = form.querySelector('[data-role="deepl-source"]');
        var target = form.querySelector('[data-role="deepl-target"]');
        var submit = form.querySelector('[data-role="deepl-submit"]');
        var warning = form.querySelector('[data-role="deepl-language-warning"]');
        var message = getLabel(labels, 'sameLanguage');
        var sameLanguage;

        if (!source || !target || !submit) {
            return true;
        }

        updateTargetLanguageAvailability(source, target);
        sameLanguage = isSameLanguageValue(source.value, target.value);

        submit.disabled = sameLanguage;
        submit.title = sameLanguage ? message : '';
        source.setCustomValidity(sameLanguage ? message : '');
        target.setCustomValidity(sameLanguage ? message : '');

        if (warning) {
            warning.hidden = !sameLanguage;
            if (message !== '') {
                warning.textContent = message;
            }
        }

        return !sameLanguage;
    }

    function updateGlossaryOptions(form, data, labels) {
        var source = form.querySelector('[data-role="deepl-source"]');
        var target = form.querySelector('[data-role="deepl-target"]');
        var glossary = form.querySelector('[data-role="deepl-glossary-select"]');
        var label = form.querySelector('[data-role="deepl-glossary-label"]');
        var optionsByCombination = data.glossaryOptionsByCombination || {};
        var key;
        var options;
        var selectedValue;
        var ids;
        var emptyOption;

        if (!source || !target || !glossary) {
            return;
        }

        key = normalizeGlossaryLanguage(source.value) + ':' + normalizeGlossaryLanguage(target.value);
        options = optionsByCombination[key] || {};
        selectedValue = glossary.value;
        ids = Object.keys(options);
        glossary.innerHTML = '';

        emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = getLabel(labels, 'noGlossary');
        glossary.appendChild(emptyOption);

        ids.forEach(function (id) {
            var option = document.createElement('option');
            option.value = id;
            option.textContent = options[id];
            if (id === selectedValue) {
                option.selected = true;
            }
            glossary.appendChild(option);
        });

        if (!Object.prototype.hasOwnProperty.call(options, selectedValue)) {
            glossary.value = ids[0] || '';
        }

        glossary.disabled = ids.length === 0;

        if (label) {
            label.textContent = ids.length > 0
                ? getLabel(labels, 'glossaryAvailable')
                : getLabel(labels, 'noGlossaryApproved');
        }
    }

    function updateStyleRuleOptions(form, data, labels) {
        var target = form.querySelector('[data-role="deepl-target"]');
        var styleRule = form.querySelector('[data-role="deepl-style-rule-select"]');
        var label = form.querySelector('[data-role="deepl-style-rule-label"]');
        var optionsByLanguage = data.styleRuleOptionsByLanguage || {};
        var allOptions = data.styleRuleOptions || {};
        var language;
        var options;
        var selectedValue;
        var optionIds;
        var emptyOption;

        if (!target || !styleRule) {
            return;
        }

        language = normalizeStyleRuleLanguage(target.value);
        options = language !== '' && optionsByLanguage[language] ? optionsByLanguage[language] : {};
        selectedValue = styleRule.value;
        optionIds = Object.keys(options);
        styleRule.innerHTML = '';

        emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = getLabel(labels, 'disabled');
        styleRule.appendChild(emptyOption);

        optionIds.forEach(function (id) {
            var option = document.createElement('option');
            option.value = id;
            option.textContent = options[id];
            if (id === selectedValue) {
                option.selected = true;
            }
            styleRule.appendChild(option);
        });

        if (!Object.prototype.hasOwnProperty.call(options, selectedValue)) {
            styleRule.value = '';
        }

        styleRule.disabled = optionIds.length === 0;

        if (label) {
            label.textContent = optionIds.length > 0
                ? getLabel(labels, 'styleRuleAvailable')
                : (Object.keys(allOptions).length > 0 ? getLabel(labels, 'noStyleRuleForTarget') : getLabel(labels, 'noStyleRule'));
        }
    }

    function clearServerErrors(form) {
        var container = form.closest('.ppl-deepl-v3__card-body') || form.parentElement || form;
        container.querySelectorAll('[data-role="deepl-server-error"]').forEach(function (message) {
            message.hidden = true;
        });
    }

    function saveScrollState(form, submitter) {
        var anchor = submitter && submitter.getAttribute
            ? submitter.getAttribute('data-scroll-anchor') || submitter.value || submitter.name || submitter.id || ''
            : '';
        var tab = form.querySelector('[name="config_tab"], [name="active_tab"]');

        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify({
                href: window.location.pathname + window.location.search,
                scrollY: window.scrollY || window.pageYOffset || 0,
                anchor: anchor,
                tab: tab ? tab.value : ''
            }));
        } catch (error) {
            // Session storage can be unavailable in hardened browser contexts.
        }
    }

    function restoreScrollState() {
        var state;
        var target = null;

        try {
            state = JSON.parse(window.sessionStorage.getItem(storageKey) || '{}');
        } catch (error) {
            state = {};
        }

        if (!state || state.href !== window.location.pathname + window.location.search) {
            return;
        }

        if (state.anchor && window.CSS && typeof window.CSS.escape === 'function') {
            target = document.querySelector('[data-scroll-anchor="' + window.CSS.escape(state.anchor) + '"], [name="' + window.CSS.escape(state.anchor) + '"], #' + window.CSS.escape(state.anchor));
        }

        if (target && target.scrollIntoView) {
            target.scrollIntoView({block: 'center'});
            return;
        }

        if (typeof state.scrollY === 'number') {
            window.scrollTo(0, state.scrollY);
        }
    }

    function updateForm(form, data, labels) {
        updateGlossaryOptions(form, data, labels);
        updateStyleRuleOptions(form, data, labels);
        updateLanguageGuard(form, labels);
    }

    function initialize() {
        var data = readBackendData();
        var labels = getLabels(data);

        document.querySelectorAll('#pplDeeplTextForm, #pplDeeplFileForm').forEach(function (form) {
            updateForm(form, data, labels);

            form.addEventListener('submit', function (event) {
                if (!updateLanguageGuard(form, labels)) {
                    event.preventDefault();
                    clearServerErrors(form);
                    form.querySelector('[data-role="deepl-target"]').reportValidity();
                    form.querySelector('[data-role="deepl-target"]').focus();
                    return;
                }

                saveScrollState(form, event.submitter || document.activeElement);
            });

            form.querySelectorAll('[data-role="deepl-source"], [data-role="deepl-target"]').forEach(function (select) {
                select.addEventListener('change', function () {
                    clearServerErrors(form);
                    updateForm(form, data, labels);
                });
            });

            form.querySelectorAll('[data-role="deepl-glossary-select"], [data-role="deepl-style-rule-select"], textarea, input[type="file"]').forEach(function (field) {
                field.addEventListener('change', function () {
                    clearServerErrors(form);
                });
                field.addEventListener('input', function () {
                    clearServerErrors(form);
                });
            });
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form && form.closest && form.closest('.ppl-deepl-v3')) {
                saveScrollState(form, event.submitter || document.activeElement);
            }
        }, true);

        document.querySelectorAll('.ppl-deepl-v3__tab').forEach(function (link) {
            link.addEventListener('click', function () {
                try {
                    window.sessionStorage.setItem(storageKey, JSON.stringify({
                        href: link.pathname + link.search,
                        scrollY: 0,
                        anchor: link.getAttribute('data-scroll-anchor') || ''
                    }));
                } catch (error) {
                    // Session storage can be unavailable in hardened browser contexts.
                }
            });
        });

        restoreScrollState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
}());
