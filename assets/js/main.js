/* ============================================================
   UIS Driver Scheduling & Management System
   Main JavaScript — assets/js/main.js
   Universiti Islam Selangor (UIS)

   Dependencies (load before this file):
     - jQuery 3+
     - Bootstrap 5 bundle (includes Popper)
     - DataTables 1.13+
     - SweetAlert2  (loaded below via dynamic injection if absent)
     - Chart.js     (optional – chart helpers degrade gracefully)
   ============================================================ */

/* ── Inline SweetAlert2 CDN loader ─────────────────────────────
   If the page did not include SweetAlert2, we inject it once.  */
(function () {
  'use strict';
  if (typeof Swal === 'undefined') {
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
    s.async = true;
    document.head.appendChild(s);

    var l = document.createElement('link');
    l.rel  = 'stylesheet';
    l.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css';
    document.head.appendChild(l);
  }
})();

/* ============================================================
   MAIN MODULE  (jQuery wrapper for safe $-alias usage)
   ============================================================ */
(function ($) {
  'use strict';

  /* ----------------------------------------------------------
     1. SIDEBAR TOGGLE
        - Mobile:  slide-in offcanvas via .sidebar-open class
        - Desktop: toggle body.sidebar-mini for icon-only mode
     ---------------------------------------------------------- */
  function initSidebar() {
    var $sidebar  = $('.sidebar');
    var $overlay  = $('.sidebar-overlay');
    var $hamb     = $('.topbar-hamburger, .btn-sidebar-toggle');
    var $closeBtn = $('.sidebar-close-btn');
    var MINI_KEY  = 'uis_sidebar_mini';

    // Restore desktop mini-mode preference from localStorage
    if (window.innerWidth >= 992 && localStorage.getItem(MINI_KEY) === '1') {
      $('body').addClass('sidebar-mini');
    }

    // Open sidebar (mobile)
    function openSidebar() {
      $sidebar.addClass('sidebar-open');
      $overlay.addClass('overlay-show');
      $('body').css('overflow', 'hidden');
    }

    // Close sidebar (mobile)
    function closeSidebar() {
      $sidebar.removeClass('sidebar-open');
      $overlay.removeClass('overlay-show');
      $('body').css('overflow', '');
    }

    // Toggle mini-mode (desktop)
    function toggleMini() {
      $('body').toggleClass('sidebar-mini');
      var isMini = $('body').hasClass('sidebar-mini');
      localStorage.setItem(MINI_KEY, isMini ? '1' : '0');
    }

    $hamb.on('click', function () {
      if (window.innerWidth < 992) {
        $sidebar.hasClass('sidebar-open') ? closeSidebar() : openSidebar();
      } else {
        toggleMini();
      }
    });

    $closeBtn.on('click', closeSidebar);
    $overlay.on('click', closeSidebar);

    // Close on ESC
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') closeSidebar();
    });

    // Re-evaluate on resize
    var resizeTimer;
    $(window).on('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        if (window.innerWidth >= 992) closeSidebar();
      }, 200);
    });
  }

  /* ----------------------------------------------------------
     2. ACTIVE SIDEBAR LINK DETECTION  (based on current URL)
     ---------------------------------------------------------- */
  function initActiveSidebarLink() {
    var currentPath = window.location.pathname.split('/').pop() || 'index.php';
    var currentHref = window.location.href;

    $('.sidebar-link, .sidebar-sublink').each(function () {
      var $link = $(this);
      var href  = $link.attr('href') || '';

      if (!href || href === '#' || href.startsWith('javascript')) return;

      // Match by page filename or full URL segment
      var linkPage = href.split('/').pop().split('?')[0];
      var curPage  = currentPath.split('?')[0];

      if (
        linkPage === curPage ||
        (href.length > 1 && currentHref.includes(href.split('?')[0]))
      ) {
        $link.addClass('active');

        // Expand parent collapse if inside a submenu
        var $parentCollapse = $link.closest('.collapse, .sidebar-submenu');
        if ($parentCollapse.length) {
          $parentCollapse.addClass('show');
          var parentId = $parentCollapse.attr('id');
          if (parentId) {
            $('[data-bs-target="#' + parentId + '"], [aria-controls="' + parentId + '"]')
              .attr('aria-expanded', 'true')
              .removeClass('collapsed');
          }
        }

        // Mark parent sidebar-item as active
        $link.closest('.sidebar-item, .sidebar-subitem').addClass('active');
      }
    });
  }

  /* ----------------------------------------------------------
     3. DATATABLES DEFAULT INITIALISATION
        Pages can opt in with <table class="data-table">
        Override per-table with data-page-length="10" etc.
     ---------------------------------------------------------- */
  function initDataTables() {
    if (!$.fn.DataTable) return;

    $('table.data-table:not(.dt-init)').each(function () {
      var $t = $(this);
      $t.addClass('dt-init').DataTable({
        pageLength:  parseInt($t.data('page-length'), 10) || 25,
        responsive:  true,
        order:       [],
        autoWidth:   false,
        dom: '<"row align-items-center mb-2"<"col-sm-6"l><"col-sm-6 text-end"f>>rtip',
        language: {
          search:        '<i class="fas fa-search fa-xs me-1"></i>',
          searchPlaceholder: 'Search…',
          lengthMenu:    'Show _MENU_ entries',
          info:          'Showing _START_–_END_ of _TOTAL_',
          infoEmpty:     'No entries found',
          infoFiltered:  '(filtered from _MAX_ total)',
          zeroRecords:   '<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No matching records found</div>',
          emptyTable:    '<div class="text-center text-muted py-3"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No data available</div>',
          paginate: {
            first:    '<i class="fas fa-angles-left fa-xs"></i>',
            previous: '<i class="fas fa-angle-left fa-xs"></i>',
            next:     '<i class="fas fa-angle-right fa-xs"></i>',
            last:     '<i class="fas fa-angles-right fa-xs"></i>',
          }
        }
      });
    });
  }

  /* ----------------------------------------------------------
     4. AUTO-DISMISS ALERTS  (5 seconds)
     ---------------------------------------------------------- */
  function initAutoDismissAlerts() {
    var DELAY = 5000;

    function dismissAlert($el) {
      $el.fadeOut(400, function () { $el.remove(); });
    }

    // Dismiss alerts already in the DOM
    $('[data-auto-dismiss], .alert-flash').each(function () {
      var $el    = $(this);
      var delay  = parseInt($el.data('auto-dismiss'), 10) || DELAY;
      setTimeout(function () { dismissAlert($el); }, delay);
    });

    // Also handle alerts injected after page load
    $(document).on('DOMNodeInserted', '[data-auto-dismiss]', function () {
      var $el   = $(this);
      var delay = parseInt($el.data('auto-dismiss'), 10) || DELAY;
      setTimeout(function () { dismissAlert($el); }, delay);
    });
  }

  /* ----------------------------------------------------------
     5. CONFIRM DELETE  (SweetAlert2 when available, else native)
     ---------------------------------------------------------- */
  function initConfirmDelete() {
    /**
     * Unified confirm helper.
     * Resolves to true (proceed) or false (cancel).
     * @param  {object} opts – { title, text, confirmText, type }
     * @return {Promise<boolean>}
     */
    window.UIS.confirmDelete = function (opts) {
      opts = $.extend({
        title:       'Delete Record?',
        text:        'This action cannot be undone.',
        confirmText: 'Yes, Delete',
        cancelText:  'Cancel',
        type:        'warning',
      }, opts);

      return new Promise(function (resolve) {
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            title:              opts.title,
            text:               opts.text,
            icon:               opts.type,
            showCancelButton:   true,
            confirmButtonText:  opts.confirmText,
            cancelButtonText:   opts.cancelText,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            reverseButtons:     true,
            focusCancel:        true,
          }).then(function (result) {
            resolve(result.isConfirmed);
          });
        } else {
          resolve(window.confirm(opts.title + '\n' + opts.text));
        }
      });
    };

    // Delegate click handler for [data-delete-url] or [data-confirm-delete]
    $(document).on('click', '[data-delete-url], [data-confirm-delete]', function (e) {
      e.preventDefault();
      var $btn     = $(this);
      var url      = $btn.data('delete-url') || $btn.attr('href');
      var title    = $btn.data('title')  || 'Delete this record?';
      var text     = $btn.data('text')   || 'This action cannot be undone.';
      var $form    = $btn.closest('form');

      UIS.confirmDelete({ title: title, text: text }).then(function (confirmed) {
        if (!confirmed) return;

        if ($form.length) {
          $form.submit();
        } else if (url && url !== '#') {
          window.location.href = url;
        }
      });
    });

    // Also support plain data-confirm attribute (generic confirm, not delete)
    $(document).on('click', '[data-confirm]:not([data-delete-url]):not([data-confirm-delete])', function (e) {
      var msg = $(this).data('confirm') || 'Are you sure?';
      if (typeof Swal !== 'undefined') {
        e.preventDefault();
        var $el = $(this);
        Swal.fire({
          title:              'Confirm Action',
          text:               msg,
          icon:               'question',
          showCancelButton:   true,
          confirmButtonText:  'Yes, proceed',
          cancelButtonText:   'Cancel',
          confirmButtonColor: '#003580',
        }).then(function (result) {
          if (result.isConfirmed) {
            // Re-trigger default action
            if ($el[0].tagName === 'A') {
              window.location.href = $el.attr('href');
            } else if ($el.closest('form').length) {
              $el.closest('form').submit();
            }
          }
        });
      } else {
        if (!window.confirm(msg)) {
          e.preventDefault();
          e.stopImmediatePropagation();
        }
      }
    });
  }

  /* ----------------------------------------------------------
     6. PRIORITY SCORE CALCULATOR
        Formula: weighted average of four factors, scaled 0-10.
        Weights: experience 30%, attendance 25%, performance 30%,
                 certification 15%.

        @param  {number} experience    – years (0–30+)
        @param  {number} attendance    – % (0–100)
        @param  {number} performance   – rating (0–10)
        @param  {boolean|number} certification – 1/true = certified
        @return {number} score 0–10, two decimal places
     ---------------------------------------------------------- */
  window.UIS = window.UIS || {};

  UIS.calculatePriorityScore = function (experience, attendance, performance, certification) {
    // Normalise inputs
    var expScore    = Math.min(parseFloat(experience)  || 0, 30) / 30 * 10;  // 0-10
    var attScore    = Math.min(parseFloat(attendance)  || 0, 100) / 10;       // 0-10
    var perfScore   = Math.min(parseFloat(performance) || 0, 10);             // 0-10
    var certBonus   = (certification == 1 || certification === true) ? 10 : 0; // 0 or 10

    var weighted = (expScore * 0.30) +
                   (attScore * 0.25) +
                   (perfScore * 0.30) +
                   (certBonus * 0.15);

    return Math.min(Math.round(weighted * 100) / 100, 10);
  };

  /**
   * Return a priority category string and CSS class for a given score.
   * @param  {number} score
   * @return {{ label: string, cssClass: string, badgeClass: string }}
   */
  UIS.priorityCategory = function (score) {
    if (score > 7) {
      return { label: 'High',   cssClass: 'score-high',   badgeClass: 'priority-high'   };
    } else if (score >= 5) {
      return { label: 'Medium', cssClass: 'score-medium', badgeClass: 'priority-medium' };
    } else {
      return { label: 'Low',    cssClass: 'score-low',    badgeClass: 'priority-low'    };
    }
  };

  /* ----------------------------------------------------------
     7. REAL-TIME PRIORITY SCORE PREVIEW  (driver form)
        Looks for form fields:  #experience | [name="experience"]
                                #attendance_rate | [name="attendance_rate"]
                                #performance_rating | [name="performance_rating"]
                                #is_certified | [name="is_certified"]
        Outputs to:             #priority-score-preview
                                #priority-score-label
     ---------------------------------------------------------- */
  function initPriorityScorePreview() {
    var $form    = $('#driverForm, form[data-priority-preview]');
    if (!$form.length) return;

    var selectors = {
      experience:   '[name="experience"], #experience',
      attendance:   '[name="attendance_rate"], #attendance_rate',
      performance:  '[name="performance_rating"], #performance_rating',
      cert:         '[name="is_certified"], #is_certified',
    };

    var $preview = $('#priority-score-preview');
    var $label   = $('#priority-score-label');

    function updatePreview() {
      var exp  = parseFloat($form.find(selectors.experience).val())  || 0;
      var att  = parseFloat($form.find(selectors.attendance).val())  || 0;
      var perf = parseFloat($form.find(selectors.performance).val()) || 0;
      var cert = $form.find(selectors.cert).is(':checked') ? 1 :
                 (parseInt($form.find(selectors.cert).val(), 10) === 1 ? 1 : 0);

      var score = UIS.calculatePriorityScore(exp, att, perf, cert);
      var cat   = UIS.priorityCategory(score);

      if ($preview.length) {
        $preview.text(score.toFixed(2))
                .removeClass('score-high score-medium score-low')
                .addClass(cat.cssClass);
      }

      if ($label.length) {
        $label.text(cat.label)
              .removeClass('priority-high priority-medium priority-low')
              .addClass(cat.badgeClass);
      }

      // Also update hidden input if present
      $form.find('[name="priority_score"], #priority_score').val(score.toFixed(2));
    }

    // Bind to all relevant inputs
    var $inputs = $form.find(
      '[name="experience"], #experience,' +
      '[name="attendance_rate"], #attendance_rate,' +
      '[name="performance_rating"], #performance_rating,' +
      '[name="is_certified"], #is_certified'
    );

    $inputs.on('input change', updatePreview);
    updatePreview(); // run once on load
  }

  /* ----------------------------------------------------------
     8. SCHEDULE CONFLICT DETECTION  (client-side)
        Usage: UIS.checkScheduleConflict(schedules, newStart, newEnd, excludeId)
        @param {Array}  schedules  – array of {id, start, end} objects
        @param {string} newStart   – 'YYYY-MM-DD HH:MM' or Date
        @param {string} newEnd     – 'YYYY-MM-DD HH:MM' or Date
        @param {*}      excludeId  – optional schedule id to skip (editing)
        @return {Array} conflicting schedule objects (empty if none)
     ---------------------------------------------------------- */
  UIS.checkScheduleConflict = function (schedules, newStart, newEnd, excludeId) {
    var start = new Date(newStart);
    var end   = new Date(newEnd);

    if (isNaN(start) || isNaN(end)) return [];

    return (schedules || []).filter(function (s) {
      if (excludeId !== undefined && s.id == excludeId) return false;
      var sStart = new Date(s.start);
      var sEnd   = new Date(s.end);
      // Overlap: new starts before existing ends AND new ends after existing starts
      return start < sEnd && end > sStart;
    });
  };

  /**
   * Wire up conflict detection on the schedule form.
   * The form must expose driver schedules via window.driverSchedules or
   * a data-schedules attribute on the form element.
   */
  function initScheduleConflictUI() {
    var $form = $('#scheduleForm, form[data-conflict-check]');
    if (!$form.length) return;

    var $dateField  = $form.find('[name="schedule_date"], #schedule_date');
    var $startField = $form.find('[name="start_time"], #start_time');
    var $endField   = $form.find('[name="end_time"], #end_time');
    var $driverSel  = $form.find('[name="driver_id"], #driver_id');
    var $conflictEl = $('#conflict-alert');
    var editId      = $form.data('edit-id') || null;

    function checkConflict() {
      var date  = $dateField.val();
      var start = $startField.val();
      var end   = $endField.val();

      if (!date || !start || !end) {
        $conflictEl.hide();
        return;
      }

      var newStart  = date + ' ' + start;
      var newEnd    = date + ' ' + end;

      // Validate end > start
      if (new Date(newEnd) <= new Date(newStart)) {
        $conflictEl.show().find('.conflict-message')
          .text('End time must be after start time.');
        return;
      }

      // Retrieve schedules: form attr > global > empty
      var schedules = [];
      try {
        schedules = JSON.parse($form.attr('data-schedules') || '[]');
      } catch (ex) { /* ignore */ }

      if (window.driverSchedules && $driverSel.length) {
        var driverId = $driverSel.val();
        schedules = (window.driverSchedules[driverId] || []);
      }

      var conflicts = UIS.checkScheduleConflict(schedules, newStart, newEnd, editId);

      if (conflicts.length) {
        var msgs = conflicts.map(function (c) {
          return UIS.formatTime(c.start) + ' – ' + UIS.formatTime(c.end);
        }).join(', ');
        $conflictEl.show().find('.conflict-message')
          .text('Conflict with existing schedule(s): ' + msgs);
        $form.find('[type="submit"]').prop('disabled', true);
      } else {
        $conflictEl.hide();
        $form.find('[type="submit"]').prop('disabled', false);
      }
    }

    $dateField.add($startField).add($endField).add($driverSel)
              .on('change input', checkConflict);
  }

  /* ----------------------------------------------------------
     9. DYNAMIC FORM VALIDATION
     ---------------------------------------------------------- */
  function initFormValidation() {
    // HTML5 constraint validation with Bootstrap styling
    $(document).on('submit', 'form[data-validate]', function (e) {
      var form = this;
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      $(form).addClass('was-validated');
    });

    // Real-time input validation feedback
    $(document).on('blur', 'form[data-validate] .form-control, form[data-validate] .form-select',
      function () {
        var $el = $(this);
        if ($el[0].checkValidity()) {
          $el.removeClass('is-invalid').addClass('is-valid');
        } else {
          $el.removeClass('is-valid').addClass('is-invalid');
          // Show custom message if provided
          var msg = $el.data('invalid-msg') || $el[0].validationMessage;
          $el.siblings('.invalid-feedback').text(msg);
        }
      }
    );

    // Password match validation
    $(document).on('input', '[data-match-target]', function () {
      var $el     = $(this);
      var target  = $($el.data('match-target'));
      if ($el.val() !== target.val()) {
        $el[0].setCustomValidity('Passwords do not match.');
        $el.addClass('is-invalid').removeClass('is-valid');
      } else {
        $el[0].setCustomValidity('');
        $el.addClass('is-valid').removeClass('is-invalid');
      }
    });
  }

  /* ----------------------------------------------------------
     10. PRINT FUNCTION FOR REPORTS
     ---------------------------------------------------------- */
  UIS.printReport = function (sectionSelector) {
    var $section = $(sectionSelector || '#print-area, .report-printable').first();
    if (!$section.length) {
      window.print();
      return;
    }

    var printContents = $section.html();
    var win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(
      '<!DOCTYPE html><html><head>' +
      '<meta charset="UTF-8"><title>UIS Report</title>' +
      '<link rel="stylesheet" href="' + UIS._basePath() + 'assets/css/style.css">' +
      '<style>body{padding:20px}@media screen{body{max-width:800px;margin:0 auto}}</style>' +
      '</head><body>' + printContents + '</body></html>'
    );
    win.document.close();
    win.focus();
    win.onload = function () {
      win.print();
      win.close();
    };
  };

  UIS._basePath = function () {
    // Detect the application root relative to the current page
    var scripts  = document.querySelectorAll('script[src*="main.js"]');
    if (scripts.length) {
      var src = scripts[0].src;
      return src.replace(/assets\/js\/main\.js.*$/, '');
    }
    return '/';
  };

  // Bind print button(s)
  $(document).on('click', '[data-print], .btn-print', function (e) {
    e.preventDefault();
    var target = $(this).data('print') || '#print-area';
    UIS.printReport(target);
  });

  /* ----------------------------------------------------------
     11. CHART INITIALISATION HELPERS
     ---------------------------------------------------------- */

  /**
   * Create a Chart.js bar chart.
   * @param {string|HTMLElement} canvasId
   * @param {object} config – { labels, datasets, options, title }
   * @return {Chart|null}
   */
  UIS.createBarChart = function (canvasId, config) {
    return UIS._createChart(canvasId, 'bar', config);
  };

  /**
   * Create a Chart.js line chart.
   */
  UIS.createLineChart = function (canvasId, config) {
    return UIS._createChart(canvasId, 'line', config);
  };

  /**
   * Create a Chart.js doughnut/pie chart.
   */
  UIS.createDoughnutChart = function (canvasId, config) {
    return UIS._createChart(canvasId, 'doughnut', config);
  };

  UIS.createPieChart = function (canvasId, config) {
    return UIS._createChart(canvasId, 'pie', config);
  };

  UIS._createChart = function (canvasId, type, config) {
    if (typeof Chart === 'undefined') return null;

    var canvas = (typeof canvasId === 'string')
      ? document.getElementById(canvasId)
      : canvasId;

    if (!canvas) return null;

    // Destroy existing chart on the same canvas to prevent duplicates
    var existing = Chart.getChart(canvas);
    if (existing) existing.destroy();

    var UIS_COLORS = [
      '#003580', '#0056b3', '#ffd700', '#28a745',
      '#dc3545', '#17a2b8', '#ffc107', '#6f42c1',
      '#fd7e14', '#20c997',
    ];

    var defaults = {
      responsive:          true,
      maintainAspectRatio: true,
      plugins: {
        legend: {
          display:  true,
          position: 'bottom',
          labels: { font: { size: 12 }, padding: 16 },
        },
        title: {
          display:  !!(config.title),
          text:     config.title || '',
          font:     { size: 14, weight: 'bold' },
          color:    '#003580',
          padding:  { bottom: 12 },
        },
        tooltip: {
          backgroundColor: 'rgba(0,31,77,0.9)',
          titleColor: '#ffd700',
          bodyColor:  '#ffffff',
          cornerRadius: 8,
          padding:    10,
        },
      },
    };

    var datasets = (config.datasets || []).map(function (ds, i) {
      var color = ds.color || UIS_COLORS[i % UIS_COLORS.length];
      var base = {
        backgroundColor: ds.backgroundColor || (type === 'line'
          ? 'rgba(0, 53, 128, 0.08)'
          : UIS_COLORS.map(function (c) {
              return UIS._hexToRgba(c, 0.80);
            })),
        borderColor:     ds.borderColor || color,
        borderWidth:     ds.borderWidth || (type === 'line' ? 2 : 0),
        borderRadius:    type === 'bar' ? 4 : 0,
      };
      return $.extend({}, base, ds);
    });

    return new Chart(canvas, {
      type: type,
      data: {
        labels:   config.labels   || [],
        datasets: datasets,
      },
      options: $.extend(true, defaults, config.options || {}),
    });
  };

  UIS._hexToRgba = function (hex, alpha) {
    var r = parseInt(hex.slice(1, 3), 16);
    var g = parseInt(hex.slice(3, 5), 16);
    var b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + (alpha || 1) + ')';
  };

  /* ----------------------------------------------------------
     12. BOOTSTRAP TOOLTIP & POPOVER INITIALISATION
     ---------------------------------------------------------- */
  function initTooltipsPopovers() {
    if (typeof bootstrap === 'undefined') return;

    // Tooltips
    [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
      .forEach(function (el) {
        new bootstrap.Tooltip(el, { trigger: 'hover', boundary: 'window' });
      });

    // Popovers
    [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
      .forEach(function (el) {
        new bootstrap.Popover(el);
      });
  }

  /* ----------------------------------------------------------
     13. SMOOTH SCROLL  (anchor links)
     ---------------------------------------------------------- */
  function initSmoothScroll() {
    $(document).on('click', 'a[href^="#"]:not([data-bs-toggle])', function (e) {
      var target = this.hash;
      if (!target || target === '#') return;
      var $target = $(target);
      if (!$target.length) return;
      e.preventDefault();
      $('html, body').animate({
        scrollTop: $target.offset().top - 80,
      }, 400);
    });
  }

  /* ----------------------------------------------------------
     14. AUTO-REFRESH DASHBOARD STATS  (every 60 s via AJAX)
     ---------------------------------------------------------- */
  function initDashboardAutoRefresh() {
    var $dashboard = $('#dashboardStats, [data-auto-refresh]');
    if (!$dashboard.length) return;

    var interval  = parseInt($dashboard.data('refresh-interval'), 10) || 60000; // 60 s
    var endpoint  = $dashboard.data('refresh-url') || 'ajax/dashboard_stats.php';

    function refresh() {
      $.ajax({
        url:      endpoint,
        method:   'GET',
        dataType: 'json',
        success: function (data) {
          if (!data) return;
          // Update each [data-stat] element
          $('[data-stat]').each(function () {
            var key = $(this).data('stat');
            if (data[key] !== undefined) {
              $(this).text(data[key]);
            }
          });
        },
        error: function () {
          // Fail silently – don't interrupt the user
        },
      });
    }

    setInterval(refresh, interval);
  }

  /* ----------------------------------------------------------
     15. DATE / TIME FORMAT HELPERS
     ---------------------------------------------------------- */

  /**
   * Format an ISO date/datetime string for display.
   * @param  {string} str
   * @param  {object} opts  Intl.DateTimeFormat options
   * @return {string}
   */
  UIS.formatDate = function (str, opts) {
    if (!str) return '—';
    opts = opts || { year: 'numeric', month: 'short', day: 'numeric' };
    try {
      return new Intl.DateTimeFormat('en-MY', opts).format(new Date(str));
    } catch (e) { return str; }
  };

  /**
   * Format as date + time.
   * @param  {string} str
   * @return {string}
   */
  UIS.formatDateTime = function (str) {
    return UIS.formatDate(str, {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit',
    });
  };

  /**
   * Format as time only.
   * @param  {string} str
   * @return {string}
   */
  UIS.formatTime = function (str) {
    return UIS.formatDate(str, { hour: '2-digit', minute: '2-digit' });
  };

  /**
   * Return human-readable relative time ("2 hours ago").
   * @param  {string} str
   * @return {string}
   */
  UIS.timeAgo = function (str) {
    if (!str) return '—';
    try {
      var ms    = Date.now() - new Date(str).getTime();
      var secs  = Math.floor(ms / 1000);
      var mins  = Math.floor(secs  / 60);
      var hours = Math.floor(mins  / 60);
      var days  = Math.floor(hours / 24);

      if (secs  < 60)   return 'just now';
      if (mins  < 60)   return mins  + ' minute'  + (mins  !== 1 ? 's' : '') + ' ago';
      if (hours < 24)   return hours + ' hour'    + (hours !== 1 ? 's' : '') + ' ago';
      if (days  < 30)   return days  + ' day'     + (days  !== 1 ? 's' : '') + ' ago';
      return UIS.formatDate(str);
    } catch (e) { return str; }
  };

  /* ----------------------------------------------------------
     16. STATUS UPDATE AJAX HANDLER
        Usage: <button data-status-url="ajax/update_status.php"
                       data-status="active"
                       data-id="5"
                       data-refresh-table="#driverTable">
     ---------------------------------------------------------- */
  function initStatusUpdateHandler() {
    $(document).on('click', '[data-status-url]', function (e) {
      e.preventDefault();
      var $btn   = $(this);
      var url    = $btn.data('status-url');
      var id     = $btn.data('id');
      var status = $btn.data('status');
      var label  = $btn.data('label') || 'status';
      var $table = $($btn.data('refresh-table') || '');

      if (!url || !id) return;

      $btn.prop('disabled', true);

      $.ajax({
        url:      url,
        method:   'POST',
        data:     { id: id, status: status, _token: $('meta[name="csrf-token"]').attr('content') },
        dataType: 'json',
        success: function (res) {
          if (res && res.success) {
            UIS.notify(res.message || ('Updated ' + label + ' successfully.'), 'success');
            if ($table.length && $.fn.DataTable && $.fn.DataTable.isDataTable($table[0])) {
              $table.DataTable().ajax.reload(null, false);
            } else if (res.redirect) {
              setTimeout(function () { window.location.href = res.redirect; }, 800);
            } else {
              location.reload();
            }
          } else {
            UIS.notify((res && res.message) || 'Failed to update status.', 'danger');
          }
        },
        error: function () {
          UIS.notify('Network error. Please try again.', 'danger');
        },
        complete: function () {
          $btn.prop('disabled', false);
        },
      });
    });
  }

  /* ----------------------------------------------------------
     17. FORM DOUBLE-SUBMIT PREVENTION
     ---------------------------------------------------------- */
  function initFormDoubleSubmit() {
    $(document).on('submit', 'form[data-once]', function () {
      var $btn = $(this).find('[type="submit"]');
      $btn.prop('disabled', true).prepend(
        '<span class="spinner-sm me-1" aria-hidden="true"></span>'
      );
    });
  }

  /* ----------------------------------------------------------
     18. TOAST / NOTIFY HELPER
     ---------------------------------------------------------- */
  UIS.notify = function (message, type, duration) {
    type     = type     || 'info';
    duration = (duration === undefined) ? 4000 : duration;

    var iconMap = {
      success: 'fas fa-circle-check',
      danger:  'fas fa-circle-xmark',
      warning: 'fas fa-triangle-exclamation',
      info:    'fas fa-circle-info',
    };

    var $container = $('#uis-toast-container');
    if (!$container.length) {
      $container = $(
        '<div id="uis-toast-container" ' +
        'style="position:fixed;top:1rem;right:1rem;z-index:9998;' +
        'display:flex;flex-direction:column;gap:.5rem;' +
        'max-width:340px;pointer-events:none;"></div>'
      );
      $('body').append($container);
    }

    var $toast = $(
      '<div class="alert alert-' + type + ' alert-dismissible shadow-sm ' +
      'd-flex align-items-center gap-2 mb-0 py-2 px-3" ' +
      'style="font-size:.855rem;border-radius:10px;pointer-events:all;' +
      'animation:slideDownFade .3s ease both">' +
      '<i class="' + (iconMap[type] || 'fas fa-info-circle') + ' flex-shrink-0"></i>' +
      '<span>' + UIS.escapeHtml(String(message)) + '</span>' +
      '<button type="button" class="btn-close ms-auto" ' +
      'data-bs-dismiss="alert" aria-label="Close" ' +
      'style="pointer-events:all"></button>' +
      '</div>'
    );

    $container.append($toast);

    $toast.find('.btn-close').on('click', function () {
      $toast.fadeOut(300, function () { $toast.remove(); });
    });

    if (duration > 0) {
      setTimeout(function () {
        $toast.fadeOut(350, function () { $toast.remove(); });
      }, duration);
    }

    return $toast;
  };

  /* ----------------------------------------------------------
     19. MISC UTILITIES
     ---------------------------------------------------------- */
  UIS.escapeHtml = function (str) {
    return String(str)
      .replace(/&/g,  '&amp;')
      .replace(/</g,  '&lt;')
      .replace(/>/g,  '&gt;')
      .replace(/"/g,  '&quot;')
      .replace(/'/g,  '&#039;');
  };

  /**
   * Show or hide the full-page loading overlay.
   * @param {boolean} show
   * @param {string}  text  – optional message (default 'Loading…')
   */
  UIS.loading = function (show, text) {
    var $overlay = $('.loading-overlay');
    if (!$overlay.length) {
      $overlay = $(
        '<div class="loading-overlay">' +
        '<div class="spinner-wrapper">' +
        '<div class="spinner-ring"></div>' +
        '<div class="loading-text">Loading…</div>' +
        '</div></div>'
      );
      $('body').append($overlay);
    }

    if (text) $overlay.find('.loading-text').text(text);

    if (show) {
      $overlay.addClass('active');
    } else {
      $overlay.removeClass('active');
    }
  };

  /**
   * Refresh a DataTable.
   * @param {string|jQuery} selector
   */
  UIS.refreshTable = function (selector) {
    var $t = $(selector);
    if ($.fn.DataTable && $.fn.DataTable.isDataTable($t[0])) {
      $t.DataTable().ajax.reload(null, false);
    }
  };

  /* ----------------------------------------------------------
     SIDEBAR SUBMENU COLLAPSE SYNC  (Bootstrap 5 collapse events)
     ---------------------------------------------------------- */
  function initSubmenuSync() {
    $(document).on('show.bs.collapse', '.sidebar-submenu', function () {
      var $sub    = $(this);
      var id      = $sub.attr('id');
      var $toggle = $('[data-bs-target="#' + id + '"], [aria-controls="' + id + '"]');
      $toggle.attr('aria-expanded', 'true').closest('.sidebar-item').addClass('open');
    });

    $(document).on('hide.bs.collapse', '.sidebar-submenu', function () {
      var $sub    = $(this);
      var id      = $sub.attr('id');
      var $toggle = $('[data-bs-target="#' + id + '"], [aria-controls="' + id + '"]');
      $toggle.attr('aria-expanded', 'false').closest('.sidebar-item').removeClass('open');
    });
  }

  /* ----------------------------------------------------------
     DOM READY — Bootstrap everything
     ---------------------------------------------------------- */
  $(function () {
    initSidebar();
    initActiveSidebarLink();
    initDataTables();
    initAutoDismissAlerts();
    initConfirmDelete();
    initPriorityScorePreview();
    initScheduleConflictUI();
    initFormValidation();
    initTooltipsPopovers();
    initSmoothScroll();
    initDashboardAutoRefresh();
    initStatusUpdateHandler();
    initFormDoubleSubmit();
    initSubmenuSync();
  });

})(jQuery);
