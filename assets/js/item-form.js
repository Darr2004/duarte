/**
 * DuaRTE — shared behavior for the item add & edit forms.
 *
 * Both forms use the same three interactive pieces: a borrow/consume
 * hint tied to the category dropdown, a stall -> layer cascading
 * select, and a repeatable "variant option" row group. This used to be
 * two copies of nearly the same inline <script>, which had already
 * drifted slightly. Each piece below checks for its own elements before
 * doing anything, so this file works whether or not a given page has
 * that piece (e.g. only item_add.php has a plain "quantity on hand"
 * field to hide/show).
 *
 * Expects a `window.ItemFormData` object set by the page before this
 * script loads:
 *   {
 *     layersByStall: { [stallId]: [{id, layer_name}, ...] },
 *     selectedLayerId: number|null
 *   }
 */
(function () {
  var data = window.ItemFormData || { layersByStall: {}, selectedLayerId: null };

  // ---- Borrow/consume hint on the category select --------------------
  var categorySelect = document.getElementById('category_id');
  var borrowHint = document.getElementById('borrow-hint');
  if (categorySelect && borrowHint) {
    var updateBorrowHint = function () {
      var opt = categorySelect.options[categorySelect.selectedIndex];
      var mode = opt ? opt.dataset.borrowMode : 'consume';
      if (mode === 'borrow') {
        borrowHint.textContent = 'Will be borrowed and must be returned.';
      } else if (mode === 'choice') {
        borrowHint.textContent = 'Requester chooses per request — consume it, or borrow and return it.';
      } else {
        borrowHint.textContent = 'Will be issued as a consumable (no return tracking).';
      }
    };
    categorySelect.addEventListener('change', updateBorrowHint);
    updateBorrowHint();
  }

  // ---- Auto item code by category (item_add.php only) -----------------
  // The item_code field is read-only there; this fills it in from
  // inventory/item_code_ajax.php whenever the category changes (and once
  // on load), so it always shows the next "PREFIX-NNN" code for whatever
  // category is currently selected. See includes/functions.php ->
  // next_item_code_for_category() for the actual numbering logic.
  var itemCodeField = document.getElementById('item_code');
  if (categorySelect && itemCodeField && data.autoItemCode && data.itemCodeUrl) {
    var refreshItemCode = function () {
      var url = data.itemCodeUrl + '?category_id=' + encodeURIComponent(categorySelect.value || '');
      itemCodeField.classList.add('is-loading');
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success && json.code) {
            itemCodeField.value = json.code;
          }
        })
        .catch(function () { /* keep whatever code was already showing */ })
        .finally(function () { itemCodeField.classList.remove('is-loading'); });
    };
    categorySelect.addEventListener('change', refreshItemCode);
    // Only fetch on load if the field doesn't already have a code (e.g.
    // re-rendered after a failed submit, where the previous code should
    // stick rather than silently changing under the user).
    if (!itemCodeField.value.trim()) {
      refreshItemCode();
    }
  }

  // ---- "This looks like an existing item" hint (item_add.php only) ---
  // Warns before submit if the name/brand being typed already matches
  // an active item, so a restock doesn't turn into a duplicate catalog
  // entry with its own new code. Purely advisory — never blocks saving.
  var nameField = document.getElementById('name');
  var brandField = document.getElementById('brand');
  var matchBox = document.getElementById('existing-item-match');
  if (nameField && matchBox && data.itemMatchUrl) {
    var matchDebounce = null;
    var escapeHtml = function (s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    };
    var renderMatches = function (matches) {
      if (!matches.length) {
        matchBox.style.display = 'none';
        matchBox.innerHTML = '';
        return;
      }
      var rows = matches.map(function (m) {
        var stockInUrl = data.stockInBaseUrl + '?item_id=' + encodeURIComponent(m.id);
        var brandBit = m.brand ? ' — ' + escapeHtml(m.brand) : '';
        return '<div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; padding:0.4rem 0;">'
          + '<span><span class="mono" style="font-weight:600;">' + escapeHtml(m.item_code) + '</span> '
          + escapeHtml(m.name) + brandBit
          + ' <span style="color:var(--ink-soft);">(' + m.quantity_on_hand + ' ' + escapeHtml(m.unit) + ' on hand)</span></span>'
          + '<a href="' + stockInUrl + '" class="btn btn-outline btn-sm" style="flex-shrink:0;">Record stock in instead</a>'
          + '</div>';
      }).join('');
      matchBox.innerHTML = '<div class="alert alert-warning">'
        + '<strong>Already in the catalog?</strong> This looks similar to an existing item — if this is a delivery of the same part, record it as stock in instead so it keeps its current code.'
        + rows + '</div>';
      matchBox.style.display = 'block';
    };
    var checkForMatch = function () {
      var name = nameField.value.trim();
      if (name.length < 3) {
        matchBox.style.display = 'none';
        matchBox.innerHTML = '';
        return;
      }
      var url = data.itemMatchUrl
        + '?name=' + encodeURIComponent(name)
        + '&brand=' + encodeURIComponent(brandField ? brandField.value.trim() : '')
        + '&category_id=' + encodeURIComponent(categorySelect ? categorySelect.value : '');
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          if (json && json.success) {
            renderMatches(json.matches || []);
          }
        })
        .catch(function () { /* advisory only — fail silently */ });
    };
    var debouncedCheck = function () {
      clearTimeout(matchDebounce);
      matchDebounce = setTimeout(checkForMatch, 400);
    };
    nameField.addEventListener('input', debouncedCheck);
    if (brandField) brandField.addEventListener('input', debouncedCheck);
    if (categorySelect) categorySelect.addEventListener('change', debouncedCheck);
    if (nameField.value.trim()) checkForMatch();
  }

  // ---- Measurement select with "Other (specify)" ----------------------
  var unitSelect = document.getElementById('unit_select');
  var unitOther = document.getElementById('unit_other');
  var unitHidden = document.getElementById('unit');
  if (unitSelect && unitOther && unitHidden) {
    var syncUnitFromSelect = function () {
      if (unitSelect.value === '__other__') {
        unitOther.style.display = '';
        unitHidden.value = unitOther.value.trim();
      } else {
        unitOther.style.display = 'none';
        unitHidden.value = unitSelect.value;
      }
    };

    // Initialize from the current saved unit: select it if it's one of
    // the preset options, otherwise fall back to "Other" with the raw
    // value shown in the text field.
    var current = (data.currentUnit || '').trim();
    var matched = false;
    for (var i = 0; i < unitSelect.options.length; i++) {
      if (unitSelect.options[i].value === current) {
        unitSelect.selectedIndex = i;
        matched = true;
        break;
      }
    }
    if (!matched && current !== '') {
      unitSelect.value = '__other__';
      unitOther.value = current;
    } else if (!matched) {
      unitSelect.value = 'pc';
    }
    syncUnitFromSelect();

    unitSelect.addEventListener('change', syncUnitFromSelect);
    unitOther.addEventListener('input', function () {
      unitHidden.value = unitOther.value.trim();
    });
  }

  // ---- Stall -> layer cascading select --------------------------------
  var stallSelect = document.getElementById('stall_select');
  var layerSelect = document.getElementById('stall_layer_id');
  if (stallSelect && layerSelect) {
    var syncLayerOptions = function () {
      var stallId = stallSelect.value;
      layerSelect.innerHTML = '';
      if (!stallId) {
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '— Select a stall first —';
        layerSelect.appendChild(placeholder);
        return;
      }
      var layers = data.layersByStall[stallId] || [];
      layers.forEach(function (l) {
        var opt = document.createElement('option');
        opt.value = l.id;
        opt.textContent = l.layer_name;
        if (data.selectedLayerId && parseInt(data.selectedLayerId, 10) === l.id) {
          opt.selected = true;
        }
        layerSelect.appendChild(opt);
      });
    };
    stallSelect.addEventListener('change', syncLayerOptions);
    syncLayerOptions();
  }

  // ---- "This item has multiple options" toggle (item_add.php only) ---
  // Keeps the variant/options section out of the way by default, since
  // most items (like the VIC oil filters) are just separate items with
  // a plain quantity — variants are only for one item split into
  // several stock-tracked options (sizes, amperages, etc.).
  var hasVariantsToggle = document.getElementById('hasVariantsToggle');
  var variantSection = document.getElementById('variantSection');
  if (hasVariantsToggle && variantSection) {
    var syncVariantSection = function () {
      variantSection.style.display = hasVariantsToggle.checked ? 'block' : 'none';
      var plainQtyGroup = document.getElementById('plainQtyGroup');
      if (!hasVariantsToggle.checked && plainQtyGroup) {
        plainQtyGroup.style.display = '';
      }
    };
    hasVariantsToggle.addEventListener('change', syncVariantSection);
  }

  // ---- Repeatable variant option rows ---------------------------------
  var rowsContainer = document.getElementById('variantRows');
  var addBtn = document.getElementById('addVariantRow');
  if (rowsContainer && addBtn) {
    var plainQtyGroup = document.getElementById('plainQtyGroup'); // only on item_add.php
    var specificationGroup = document.getElementById('specificationGroup'); // only on item_edit.php

    var makeRow = function () {
      var row = document.createElement('div');
      row.className = 'variant-row';
      row.style.cssText = 'display:flex; gap:0.5rem; margin-bottom:0.5rem;';
      row.innerHTML =
        '<input type="text" name="variant_value[]" placeholder="e.g. 10A" style="flex:2;">' +
        '<input type="text" inputmode="numeric" name="variant_qty[]" value="0" placeholder="Starting qty" style="flex:1;">' +
        '<input type="text" name="variant_note[]" placeholder="Description, e.g. fitment (optional)" style="flex:2;">' +
        '<button type="button" class="btn btn-outline btn-sm variant-row-remove">Remove</button>';
      return row;
    };

    var syncPlainQtyVisibility = function () {
      // The plain "Quantity on hand" field (item_add.php) and the
      // "Specification" note (item_edit.php) only make sense for an
      // item with no options — once any option row has a value, stock
      // (and any per-version spec) is tracked per option instead, so
      // hide these to avoid double-entry confusion.
      var anyFilled = Array.prototype.some.call(
        rowsContainer.querySelectorAll('input[name="variant_value[]"]'),
        function (i) { return i.value.trim() !== ''; }
      );
      if (plainQtyGroup) plainQtyGroup.style.display = anyFilled ? 'none' : '';
      if (specificationGroup) {
        // Once the item already has saved options (data-has-existing),
        // keep the note hidden regardless of the new-row inputs below —
        // those are empty on load and would otherwise wrongly re-reveal it.
        var hasExisting = specificationGroup.dataset.hasExisting === '1';
        specificationGroup.style.display = (anyFilled || hasExisting) ? 'none' : '';
      }
    };

    addBtn.addEventListener('click', function () {
      rowsContainer.appendChild(makeRow());
    });

    rowsContainer.addEventListener('click', function (e) {
      if (!e.target.classList.contains('variant-row-remove')) return;
      var rows = rowsContainer.querySelectorAll('.variant-row');
      if (!plainQtyGroup && rows.length <= 1) {
        // item_edit.php's "add more options" group is allowed to go
        // fully empty (existing variants are listed separately above),
        // so just remove the row.
        e.target.closest('.variant-row').remove();
      } else if (rows.length > 1) {
        e.target.closest('.variant-row').remove();
      } else {
        // item_add.php: keep at least one (empty) row instead of
        // removing the last one, since a brand-new item has nowhere
        // else to show its options.
        e.target.closest('.variant-row').querySelectorAll('input').forEach(function (i) {
          i.value = i.name === 'variant_qty[]' ? '0' : '';
        });
      }
      syncPlainQtyVisibility();
    });

    rowsContainer.addEventListener('input', syncPlainQtyVisibility);
    syncPlainQtyVisibility();

    // Re-populate rows kept from a failed submit (item_edit.php only —
    // item_add.php renders its prefill rows directly in PHP instead).
    (window.ItemFormPrefillVariantRows || []).forEach(function (row) {
      var r = makeRow();
      r.querySelector('input[name="variant_value[]"]').value = row.value;
      r.querySelector('input[name="variant_qty[]"]').value = row.qty;
      r.querySelector('input[name="variant_note[]"]').value = row.note || '';
      rowsContainer.appendChild(r);
    });
  }

  // ---- Plain "Quantity on hand" +/- stepper (item_add.php only) ------
  var qtyOnHandInput = document.getElementById('quantity_on_hand');
  var qtyOnHandMinus = document.getElementById('qtyOnHandMinus');
  var qtyOnHandPlus = document.getElementById('qtyOnHandPlus');
  if (qtyOnHandInput && qtyOnHandMinus && qtyOnHandPlus) {
    var stepOnHandQty = function (delta) {
      var current = parseInt(qtyOnHandInput.value, 10);
      if (isNaN(current)) { current = 0; }
      var next = current + delta;
      if (next < 0) { next = 0; }
      qtyOnHandInput.value = next;
    };
    qtyOnHandMinus.addEventListener('click', function () { stepOnHandQty(-1); });
    qtyOnHandPlus.addEventListener('click', function () { stepOnHandQty(1); });
  }
})();
