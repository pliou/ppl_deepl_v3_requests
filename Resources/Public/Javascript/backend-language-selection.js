(function () {
    'use strict';

    function getElementTarget(event) {
        var target = event.target;

        if (target && target.nodeType === 1) {
            return target;
        }

        return target && target.parentElement ? target.parentElement : null;
    }

    function closestLanguageSelectionButton(target) {
        while (target && target !== document) {
            if (target.getAttribute && target.getAttribute('data-language-selection')) {
                return target;
            }

            target = target.parentElement;
        }

        return null;
    }

    function dispatchCheckboxEvents(checkbox) {
        ['input', 'change'].forEach(function (eventName) {
            var event;

            if (typeof Event === 'function') {
                event = new Event(eventName, { bubbles: true });
            } else {
                event = document.createEvent('Event');
                event.initEvent(eventName, true, false);
            }

            checkbox.dispatchEvent(event);
        });
    }

    function updateLanguageSelection(button) {
        var form = button.form || button.closest('form');
        var checked = button.getAttribute('data-language-selection') === 'all';

        if (!form) {
            return;
        }

        Array.prototype.forEach.call(form.querySelectorAll('input'), function (input) {
            if (input.type !== 'checkbox' || input.name !== 'enabled_languages[]') {
                return;
            }

            input.checked = checked;
            dispatchCheckboxEvents(input);
        });
    }

    document.addEventListener('click', function (event) {
        var button = closestLanguageSelectionButton(getElementTarget(event));

        if (!button) {
            return;
        }

        event.preventDefault();
        updateLanguageSelection(button);
    }, true);
})();
