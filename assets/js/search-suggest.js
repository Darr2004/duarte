/**
 * DuaRTE — Intelligent Search Bar & Auto-Suggest
 * Provides instant autocomplete dropdown, search highlighting, keyboard navigation,
 * and quick-clear controls for search inputs.
 */
(function () {
  'use strict';

  function initSearchSuggest(options) {
    var inputId = options.inputId || 'q';
    var input = document.getElementById(inputId);
    if (!input) return;

    var type = options.type || 'assets';
    var endpoint = options.endpoint || (window.BASE_URL || '/duarte') + '/inventory/search_suggest.php';
    var form = input.closest('form');
    var minChars = options.minChars !== undefined ? options.minChars : 1;
    var debounceTimer = null;
    var activeIndex = -1;
    var currentItems = [];

    // Ensure input container styling
    var parent = input.parentElement;
    if (!parent.classList.contains('search-input-wrap')) {
      var wrap = document.createElement('div');
      wrap.className = 'search-input-wrap';
      parent.insertBefore(wrap, input);
      wrap.appendChild(input);
      parent = wrap;
    }

    // Add clear button if not present
    var clearBtn = parent.querySelector('.search-clear-btn');
    if (!clearBtn) {
      clearBtn = document.createElement('button');
      clearBtn.type = 'button';
      clearBtn.className = 'search-clear-btn';
      clearBtn.setAttribute('aria-label', 'Clear search');
      clearBtn.innerHTML = '&times;';
      parent.appendChild(clearBtn);
    }

    // Toggle clear button visibility
    function updateClearBtn() {
      if (input.value.trim().length > 0) {
        clearBtn.classList.add('visible');
      } else {
        clearBtn.classList.remove('visible');
      }
    }
    updateClearBtn();

    clearBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var hadValue = input.value.trim().length > 0;
      input.value = '';
      updateClearBtn();
      closeDropdown();
      input.focus();

      // If form was already submitted with search, submit now to reset
      if (hadValue && form) {
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('q') && urlParams.get('q') !== '') {
          // Remove q and reset page to 1
          urlParams.delete('q');
          urlParams.delete('page');
          var qs = urlParams.toString();
          window.location.href = window.location.pathname + (qs ? '?' + qs : '');
        } else {
          // Trigger live table search filter if registered
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }
    });

    // Create dropdown element
    var menu = document.createElement('div');
    menu.className = 'search-suggest-dropdown';
    menu.style.display = 'none';
    menu.style.backgroundColor = '#ffffff';
    menu.style.zIndex = '99999';
    parent.appendChild(menu);

    function closeDropdown() {
      menu.style.display = 'none';
      menu.innerHTML = '';
      activeIndex = -1;
      currentItems = [];
    }

    function highlightMatch(text, q) {
      if (!q) return escapeHtml(text);
      var lowerText = text.toLowerCase();
      var lowerQ = q.toLowerCase();
      var idx = lowerText.indexOf(lowerQ);
      if (idx === -1) return escapeHtml(text);

      var before = escapeHtml(text.slice(0, idx));
      var match = escapeHtml(text.slice(idx, idx + q.length));
      var after = escapeHtml(text.slice(idx + q.length));
      return before + '<mark class="search-suggest-hit">' + match + '</mark>' + after;
    }

    function escapeHtml(str) {
      return String(str || '').replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
    }

    function renderDropdown(items, q) {
      menu.innerHTML = '';
      currentItems = items;
      activeIndex = -1;

      if (!items || items.length === 0) {
        var empty = document.createElement('div');
        empty.className = 'search-suggest-empty';
        empty.innerHTML = '<span>No matching results for "<strong>' + escapeHtml(q) + '</strong>"</span>';
        menu.appendChild(empty);
        menu.style.display = 'block';
        return;
      }

      var list = document.createElement('div');
      list.className = 'search-suggest-list';

      items.forEach(function (it, idx) {
        var row = document.createElement('div');
        row.className = 'search-suggest-item';
        row.setAttribute('data-index', idx);

        var titleHtml = highlightMatch(it.title, q);
        var badgeHtml = it.badge ? '<span class="badge ' + escapeHtml(it.badge_class || '') + '" style="font-size:0.75rem; padding:0.15rem 0.45rem;">' + escapeHtml(it.badge) + '</span>' : '';
        var subtitleHtml = it.subtitle ? '<div class="search-suggest-sub">' + highlightMatch(it.subtitle, q) + '</div>' : '';

        row.innerHTML = 
          '<div style="display:flex; justify-content:space-between; align-items:center; gap:0.5rem;">' +
            '<span class="search-suggest-title">' + titleHtml + '</span>' +
            badgeHtml +
          '</div>' +
          subtitleHtml;

        row.addEventListener('mouseenter', function () {
          setActiveIndex(idx);
        });

        row.addEventListener('mousedown', function (e) {
          // Prevent input blur before click registers
          e.preventDefault();
        });

        row.addEventListener('click', function (e) {
          e.preventDefault();
          selectItem(it);
        });

        list.appendChild(row);
      });

      menu.appendChild(list);

      // Bottom footer to search full catalog
      var footer = document.createElement('div');
      footer.className = 'search-suggest-footer';
      footer.innerHTML = 'Press <kbd>Enter</kbd> to search or click any item';
      menu.appendChild(footer);

      menu.style.display = 'block';
    }

    function setActiveIndex(idx) {
      activeIndex = idx;
      var rows = menu.querySelectorAll('.search-suggest-item');
      rows.forEach(function (r, i) {
        if (i === activeIndex) {
          r.classList.add('is-active');
          r.scrollIntoView({ block: 'nearest' });
        } else {
          r.classList.remove('is-active');
        }
      });
    }

    function selectItem(it) {
      if (!it) return;
      input.value = it.value || it.title;
      updateClearBtn();
      closeDropdown();

      if (form) {
        // If there's an action or hidden page param, remove page so search begins on page 1
        var pageInput = form.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = '1';
        form.submit();
      }
    }

    function fetchSuggestions(q) {
      var url = endpoint + '?type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(q);
      fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          // If query has changed in the meantime, ignore old response
          if (input.value.trim() !== q) return;
          renderDropdown(data.results || [], q);
        })
        .catch(function () {
          closeDropdown();
        });
    }

    // Input event
    input.addEventListener('input', function () {
      updateClearBtn();
      clearTimeout(debounceTimer);
      var q = input.value.trim();

      if (q.length < minChars) {
        closeDropdown();
        return;
      }

      debounceTimer = setTimeout(function () {
        fetchSuggestions(q);
      }, 160);
    });

    // Keyboard navigation
    input.addEventListener('keydown', function (e) {
      if (menu.style.display !== 'none' && currentItems.length > 0) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          var next = activeIndex + 1;
          if (next >= currentItems.length) next = 0;
          setActiveIndex(next);
          return;
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          var prev = activeIndex - 1;
          if (prev < 0) prev = currentItems.length - 1;
          setActiveIndex(prev);
          return;
        } else if (e.key === 'Enter') {
          if (activeIndex >= 0 && activeIndex < currentItems.length) {
            e.preventDefault();
            selectItem(currentItems[activeIndex]);
            return;
          }
        } else if (e.key === 'Escape') {
          e.preventDefault();
          closeDropdown();
          return;
        }
      }
    });

    // Close on click outside
    document.addEventListener('click', function (e) {
      if (!parent.contains(e.target)) {
        closeDropdown();
      }
    });

    // Reopen on focus if query has text
    input.addEventListener('focus', function () {
      var q = input.value.trim();
      if (q.length >= minChars && menu.style.display === 'none') {
        fetchSuggestions(q);
      }
    });
  }

  window.initSearchSuggest = initSearchSuggest;
})();
