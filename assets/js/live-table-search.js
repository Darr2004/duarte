/**
 * Instant "as you type" search filter for a list of rows/cards.
 *
 * No Enter, no submit button, no page reload — filters and highlights
 * matching text the moment the person types a character. Used on the
 * Catalog, User Accounts, and Audit Log pages.
 *
 * Usage:
 *   initLiveTableSearch({
 *     inputId: 'q',                  // the <input> to watch
 *     rowSelector: 'tr.item-row',    // rows/cards to filter (must have data-search="...")
 *     emptyRowId: 'live-search-empty',        // optional: shown when nothing matches
 *     emptyTermId: 'live-search-empty-term',  // optional: filled with the typed term
 *     onFilter: function (visibleCount, term) {}  // optional callback, e.g. for a results counter
 *   });
 *
 * Each row needs a `data-search="..."` attribute (lowercase, pre-built
 * server-side) holding everything it should be matchable by. To also
 * highlight the matched text in the UI, wrap the visible text in
 * `<span class="searchable-text">...</span>`.
 */
function initLiveTableSearch(options) {
  var input = document.getElementById(options.inputId);
  if (!input) return;

  var rows = Array.prototype.slice.call(document.querySelectorAll(options.rowSelector));
  var emptyRow = options.emptyRowId ? document.getElementById(options.emptyRowId) : null;
  var emptyTerm = options.emptyTermId ? document.getElementById(options.emptyTermId) : null;

  function highlight(span, term) {
    var original = span.getAttribute('data-original') || span.textContent;
    span.setAttribute('data-original', original);

    if (!term) {
      span.textContent = original;
      return;
    }

    var lowerOriginal = original.toLowerCase();
    var idx = lowerOriginal.indexOf(term);
    if (idx === -1) {
      span.textContent = original;
      return;
    }

    span.textContent = '';
    span.appendChild(document.createTextNode(original.slice(0, idx)));
    var mark = document.createElement('mark');
    mark.className = 'live-search-hit';
    mark.textContent = original.slice(idx, idx + term.length);
    span.appendChild(mark);
    span.appendChild(document.createTextNode(original.slice(idx + term.length)));
  }

  function applyFilter() {
    var term = input.value.trim().toLowerCase();
    var visibleCount = 0;

    rows.forEach(function (row) {
      var haystack = row.getAttribute('data-search') || '';
      var matches = term === '' || haystack.indexOf(term) !== -1;
      row.style.display = matches ? '' : 'none';
      if (matches) visibleCount++;

      row.querySelectorAll('.searchable-text').forEach(function (span) {
        highlight(span, term);
      });
    });

    if (emptyRow) {
      if (term !== '' && visibleCount === 0) {
        if (emptyTerm) emptyTerm.textContent = input.value.trim();
        emptyRow.style.display = '';
      } else {
        emptyRow.style.display = 'none';
      }
    }

    if (typeof options.onFilter === 'function') {
      options.onFilter(visibleCount, input.value.trim());
    }
  }

  input.addEventListener('input', applyFilter);

  // A normal form submit (e.g. pressing Enter) would just reload the page;
  // results are already filtered live, so swallow it.
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') e.preventDefault();
  });

  // Respect a pre-filled ?q= value (e.g. after a category/role filter or
  // a page reload) by applying the filter immediately on load.
  if (input.value.trim() !== '') applyFilter();
}
