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
            if (nameEl) { nameEl.classList.remove('is-muted'); }

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
