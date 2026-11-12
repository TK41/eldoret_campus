// Student equipment picker, pickup calendar, and booking summary.
(function () {
    var root = document.getElementById('kit-builder');
    if (!root) return;

    var api = root.dataset.api;
    var maxItems = parseInt(root.dataset.maxItems, 10) || 10;
    var canKit = root.dataset.canKit === '1';
    var form = root.closest('form');
    var pickup = document.getElementById('pickup');
    var pickupDate = document.getElementById('pickup-date');
    var pickupTime = document.getElementById('pickup_time');
    var pickupDisplay = document.getElementById('pickup-display');
    var picker = document.getElementById('pickup-picker');
    var calendar = document.getElementById('pickup-calendar');
    var calendarDays = document.getElementById('calendar-days');
    var calendarMonth = document.getElementById('calendar-month');
    var search = document.getElementById('item-search');
    var listbox = document.getElementById('item-results');
    var picked = document.getElementById('picked-items');
    var inputs = document.getElementById('picked-inputs');
    var status = document.getElementById('search-status');
    var counter = document.getElementById('item-count');
    var kitName = document.getElementById('kit_name');
    var purpose = document.getElementById('purpose');
    var dateTouched = false;
    var timeTouched = false;
    var kitTouched = false;
    var purposeTouched = false;
    var selectionTouched = false;
    var submitAttempted = false;
    var selectedDate = '';
    var activeMonth = '';
    var controller = null;
    var results = [];
    var shown = [];
    var active = -1;
    var chosen = new Map();
    var restored = {};
    var restoredLoaded = false;
    var availabilityLoading = false;
    var allowedDates = [];
    var allowedDateSet = {};
    var allowedMonths = [];
    var typeFilter = 'All';
    var chipsBox = document.getElementById('type-chips');
    var initialErrors = {};

    try {
        allowedDates = JSON.parse(picker.dataset.allowedDates || '[]');
        restored = JSON.parse(root.dataset.selected || '{}');
    } catch (error) {
        allowedDates = [];
        restored = {};
    }
    allowedDates.forEach(function (date) {
        allowedDateSet[date] = true;
        var month = date.slice(0, 7);
        if (allowedMonths.indexOf(month) === -1) allowedMonths.push(month);
    });
    allowedMonths.sort();
    ['kit-name-error', 'pickup-error', 'items-error', 'purpose-error'].forEach(function (id) {
        var node = document.getElementById(id);
        initialErrors[id] = node && !node.hidden ? node.querySelector('span').textContent : '';
    });

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function setError(id, message) {
        var node = document.getElementById(id);
        if (!node) return;
        var text = node.querySelector('span');
        if (text) text.textContent = message || '';
        node.hidden = !message;
        var controls = {
            'kit-name-error': 'kit_name',
            'pickup-error': 'pickup-display',
            'pickup-time-error': 'pickup_time',
            'items-error': 'item-search',
            'purpose-error': 'purpose'
        };
        var control = document.getElementById(controls[id]);
        if (control) {
            if (message) control.setAttribute('aria-invalid', 'true');
            else control.removeAttribute('aria-invalid');
        }
    }

    function totalQty() {
        var total = 0;
        chosen.forEach(function (item) { total += item.qty; });
        return total;
    }

    function selectionsAvailable() {
        var available = true;
        chosen.forEach(function (item) {
            if (item.qty > item.free) available = false;
        });
        if (totalQty() > maxItems) available = false;
        if (!canKit && totalQty() > 1) available = false;
        return available;
    }

    function updatePickup() {
        pickupDate.value = selectedDate;
        pickupDisplay.value = selectedDate ? formatDate(selectedDate) : '';
        pickup.value = selectedDate && pickupTime.value ? selectedDate + 'T' + pickupTime.value : '';
        updateSummary();
        loadItems();
    }

    function parseDate(value) {
        var parts = value.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]), 12);
    }

    function toIsoDate(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function formatDate(value) {
        if (!value) return '';
        return parseDate(value).toLocaleDateString('en-KE', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            year: 'numeric'
        });
    }

    function monthLabel(value) {
        var parts = value.split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, 1, 12).toLocaleDateString('en-KE', {
            month: 'long',
            year: 'numeric'
        });
    }

    function closeCalendar(returnFocus) {
        calendar.hidden = true;
        pickupDisplay.setAttribute('aria-expanded', 'false');
        if (returnFocus) pickupDisplay.focus();
    }

    function renderCalendar() {
        if (!allowedMonths.length) return;
        calendarMonth.textContent = monthLabel(activeMonth);
        calendarDays.textContent = '';
        var parts = activeMonth.split('-');
        var year = Number(parts[0]);
        var month = Number(parts[1]) - 1;
        var first = new Date(year, month, 1, 12);
        var offset = (first.getDay() + 6) % 7;
        var daysInMonth = new Date(year, month + 1, 0, 12).getDate();

        for (var blank = 0; blank < offset; blank++) {
            calendarDays.appendChild(el('span'));
        }
        for (var day = 1; day <= daysInMonth; day++) {
            var value = toIsoDate(new Date(year, month, day, 12));
            var cell = el('div');
            cell.setAttribute('role', 'gridcell');
            var button = el('button', 'calendar-day', String(day));
            button.type = 'button';
            button.disabled = !allowedDateSet[value];
            button.setAttribute('aria-label', parseDate(value).toLocaleDateString('en-KE', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            }));
            button.setAttribute('aria-pressed', value === selectedDate ? 'true' : 'false');
            if (value === selectedDate) button.setAttribute('aria-current', 'date');
            button.addEventListener('click', function (dateValue) {
                return function () {
                    selectedDate = dateValue;
                    dateTouched = true;
                    closeCalendar(true);
                    updatePickup();
                    validateForm();
                    setError('pickup-error', pickup.value ? '' : 'Choose a pickup time slot.');
                };
            }(value));
            cell.appendChild(button);
            calendarDays.appendChild(cell);
        }

        var monthIndex = allowedMonths.indexOf(activeMonth);
        document.getElementById('calendar-prev').disabled = monthIndex <= 0;
        document.getElementById('calendar-next').disabled = monthIndex < 0 || monthIndex >= allowedMonths.length - 1;
    }

    function openCalendar() {
        if (!allowedMonths.length) return;
        if (!activeMonth) activeMonth = selectedDate ? selectedDate.slice(0, 7) : allowedMonths[0];
        calendar.hidden = false;
        pickupDisplay.setAttribute('aria-expanded', 'true');
        renderCalendar();
        var selected = calendarDays.querySelector('[aria-current="date"]:not(:disabled)');
        var firstAvailable = calendarDays.querySelector('.calendar-day:not(:disabled)');
        (selected || firstAvailable || document.getElementById('calendar-prev')).focus();
    }

    document.getElementById('pickup-calendar-toggle').addEventListener('click', openCalendar);
    pickupDisplay.addEventListener('click', openCalendar);
    pickupDisplay.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openCalendar();
        }
    });
    document.getElementById('calendar-prev').addEventListener('click', function () {
        var index = allowedMonths.indexOf(activeMonth);
        if (index > 0) activeMonth = allowedMonths[index - 1];
        renderCalendar();
        document.getElementById('calendar-prev').focus();
    });
    document.getElementById('calendar-next').addEventListener('click', function () {
        var index = allowedMonths.indexOf(activeMonth);
        if (index >= 0 && index < allowedMonths.length - 1) activeMonth = allowedMonths[index + 1];
        renderCalendar();
        document.getElementById('calendar-next').focus();
    });
    document.addEventListener('click', function (event) {
        if (!picker.contains(event.target) && !calendar.hidden) closeCalendar(false);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !calendar.hidden) closeCalendar(true);
    });

    function freeLeft(item) {
        return item.free - ((chosen.get(item.key) || {}).qty || 0);
    }

    function closeList() {
        listbox.hidden = true;
        search.setAttribute('aria-expanded', 'false');
        search.removeAttribute('aria-activedescendant');
        active = -1;
    }

    function highlight(index) {
        var options = listbox.querySelectorAll('[role="option"]');
        options.forEach(function (option) { option.setAttribute('aria-selected', 'false'); });
        active = index;
        if (index >= 0 && options[index]) {
            options[index].setAttribute('aria-selected', 'true');
            options[index].scrollIntoView({ block: 'nearest' });
            search.setAttribute('aria-activedescendant', options[index].id);
        }
    }

    var typeRules = [
        ['Cameras', /camera|dslr|mirrorless|camcorder|gopro|canon|nikon|sony|blackmagic|\bcam\b/],
        ['Lenses', /lens|mm\b/],
        ['Audio', /mic|audio|sound|recorder|boom|headphone|mixer|speaker|lavalier|zoom h/],
        ['Lighting', /light|led|softbox|flash|reflector|diffuser|strobe/],
        ['Support', /tripod|gimbal|stabili|slider|monopod|rig|dolly|stand|crane/],
        ['Power & storage', /battery|charger|memory|card|ssd|drive|cable|adapter/]
    ];

    function typeOf(item) {
        var haystack = (item.name + ' ' + (item.detail || '')).toLowerCase();
        for (var i = 0; i < typeRules.length; i++) {
            if (typeRules[i][1].test(haystack)) return typeRules[i][0];
        }
        return 'Other';
    }

    function filterItems() {
        var words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
        shown = results.filter(function (item) {
            var haystack = (item.name + ' ' + (item.detail || '')).toLowerCase();
            return words.every(function (word) { return haystack.indexOf(word) !== -1; })
                && (typeFilter === 'All' || typeOf(item) === typeFilter);
        });
    }

    function choose(item) {
        if (!item) return;
        var existing = chosen.get(item.key);
        var quantity = existing ? existing.qty : 0;
        if (quantity >= item.free) {
            status.textContent = 'No more of "' + item.name + '" are available for that time.';
            return;
        }
        if (totalQty() >= maxItems) {
            status.textContent = 'A kit can hold at most ' + maxItems + ' items.';
            return;
        }
        if (!canKit && totalQty() >= 1) {
            status.textContent = 'Your borrowing tier allows one item per booking.';
            return;
        }
        selectionTouched = true;
        chosen.set(item.key, {
            key: item.key,
            name: item.name,
            detail: item.detail,
            qty: quantity + 1,
            free: item.free
        });
        status.textContent = '';
        renderPicked();
        search.value = '';
        closeList();
        search.focus();
    }

    function renderResults() {
        filterItems();
        listbox.textContent = '';
        if (!shown.length) {
            var none = el('li', 'opt', results.length ? 'No matching equipment.' : 'No equipment available.');
            none.setAttribute('aria-disabled', 'true');
            listbox.appendChild(none);
        }
        shown.forEach(function (item, index) {
            var option = el('li', 'opt');
            option.id = 'opt-' + index;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            var left = el('div');
            left.appendChild(el('div', 'nm', item.name));
            if (item.detail) left.appendChild(el('div', 'dt', item.detail));
            var free = freeLeft(item);
            var badge = el('span', 'tag' + (free > 0 ? '' : ' none'),
                free > 0 ? free + ' of ' + item.total + ' available' : 'None available');
            option.appendChild(left);
            option.appendChild(badge);
            if (free <= 0) option.setAttribute('aria-disabled', 'true');
            option.addEventListener('click', function () { if (free > 0) choose(item); });
            listbox.appendChild(option);
        });
        listbox.hidden = false;
        search.setAttribute('aria-expanded', 'true');
        highlight(shown.length ? 0 : -1);
    }

    function renderChips() {
        if (!chipsBox) return;
        var seen = {};
        results.forEach(function (item) { seen[typeOf(item)] = true; });
        var types = ['All'].concat(typeRules.map(function (rule) { return rule[0]; }).concat(['Other']).filter(function (type) { return seen[type]; }));
        if (types.indexOf(typeFilter) === -1) typeFilter = 'All';
        chipsBox.textContent = '';
        types.forEach(function (type) {
            var button = el('button', 'chip' + (type === typeFilter ? ' on' : ''), type);
            button.type = 'button';
            button.setAttribute('aria-pressed', type === typeFilter ? 'true' : 'false');
            button.addEventListener('click', function () {
                typeFilter = type;
                renderChips();
                renderResults();
                search.focus();
            });
            chipsBox.appendChild(button);
        });
    }

    function renderPicked() {
        picked.textContent = '';
        inputs.textContent = '';
        if (!chosen.size) {
            picked.appendChild(el('li', 'empty', 'No items selected yet. Search for equipment to add it.'));
        }
        chosen.forEach(function (item) {
            var row = el('li');
            var grow = el('div', 'grow');
            grow.appendChild(el('div', '', item.name));
            if (item.detail) grow.appendChild(el('div', 'hint', item.detail));
            var available = item.free < item.qty ? 'Only ' + item.free + ' available for this time' : item.free + ' available';
            grow.appendChild(el('span', 'selection-availability' + (item.free < item.qty ? ' unavailable' : ''), available));
            row.appendChild(grow);

            var quantity = el('div', 'qty');
            var minus = el('button');
            minus.innerHTML = window.KIMC_ICON_MINUS;
            minus.type = 'button';
            minus.setAttribute('aria-label', 'Remove one ' + item.name);
            var number = el('span', '', String(item.qty));
            var plus = el('button');
            plus.innerHTML = window.KIMC_ICON_PLUS;
            plus.type = 'button';
            plus.setAttribute('aria-label', 'Add one ' + item.name);
            plus.disabled = item.qty >= item.free || totalQty() >= maxItems || (!canKit && totalQty() >= 1);
            minus.addEventListener('click', function () {
                selectionTouched = true;
                item.qty -= 1;
                if (item.qty <= 0) chosen.delete(item.key);
                renderPicked();
            });
            plus.addEventListener('click', function () {
                if (item.qty >= item.free) {
                    status.textContent = 'No more of "' + item.name + '" are available for that time.';
                    return;
                }
                if (!canKit && totalQty() >= 1) {
                    status.textContent = 'Your borrowing tier allows one item per booking.';
                    return;
                }
                if (totalQty() >= maxItems) {
                    status.textContent = 'A kit can hold at most ' + maxItems + ' items.';
                    return;
                }
                selectionTouched = true;
                item.qty += 1;
                status.textContent = '';
                renderPicked();
            });
            quantity.appendChild(minus);
            quantity.appendChild(number);
            quantity.appendChild(plus);
            row.appendChild(quantity);

            var remove = el('button', 'x');
            remove.innerHTML = window.KIMC_ICON_CLOSE;
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Remove ' + item.name);
            remove.addEventListener('click', function () {
                selectionTouched = true;
                chosen.delete(item.key);
                renderPicked();
            });
            row.appendChild(remove);
            picked.appendChild(row);

            var keyInput = document.createElement('input');
            keyInput.type = 'hidden';
            keyInput.name = 'item_key[]';
            keyInput.value = item.key;
            var qtyInput = document.createElement('input');
            qtyInput.type = 'hidden';
            qtyInput.name = 'item_qty[]';
            qtyInput.value = String(item.qty);
            inputs.appendChild(keyInput);
            inputs.appendChild(qtyInput);
        });
        counter.textContent = totalQty() + ' of ' + maxItems + ' items';
        updateSummary();
    }

    function selectedResult(key) {
        for (var i = 0; i < results.length; i++) {
            if (results[i].key === key) return results[i];
        }
        return null;
    }

    function loadItems() {
        if (controller) controller.abort();
        controller = new AbortController();
        availabilityLoading = true;
        status.textContent = 'Loading equipment availability…';
        updateSummary();
        var url = api + (pickup.value ? '?pickup=' + encodeURIComponent(pickup.value) : '');
        fetch(url, { credentials: 'same-origin', signal: controller.signal })
            .then(function (response) {
                return response.json().then(function (body) { return { ok: response.ok, body: body }; });
            })
            .then(function (response) {
                if (!response.ok) {
                    results = [];
                    availabilityLoading = false;
                    status.textContent = response.body.error || 'Could not load equipment.';
                    chosen.forEach(function (item) { item.free = 0; });
                    renderPicked();
                    updateSummary();
                    return;
                }
                results = response.body.items || [];
                chosen.forEach(function (item) {
                    var refreshed = selectedResult(item.key);
                    item.free = refreshed ? refreshed.free : 0;
                    if (refreshed) {
                        item.name = refreshed.name;
                        item.detail = refreshed.detail;
                    }
                });
                if (!restoredLoaded) {
                    Object.keys(restored).forEach(function (key) {
                        var item = selectedResult(key);
                        var quantity = parseInt(restored[key], 10);
                        if (item && quantity > 0 && quantity <= maxItems) {
                            chosen.set(key, {
                                key: key,
                                name: item.name,
                                detail: item.detail,
                                qty: quantity,
                                free: item.free
                            });
                        }
                    });
                    restoredLoaded = true;
                }
                availabilityLoading = false;
                status.textContent = pickup.value ? '' : 'Choose a pickup time to check availability.';
                renderChips();
                renderPicked();
                if (!listbox.hidden) renderResults();
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    availabilityLoading = false;
                    chosen.forEach(function (item) { item.free = 0; });
                    status.textContent = 'Could not load equipment. Check your connection and try again.';
                    renderPicked();
                    updateSummary();
                }
            });
    }

    function setClientErrors() {
        if (kitTouched || submitAttempted) {
            var kitError = '';
            if (!kitName.value.trim()) kitError = 'Enter a name for your kit.';
            else if (!kitName.validity.valid) kitError = 'Use 2 to 120 characters for the kit name.';
            setError('kit-name-error', kitError);
        } else if (initialErrors['kit-name-error']) {
            setError('kit-name-error', initialErrors['kit-name-error']);
        }

        if (dateTouched || timeTouched || submitAttempted) {
            var pickupError = '';
            if (!selectedDate) pickupError = 'Choose a pickup date.';
            else if (!pickupTime.value) pickupError = 'Choose a pickup time slot.';
            setError('pickup-error', pickupError);
            setError('pickup-time-error', selectedDate && !pickupTime.value ? 'Choose a pickup time slot.' : '');
        } else if (initialErrors['pickup-error']) {
            setError('pickup-error', initialErrors['pickup-error']);
        }

        if (selectionTouched || submitAttempted) {
            var itemError = '';
            if (!totalQty()) itemError = 'Add at least one item to your kit.';
            else if (totalQty() > maxItems) itemError = 'Reduce your selection to at most ' + maxItems + ' items.';
            else if (!canKit && totalQty() > 1) itemError = 'Your borrowing tier allows one item per booking.';
            else if (!selectionsAvailable()) itemError = 'Adjust the quantity of any item that is no longer available for this pickup time.';
            setError('items-error', itemError);
        } else if (initialErrors['items-error']) {
            setError('items-error', initialErrors['items-error']);
        }

        if (purposeTouched || submitAttempted) {
            var purposeError = '';
            if (!purpose.value.trim()) purposeError = 'Enter the purpose of borrowing.';
            else if (!purpose.validity.valid) purposeError = 'Keep the purpose within 500 characters.';
            setError('purpose-error', purposeError);
        } else if (initialErrors['purpose-error']) {
            setError('purpose-error', initialErrors['purpose-error']);
        }
    }

    function updateStepper() {
        var complete = [
            kitName.validity.valid && !!pickup.value,
            totalQty() > 0 && selectionsAvailable(),
            purpose.validity.valid && !!purpose.value.trim()
        ];
        var current = complete.indexOf(false);
        if (current === -1) current = 2;
        document.querySelectorAll('.booking-stepper li').forEach(function (step, index) {
            var state = complete[index] ? 'complete' : (index === current ? 'active' : 'upcoming');
            step.dataset.state = state;
            var link = step.querySelector('a');
            if (index === current) link.setAttribute('aria-current', 'step');
            else link.removeAttribute('aria-current');
        });
        var currentLabel = document.getElementById('step-current');
        if (currentLabel) currentLabel.textContent = String(current + 1);
    }

    function updateSummary() {
        var kitSummary = document.getElementById('sum-kit');
        var pickupSummary = document.getElementById('sum-pickup');
        var itemsSummary = document.getElementById('sum-items');
        if (kitSummary) kitSummary.textContent = kitName.value.trim() || 'Not named';
        if (pickupSummary) {
            pickupSummary.textContent = selectedDate && pickupTime.value
                ? formatDate(selectedDate) + ' at ' + pickupTime.value
                : 'Not chosen';
        }
        if (itemsSummary) itemsSummary.textContent = totalQty() ? totalQty() + (totalQty() === 1 ? ' item' : ' items') : '0';
        var mobileItems = document.getElementById('mobile-items');
        if (mobileItems) mobileItems.textContent = totalQty() + (totalQty() === 1 ? ' item' : ' items');

        setClientErrors();
        updateStepper();
        var valid = form.checkValidity() && totalQty() > 0 && selectionsAvailable() && !!pickup.value && !availabilityLoading;
        var reason = '';
        if (availabilityLoading) reason = 'Checking equipment availability…';
        else if (!totalQty() && !pickup.value) reason = 'Add at least one item and choose a pickup time.';
        else if (!totalQty()) reason = 'Add at least one item.';
        else if (!pickup.value) reason = 'Choose a pickup date and time.';
        else if (!kitName.validity.valid) reason = 'Enter a valid kit name.';
        else if (!purpose.validity.valid) reason = 'Add a purpose for borrowing.';
        else if (totalQty() > maxItems) reason = 'Choose no more than ' + maxItems + ' items.';
        else if (!canKit && totalQty() > 1) reason = 'Choose one item for this booking.';
        else if (!selectionsAvailable()) reason = 'Adjust items that are unavailable for this time.';
        document.querySelectorAll('.submit-reason').forEach(function (node) {
            node.textContent = valid ? 'You will be notified after staff review.' : reason;
        });
        document.querySelectorAll('.submit-button').forEach(function (button) {
            button.disabled = !valid;
        });
    }

    search.addEventListener('input', renderResults);
    search.addEventListener('focus', renderResults);
    search.addEventListener('click', function () { if (listbox.hidden) renderResults(); });
    search.addEventListener('blur', function () { setTimeout(closeList, 120); });
    search.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' && listbox.hidden) { renderResults(); return; }
        if (event.key === 'ArrowDown') { event.preventDefault(); highlight(Math.min(active + 1, shown.length - 1)); }
        else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(Math.max(active - 1, 0)); }
        else if (event.key === 'Enter' && !listbox.hidden) {
            event.preventDefault();
            if (active >= 0) choose(shown[active]);
        } else if (event.key === 'Escape') closeList();
    });

    kitName.addEventListener('input', function () {
        kitTouched = true;
        updateSummary();
    });
    purpose.addEventListener('input', function () {
        purposeTouched = true;
        updateSummary();
    });
    pickupTime.addEventListener('change', function () {
        timeTouched = true;
        updatePickup();
        validateForm();
    });
    form.addEventListener('input', updateSummary);

    function validateForm() {
        updateSummary();
    }

    form.addEventListener('submit', function (event) {
        submitAttempted = true;
        updateSummary();
        if (!form.checkValidity() || !totalQty() || !selectionsAvailable() || !pickup.value) {
            event.preventDefault();
            setClientErrors();
            form.reportValidity();
            return;
        }
        form.setAttribute('aria-busy', 'true');
        document.querySelectorAll('.submit-button').forEach(function (button) {
            button.disabled = true;
            button.textContent = 'Sending request…';
        });
    });

    var initialDate = picker.dataset.initialDate || '';
    if (allowedDateSet[initialDate]) {
        selectedDate = initialDate;
        activeMonth = initialDate.slice(0, 7);
    } else if (allowedMonths.length) {
        activeMonth = allowedMonths[0];
    }
    updatePickup();
    renderPicked();
    updateSummary();
    loadItems();
})();
