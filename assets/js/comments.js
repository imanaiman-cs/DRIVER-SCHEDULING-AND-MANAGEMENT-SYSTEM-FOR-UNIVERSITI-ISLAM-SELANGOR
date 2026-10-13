// Comments on a vehicle request (add only: a comment can never be edited or deleted).
// UISComments.mount(containerId, requestId) appends the comment box to the end of a details modal.
(function () {
    'use strict';
    var BASE = (document.currentScript && document.currentScript.src.replace(/\/assets\/js\/comments\.js.*$/, '')) || '';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function fmt(ts) {
        var d = new Date(String(ts).replace(' ', 'T'));
        return isNaN(d) ? String(ts) : d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    function render(box, requestId, data) {
        var html = '<h6 class="fw-bold mb-2"><i class="fas fa-comments text-success me-2"></i>Comments</h6>';
        if (!data.comments.length) {
            html += '<p class="small text-muted fst-italic mb-2">No comments yet.</p>';
        }
        data.comments.forEach(function (c) {
            html += '<div class="border-bottom pb-2 mb-2 small"><strong>' + esc(c.full_name) + '</strong> ' +
                    '<span class="text-muted">(' + esc(c.role_label) + ') &middot; ' + esc(fmt(c.created_at)) + '</span><br>' +
                    esc(c.comment).replace(/\n/g, '<br>') + '</div>';
        });
        if (data.can_post) {
            html += '<div class="mt-2"><textarea class="form-control form-control-sm" rows="2" maxlength="1000" ' +
                    'placeholder="Write a comment..." aria-label="Comment"></textarea>' +
                    '<div class="d-flex justify-content-between align-items-center mt-1">' +
                    '<span class="form-text">Comments cannot be edited or deleted after posting.</span>' +
                    '<button type="button" class="btn btn-sm btn-success">Post comment</button></div></div>';
        }
        box.innerHTML = html;
        var btn = box.querySelector('button');
        if (btn) {
            btn.addEventListener('click', function () {
                var ta = box.querySelector('textarea');
                var text = ta.value.trim();
                if (!text) { ta.focus(); return; }
                btn.disabled = true;
                var body = new URLSearchParams();
                body.append('request_id', String(requestId));
                body.append('comment', text);
                fetch(BASE + '/ajax/add_comment.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res.success) { load(box, requestId); }
                    else { btn.disabled = false; window.UIS && UIS.alert(res.message || 'The comment could not be saved.', { title: 'Comment', tone: 'danger' }); }
                }).catch(function () { btn.disabled = false; });
            });
        }
    }

    function load(box, requestId) {
        fetch(BASE + '/ajax/get_comments.php?request_id=' + encodeURIComponent(requestId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data && data.success) { render(box, requestId, data); } else { box.innerHTML = ''; } })
            .catch(function () { box.innerHTML = ''; });
    }

    window.UISComments = {
        mount: function (containerId, requestId) {
            var host = document.getElementById(containerId);
            if (!host) { return; }
            var old = host.querySelector('.uis-comments');
            if (old) { old.remove(); }
            var box = document.createElement('div');
            box.className = 'uis-comments mt-4 pt-3 border-top';
            host.appendChild(box);
            load(box, requestId);
        }
    };
})();
