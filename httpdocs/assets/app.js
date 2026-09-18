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

    /* ---------- aantal personen per dag ---------- */

    /*
     * Elke dag heeft zijn eigen aantal, opgeslagen bij de week. Komt er
     * iemand eten op woensdag, dan zet je die dag op 4 en telt de
     * boodschappenlijst dat vanzelf mee.
     */

    var openDay = null;   // welke dag staat er in het receptvenster

    function dayEl(day) {
        return document.querySelector('.day[data-day="' + day + '"]');
    }

    function getDayServings(day) {
        var el = dayEl(day);
        return el ? parseInt(el.getAttribute('data-day-servings'), 10) || 3 : 3;
    }

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

        return String(rounded).replace('.', ',') + (unit ? ' ' + unit + ' ' : ' ');
    }

    /** Boodschappenlijst: de server rekent al, wij maken er tekst van. */
    function renderShopping() {
        Array.prototype.forEach.call(
            document.querySelectorAll('.shop-amount'),
            function (el) {
                var amount = parseFloat(el.getAttribute('data-amount'));
                el.textContent = isNaN(amount)
                    ? ''
                    : formatAmount(amount, el.getAttribute('data-unit'));
            }
        );
    }

    /** Hoeveelheden in het receptvenster omrekenen naar de dag die openstaat. */
    function renderRecipeAmounts() {
        var list = document.getElementById('recipeIngredients');
        if (!list || !list.dataset.base) { return; }

        var people = openDay === null ? 3 : getDayServings(openDay);
        var factor = people / parseInt(list.dataset.base, 10);

        var label = document.querySelector('#recipeServings [data-servings-value]');
        if (label) { label.textContent = people; }

        Array.prototype.forEach.call(list.querySelectorAll('.detail-amount'), function (el) {
            var base = parseFloat(el.getAttribute('data-amount'));
            el.textContent = isNaN(base)
                ? ''
                : formatAmount(base * factor, el.getAttribute('data-unit'));
        });
    }

    /** Zet een dag op een nieuw aantal en bewaar dat. */
    function changeServings(day, delta) {
        if (!cfg.mayEdit) { return; }

        var next = getDayServings(day) + delta;
        if (next < 1 || next > 20) { return; }

        var el = dayEl(day);
        if (el) {
            el.setAttribute('data-day-servings', next);
            var label = el.querySelector('[data-servings-value]');
            if (label) { label.textContent = next; }
        }

        if (openDay === day) { renderRecipeAmounts(); }

        postJson('api/servings.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            day_index: day,
            servings: next
        }).then(function (data) {
            // De server stuurt de herberekende lijst mee.
            applyShopping(data.shopping);
        }).catch(function (err) {
            toast(err.message, true);
        });
    }

    /** Nieuwe totalen in de bestaande boodschappenlijst zetten. */
    function applyShopping(shopping) {
        if (!shopping) { return; }

        var byName = {};
        Object.keys(shopping).forEach(function (group) {
            shopping[group].forEach(function (item) {
                byName[item.name] = item.amount;
            });
        });

        Array.prototype.forEach.call(document.querySelectorAll('.shop-amount'), function (el) {
            var name = el.getAttribute('data-name');
            if (Object.prototype.hasOwnProperty.call(byName, name)) {
                el.setAttribute('data-amount', byName[name] === null ? '' : byName[name]);
            }
        });

        renderShopping();
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-servings]');
        if (!btn) { return; }

        e.preventDefault();

        var delta = parseInt(btn.getAttribute('data-servings'), 10);
        var control = btn.closest('[data-servings-control]');

        if (control) {
            changeServings(parseInt(control.getAttribute('data-servings-control'), 10), delta);
        } else if (btn.closest('#recipeServings') && openDay !== null) {
            changeServings(openDay, delta);
        }
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

    function openRecipe(id, day) {
        openDay = day;
        if (!recipeModal) { return; }

        document.getElementById('recipeTitle').textContent = 'Bezig met laden...';
        document.getElementById('recipeMeta').innerHTML = '';
        document.getElementById('recipeIngredients').innerHTML = '';
        delete document.getElementById('recipeIngredients').dataset.base;
        document.getElementById('recipeSteps').innerHTML = '';
        document.getElementById('recipeNotes').textContent = '';
        document.getElementById('recipeHint').textContent = '';
        document.getElementById('recipeLinkWrap').hidden = true;

        var label = document.querySelector('#recipeServings [data-servings-value]');
        if (label) { label.textContent = day === null ? 3 : getDayServings(day); }

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

                // Hoeveelheden meteen naar het aantal personen van die dag.
                renderRecipeAmounts();

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
            var article = trigger.closest('.day');
            openRecipe(
                trigger.getAttribute('data-recipe'),
                article ? parseInt(article.getAttribute('data-day'), 10) : null
            );
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

    renderShopping();

    /* ---------- boodschappen afvinken ---------- */

    /*
     * Wat je afvinkt gaat naar de server, niet naar deze browser. Anders
     * staat de lijst op de telefoon van de een wel afgestreept en op die
     * van de ander niet.
     */

    document.addEventListener('change', function (e) {
        var box = e.target.closest('[data-check]');
        if (!box) { return; }

        var label = box.closest('label');
        if (label) { label.classList.toggle('is-done', box.checked); }

        if (!cfg.mayEdit) { return; }

        postJson('api/check.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            item: box.getAttribute('data-check'),
            checked: box.checked
        }).catch(function (err) {
            // Niet opgeslagen: zet het vinkje terug, anders denk je
            // straks in de winkel dat je het al hebt.
            box.checked = !box.checked;
            if (label) { label.classList.toggle('is-done', box.checked); }
            toast(err.message, true);
        });
    });

}());
