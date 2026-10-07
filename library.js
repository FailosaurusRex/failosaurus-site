/* Library: live filter of the cards on the current page.
   Without JS the form still works server-side (?q=). */
(function () {
  'use strict';
  var input = document.getElementById('lib-q');
  var grid = document.getElementById('lib-grid');
  if (!input || !grid) return;

  var items = Array.prototype.slice.call(grid.querySelectorAll('.lib-item'));
  var count = document.getElementById('lib-results-count');
  var empty = document.getElementById('lib-js-empty');
  var pager = document.getElementById('lib-pager');
  var start = document.querySelector('.lib-start');
  var baseCount = count ? count.textContent : '';

  function run() {
    var terms = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    var visible = 0;
    items.forEach(function (li) {
      var hay = li.getAttribute('data-search') || '';
      var ok = terms.every(function (t) { return hay.indexOf(t) !== -1; });
      li.hidden = !ok;
      if (ok) visible++;
    });
    if (empty) empty.hidden = visible !== 0;
    grid.hidden = visible === 0;
    if (pager) pager.hidden = terms.length > 0;
    if (start) start.hidden = terms.length > 0;
    if (count) {
      count.textContent = terms.length
        ? visible + (visible === 1 ? ' match' : ' matches') + ' on this page'
        : baseCount;
    }
  }

  input.addEventListener('input', run);
})();
