/**
 * "Show all reviews" for a capped product review list (Reviews\ListCap).
 *
 * The server renders every review and marks those past the cap; CSS hides them.
 * This adds the button that shows and hides them, and opens the list when the
 * URL points at a hidden review.
 */
(function () {
    'use strict';

    var OVERFLOW = 'kaupang-reviews__overflow';
    var OPEN = 'kaupang-reviews--open';
    var labels = window.kaupangReviewList;

    function init() {
        // The hide rule sits behind (scripting: enabled). A browser that doesn't know the
        // feature hides nothing, so a button would do nothing: leave the list as it is.
        if (!labels || !window.matchMedia || !window.matchMedia('(scripting: enabled)').matches) {
            return;
        }

        var hidden = document.querySelectorAll('.commentlist > .' + OVERFLOW);
        if (!hidden.length) {
            return;
        }

        var list = hidden[0].parentElement;
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
