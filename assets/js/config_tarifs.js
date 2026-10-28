// RegistrArc - assets/js/config_tarifs.js (scripts de config_tarifs.php)

const registrarcDefaultRuleMatches = {};

Object.keys(registrarcAllowedRuleScopes).forEach(function(scopeCode) {
    if (scopeCode === 'categorie') {
        registrarcDefaultRuleMatches[scopeCode] = 'CL';
    } else if (scopeCode === 'region') {
        registrarcDefaultRuleMatches[scopeCode] = '34';
    } else if (scopeCode === 'departement') {
        registrarcDefaultRuleMatches[scopeCode] = '34';
    } else {
        registrarcDefaultRuleMatches[scopeCode] = 'OUI';
    }
});

function splitAgeClasses(value) {
    if (!value) {
        return [];
    }

    const parts = String(value).split(/[,;\r\n]+/);
    const out = [];
    const seen = new Set();

    parts.forEach(function(part) {
        const clean = String(part || '').trim().toUpperCase();

        if (clean !== '' && !seen.has(clean)) {
            seen.add(clean);
            out.push(clean);
        }
    });

    return out;
}

function renderAgeChips(containerId, inputId, className) {
    const container = document.getElementById(containerId);
    const input = document.getElementById(inputId);

    if (!container || !input) {
        return;
    }

    const values = splitAgeClasses(input.value);
    let html = '';

    values.forEach(function(value) {
        html += '<span class="chip ' + className + '">' + escapeHtml(value) + '</span>';
    });

    container.innerHTML = html;
}

function updateAgePreview() {
    renderAgeChips('agePreviewJeunes', 'age_classes_jeunes', 'age-chip-jeune');
    renderAgeChips('agePreviewAdultes', 'age_classes_adultes', 'age-chip-adulte');
}

function syncAgeClassesFromLists() {
    const jeunesZone = document.getElementById('jeunesZone');
    const adultesZone = document.getElementById('adultesZone');

    const jeunes = jeunesZone ? Array.from(jeunesZone.querySelectorAll('.age-card')).map(function(card) { return card.dataset.classId; }) : [];
    const adultes = adultesZone ? Array.from(adultesZone.querySelectorAll('.age-card')).map(function(card) { return card.dataset.classId; }) : [];

    const jeunesInput = document.getElementById('age_classes_jeunes');
    const adultesInput = document.getElementById('age_classes_adultes');

    if (jeunesInput) {
        jeunesInput.value = jeunes.join(', ');
    }

    if (adultesInput) {
        adultesInput.value = adultes.join(', ');
    }

    const jeunesCount = document.getElementById('jeunesCount');
    const adultesCount = document.getElementById('adultesCount');

    if (jeunesCount) {
        jeunesCount.textContent = jeunes.length;
    }

    if (adultesCount) {
        adultesCount.textContent = adultes.length;
    }

    updateAgePreview();
    updateAgeDropzoneEmptyStates();
}

function updateAgeDropzoneEmptyStates() {
    document.querySelectorAll('.age-dropzone').forEach(function(zone) {
        const hasCards = zone.querySelector('.age-card') !== null;
        let emptyEl = zone.querySelector('.age-dropzone-empty');

        if (!hasCards) {
            if (!emptyEl) {
                emptyEl = document.createElement('div');
                emptyEl.className = 'age-dropzone-empty';
                emptyEl.textContent = 'Glisse une catégorie ici';
                zone.appendChild(emptyEl);
            }
        } else if (emptyEl) {
            emptyEl.remove();
        }
    });
}

function refreshAgeCard(card) {
    const zone = card.closest('.age-dropzone');

    if (!zone) {
        return;
    }

    const group = zone.dataset.group;
    const otherGroup = (group === 'jeunes') ? 'adultes' : 'jeunes';
    card.dataset.group = group;

    const btn = card.querySelector('.age-card-move');

    if (btn) {
        btn.textContent = (group === 'jeunes') ? '→' : '←';
        btn.title = 'Déplacer vers ' + (otherGroup === 'jeunes' ? 'Jeunes' : 'Adultes');
        btn.dataset.target = otherGroup;
    }
}

function moveAgeCardTo(card, targetGroup) {
    const targetZone = document.querySelector('.age-dropzone[data-group="' + targetGroup + '"]');

    if (!targetZone || !card) {
        return;
    }

    targetZone.appendChild(card);
    refreshAgeCard(card);
    syncAgeClassesFromLists();
}

function handleAgeCardMoveClick(btn) {
    const card = btn.closest('.age-card');

    if (!card) {
        return;
    }

    moveAgeCardTo(card, btn.dataset.target);
}

let draggedAgeCard = null;

function handleAgeCardDragStart(e) {
    draggedAgeCard = e.currentTarget;
    e.currentTarget.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';

    try {
        e.dataTransfer.setData('text/plain', e.currentTarget.dataset.classId || '');
    } catch (err) {
        // Certains navigateurs sont plus stricts sur setData, on ignore l'erreur sans bloquer le drag.
    }
}

function handleAgeCardDragEnd(e) {
    e.currentTarget.classList.remove('dragging');
    draggedAgeCard = null;
}

function handleZoneDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
}

function handleZoneDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

function handleZoneDrop(e) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');

    if (draggedAgeCard) {
        e.currentTarget.appendChild(draggedAgeCard);
        refreshAgeCard(draggedAgeCard);
        syncAgeClassesFromLists();
    }
}

function filterAgeCards(term) {
    term = term.trim().toLowerCase();

    document.querySelectorAll('.age-card').forEach(function(card) {
        const text = card.textContent.toLowerCase();
        card.style.display = (term === '' || text.includes(term)) ? '' : 'none';
    });
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function buildRuleScopeOptionsHtml(selectedValue) {
    let html = '';

    Object.keys(registrarcAllowedRuleScopes).forEach(function(scopeCode) {
        const selected = scopeCode === selectedValue ? ' selected' : '';
        html += '<option value="' + scopeCode + '" data-default="' + registrarcDefaultRuleMatches[scopeCode] + '"' + selected + '>' + registrarcAllowedRuleScopes[scopeCode] + '</option>';
    });

    return html;
}

function updateRuleDefaultValue(selectEl) {
    const row = selectEl.closest('tr');

    if (!row) {
        return;
    }

    const input = row.querySelector('input[name^="rule_match"]');

    if (!input) {
        return;
    }

    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const newDefault = selectedOption ? selectedOption.getAttribute('data-default') : '';

    const currentValue = input.value.trim();
    const knownDefaults = Object.values(registrarcDefaultRuleMatches);

    if (currentValue === '' || knownDefaults.includes(currentValue)) {
        input.value = newDefault || '';
        input.dataset.autoValue = '1';
    }
}

function parseRegistrArcDate(value) {
    if (!value || typeof value !== 'string') {
        return 0;
    }

    const normalized = value.trim().replace(' ', 'T');
    const timestamp = Date.parse(normalized);

    if (Number.isNaN(timestamp)) {
        return 0;
    }

    return Math.floor(timestamp / 1000);
}

function formatTimestampForAlert(timestamp) {
    timestamp = parseInt(timestamp, 10);

    if (!timestamp || timestamp <= 0) {
        return 'date inconnue';
    }

    const d = new Date(timestamp * 1000);
    return d.toLocaleString('fr-FR');
}

function getImportedJsonTimestamp(data, file) {
    if (data && typeof data === 'object') {
        if (data.saved_at) {
            const savedTs = parseRegistrArcDate(String(data.saved_at));

            if (savedTs > 0) {
                return {
                    timestamp: savedTs,
                    source: 'saved_at'
                };
            }
        }

        if (data.exported_at) {
            const exportedTs = parseRegistrArcDate(String(data.exported_at));

            if (exportedTs > 0) {
                return {
                    timestamp: exportedTs,
                    source: 'exported_at'
                };
            }
        }
    }

    if (file && file.lastModified) {
        return {
            timestamp: Math.floor(file.lastModified / 1000),
            source: 'date du fichier'
        };
    }

    return {
        timestamp: 0,
        source: 'aucune balise de date'
    };
}

function getImportedTournamentCode(data) {
    if (!data || typeof data !== 'object') {
        return '';
    }

    if (data.tournament_code) {
        return String(data.tournament_code).trim();
    }

    if (data.tournament && typeof data.tournament === 'object' && data.tournament.code) {
        return String(data.tournament.code).trim();
    }

    return '';
}

function handleImportSubmit(event) {
    const form = event.currentTarget;

    if (form.dataset.confirmed === '1') {
        return true;
    }

    const fileInput = document.getElementById('settings_file');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert('Aucun fichier JSON sélectionné.');
        event.preventDefault();
        return false;
    }

    event.preventDefault();

    const file = fileInput.files[0];
    const currentTs = parseInt(form.dataset.currentTs || '0', 10);
    const currentLabel = form.dataset.currentLabel || formatTimestampForAlert(currentTs);
    const currentCode = String(form.dataset.currentCode || '').trim();

    const reader = new FileReader();

    reader.onload = function(e) {
        let data = null;

        try {
            data = JSON.parse(e.target.result);
        } catch (err) {
            alert("Le fichier sélectionné n'est pas un JSON valide.");
            return;
        }

        const imported = getImportedJsonTimestamp(data, file);
        const importedTs = imported.timestamp;
        const importedLabel = formatTimestampForAlert(importedTs);
        const importedCode = getImportedTournamentCode(data);

        let warnings = [];

        if (importedTs <= 0) {
            warnings.push(
                'Impossible de lire une balise de date dans ce JSON.\n' +
                'Balises attendues : saved_at ou exported_at.'
            );
        } else if (currentTs > 0 && importedTs < currentTs) {
            warnings.push(
                'Le JSON importé semble plus ancien que le paramétrage actuellement utilisé.\n' +
                'Date du JSON importé (' + imported.source + ') : ' + importedLabel + '\n' +
                'Date du paramétrage actuel : ' + currentLabel
            );
        }

        if (importedCode !== '' && currentCode !== '' && importedCode.toUpperCase() !== currentCode.toUpperCase()) {
            warnings.push(
                'Le code tournoi du JSON importé est différent du code tournoi courant.\n' +
                'Code importé : ' + importedCode + '\n' +
                'Code courant : ' + currentCode
            );
        }

        if (importedCode === '') {
            warnings.push('Le JSON importé ne contient pas de code tournoi.');
        }

        let message = '';

        if (warnings.length > 0) {
            message =
                'Attention avant import :\n\n' +
                warnings.join('\n\n') +
                '\n\nImporter quand même et remplacer le paramétrage actuel ?';
        } else {
            message =
                'Importer ce fichier JSON et remplacer le paramétrage actuel ?\n\n' +
                'Code tournoi détecté : ' + importedCode + '\n' +
                'Date détectée (' + imported.source + ') : ' + importedLabel;
        }

        if (!confirm(message)) {
            return;
        }

        form.dataset.confirmed = '1';
        form.submit();
    };

    reader.onerror = function() {
        alert('Impossible de lire le fichier sélectionné.');
    };

    reader.readAsText(file);

    return false;
}

function addPaymentModeRow() {
    const tbody = document.getElementById('pmTableBody');

    if (!tbody) {
        return;
    }

    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td><input type="text" name="pm_code[]" value="" placeholder="ESP"></td>
        <td><input type="text" name="pm_label[]" value="" placeholder="Espèces"></td>
        <td style="text-align:center;">
            <button type="button" class="delete-btn" onclick="deletePaymentModeRow(this)">🗑</button>
        </td>
    `;

    tbody.appendChild(tr);
}

function deletePaymentModeRow(btn) {
    const tr = btn.closest('tr');

    if (!tr) {
        return;
    }

    const codeInput = tr.querySelector('input[name="pm_code[]"]');
    const code = codeInput ? codeInput.value.trim().toUpperCase() : '';

    if (code === 'GRA') {
        alert('Le mode de paiement GRA / Gratuit est protégé et ne peut pas être supprimé.');
        return;
    }

    tr.remove();
}

function addRuleRow() {
    const tbody = document.getElementById('rulesTableBody');

    if (!tbody) {
        return;
    }

    const index = tbody.querySelectorAll('tr').length;
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td style="text-align:center;">
            <input type="checkbox" name="rule_active[${index}]" value="1">
        </td>
        <td>
            <input type="text" name="rule_label[${index}]" value="" placeholder="ex : Tarif spécial">
        </td>
        <td>
            <select name="rule_scope[${index}]" onchange="updateRuleDefaultValue(this)">
                ${buildRuleScopeOptionsHtml('categorie')}
            </select>
        </td>
        <td>
            <input type="text" name="rule_match[${index}]" value="CL" data-auto-value="1">
        </td>
        <td>
            <input type="number" name="rule_value[${index}]" value="0" step="0.01">
        </td>
        <td style="text-align:center;">
            <button type="button" class="delete-btn" onclick="deleteRuleRow(this)">🗑</button>
        </td>
    `;

    tbody.appendChild(tr);
}

function deleteRuleRow(btn) {
    const tr = btn.closest('tr');

    if (tr) {
        tr.remove();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importSettingsForm');

    if (importForm) {
        importForm.addEventListener('submit', handleImportSubmit);
    }

    const mainForm = document.getElementById('mainSettingsForm');

    if (mainForm) {
        mainForm.addEventListener('submit', function() {
            syncAgeClassesFromLists();
        });
    }

    syncAgeClassesFromLists();
});
