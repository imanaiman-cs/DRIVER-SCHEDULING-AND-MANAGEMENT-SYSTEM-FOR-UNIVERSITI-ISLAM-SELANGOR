// DSMS calendar (FullCalendar). The server decides WHAT each person may see (calendar/events);
// this file only draws it. The feed address comes from the data-feed attribute of #calendar.
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('calendar');
    if (!el || typeof FullCalendar === 'undefined') {
        if (el) { el.textContent = 'The calendar could not be loaded (check your internet connection).'; }
        return;
    }
    var feed = el.getAttribute('data-feed');
    var vehicle = document.getElementById('cal-vehicle');
    var tasks = document.getElementById('cal-tasks');
    var welfare = document.getElementById('cal-welfare');
    var small = window.matchMedia('(max-width: 768px)').matches;

    var calendar = new FullCalendar.Calendar(el, {
        initialView: small ? 'listMonth' : 'dayGridMonth',
        height: 'auto',
        firstDay: 1,
        nowIndicator: true,
        headerToolbar: { left: 'prev,next today', center: 'title', right: small ? 'listMonth,dayGridMonth' : 'dayGridMonth,timeGridWeek,listMonth' },
        buttonText: { today: 'Today', month: 'Month', week: 'Week', list: 'List' },
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        events: function (info, success, failure) {
            var q = new URLSearchParams({ page: 'calendar/events', start: info.startStr, end: info.endStr });
            if (vehicle && vehicle.value !== '0') { q.set('vehicle', vehicle.value); }
            if (tasks && !tasks.checked) { q.set('tasks', '0'); }
            if (welfare && !welfare.checked) { q.set('welfare', '0'); }
            fetch(feed + '?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(success).catch(failure);
        },
        eventDidMount: function (arg) {
            // Hover text: status and requester (set as plain text, never as HTML).
            var p = arg.event.extendedProps;
            arg.el.title = arg.event.title + (p.status ? ' - ' + p.status : '') + (p.requester ? ' - ' + p.requester : '');
        }
    });
    calendar.render();
    [vehicle, tasks, welfare].forEach(function (c) { if (c) { c.addEventListener('change', function () { calendar.refetchEvents(); }); } });
});
