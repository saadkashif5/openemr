// Signs the super admin in to a practice: waits for the worker to prepare a one-time password, collects it once, and
// posts the practice's own login form in this same tab. Nothing is stored, and the password lives only in this function.
(function () {
    'use strict';
    var box = document.getElementById('handoff');
    if (!box) {
        return;
    }
    var message = document.getElementById('handoff-message');
    var statusUrl = box.getAttribute('data-status-url');
    var handoffUrl = box.getAttribute('data-handoff-url');
    var csrf = box.getAttribute('data-csrf');
    var deadline = Date.now() + 90000;

    function fail(text) {
        message.textContent = text;
        message.className = 'error-text';
    }

    function signIn() {
        var body = new URLSearchParams();
        body.set('_csrf', csrf);
        fetch(handoffUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('unavailable');
                }
                return response.json();
            })
            .then(function (data) {
                message.textContent = 'Signing in…';
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = data.action;
                Object.keys(data.fields).forEach(function (name) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = data.fields[name];
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            })
            .catch(function () {
                fail('The sign-in is no longer available. Close this tab and click Open again.');
            });
    }

    function poll() {
        if (Date.now() > deadline) {
            fail('This is taking too long. Close this tab and try again.');
            return;
        }
        fetch(statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (state) {
                if (state.status === 'succeeded') {
                    signIn();
                } else if (state.status === 'failed') {
                    fail('Could not prepare the sign-in. The job list shows why.');
                } else {
                    setTimeout(poll, 1000);
                }
            })
            .catch(function () {
                setTimeout(poll, 2000);
            });
    }

    poll();
}());
