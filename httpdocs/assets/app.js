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
        if (!cfg.mayEditMenu) { return; }

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

    /*
     * Scherm aan laten zolang het recept openstaat: met vette handen tik je
     * je telefoon niet wakker. Na wisselen van app laat de browser het slot
     * los, dus bij terugkomen opnieuw aanvragen.
     */
    var wakeLock = null;

    function keepAwake() {
        if (!('wakeLock' in navigator) || wakeLock) { return; }
        navigator.wakeLock.request('screen').then(function (lock) {
            wakeLock = lock;
            lock.addEventListener('release', function () { wakeLock = null; });
        }).catch(function () {
            // Batterijbesparing of geen https: dan maar niet.
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && recipeModal && !recipeModal.hidden) { keepAwake(); }
    });

    /* Ligt het vlees in de vriezer: de avond ervoor komt er een melding. */
    var thawBtn = document.querySelector('[data-thaw-toggle]');

    function showThaw(day) {
        if (!thawBtn) { return; }
        var el = day === null ? null : dayEl(day);
        thawBtn.hidden = !el;
        if (!el) { return; }
        var on = el.getAttribute('data-thaw') === '1';
        thawBtn.classList.toggle('is-active', on);
        thawBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
        thawBtn.querySelector('[data-thaw-label]').textContent =
            on ? 'Ligt in de vriezer' : 'Ligt in de vriezer?';
    }

    if (thawBtn) {
        thawBtn.addEventListener('click', function () {
            var day = openDay;
            var el  = day === null ? null : dayEl(day);
            if (!el) { return; }

            var next = el.getAttribute('data-thaw') !== '1';
            thawBtn.disabled = true;

            postJson('api/thaw.php', {
                csrf: cfg.csrf,
                week_id: cfg.weekId,
                day_index: day,
                thaw: next
            }).then(function () {
                el.setAttribute('data-thaw', next ? '1' : '0');
                var icon = el.querySelector('.day-thaw');
                if (icon) { icon.hidden = !next; }
                showThaw(day);
                toast(next ? 'De avond ervoor krijg je een melding om het eruit te halen.' : 'Melding uitgezet.');
            }).catch(function (err) {
                toast(err.message, true);
            }).then(function () {
                thawBtn.disabled = false;
            });
        });
    }

    function openRecipe(id, day) {
        openDay = day;
        if (!recipeModal) { return; }

        keepAwake();
        showThaw(day);

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
        if (wakeLock) { wakeLock.release().catch(function () {}); }
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

    /* ---------- korting bekijken ---------- */

    var dealModal = document.getElementById('dealModal');

    function formatPrice(value) {
        return '€ ' + value.toFixed(2).replace('.', ',');
    }

    function openDeal(name, deals, ingredientId) {
        if (!dealModal) { return; }

        document.getElementById('dealTitle').textContent = name;

        var list = document.getElementById('dealList');
        list.innerHTML = '';
        deals.forEach(function (d) {
            var li = document.createElement('li');

            var head = document.createElement('div');
            head.className = 'deal-list-head';
            var store = document.createElement('span');
            store.className = 'deal-list-store';
            store.textContent = d.label;
            var price = document.createElement('span');
            price.className = 'deal-list-price';
            price.textContent = formatPrice(d.price);
            if (d.original_price && d.original_price > d.price) {
                var was = document.createElement('span');
                was.className = 'deal-list-was';
                was.textContent = formatPrice(d.original_price);
                price.appendChild(was);
            }
            head.appendChild(store);
            head.appendChild(price);

            var product = document.createElement('div');
            product.className = 'deal-list-product';
            product.textContent = d.product_name;

            li.appendChild(head);
            li.appendChild(product);

            // Zelfde product, andere aanbieding: dit is geen "gehakt" maar
            // "gebraden gehakt". Sluit het voortaan uit voor dit ingredient.
            if (cfg.mayEditMenu) {
                var foot = document.createElement('div');
                foot.className = 'deal-list-foot';
                var exclude = document.createElement('button');
                exclude.type = 'button';
                exclude.className = 'linkbtn linkbtn-danger';
                exclude.textContent = 'klopt niet, uitsluiten';
                exclude.setAttribute('data-exclude-deal', '1');
                exclude.setAttribute('data-ingredient-id', ingredientId);
                exclude.setAttribute('data-retailer', d.retailer);
                exclude.setAttribute('data-product-key', d.product_key);
                foot.appendChild(exclude);
                li.appendChild(foot);
            }

            list.appendChild(li);
        });

        dealModal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function closeDeal() {
        if (!dealModal) { return; }
        dealModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function (e) {
        var dealTrigger = e.target.closest('[data-deals]');
        if (dealTrigger) {
            e.preventDefault();
            openDeal(
                dealTrigger.getAttribute('data-deal-name'),
                JSON.parse(dealTrigger.getAttribute('data-deals')),
                dealTrigger.getAttribute('data-ingredient-id')
            );
        }
        if (e.target.closest('[data-close-deal]')) {
            e.preventDefault();
            closeDeal();
        }

        var excludeBtn = e.target.closest('[data-exclude-deal]');
        if (excludeBtn) {
            e.preventDefault();
            excludeBtn.disabled = true;

            postJson('api/deal_exclude.php', {
                csrf: cfg.csrf,
                ingredient_id: parseInt(excludeBtn.getAttribute('data-ingredient-id'), 10),
                retailer: excludeBtn.getAttribute('data-retailer'),
                product_key: excludeBtn.getAttribute('data-product-key')
            }).then(function () {
                // De boodschappenlijst zelf staat elders op de pagina en moet
                // ook zonder deze deal verder - herladen is hier het simpelst.
                window.location.reload();
            }).catch(function (err) {
                excludeBtn.disabled = false;
                toast(err.message, true);
            });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && dealModal && !dealModal.hidden) {
            closeDeal();
        }
    });

    /* ---------- losse dag opnieuw ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-reroll]');
        if (!btn) { return; }

        e.preventDefault();

        var day = parseInt(btn.getAttribute('data-reroll'), 10);

        btn.disabled = true;
        var original = btn.textContent;
        btn.textContent = 'Bezig...';

        postJson('api/reroll.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            day_index: day
        }).then(function () {
            // Een ander gerecht kan een restjesdag verderop in de week
            // losmaken, of een nieuwe "restjes van"-knop laten verschijnen.
            // Dat staat overal in de pagina, dus een herlaad is simpeler
            // (en minder foutgevoelig) dan alles met de hand bijwerken.
            window.location.reload();
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = original;
            toast(err.message, true);
        });
    });

    /* ---------- restjesdag aanwijzen of weer loskoppelen ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-leftover-assign], [data-leftover-revert]');
        if (!btn) { return; }

        e.preventDefault();

        var isRevert = btn.hasAttribute('data-leftover-revert');
        var day      = parseInt(btn.getAttribute(isRevert ? 'data-leftover-revert' : 'data-leftover-assign'), 10);
        var original = btn.textContent;

        var payload = {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            day_index: day
        };

        if (isRevert) {
            payload.action = 'revert';
        } else {
            payload.source_day = parseInt(btn.getAttribute('data-leftover-source'), 10);
        }

        btn.disabled = true;
        btn.textContent = 'Bezig...';

        postJson('api/leftover.php', payload).then(function () {
            // De kaart wisselt tussen het gewone gerecht en de
            // restjesweergave, en dat kan verderop in de week ook een
            // "Restjes van..."-knop laten verschijnen of verdwijnen: een
            // herlaad is hier simpeler dan alles met de hand bijwerken.
            window.location.reload();
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = original;
            toast(err.message, true);
        });
    });

    /* ---------- ⋯-menu op een dag ---------- */

    // Een open menu gaat dicht bij een klik ergens anders.
    document.addEventListener('click', function (e) {
        document.querySelectorAll('.day-menu[open]').forEach(function (m) {
            if (!m.contains(e.target) || e.target.closest('.day-menu-list button')) {
                m.open = false;
            }
        });
    });

    /* ---------- twee dagen wisselen ---------- */

    var swapModal = document.getElementById('swapModal');

    function closeSwap() {
        if (swapModal) { swapModal.hidden = true; }
    }

    document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-swap-open]');
        if (open && swapModal) {
            var from    = parseInt(open.getAttribute('data-swap-open'), 10);
            var options = JSON.parse(open.getAttribute('data-swap-options'));
            var list    = document.getElementById('swapList');

            document.getElementById('swapTitle').textContent =
                dayEl(from).querySelector('.day-name').textContent + ' wisselen met…';
            list.innerHTML = '';

            options.forEach(function (o) {
                var li  = document.createElement('li');
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'swap-option';
                btn.disabled = o.reason !== null;
                btn.setAttribute('data-swap-from', from);
                btn.setAttribute('data-swap-to', o.day);

                var day = document.createElement('strong');
                day.textContent = o.name;
                var what = document.createElement('span');
                what.textContent = o.what;
                btn.appendChild(day);
                btn.appendChild(what);
                if (o.reason) {
                    var why = document.createElement('small');
                    why.textContent = o.reason;
                    btn.appendChild(why);
                }
                li.appendChild(btn);
                list.appendChild(li);
            });

            swapModal.hidden = false;
            return;
        }

        if (e.target.closest('[data-close-swap]')) {
            closeSwap();
            return;
        }

        var pick = e.target.closest('[data-swap-to]');
        if (!pick) { return; }

        pick.disabled = true;
        postJson('api/swap.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            day_a: parseInt(pick.getAttribute('data-swap-from'), 10),
            day_b: parseInt(pick.getAttribute('data-swap-to'), 10)
        }).then(function () {
            // Restjesknoppen, labels en vrije dagen verschuiven allemaal mee.
            window.location.reload();
        }).catch(function (err) {
            pick.disabled = false;
            toast(err.message, true);
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && swapModal && !swapModal.hidden) {
            closeSwap();
        }
    });

    renderShopping();

    /* ---------- admin: tabbladen ---------- */

    var tabButtons = document.querySelectorAll('.tab-btn[data-tab]');

    if (tabButtons.length) {
        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var name = btn.getAttribute('data-tab');

                tabButtons.forEach(function (b) {
                    b.classList.toggle('is-active', b === btn);
                });

                document.querySelectorAll('[data-tab-panel]').forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-tab-panel') !== name;
                });
            });
        });
    }

    /* ---------- admin: recept toevoegen / bewerken ---------- */

    var recipeEditModal = document.getElementById('recipeEditModal');
    var recipeEditForm  = document.getElementById('recipeEditForm');

    function openRecipeEdit(data) {
        if (!recipeEditModal || !recipeEditForm) { return; }

        hideIngredientSuggest();

        var f = recipeEditForm.elements;

        var isEdit = !!(data && data.id);
        document.getElementById('recipeEditTitle').textContent = isEdit ? 'Recept bewerken' : 'Nieuw recept';
        document.getElementById('recipeEditSubmit').textContent = isEdit ? 'Opslaan' : 'Toevoegen';

        recipeEditForm.reset();
        f.id.value              = data && data.id ? data.id : 0;
        f.name.value            = data ? data.name : '';
        f.category.value        = data ? data.category : 'overig';
        f.effort.value          = data ? data.effort : 2;
        f.servings.value        = data ? data.servings : 4;
        f.ingredients.value     = data ? data.ingredients : '';
        f.steps.value           = data ? data.steps : '';
        f.notes.value           = data ? data.notes : '';
        f.url.value              = data ? data.url : '';
        f.weekend_only.checked     = !!(data && data.weekend_only);
        f.makes_leftovers.checked  = !!(data && data.makes_leftovers);
        f.is_mine.checked          = data ? !!data.is_mine : true;
        f.preference.value         = data ? data.preference : 0;

        var season = (data && data.season) || [];
        Array.prototype.forEach.call(recipeEditForm.querySelectorAll('input[name="season"]'), function (cb) {
            cb.checked = season.indexOf(parseInt(cb.value, 10)) !== -1;
        });

        recipeEditModal.hidden = false;
        document.body.classList.add('modal-open');

        var first = document.getElementById('f-name');
        if (first) { first.focus(); }
    }

    function closeRecipeEdit() {
        if (!recipeEditModal) { return; }
        recipeEditModal.hidden = true;
        document.body.classList.remove('modal-open');
        hideIngredientSuggest();
    }

    /* ---------- admin: ingrediënten aanvullen tijdens typen ---------- */

    /*
     * Voorkomt dat "ui", "uien" en "rode ui" drie aparte rijen in de
     * ingredient-tabel worden: terwijl je typt zoeken we in de namen die
     * er al zijn en bied je die aan in plaats van dat er een nieuwe
     * variant ontstaat. Alleen de naam in de regel wordt vervangen, de
     * hoeveelheid en eenheid ervoor blijven staan. Hoe dat stukje ervoor
     * herkend wordt volgt dezelfde regels als parseIngredientLine() in
     * inc/admin_helpers.php — verander die niet zonder dit ook aan te
     * passen.
     */

    var ingredientsField  = document.getElementById('f-ingredients');
    var ingredientSuggest = document.getElementById('ingredientSuggest');

    function splitIngredientLine(line) {
        var m = line.match(/^(\s*[0-9]+(?:[.,][0-9]+)?\s+)([\s\S]*)$/);
        if (!m) { return { prefix: '', name: line }; }

        var rest  = m[2];
        var parts = rest.match(/^(\S+)(\s+)([\s\S]*)$/);

        if (parts && (cfg.ingredientUnits || []).indexOf(parts[1].toLowerCase()) !== -1) {
            return { prefix: m[1] + parts[1] + parts[2], name: parts[3] };
        }
        return { prefix: m[1], name: rest };
    }

    function currentLine(textarea) {
        var value = textarea.value;
        var pos   = textarea.selectionStart;
        var start = value.lastIndexOf('\n', pos - 1) + 1;
        var end   = value.indexOf('\n', pos);
        if (end === -1) { end = value.length; }
        return { start: start, end: end, text: value.slice(start, end) };
    }

    function hideIngredientSuggest() {
        if (!ingredientSuggest) { return; }
        ingredientSuggest.hidden = true;
        ingredientSuggest.innerHTML = '';
    }

    function showIngredientSuggest(names, onPick) {
        ingredientSuggest.innerHTML = '';
        names.forEach(function (name) {
            var li = document.createElement('li');
            li.textContent = name;
            li.addEventListener('mousedown', function (e) {
                // mousedown, niet click: anders is de textarea al blurred
                // (en de lijst dus al weg) voordat de klik aankomt.
                e.preventDefault();
                onPick(name);
            });
            ingredientSuggest.appendChild(li);
        });
        ingredientSuggest.hidden = names.length === 0;
    }

    if (ingredientsField && ingredientSuggest) {
        ingredientsField.addEventListener('input', function () {
            var line  = currentLine(ingredientsField);
            var split = splitIngredientLine(line.text);
            var typed = split.name.trim().toLowerCase();

            if (typed.length < 2) {
                hideIngredientSuggest();
                return;
            }

            var names = (cfg.ingredientNames || []).filter(function (name) {
                return name.toLowerCase().indexOf(typed) !== -1;
            }).slice(0, 6);

            if (!names.length) {
                hideIngredientSuggest();
                return;
            }

            showIngredientSuggest(names, function (chosen) {
                var value   = ingredientsField.value;
                var newLine = split.prefix + chosen;

                ingredientsField.value = value.slice(0, line.start) + newLine + value.slice(line.end);
                var caret = line.start + newLine.length;
                ingredientsField.setSelectionRange(caret, caret);
                ingredientsField.focus();
                hideIngredientSuggest();
            });
        });

        ingredientsField.addEventListener('blur', hideIngredientSuggest);
        ingredientsField.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { hideIngredientSuggest(); }
        });
    }

    /** Nieuwe tabel-, voorraad- en ingrediënteninhoud in de admin-pagina zetten, zonder te herladen. */
    function applyAdminData(data) {
        var body = document.getElementById('recipeTableBody');
        if (body && data.recipe_table !== undefined) { body.innerHTML = data.recipe_table; }

        var count = document.getElementById('recipeCount');
        if (count && data.recipe_count !== undefined) { count.textContent = data.recipe_count + ' recepten'; }

        var pantry = document.getElementById('pantryItemsList');
        if (pantry && data.pantry_list !== undefined) { pantry.innerHTML = data.pantry_list; }

        if (data.ingredient_options !== undefined) {
            ['f-merge-from', 'f-merge-into'].forEach(function (id) {
                var select = document.getElementById(id);
                if (!select) { return; }
                var current = select.value;
                select.innerHTML = '<option value="">Kies een ingrediënt...</option>' + data.ingredient_options;
                select.value = current;
            });
        }

        if (data.ingredient_names !== undefined) { cfg.ingredientNames = data.ingredient_names; }

        var dealExclusionBody = document.getElementById('dealExclusionTableBody');
        if (dealExclusionBody && data.deal_exclusion_table !== undefined) { dealExclusionBody.innerHTML = data.deal_exclusion_table; }

        var dealExclusionCount = document.getElementById('dealExclusionCount');
        if (dealExclusionCount && data.deal_exclusion_count !== undefined) {
            dealExclusionCount.textContent = data.deal_exclusion_count + ' uitgesloten producten';
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-new-recipe]')) {
            e.preventDefault();
            openRecipeEdit(null);
        }
        if (e.target.closest('[data-close-recipe-edit]')) {
            e.preventDefault();
            closeRecipeEdit();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && recipeEditModal && !recipeEditModal.hidden) {
            closeRecipeEdit();
        }
    });

    document.addEventListener('click', function (e) {
        var editBtn = e.target.closest('[data-edit]');
        if (!editBtn) { return; }

        e.preventDefault();

        fetch('api/admin_recipe_edit.php?id=' + encodeURIComponent(editBtn.getAttribute('data-edit')))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) { throw new Error(data.error); }
                openRecipeEdit(data);
            })
            .catch(function (err) { toast(err.message, true); });
    });

    if (recipeEditForm) {
        recipeEditForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = document.getElementById('recipeEditSubmit');
            var original  = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Bezig...';

            var f = recipeEditForm.elements;

            postJson('api/admin_save.php', {
                csrf: cfg.csrf,
                id: parseInt(f.id.value, 10) || 0,
                name: f.name.value,
                category: f.category.value,
                effort: parseInt(f.effort.value, 10),
                servings: parseInt(f.servings.value, 10),
                ingredients: f.ingredients.value,
                steps: f.steps.value,
                notes: f.notes.value,
                url: f.url.value,
                weekend_only: f.weekend_only.checked,
                makes_leftovers: f.makes_leftovers.checked,
                is_mine: f.is_mine.checked,
                preference: parseInt(f.preference.value, 10) || 0,
                season: Array.prototype.map.call(
                    recipeEditForm.querySelectorAll('input[name="season"]:checked'),
                    function (cb) { return parseInt(cb.value, 10); }
                )
            }).then(function (data) {
                applyAdminData(data);
                closeRecipeEdit();
                toast(data.notice);
            }).catch(function (err) {
                toast(err.message, true);
            }).then(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = original;
            });
        });
    }

    document.addEventListener('click', function (e) {
        var toggleBtn = e.target.closest('[data-toggle]');
        if (toggleBtn) {
            e.preventDefault();

            toggleBtn.disabled = true;

            postJson('api/admin_toggle.php', { csrf: cfg.csrf, id: parseInt(toggleBtn.getAttribute('data-toggle'), 10) })
                .then(function (data) {
                    var row = document.querySelector('.recipe-table tr[data-id="' + data.id + '"]');
                    if (row) { row.classList.toggle('is-inactive', data.is_active === 0); }

                    toggleBtn.disabled = false;
                    toggleBtn.textContent = data.is_active === 1 ? 'pauzeer' : 'activeer';
                    toast(data.notice);
                })
                .catch(function (err) {
                    toggleBtn.disabled = false;
                    toast(err.message, true);
                });
            return;
        }

        var deleteBtn = e.target.closest('[data-delete]');
        if (deleteBtn) {
            e.preventDefault();

            if (!confirm(deleteBtn.getAttribute('data-name') + ' verwijderen?')) { return; }

            deleteBtn.disabled = true;

            postJson('api/admin_delete.php', { csrf: cfg.csrf, id: parseInt(deleteBtn.getAttribute('data-delete'), 10) })
                .then(function (data) {
                    applyAdminData(data);
                    toast(data.notice);
                })
                .catch(function (err) {
                    deleteBtn.disabled = false;
                    toast(err.message, true);
                });
            return;
        }

        var deleteExclusionBtn = e.target.closest('[data-delete-deal-exclusion]');
        if (deleteExclusionBtn) {
            e.preventDefault();

            if (!confirm('Deze uitsluiting weer toestaan?')) { return; }

            deleteExclusionBtn.disabled = true;

            postJson('api/admin_deal_exclusion_delete.php', {
                csrf: cfg.csrf,
                ingredient_id: parseInt(deleteExclusionBtn.getAttribute('data-ingredient-id'), 10),
                retailer: deleteExclusionBtn.getAttribute('data-retailer'),
                product_key: deleteExclusionBtn.getAttribute('data-product-key')
            })
                .then(function (data) {
                    applyAdminData(data);
                    toast(data.notice);
                })
                .catch(function (err) {
                    deleteExclusionBtn.disabled = false;
                    toast(err.message, true);
                });
        }
    });

    /* ---------- admin: recept importeren van een link ---------- */

    /*
     * De server haalt het recept op en doet per ingrediëntregel een
     * voorstel (zie inc/import.php). Hier kies je per regel wat het bij ons
     * wordt; daarna gaat alles het gewone receptvenster in, zodat je het
     * nog kunt nakijken voor het opslaan.
     */

    var importModal = document.getElementById('importModal');
    var importList  = document.getElementById('importList');
    var importData  = null;

    var IMPORT_UNITS = ['', 'g', 'kg', 'ml', 'l', 'el', 'tl', 'teen', 'blik', 'pak', 'pot', 'bosje', 'snuf', 'plak', 'zak'];

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function option(value, label) {
        var o = el('option', '', label);
        o.value = value;
        return o;
    }

    function formatImportAmount(value) {
        return value === null || value === undefined ? '' : String(Math.round(value * 100) / 100).replace('.', ',');
    }

    function importRow(line, ingredients) {
        var li = el('li', 'import-row');

        var raw = el('div', 'import-raw', line.raw);
        if (line.learned) { raw.appendChild(el('span', 'chip chip-soft', 'onthouden')); }
        li.appendChild(raw);

        var pick = el('div', 'import-pick');

        var amount = el('input', 'import-amount');
        amount.type = 'text';
        amount.inputMode = 'decimal';
        amount.value = formatImportAmount(line.amount);
        amount.setAttribute('aria-label', 'Hoeveelheid');

        var unit = el('select', 'import-unit');
        unit.setAttribute('aria-label', 'Eenheid');
        IMPORT_UNITS.forEach(function (u) { unit.appendChild(option(u, u || '—')); });
        unit.value = line.unit || '';

        var select = el('select', 'import-ingredient');
        select.setAttribute('aria-label', 'Wordt bij ons');
        if (line.candidates.length) {
            var best = el('optgroup');
            best.label = 'Voorstel';
            line.candidates.forEach(function (c) { best.appendChild(option(c.id, c.name)); });
            select.appendChild(best);
        }
        select.appendChild(option('new', '+ Nieuw ingrediënt'));
        select.appendChild(option('skip', 'Niet meenemen'));
        var all = el('optgroup');
        all.label = 'Alle ingrediënten';
        ingredients.forEach(function (i) { all.appendChild(option(i.id, i.name)); });
        select.appendChild(all);
        select.value = String(line.choice);

        var newName = el('input', 'import-new');
        newName.type = 'text';
        newName.maxLength = 80;
        newName.value = line.new_name;
        newName.setAttribute('aria-label', 'Naam van het nieuwe ingrediënt');

        function sync() {
            li.classList.toggle('is-skip', select.value === 'skip');
            li.classList.toggle('is-new', select.value === 'new');
            newName.hidden = select.value !== 'new';
        }
        select.addEventListener('change', sync);
        sync();

        pick.appendChild(amount);
        pick.appendChild(unit);
        pick.appendChild(select);
        pick.appendChild(newName);
        li.appendChild(pick);

        li._import = { line: line, amount: amount, unit: unit, select: select, newName: newName };
        return li;
    }

    var importPaste = document.getElementById('importPaste');
    var importUrl   = '';

    /* Stap 1 (zelf plakken) of stap 2 (koppelen) laten zien. */
    function importStep(paste) {
        importPaste.hidden = !paste;
        Array.prototype.forEach.call(importModal.querySelectorAll('[data-import-step]'), function (node) {
            node.hidden = paste;
        });
        importModal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function openPaste(message) {
        importPaste.reset();
        document.getElementById('importTitle').textContent = 'Zelf plakken';
        importStep(true);
        toast(message);
        document.getElementById('f-paste-name').focus();
    }

    if (importPaste) {
        importPaste.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = importPaste.querySelector('button[type="submit"]');
            var f   = importPaste.elements;
            btn.disabled = true;

            postJson('api/admin_import.php', {
                csrf: cfg.csrf,
                action: 'paste',
                url: importUrl,
                name: f.name.value,
                servings: parseInt(f.servings.value, 10) || 4,
                ingredients: f.ingredients.value,
                steps: f.steps.value
            }).then(openImport)
              .catch(function (err) { toast(err.message, true); })
              .then(function () { btn.disabled = false; });
        });
    }

    function openImport(data) {
        importData = data;
        document.getElementById('importTitle').textContent = data.name || 'Ingrediënten koppelen';
        importStep(false);
        importList.innerHTML = '';
        data.lines.forEach(function (line) { importList.appendChild(importRow(line, data.ingredients)); });
    }

    function closeImport() {
        if (!importModal) { return; }
        importModal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    function confirmImport() {
        var names   = {};
        importData.ingredients.forEach(function (i) { names[i.id] = i.name; });

        var lines   = [];
        var byName  = {};
        var choices = [];

        Array.prototype.forEach.call(importList.children, function (li) {
            var r      = li._import;
            var choice = r.select.value;

            if (choice === 'skip') {
                choices.push({ alias: r.line.name, ingredient_id: null });
                return;
            }

            var name = choice === 'new' ? r.newName.value.trim().toLowerCase() : names[choice];
            if (!name) { return; }
            if (choice !== 'new') {
                choices.push({ alias: r.line.name, ingredient_id: parseInt(choice, 10) });
            }

            var amount = parseFloat(r.amount.value.replace(',', '.'));
            amount = isNaN(amount) ? null : amount;
            var unit = amount === null ? '' : r.unit.value;

            // Twee regels, zelfde ingrediënt ("1 ui" en "1 rode ui" => ui):
            // optellen als de eenheid klopt, anders telt de eerste.
            if (byName[name]) {
                var prev = byName[name];
                if (prev.unit === unit && prev.amount !== null && amount !== null) {
                    prev.amount += amount;
                }
                return;
            }
            byName[name] = { name: name, amount: amount, unit: unit };
            lines.push(byName[name]);
        });

        if (choices.length) {
            postJson('api/admin_import.php', { csrf: cfg.csrf, action: 'learn', choices: choices })
                .catch(function () { /* niet onthouden is geen ramp */ });
        }

        var text = lines.map(function (l) {
            return [formatImportAmount(l.amount), l.unit, l.name].filter(Boolean).join(' ');
        }).join('\n');

        closeImport();
        openRecipeEdit({
            id: 0,
            name: importData.name,
            category: importData.category,
            effort: importData.effort,
            servings: importData.servings,
            ingredients: text,
            steps: importData.steps,
            notes: '',
            url: importData.url,
            is_mine: 1,
            preference: 0,
            season: []
        });
    }

    document.addEventListener('click', function (e) {
        var start = e.target.closest('[data-import-recipe]');
        if (start) {
            e.preventDefault();
            var url = prompt('Link naar het recept (Leukerecepten, 24Kitchen, Jumbo, ...):');
            if (!url) { return; }

            importUrl = url.trim();
            start.disabled = true;
            postJson('api/admin_import.php', { csrf: cfg.csrf, action: 'fetch', url: importUrl })
                .then(function (data) {
                    if (data.paste) { openPaste(data.message); } else { openImport(data); }
                })
                .catch(function (err) { toast(err.message, true); })
                .then(function () { start.disabled = false; });
            return;
        }
        if (e.target.closest('[data-close-import]')) {
            e.preventDefault();
            closeImport();
        }
        if (e.target.closest('[data-import-confirm]')) {
            e.preventDefault();
            confirmImport();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && importModal && !importModal.hidden) {
            closeImport();
        }
    });

    /* ---------- admin: instellingen ---------- */

    var settingsForm = document.getElementById('settingsForm');

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn = settingsForm.querySelector('button[type="submit"]');
            btn.disabled = true;

            postJson('api/admin_settings.php', {
                csrf: cfg.csrf,
                default_servings: parseInt(settingsForm.elements.default_servings.value, 10),
                planning_days: parseInt(settingsForm.elements.planning_days.value, 10)
            }).then(function (data) {
                settingsForm.elements.default_servings.value = data.value;
                settingsForm.elements.planning_days.value = data.planning_days;
                toast(data.notice);
            }).catch(function (err) {
                toast(err.message, true);
            }).then(function () {
                btn.disabled = false;
            });
        });
    }

    /* ---------- admin: voorraadlijst ---------- */

    var pantryForm = document.getElementById('pantryForm');

    if (pantryForm) {
        pantryForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var btn = pantryForm.querySelector('button[type="submit"]');
            btn.disabled = true;

            var checked = Array.prototype.map.call(
                pantryForm.querySelectorAll('input[name="pantry[]"]:checked'),
                function (cb) { return parseInt(cb.value, 10); }
            );

            postJson('api/admin_pantry.php', { csrf: cfg.csrf, pantry: checked })
                .then(function (data) {
                    toast(data.notice);
                })
                .catch(function (err) {
                    toast(err.message, true);
                })
                .then(function () {
                    btn.disabled = false;
                });
        });
    }

    /* ---------- admin: ingrediënten samenvoegen ---------- */

    var ingredientMergeForm = document.getElementById('ingredientMergeForm');

    if (ingredientMergeForm) {
        ingredientMergeForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var f      = ingredientMergeForm.elements;
            var fromId = parseInt(f.from_id.value, 10);
            var intoId = parseInt(f.into_id.value, 10);

            if (!fromId || !intoId) { return; }
            if (fromId === intoId) {
                toast('Kies twee verschillende ingrediënten.', true);
                return;
            }

            var btn = ingredientMergeForm.querySelector('button[type="submit"]');
            btn.disabled = true;

            postJson('api/admin_ingredient_merge.php', { csrf: cfg.csrf, from_id: fromId, into_id: intoId })
                .then(function (data) {
                    applyAdminData(data);
                    ingredientMergeForm.reset();
                    toast(data.notice);
                })
                .catch(function (err) {
                    toast(err.message, true);
                })
                .then(function () {
                    btn.disabled = false;
                });
        });
    }

    /* ---------- week weer van het slot ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-unlock]');
        if (!btn) { return; }

        e.preventDefault();

        if (!confirm('Het menu van deze week weer kunnen wijzigen? ' +
                     'Wat al in Bring staat verandert daar niet meer door.')) {
            return;
        }

        btn.disabled = true;

        postJson('api/lock.php', {
            csrf: cfg.csrf,
            week_id: cfg.weekId,
            locked: false
        }).then(function () {
            window.location.reload();
        }).catch(function (err) {
            btn.disabled = false;
            toast(err.message, true);
        });
    });

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

    /* ---------- gebruikers (admin) ---------- */

    var userTableBody  = document.getElementById('userTableBody');
    var userCreateForm = document.getElementById('userCreateForm');

    function userAction(payload) {
        payload.csrf = cfg.csrf;
        return postJson('api/admin_users.php', payload).then(function (data) {
            if (userTableBody) { userTableBody.innerHTML = data.user_table; }
            toast(data.notice);
        }).catch(function (err) {
            toast(err.message, true);
            throw err;
        });
    }

    if (userCreateForm) {
        userCreateForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var f = userCreateForm.elements;
            userAction({
                action: 'create',
                username: f.username.value,
                password: f.password.value,
                role: f.role.value
            }).then(function () { userCreateForm.reset(); }, function () {});
        });
    }

    document.addEventListener('change', function (e) {
        var select = e.target.closest('[data-user-role]');
        if (!select) { return; }
        userAction({
            action: 'role',
            id: parseInt(select.getAttribute('data-user-role'), 10),
            role: select.value
        }).catch(function () {
            // Geweigerd (laatste beheerder): tabel opnieuw laden zet hem terug.
            window.location.reload();
        });
    });

    document.addEventListener('click', function (e) {
        var pwBtn = e.target.closest('[data-user-password]');
        if (pwBtn) {
            var pw = prompt('Nieuw wachtwoord voor ' + pwBtn.getAttribute('data-name') + ' (minstens 8 tekens):');
            if (pw) {
                userAction({ action: 'password', id: parseInt(pwBtn.getAttribute('data-user-password'), 10), password: pw })
                    .catch(function () {});
            }
            return;
        }

        var delBtn = e.target.closest('[data-user-delete]');
        if (delBtn && confirm('Gebruiker ' + delBtn.getAttribute('data-name') + ' wissen?')) {
            userAction({ action: 'delete', id: parseInt(delBtn.getAttribute('data-user-delete'), 10) })
                .catch(function () {});
        }
    });

    /* ---------- wanneer vorige keer ---------- */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-history]');
        if (btn) { toast(btn.getAttribute('data-history')); }
    });

    /* ---------- vandaag in beeld op de telefoon ---------- */

    var today = document.querySelector('.day-today');
    if (today && window.matchMedia('(max-width: 700px)').matches && !location.hash) {
        today.scrollIntoView({ block: 'start' });
    }

    /* ---------- delen en afdrukken ---------- */

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-print]')) {
            window.print();
            return;
        }
        if (!e.target.closest('[data-share]')) { return; }

        var url = location.origin + location.pathname + '?week=' + encodeURIComponent(cfg.weekStart);
        if (navigator.share) {
            navigator.share({ title: 'Weekmenu', url: url }).catch(function () {});
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () { toast('Link gekopieerd.'); });
        } else {
            prompt('Kopieer de link:', url);
        }
    });

    /* ---------- afvinken van een ander apparaat bijhouden ---------- */

    /*
     * Twee telefoons in de winkel: elke 15 seconden, en meteen als je de
     * app weer voor je haalt, ophalen wat er afgevinkt is. Alleen zolang de
     * pagina zichtbaar is.
     */

    function syncChecks() {
        if (document.hidden || !cfg.weekId || !document.querySelector('[data-check]')) { return; }

        fetch('api/checks.php?week_id=' + encodeURIComponent(cfg.weekId), { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.checked) { return; }
                var done = {};
                data.checked.forEach(function (item) { done[item] = true; });

                Array.prototype.forEach.call(document.querySelectorAll('[data-check]'), function (box) {
                    var on = !!done[box.getAttribute('data-check')];
                    if (box.checked !== on) {
                        box.checked = on;
                        var label = box.closest('label');
                        if (label) { label.classList.toggle('is-done', on); }
                    }
                });
            })
            .catch(function () {
                // Geen bereik in de winkel: volgende keer beter.
            });
    }

    if (cfg.weekId && document.querySelector('[data-check]')) {
        setInterval(syncChecks, 15000);
        document.addEventListener('visibilitychange', syncChecks);
    }

    /* ---------- pushmeldingen ---------- */

    var pushBtn = document.querySelector('[data-push]');

    function pushSupported() {
        return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    }

    function keyToBytes(b64) {
        var raw = atob((b64 + '==='.slice((b64.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
        return out;
    }

    function bytesToKey(buf) {
        return btoa(String.fromCharCode.apply(null, new Uint8Array(buf)))
            .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function showPushState(on) {
        pushBtn.hidden = false;
        pushBtn.classList.toggle('is-on', on);
        pushBtn.title = on ? 'Meldingen staan aan (tik om uit te zetten)' : 'Meldingen aanzetten';
        pushBtn.querySelector('i').className = 'fa-solid ' + (on ? 'fa-bell' : 'fa-bell-slash');
    }

    if (pushBtn && cfg.vapidPublic) {
        if (!pushSupported()) {
            // Laat de knop wel zien, anders weet je op een iPhone niet waarom.
            showPushState(false);
        } else {
            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription();
            }).then(function (sub) {
                showPushState(!!sub);
            });
        }

        pushBtn.addEventListener('click', function () {
            if (!pushSupported()) {
                toast('Meldingen werken hier niet. Op een iPhone: zet de app eerst op je beginscherm en open hem daarvandaan.', true);
                return;
            }

            pushBtn.disabled = true;

            navigator.serviceWorker.ready.then(function (reg) {
                return reg.pushManager.getSubscription().then(function (sub) {
                    if (sub) {
                        return postJson('api/push.php', { csrf: cfg.csrf, action: 'unsubscribe', endpoint: sub.endpoint })
                            .then(function (data) {
                                return sub.unsubscribe().then(function () {
                                    showPushState(false);
                                    toast(data.notice);
                                });
                            });
                    }

                    return Notification.requestPermission().then(function (perm) {
                        if (perm !== 'granted') {
                            throw new Error('Meldingen zijn geblokkeerd. Zet ze aan in de instellingen van je browser.');
                        }
                        return reg.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: keyToBytes(cfg.vapidPublic)
                        });
                    }).then(function (sub) {
                        return postJson('api/push.php', {
                            csrf: cfg.csrf,
                            action: 'subscribe',
                            endpoint: sub.endpoint,
                            p256dh: bytesToKey(sub.getKey('p256dh')),
                            auth: bytesToKey(sub.getKey('auth'))
                        });
                    }).then(function (data) {
                        showPushState(true);
                        toast(data.notice);
                        // Meteen een testmelding, dan weet je dat het werkt.
                        return postJson('api/push.php', { csrf: cfg.csrf, action: 'test' });
                    });
                });
            }).catch(function (err) {
                toast(err.message, true);
            }).then(function () {
                pushBtn.disabled = false;
            });
        });
    }

}());
