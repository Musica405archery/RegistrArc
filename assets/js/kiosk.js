// RegistrArc - assets/js/kiosk.js (scripts de kiosk.php)

let KIOSK_FILTERS_LOADING = false;

function sessionCheckboxes() {
    return Array.from(document.querySelectorAll('.session-filter'));
}

function ensureAtLeastTwoSessionsSelected() {
    const boxes = sessionCheckboxes();

    if (boxes.length === 0) {
        return;
    }

    const checked = boxes.filter(cb => cb.checked);

    if (checked.length > 0) {
        return;
    }

    boxes.slice(0, Math.min(2, boxes.length)).forEach(cb => {
        cb.checked = true;
    });
}

function selectedSessions() {
    ensureAtLeastTwoSessionsSelected();

    const checked = Array.from(document.querySelectorAll('.session-filter:checked'));
    return checked.map(input => String(input.value || ''));
}

function saveKioskFiltersState() {
    if (KIOSK_FILTERS_LOADING) {
        return;
    }

    ensureAtLeastTwoSessionsSelected();

    const searchInput = document.getElementById('kioskSearch');
    const showPaidCheckbox = document.getElementById('showPaidCheckbox');

    const state = {
        selectedSessions: selectedSessions(),
        showPaid: showPaidCheckbox ? !!showPaidCheckbox.checked : false,
        search: searchInput ? String(searchInput.value || '') : ''
    };

    try {
        localStorage.setItem(KIOSK_FILTERS_KEY, JSON.stringify(state));
    } catch (e) {
        // localStorage indisponible : pas bloquant
    }
}

function loadKioskFiltersState() {
    KIOSK_FILTERS_LOADING = true;

    let state = null;

    try {
        const raw = localStorage.getItem(KIOSK_FILTERS_KEY);

        if (raw) {
            state = JSON.parse(raw);
        }
    } catch (e) {
        state = null;
    }

    const boxes = sessionCheckboxes();
    const searchInput = document.getElementById('kioskSearch');
    const showPaidCheckbox = document.getElementById('showPaidCheckbox');

    if (state && Array.isArray(state.selectedSessions)) {
        boxes.forEach(cb => {
            cb.checked = state.selectedSessions.indexOf(String(cb.value || '')) !== -1;
        });
    } else {
        boxes.forEach(cb => {
            cb.checked = true;
        });
    }

    ensureAtLeastTwoSessionsSelected();

    if (showPaidCheckbox) {
        showPaidCheckbox.checked = state ? !!state.showPaid : false;
    }

    if (searchInput) {
        searchInput.value = state && typeof state.search === 'string' ? state.search : '';
    }

    // Ouverture depuis un QR code (kiosk.php?q=licence) : on montre l'archer, même payé
    const params = new URLSearchParams(window.location.search);
    const q = params.get('q');

    if (q && searchInput) {
        searchInput.value = q;
        boxes.forEach(cb => {
            cb.checked = true;
        });

        if (showPaidCheckbox) {
            showPaidCheckbox.checked = true;
        }

        params.delete('q');

        try {
            history.replaceState(null, '', window.location.pathname + (params.toString() ? '?' + params.toString() : ''));
        } catch (e) {
            // adresse inchangée : sans conséquence
        }
    }

    document.querySelectorAll('.bulk-checkbox').forEach(cb => {
        cb.checked = false;
    });

    document.querySelectorAll('.kiosk-card').forEach(card => {
        card.classList.remove('is-selected');
    });

    const groupInput = document.getElementById('create_cheque_group_after_payment');

    if (groupInput) {
        groupInput.value = '0';
    }

    KIOSK_FILTERS_LOADING = false;
}

function formatEuro(value) {
    const amount = Number(value || 0);

    if (Number.isNaN(amount)) {
        return '0 €';
    }

    if (Math.floor(amount) === amount) {
        return String(amount) + ' €';
    }

    return amount.toLocaleString('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' €';
}

function filterCards() {
    ensureAtLeastTwoSessionsSelected();

    const input = document.getElementById('kioskSearch');
    const query = String(input ? input.value : '').toLowerCase();
    const showPaid = document.getElementById('showPaidCheckbox')
        ? document.getElementById('showPaidCheckbox').checked
        : false;

    const selected = selectedSessions();
    const hasSessionFilter = selected.length > 0;
    const cards = document.querySelectorAll('.kiosk-card');
    let visible = 0;

    cards.forEach(card => {
        const haystack = String(card.dataset.search || '');
        const session = String(card.dataset.session || '');
        const isPaid = String(card.dataset.paid || '0') === '1';

        const matchesSearch = query === '' || haystack.indexOf(query) !== -1;
        const matchesSession = !hasSessionFilter || selected.indexOf(session) !== -1;
        const matchesPaid = showPaid || !isPaid;

        if (matchesSearch && matchesSession && matchesPaid) {
            card.style.display = '';
            visible++;
        } else {
            card.style.display = 'none';

            const checkbox = card.querySelector('.bulk-checkbox');

            if (checkbox) {
                checkbox.checked = false;
            }

            card.classList.remove('is-selected');
        }
    });

    const visibleCount = document.getElementById('visibleCount');

    if (visibleCount) {
        visibleCount.textContent = String(visible);
    }

    const noResults = document.getElementById('noResults');

    if (noResults) {
        noResults.hidden = visible > 0;
    }

    syncToggles();
    updateBulkSummary();
    saveKioskFiltersState();
}

// Aspect "activé" des pastilles de départ, de l'interrupteur Payés et du bouton Reçu
function syncToggles() {
    document.querySelectorAll('.session-pill, .switch, .pay-receipt').forEach(label => {
        const input = label.querySelector('input');
        label.classList.toggle('is-on', !!(input && input.checked));
    });
}

// Les boutons de mode de paiement pilotent la liste cachée bulk_payment_method
function selectPaymentMode(btn) {
    const select = document.getElementById('bulk_payment_method');

    if (select) {
        select.value = String(btn.dataset.code || '');
    }

    syncPaymentModes();
}

function syncPaymentModes() {
    const current = currentBulkPaymentMethod();

    document.querySelectorAll('.pay-mode').forEach(btn => {
        btn.classList.toggle('is-active', String(btn.dataset.code || '').toUpperCase() === current);
    });
}

function clearSearch() {
    const input = document.getElementById('kioskSearch');

    if (input) {
        input.value = '';
        input.focus();
    }

    filterCards();
}

function selectedBulkCheckboxes() {
    return Array.from(document.querySelectorAll('.bulk-checkbox:checked'));
}

function selectedBulkCount() {
    return selectedBulkCheckboxes().length;
}

function selectedBulkTotal() {
    let total = 0;

    selectedBulkCheckboxes().forEach(cb => {
        const card = cb.closest('.kiosk-card');

        if (!card) {
            return;
        }

        const amount = Number(card.dataset.amount || 0);

        if (!Number.isNaN(amount)) {
            total += amount;
        }
    });

    return total;
}

function visibleCards() {
    return Array.from(document.querySelectorAll('.kiosk-card')).filter(card => card.style.display !== 'none');
}

// Coche toutes les lignes affichées, ou les décoche si elles le sont déjà toutes
function toggleSelectAllVisible() {
    const boxes = visibleCards()
        .map(card => card.querySelector('.bulk-checkbox'))
        .filter(cb => cb);
    const allChecked = boxes.length > 0 && boxes.every(cb => cb.checked);

    boxes.forEach(cb => {
        cb.checked = !allChecked;
    });

    updateBulkSummary();
}

function updateSelectAllButton() {
    const btn = document.getElementById('selectAllBtn');

    if (!btn) {
        return;
    }

    const boxes = visibleCards()
        .map(card => card.querySelector('.bulk-checkbox'))
        .filter(cb => cb);
    const allChecked = boxes.length > 0 && boxes.every(cb => cb.checked);

    btn.disabled = boxes.length === 0;
    btn.textContent = allChecked ? 'Tout désélectionner' : 'Tout sélectionner';
}

function updateBulkSummary() {
    const count = selectedBulkCount();
    const total = selectedBulkTotal();

    const countEl = document.getElementById('bulkCount');
    const totalEl = document.getElementById('bulkTotal');

    if (countEl) {
        countEl.textContent = String(count);
    }

    if (totalEl) {
        totalEl.textContent = formatEuro(total);
    }

    const form = document.getElementById('bulkForm');

    if (form) {
        form.classList.toggle('has-selection', count > 0);
    }

    const submit = document.getElementById('bulkSubmit');

    if (submit) {
        submit.disabled = count === 0;
        submit.textContent = count === 0
            ? 'Valider'
            : (total > 0 ? 'Encaisser ' + formatEuro(total) : 'Valider ' + count + ' engagement(s)');
    }

    document.querySelectorAll('.kiosk-card').forEach(card => {
        const cb = card.querySelector('.bulk-checkbox');

        if (cb && cb.checked) {
            card.classList.add('is-selected');
        } else {
            card.classList.remove('is-selected');
        }
    });

    updateSelectAllButton();
}

function currentBulkPaymentMethod() {
    const select = document.getElementById('bulk_payment_method');
    return select ? String(select.value || '').toUpperCase() : '';
}

function confirmBulk() {
    const count = selectedBulkCount();
    const total = selectedBulkTotal();
    const method = currentBulkPaymentMethod();
    const groupInput = document.getElementById('create_cheque_group_after_payment');

    if (groupInput) {
        groupInput.value = '0';
    }

    if (count === 0) {
        alert('Aucun engagement sélectionné.');
        return false;
    }

    if (method === 'CHQ' && count >= 2) {
        const createGroup = confirm(
            'Valider le paiement par chèque pour ' + count + ' engagement(s) ?\n\n' +
            'Total dû : ' + formatEuro(total) + '\n\n' +
            'Créer un chèque groupé pour ces engagements ?'
        );

        if (groupInput) {
            groupInput.value = createGroup ? '1' : '0';
        }

        return true;
    }

    return confirm(
        'Valider le paiement pour ' + count + ' engagement(s) ?\n\n' +
        'Total dû : ' + formatEuro(total)
    );
}

// Opérateur : prénom mémorisé sur ce téléphone et joint à chaque envoi
const OPERATOR_KEY = 'RegistrArcOperator';

function initOperator() {
    const input = document.getElementById('operatorName');
    let name = '';

    try {
        name = localStorage.getItem(OPERATOR_KEY) || '';
    } catch (e) {
        name = '';
    }

    if (input) {
        input.value = name;
        input.addEventListener('input', function () {
            try {
                localStorage.setItem(OPERATOR_KEY, input.value.trim());
            } catch (e) {
                // mémorisation impossible : sans conséquence
            }
        });
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;

        if (!form || String(form.method).toUpperCase() !== 'POST') {
            return;
        }

        let field = form.querySelector('input[name="operator"]');

        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'operator';
            form.appendChild(field);
        }

        field.value = input ? input.value.trim() : name;
    }, true);
}

function toggleCardSelection(event, card) {
    if (event.target.closest('input, button, select, a, label, summary')) {
        return;
    }

    const checkbox = card.querySelector('.bulk-checkbox');

    if (!checkbox) {
        return;
    }

    checkbox.checked = !checkbox.checked;
    updateBulkSummary();
}

document.addEventListener('DOMContentLoaded', function() {
    initOperator();
    loadKioskFiltersState();
    syncPaymentModes();
    updateBulkSummary();
    filterCards();
});

window.addEventListener('pageshow', function() {
    loadKioskFiltersState();
    syncPaymentModes();
    updateBulkSummary();
    filterCards();
});
