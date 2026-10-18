// RegistrArc - assets/js/qrcodes.js (scripts de qrcodes.php)

const URL_KEY = 'RegistrArcKioskUrl';

function renderCodes() {
    const base = document.getElementById('kioskUrl').value.trim();

    document.querySelectorAll('.label').forEach(label => {
        const qr = qrcode(0, 'M');
        qr.addData(base + '?q=' + encodeURIComponent(label.dataset.licence));
        qr.make();
        label.querySelector('.qr').innerHTML = qr.createSvgTag(2, 0);
    });
}

function applyFilters() {
    const session = document.getElementById('sessionFilter').value;
    const dueOnly = document.getElementById('dueOnly').value === '1';

    document.querySelectorAll('.label').forEach(label => {
        const matchesSession = session === '' || label.dataset.sessions.indexOf(',' + session + ',') !== -1;
        const matchesDue = !dueOnly || label.dataset.due === '1';
        label.hidden = !(matchesSession && matchesDue);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('kioskUrl');

    try {
        const saved = localStorage.getItem(URL_KEY);

        if (saved) {
            input.value = saved;
        }
    } catch (e) {
        // adresse proposée par défaut
    }

    input.addEventListener('change', function () {
        try {
            localStorage.setItem(URL_KEY, input.value.trim());
        } catch (e) {
            // mémorisation impossible : sans conséquence
        }

        renderCodes();
    });

    document.getElementById('sessionFilter').addEventListener('change', applyFilters);
    document.getElementById('dueOnly').addEventListener('change', applyFilters);

    renderCodes();
});
