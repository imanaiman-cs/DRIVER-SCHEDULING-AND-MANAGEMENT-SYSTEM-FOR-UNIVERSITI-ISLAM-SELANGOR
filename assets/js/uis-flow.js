/* UIS Flow — progressive enhancement of the driver recommendation tables (Assign / Replace pages):
   the Score badge becomes a bar, the best match row is highlighted and the task count gets a small load meter.
   Nothing here changes data; without JavaScript the plain table stays. */
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table').forEach(function (table) {
      var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
      var sc = heads.indexOf('Score'), tk = heads.indexOf('Tasks this month');
      if (sc < 0) { return; }
      var rows = table.querySelectorAll('tbody tr'), maxTasks = 1;
      rows.forEach(function (tr) { var c = tr.children[tk]; if (c) { maxTasks = Math.max(maxTasks, parseInt(c.textContent, 10) || 0); } });
      rows.forEach(function (tr, i) {
        var cell = tr.children[sc]; if (!cell) { return; }
        var v = parseFloat(cell.textContent); if (isNaN(v)) { return; }
        cell.innerHTML = '<div class="rec-bar"><span class="rec-bar-track"><span class="rec-bar-fill" style="width:' + Math.max(4, Math.min(100, v)) + '%"></span></span><span class="rec-bar-num">' + v.toFixed(1) + '</span></div>';
        if (i === 0) { tr.classList.add('rec-best'); }
        var t = tr.children[tk];
        if (t && tk >= 0) {
          var n = parseInt(t.textContent, 10) || 0, on = Math.round(n * 5 / maxTasks), h = '<span class="rec-load" aria-hidden="true">';
          for (var k = 1; k <= 5; k++) { h += '<i class="' + (k <= on ? 'on' : '') + '"></i>'; }
          t.innerHTML = n + h + '</span>';
        }
      });
    });
  });
})();
