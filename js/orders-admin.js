/*==========================================================================
 * ORDERS ADMIN ENHANCEMENTS
 *
 * - Search placeholder and Enter key submit
 * - Scrollable order status filters
 * - Mobile layout: reorder order edit boxes (sidebar above main)
 ==========================================================================*/

(function() {
    'use strict';

    function init() {
        var searchInput = document.querySelector('#post-search-input, #product-search-input, #user-search-input, .search-box input[type="search"], .search-box input[name="s"]');
        var placeholder = (typeof ewneaterOrdersAdmin !== 'undefined' && ewneaterOrdersAdmin.searchPlaceholder)
            ? ewneaterOrdersAdmin.searchPlaceholder
            : 'Search orders...';
        if (searchInput) {
            searchInput.placeholder = placeholder;
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var searchForm = document.getElementById('posts-filter') || searchInput.closest('form');
                    if (searchForm) {
                        searchForm.submit();
                    }
                }
            });
        }

        var subsubsub = document.querySelector('.subsubsub');
        if (subsubsub) {
            var wrapper = document.createElement('div');
            wrapper.className = 'subsubsub-container';
            subsubsub.parentNode.insertBefore(wrapper, subsubsub);
            wrapper.appendChild(subsubsub);

            subsubsub.querySelectorAll('li').forEach(function(li) {
                li.childNodes.forEach(function(node) {
                    if (node.nodeType === Node.TEXT_NODE && node.textContent.indexOf('|') !== -1) {
                        node.textContent = '';
                    }
                });
            });
        }

        function reorderOrderEditBoxes() {
            var postBody = document.querySelector('.post-type-shop_order #post-body.columns-2');
            var box1 = document.getElementById('postbox-container-1');
            var box2 = document.getElementById('postbox-container-2');
            if (postBody && box1 && box2 && window.innerWidth <= 782) {
                postBody.style.display = 'flex';
                postBody.style.flexDirection = 'column';
                box2.style.order = 1;
                box2.style.marginBottom = '20px';
                box1.style.order = 2;
            }
        }

        reorderOrderEditBoxes();
        window.addEventListener('resize', reorderOrderEditBoxes);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
