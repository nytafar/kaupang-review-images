/**
 * The product review list (Reviews\ListCap): "Show all reviews" and "Read all".
 *
 * The server renders every review in full. CSS hides the reviews past the cap and
 * clamps long review text; this adds the buttons that undo either, and opens the
 * list when the URL points at a hidden review.
 */
(function () {
    'use strict';

    var OVERFLOW = 'kaupang-reviews__overflow';
    var OPEN = 'kaupang-reviews--open';
    var EXPANDED = 'kaupang-review--expanded';
    var labels = window.kaupangReviewList;

    // Both rules sit behind (scripting: enabled). A browser that doesn't know the feature
    // hides and clamps nothing, so a button would do nothing: leave the list as it is.
    function supported() {
        return labels && window.matchMedia && window.matchMedia('(scripting: enabled)').matches;
    }

    /* ---- Read all ------------------------------------------------------- */

    var uid = 0;

    // A review's text is clamped; give it a button only when the clamp actually cuts it.
    function measure(review) {
        var text = review.querySelector('.description');
        if (!text || review.classList.contains(EXPANDED) || !text.offsetHeight) {
            return; // expanded, or hidden past the cap: measured again when shown
        }

        var button = review.querySelector('.kaupang-review__more');
        var cut = text.scrollHeight > text.clientHeight + 1;
        if (!button && !cut) {
            return;
        }

        if (!button) {
            text.id = text.id || 'kaupang-review-text-' + (++uid);
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'kaupang-review__more';
            button.setAttribute('aria-controls', text.id);
            button.setAttribute('aria-expanded', 'false');
            button.textContent = labels.readAll;
            button.addEventListener('click', function () {
                var open = review.classList.toggle(EXPANDED);
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                button.textContent = open ? labels.readLess : labels.readAll;
                if (!open && button.getBoundingClientRect().top < 0) {
                    review.scrollIntoView({ block: 'start' });
                }
            });
            text.after(button);
        }

        button.hidden = !cut;
    }

    function measureAll(list) {
        list.querySelectorAll(':scope > li.review').forEach(measure);
    }

    /* ---- Show all ------------------------------------------------------- */

    function capList(list) {
        var hidden = list.querySelectorAll(':scope > .' + OVERFLOW);
        if (!hidden.length) {
            return;
        }

        var total = list.querySelectorAll(':scope > li').length;
        if (!list.id) {
            list.id = 'kaupang-reviews-list';
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button kaupang-reviews__more';
        button.setAttribute('aria-controls', list.id);

        function set(open) {
            list.classList.toggle(OPEN, open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.textContent = open ? labels.less : labels.more.replace('%d', total);
            if (open) {
                measureAll(list); // the reviews just shown had no height to measure before
            }
        }

        button.addEventListener('click', function () {
            var open = button.getAttribute('aria-expanded') !== 'true';
            set(open);

            if (open) {
                // Reading continues at the first review that was hidden.
                hidden[0].setAttribute('tabindex', '-1');
                hidden[0].focus({ preventScroll: true });
            } else if (button.getBoundingClientRect().top < 0) {
                // The list shrank above the reader: bring the button back into view.
                button.scrollIntoView({ block: 'center' });
            }
        });

        set(false);
        list.after(button);

        function revealTarget() {
            var id;
            try {
                id = decodeURIComponent(window.location.hash.slice(1));
            } catch (e) {
                return;
            }
            var target = id && document.getElementById(id);
            if (target && list.contains(target) && target.closest('.' + OVERFLOW) && !list.classList.contains(OPEN)) {
                set(true);
                target.scrollIntoView();
            }
        }

        revealTarget();
        window.addEventListener('hashchange', revealTarget);
    }

    function init() {
        if (!supported()) {
            return;
        }

        var list = document.querySelector('.commentlist');
        if (!list) {
            return;
        }

        capList(list);
        measureAll(list);

        // Column width decides where a clamp cuts; fonts arriving late change it too.
        var queued = false;
        function again() {
            if (!queued) {
                queued = true;
                window.requestAnimationFrame(function () {
                    queued = false;
                    measureAll(list);
                });
            }
        }
        window.addEventListener('resize', again);
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(again);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
