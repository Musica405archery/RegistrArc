// RegistrArc - assets/js/index.js (scripts de index.php)

function getSelectedEngagementIds() {
    const selected = document.querySelectorAll('.engagement-checkbox:checked');
    const uniqueValues = new Set();

    selected.forEach(cb => {
        if (cb.value !== '') {
            uniqueValues.add(cb.value);
        }
    });

    return Array.from(uniqueValues);
}

function updateBulkInvoiceButton() {
    const btn = document.getElementById('bulk_invoice_btn');

    if (!btn) {
        return;
    }

    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length >= 2) {
        btn.style.display = 'inline-flex';
        btn.textContent = 'Facture groupée (' + selectedIds.length + ')';
    } else {
        btn.style.display = 'none';
        btn.textContent = 'Facture groupée';
    }
}

function toggleSelectAll(master) {
    const checked = master.checked;

    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.checked = checked;
    });

    updateBulkInvoiceButton();
}

function confirmBulkAction(type) {
    const selectedIds = getSelectedEngagementIds();
    const count = selectedIds.length;
    const groupInput = document.getElementById('create_cheque_group_after_payment');
    const bulkMethod = document.getElementById('bulk_payment_method');

    if (groupInput) {
        groupInput.value = '0';
    }

    if (count === 0) {
        alert('Aucun engagement sélectionné.');
        return false;
    }

    const actionTexts = {
        validate: 'valider le paiement',
        unvalidate: 'retirer le paiement',
        refund: 'enregistrer le remboursement'
    };
    const actionText = actionTexts[type] || type;

    if (type !== 'validate') {
        return confirm(
            'Vous allez ' + actionText + ' pour ' + count + ' engagement(s).\n\nConfirmer ?'
        );
    }

    const method = bulkMethod ? String(bulkMethod.value || '').toUpperCase() : '';

    if (method === 'CHQ' && count >= 2) {
        const createGroup = confirm(
            'Vous allez valider ' + count + ' engagement(s) en paiement par chèque.\n\n' +
            'Créer un groupement de chèque pour ces engagements ?'
        );

        if (groupInput) {
            groupInput.value = createGroup ? '1' : '0';
        }

        return true;
    }

    return confirm(
        'Vous allez ' + actionText + ' pour ' + count + ' engagement(s).\n\nConfirmer ?'
    );
}

function openNoteDialog(btn) {
    document.getElementById('noteEngagementId').value = btn.dataset.id;
    document.getElementById('noteArcher').textContent = btn.dataset.archer;
    document.getElementById('noteText').value = btn.dataset.note || '';
    document.getElementById('noteLate').checked = btn.dataset.late === '1';
    document.getElementById('noteDialog').showModal();
}

function openRefundDialog(btn) {
    document.getElementById('refundEngagementId').value = btn.dataset.id;
    document.getElementById('refundArcher').textContent = btn.dataset.archer + ' · ' + btn.dataset.amount + ' €';
    document.getElementById('refundDialog').showModal();
}

// Opérateur : prénom mémorisé sur ce poste et joint à chaque envoi de formulaire
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

function factureEngagementPrompt(engagementId) {
    if (!engagementId) {
        alert('Aucun engagement sélectionné pour la facture.');
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(engagementId), '_blank');
    return false;
}

function factureGroupePrompt() {
    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length < 2) {
        alert('Sélectionne au moins 2 engagements pour générer une facture groupée.');
        updateBulkInvoiceButton();
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(selectedIds.join(',')), '_blank');
    return false;
}

function openChequeDeposit() {
    const selectedIds = getSelectedEngagementIds();
    let url = 'bankchequedeposite.php';

    if (selectedIds.length > 0) {
        url += '?ids=' + encodeURIComponent(selectedIds.join(','));
    }

    window.open(url, '_blank');
    return false;
}

function toggleImportRegistrations() {
    const form = document.getElementById('importRegistrationsForm');

    if (!form) {
        return false;
    }

    form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    return false;
}

function confirmImportRegistrations() {
    const input = document.getElementById('registrations_file');

    if (!input || !input.files || input.files.length === 0) {
        alert('Sélectionne un fichier JSON inscriptions.');
        return false;
    }

    return confirm(
        'Importer ce fichier d\'inscriptions ?\n\n' +
        'Les états de paiement correspondants seront appliqués aux inscriptions locales trouvées.\n' +
        'Aucune inscription I@nseo ne sera créée ou supprimée.'
    );
}

function printGreffeList() {
    const table = document.querySelector('.table-wrapper table');

    if (!table) {
        alert('Aucune liste à imprimer.');
        return false;
    }

    const clonedTable = table.cloneNode(true);

    clonedTable.querySelectorAll('tfoot').forEach(tfoot => {
        tfoot.remove();
    });

    clonedTable.querySelectorAll('tr').forEach(row => {
        let cells = row.querySelectorAll('th, td');

        if (cells.length > 0) {
            cells[0].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length > 0) {
            cells[cells.length - 1].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length >= 8) {
            const departCell = cells[6];
            const cibleCell = cells[7];

            if (departCell && cibleCell) {
                const departText = departCell.textContent.trim();
                const cibleText = cibleCell.textContent.trim();

                if (departText !== '' && departText !== '—' && cibleText !== '') {
                    cibleCell.textContent = departText + ' - ' + cibleText;
                } else if (cibleText !== '') {
                    cibleCell.textContent = cibleText;
                } else {
                    cibleCell.textContent = departText;
                }
            }

            cells[6].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length >= 10) {
            cells[8].remove();
        }
    });

    clonedTable.querySelectorAll('thead tr').forEach(row => {
        const cells = row.querySelectorAll('th');

        if (cells.length > 0) {
            cells[0].textContent = 'Eng.';
        }

        if (cells.length > 6) {
            cells[6].textContent = 'Départ - Cible';
        }

        const paiementTh = document.createElement('th');
        paiementTh.textContent = 'Paiement';
        row.appendChild(paiementTh);

        const recuTh = document.createElement('th');
        recuTh.textContent = 'Reçu';
        row.appendChild(recuTh);
    });

    clonedTable.querySelectorAll('tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');

        if (cells.length <= 1) {
            return;
        }

        const paiementTd = document.createElement('td');
        paiementTd.className = 'manual-payment';
        paiementTd.innerHTML = '<span class="manual-line"></span>';
        row.appendChild(paiementTd);

        const recuTd = document.createElement('td');
        recuTd.className = 'manual-recu';
        recuTd.innerHTML = '<span class="manual-checkbox"></span>';
        row.appendChild(recuTd);
    });

    clonedTable.querySelectorAll('a').forEach(link => {
        const span = document.createElement('span');
        span.textContent = link.textContent.replace(/[▲▼]/g, '').trim();
        link.replaceWith(span);
    });

    clonedTable.querySelectorAll('button, input, select, form').forEach(el => {
        el.remove();
    });

    const tbody = clonedTable.querySelector('tbody');

    if (tbody) {
        let currentLetter = '';

        Array.from(tbody.querySelectorAll('tr')).forEach(row => {
            const cells = row.querySelectorAll('td');

            if (cells.length <= 1) {
                return;
            }

            const nomCell = cells[2];

            if (!nomCell) {
                return;
            }

            const rawName = nomCell.textContent.trim();

            if (rawName === '') {
                return;
            }

            let firstLetter = rawName.charAt(0).toUpperCase();

            if (firstLetter.normalize) {
                firstLetter = firstLetter
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '');
            }

            if (!/^[A-Z]$/.test(firstLetter)) {
                firstLetter = '#';
            }

            if (firstLetter !== currentLetter) {
                currentLetter = firstLetter;

                const letterRow = document.createElement('tr');
                letterRow.className = 'letter-separator-row';

                const letterCell = document.createElement('td');
                letterCell.colSpan = cells.length;
                letterCell.textContent = currentLetter;

                letterRow.appendChild(letterCell);
                tbody.insertBefore(letterRow, row);
            }
        });
    }

    const printWindow = window.open('', '_blank', 'width=1200,height=900');

    if (!printWindow) {
        alert('La fenêtre d\'impression a été bloquée par le navigateur.');
        return false;
    }

    const title = "Liste Greffe - Registr'Arc";

    printWindow.document.open();
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <title>${title}</title>
            <style>
                @page {
                    size: landscape;
                    margin: 5mm;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    padding: 0;
                    font-family: Arial, Helvetica, sans-serif;
                    color: #000;
                    background: #fff;
                }

                .print-title {
                    font-size: 13px;
                    font-weight: 700;
                    margin-bottom: 2px;
                }

                .print-subtitle {
                    font-size: 8px;
                    margin-bottom: 5px;
                    color: #333;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    table-layout: fixed;
                    font-size: 7px;
                }

                thead {
                    display: table-header-group;
                }

                th,
                td {
                    border: 1px solid #999;
                    border-bottom: 1.5px solid #555;
                    padding: 2px 2px;
                    text-align: left;
                    vertical-align: top;
                    line-height: 1.1;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                tbody tr {
                    border-bottom: 2px solid #333;
                }

                tbody tr:nth-child(even) {
                    background: #f7f7f7;
                }

                th {
                    background: #e5e5e5;
                    font-weight: 700;
                    border-bottom: 2px solid #333;
                }

                .letter-separator-row td {
                    background: #111827 !important;
                    color: #fff;
                    font-size: 10px;
                    font-weight: 800;
                    text-align: left;
                    padding: 3px 2px;
                    padding-left: 5px;
                    border: 1px solid #111827;
                    letter-spacing: 0.08em;
                }

                th:nth-child(1),
                td:nth-child(1) {
                    width: 3.5%;
                    text-align: center;
                    white-space: nowrap;
                    word-break: normal;
                    overflow-wrap: normal;
                    padding-left: 1px;
                    padding-right: 1px;
                }

                th:nth-child(2),
                td:nth-child(2) {
                    width: 8%;
                }

                th:nth-child(3),
                td:nth-child(3) {
                    width: 10%;
                }

                th:nth-child(4),
                td:nth-child(4) {
                    width: 9%;
                }

                th:nth-child(5),
                td:nth-child(5) {
                    width: 19%;
                }

                th:nth-child(6),
                td:nth-child(6) {
                    width: 7%;
                }

                th:nth-child(7),
                td:nth-child(7) {
                    width: 12%;
                }

                th:nth-child(8),
                td:nth-child(8) {
                    width: 6%;
                    text-align: right;
                    white-space: nowrap;
                }

                th:nth-child(9),
                td:nth-child(9) {
                    width: 7%;
                }

                th:nth-child(10),
                td:nth-child(10) {
                    width: 13%;
                }

                th:nth-child(11),
                td:nth-child(11) {
                    width: 5.5%;
                    text-align: center;
                }

                .status-paid,
                .status-unpaid,
                .target-unassigned {
                    color: #000;
                    font-weight: 700;
                }

                .chip,
                .chip-engagement,
                .payment-method-label,
                .payment-receipt-label {
                    display: inline;
                    padding: 0;
                    background: transparent;
                    color: #000;
                    font-size: inherit;
                    font-weight: inherit;
                }

                .manual-payment {
                    background: #fff;
                }

                .manual-line {
                    display: block;
                    height: 10px;
                    border-bottom: 1px solid #333;
                }

                .manual-recu {
                    text-align: center;
                    background: #fff;
                }

                .manual-checkbox {
                    display: inline-block;
                    width: 10px;
                    height: 10px;
                    border: 1.5px solid #000;
                    vertical-align: middle;
                }

                .print-footer {
                    margin-top: 4px;
                    font-size: 7px;
                    color: #333;
                }
            </style>
        </head>
        <body>
            <div class="print-title">Gestion des engagements - Registr'Arc</div>
            <div class="print-subtitle">Liste Greffe</div>

            ${clonedTable.outerHTML}

            <div class="print-footer">
                Document généré depuis Registr'Arc.
            </div>

            <script>
                window.onload = function() {
                    window.focus();
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);

    printWindow.document.close();

    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkInvoiceButton);
    });

    updateBulkInvoiceButton();
    checkModuleUpdate();
    initOperator();
});

// Bouton "Mise à jour" affiché seulement si une version plus récente existe
function checkModuleUpdate() {
    const btn = document.getElementById('registrarcUpdateBtn');

    if (!btn || !window.fetch) {
        return;
    }

    fetch('update.php?action=check', { credentials: 'same-origin' })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            if (data && data.update) {
                btn.textContent = '⬆ Mise à jour ' + data.remote;
                btn.title = 'Version installée : ' + data.local;
                btn.hidden = false;
            }
        })
        .catch(() => {});
}
