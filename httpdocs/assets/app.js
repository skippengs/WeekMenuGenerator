(function () {
    'use strict';

    var cfg    = window.WEEKMENU || {};
    var modal  = document.getElementById('pantryModal');

    /* ---------- kleine melding rechtsonder ---------- */

    function toast(message, isError) {
        var el = document.createElement('div');
        el.className = 'toast' + (isError ? ' toast-error' : '');
        el.textContent = message;
        document.body.appendChild(el);

        // Laat hem even staan, dan weg.
        setTimeout(function () { el.classList.add('is-out'); }, 3200);
        setTimeout(function () { el.remove(); }, 3600);
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('Onverwacht antwoord van de server.');
            }).then(function (data) {
                if (!res.ok || data.error) {
                    throw new Error(data.error || 'Er ging iets mis.');
                }
                return data;
            });
        });
    }

    /* ---------- voorraadvenster ---------- */

    function openModal() {
        if (!modal) { return; }
        modal.hidden = false;
        document.body.classList.add('modal-open');

        var first = modal.querySelector('input[type="checkbox"]');
        if (first) { first.focus(); }
    }

    function closeModal() {
        if (!modal) { return; }
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-open-pantry]')) {
            e.preventDefault();
            openModal();
        }
        if (e.target.closest('[data-close-pantry]')) {
            e.preventDefault();
            closeModal();
        }
        if (e.target.closest('[data-pantry-clear]')) {
            e.preventDefault();
            modal.querySelectorAll('input[name="pantry"]').forEach(function (cb) {
                cb.checked = false;
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.hidden) {
            closeModal();
        }
    });

    /* ---------- genereren ---------- */

    var submitBtn = document.querySelector('[data-pantry-submit]');

    if (submitBtn) {
        submitBtn.addEventListener('click', function () {
            var checked = Array.prototype.map.call(
                modal.querySelectorAll('input[name="pantry"]:checked'),
                function (cb) { return parseInt(cb.value, 10); }
            );

            submitBtn.disabled = true;
            submitBtn.textContent = 'Bezig...';

            postJson('api/generate.php', {
                csrf: cfg.csrf,
                week_start: cfg.weekStart,
                pantry: checked
            }).then(function (data) {
                // Herladen is hier het simpelst: de hele week is nieuw,
                // inclusief boodschappenlijst.
                window.location.search = '?week=' + encodeURIComponent(data.week_start);
            }).catch(function (err) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Genereer weekmenu';
                toast(err.message, true);
            });
        });
    }

    /* ---------- aantal personen ---------- */

    var SERVINGS_KEY = 'weekmenu-personen';
    var servings = 4;

    try {
        var stored = parseInt(localStorage.getItem(SERVINGS_KEY), 10);
        if (stored >= 1 && stored <= 20) { servings = stored; }
    } catch (err) { /* geen opslag, blijf op 4 */ }

    /*
     * Afronden op iets wat je in een keuken kunt gebruiken. 266,67 gram
     * gehakt koopt niemand, dus dat wordt 270.
     */
    function roundAmount(value, unit) {
        if (unit === 'g' || unit === 'ml') {
            if (value >= 100) { return Math.round(value / 10) * 10; }
            if (value >= 20)  { return Math.round(value / 5) * 5; }
            return Math.round(value);
        }
        if (unit === 'kg' || unit === 'l') {
            return Math.round(value * 10) / 10;
        }
        // Telbaar, blikken, tenen knoflook: halve stuks zijn nog te doen.
        return Math.round(value * 2) / 2;
    }

    function formatAmount(value, unit) {
        if (value === null || isNaN(value)) { return ''; }

        // Boven de duizend gram schrijf je kilo's op je boodschappenlijstje,
        // geen "2000 g aardappelen".
        if ((unit === 'g' || unit === 'ml') && value >= 1000) {
            value = value / 1000;
            unit  = unit === 'g' ? 'kg' : 'l';
        }

        var rounded = roundAmount(value, unit);
        if (rounded <= 0) { return ''; }

        var text = String(rounded).replace('.', ',');   // Nederlandse komma
        return unit ? text + ' ' + unit + ' ' : text + ' ';
    }

    /** Zet alle hoeveelheden op de pagina om naar het huidige aantal personen. */
    function renderServings() {
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-servings-value]'),
            function (el) { el.textContent = servings; }
        );

        // Boodschappenlijst: opgeslagen per persoon, dus keer het aantal.
        Array.prototype.forEach.call(
            document.querySelectorAll('.shop-amount'),
            function (el) {
                var per = parseFloat(el.getAttribute('data-per-person'));
                if (isNaN(per)) { el.textContent = ''; return; }
                el.textContent = formatAmount(per * servings, el.getAttribute('data-unit'));
            }
        );

        // Receptvenster: opgeslagen voor de basis van dat recept.
        var list = document.getElementById('recipeIngredients');
        if (list && list.dataset.base) {
            var factor = servings / parseInt(list.dataset.base, 10);
            Array.prototype.forEach.call(list.querySelectorAll('li'), function (li) {
                var amountEl = li.querySelector('.detail-amount');
                if (!amountEl) { return; }
                var base = parseFloat(amountEl.getAttribute('data-amount'));
                if (isNaN(base)) { amountEl.textContent = ''; return; }
                amountEl.textContent = formatAmount(base * factor, amountEl.getAttribute('data-unit'));
            });
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-servings]');
        if (!btn) { return; }

        e.preventDefault();

        var next = servings + parseInt(btn.getAttribute('data-servings'), 10);
        if (next < 1 || next > 20) { return; }

        servings = next;
        try { localStorage.setItem(SERVINGS_KEY, String(servings)); } catch (err) { /* niet erg */ }
        renderServings();
    });

    /* ---------- recept bekijken ---------- */

    var recipeModal = document.getElementById('recipeModal');

    function fillList(el, items, wrap) {
        el.innerHTML = '';
        items.forEach(function (text) {
            var li = document.createElement(wrap);
            li.textContent = text;
            el.appendChild(li);
        });
    }

    function openRecipe(id) {
        if (!recipeModal) { return; }

        document.getElementById('recipeTitle').textContent = 'Bezig met laden...';
        document.getElementById('recipeMeta').innerHTML = '';
        document.getElementById('recipeIngredients').innerHTML = '';
        delete document.getElementById('recipeIngredients').dataset.base;
        document.getElementById('recipeSteps').innerHTML = '';
        document.getElementById('recipeNotes').textContent = '';
        document.getElementById('recipeHint').textContent = '';
        document.getElementById('recipeLinkWrap').hidden = true;

        recipeModal.hidden = false;
        document.body.classList.add('modal-open');

        fetch('api/recipe.php?id=' + encodeURIComponent(id))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) { throw new Error(data.error); }

                document.getElementById('recipeTitle').textContent = data.name;

                document.getElementById('recipeMeta').innerHTML =
                    '<span class="chip"></span><span class="chip chip-soft"></span>';
                var chips = document.getElementById('recipeMeta').children;
                chips[0].textContent = data.category;
                chips[1].textContent = data.effort;

                document.getElementById('recipeNotes').textContent = data.notes || '';

                var list = document.getElementById('recipeIngredients');
                list.innerHTML = '';
                list.dataset.base = data.servings || 4;

                data.ingredients.forEach(function (ing) {
                    var li = document.createElement('li');
                    var amount = document.createElement('span');
                    amount.className = 'detail-amount';
                    amount.setAttribute('data-amount', ing.amount === null ? '' : ing.amount);
                    amount.setAttribute('data-unit', ing.unit || '');
                    li.appendChild(amount);
                    li.appendChild(document.createTextNode(ing.name));
                    list.appendChild(li);
                });

                fillList(document.getElementById('recipeSteps'), data.steps, 'li');

                // Hoeveelheden meteen naar het ingestelde aantal personen.
                renderServings();

                if (!data.steps.length) {
                    document.getElementById('recipeHint').textContent =
                        'Nog geen bereiding ingevuld. Dat kan via Recepten beheren.';
                }

                if (data.url) {
                    document.getElementById('recipeLink').href = data.url;
                    document.getElementById('recipeLinkWrap').hidden = false;
                }
            })
            .catch(function (err) {
                document.getElementById('recipeTitle').textContent = 'Niet gelukt';
                document.getElementById('recipeHint').textContent = err.message;
            });
    }

    function closeRecipe() {
        if (!recipeModal) { return; }
        recipeModal.hidden = true;
        if (!modal || modal.hidden) {
            document.body.classList.remove('modal-open');
        }
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-recipe]');
        if (trigger) {
            e.preventDefault();
            openRecipe(trigger.getAttribute('data-recipe'));
        }
        if (e.target.closest('[data-close-recipe]')) {
            e.preventDefault();
            closeRecipe();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && recipeModal && !recipeModal.hidden) {
            closeRecipe();
        }
    });

    /* ---------- losse dag opnieuw ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-reroll]');
        if (!btn) { return; }

        e.preventDefault();

        var dayEl = btn.closest('.day');
        var day   = parseInt(btn.getAttribute('data-reroll'), 10);

        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = 'Bezig...';

        postJson('api/reroll.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            day_index: day
        }).then(function (data) {
            setField(dayEl, 'name', data.name);
            setField(dayEl, 'category', data.category);
            setField(dayEl, 'effort', data.effort);
            setField(dayEl, 'notes', data.notes);

            var nameEl = dayEl.querySelector('[data-field="name"]');
            if (nameEl) {
                nameEl.classList.remove('is-muted');
                // Zonder dit opent de klik nog het vorige recept.
                nameEl.setAttribute('data-recipe', data.id);
            }

            dayEl.classList.remove('is-swapped');
            void dayEl.offsetWidth;          // forceer herstart van de animatie
            dayEl.classList.add('is-swapped');
        }).catch(function (err) {
            toast(err.message, true);
        }).then(function () {
            btn.disabled = false;
            btn.textContent = original;
        });
    });

    function setField(scope, field, value) {
        var el = scope.querySelector('[data-field="' + field + '"]');
        if (!el) { return; }
        el.textContent = value || '';
        el.classList.toggle('is-empty', !value);
    }

    renderServings();

    /* ---------- boodschappenlijst onthouden per week ---------- */

    var shoppingBoxes = document.querySelectorAll('.shopping input[type="checkbox"]');

    if (shoppingBoxes.length) {
        var storeKey = 'weekmenu-boodschappen-' + (cfg.weekStart || '');

        // Wat al afgevinkt was terugzetten. Dit is puur gemak op één
        // apparaat, dus localStorage is hier prima.
        try {
            var saved = JSON.parse(localStorage.getItem(storeKey) || '[]');
            shoppingBoxes.forEach(function (cb, i) {
                if (saved.indexOf(i) !== -1) {
                    cb.checked = true;
                    cb.closest('label').classList.add('is-done');
                }
            });
        } catch (err) { /* geen opslag beschikbaar, niet erg */ }

        shoppingBoxes.forEach(function (cb, i) {
            cb.addEventListener('change', function () {
                cb.closest('label').classList.toggle('is-done', cb.checked);

                try {
                    var done = [];
                    shoppingBoxes.forEach(function (other, j) {
                        if (other.checked) { done.push(j); }
                    });
                    localStorage.setItem(storeKey, JSON.stringify(done));
                } catch (err) { /* idem */ }
            });
        });
    }
}());
