/**
 * Quick Find - Admin Menus page: filter table as user types.
 * Vanilla JS so it runs regardless of jQuery.
 */
(function() {
    function init() {
        var form = document.getElementById('ewneater-quick-find-menu-form');
        var filterInput = document.getElementById('ewneater-quick-find-menus-filter');
        if (!form || !filterInput) {
            return;
        }
        var table = form.querySelector('.ewneater-quick-find-menus-table tbody');
        if (!table) {
            return;
        }
        var rows = table.querySelectorAll('.ewneater-quick-find-menu-row');
        var headings = table.querySelectorAll('.ewneater-quick-find-menu-heading');

        function runFilter() {
            var q = (filterInput.value || '').toLowerCase().trim();

            if (q === '') {
                headings.forEach(function(h) { h.classList.remove('ewneater-filter-hidden'); });
                rows.forEach(function(r) { r.classList.remove('ewneater-filter-hidden'); });
                return;
            }

            headings.forEach(function(h) { h.classList.add('ewneater-filter-hidden'); });
            rows.forEach(function(tr) {
                var text = (tr.getAttribute('data-search') || '').toLowerCase();
                if (text.indexOf(q) !== -1) {
                    tr.classList.remove('ewneater-filter-hidden');
                } else {
                    tr.classList.add('ewneater-filter-hidden');
                }
            });

            rows.forEach(function(tr) {
                if (!tr.classList.contains('ewneater-filter-hidden')) {
                    var prev = tr.previousElementSibling;
                    while (prev) {
                        if (prev.classList.contains('ewneater-quick-find-menu-heading')) {
                            prev.classList.remove('ewneater-filter-hidden');
                            break;
                        }
                        prev = prev.previousElementSibling;
                    }
                }
            });
        }

        filterInput.addEventListener('input', runFilter);
        filterInput.addEventListener('keyup', runFilter);
        filterInput.addEventListener('keydown', function(e) {
            if (e.which === 13) {
                e.preventDefault();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
