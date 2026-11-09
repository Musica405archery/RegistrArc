// ============================================================================
// RegistrArc - assets/js/caisse.js
// Grilles de pièces et billets : montant de chaque ligne et total pendant la
// saisie (le serveur refait le calcul à l'enregistrement).
// Fond de caisse : bascule entre la grille détaillée et le montant unique.
// ============================================================================

(function () {
    'use strict';

    // Même présentation que registrarc_format_montant : décimales seulement si besoin
    function format(cents) {
        const text = cents % 100 === 0
            ? String(cents / 100)
            : (cents / 100).toFixed(2).replace('.', ',');

        return text + ' €';
    }

    document.querySelectorAll('[data-cash-grid]').forEach(function (grid) {
        const inputs = grid.querySelectorAll('input[data-cents]');

        if (!inputs.length) {
            return;
        }

        function update() {
            let total = 0;

            inputs.forEach(function (input) {
                const value = input.value.trim();
                const count = /^\d+$/.test(value) ? parseInt(value, 10) : 0;
                const amount = count * parseInt(input.dataset.cents, 10);
                const cell = input.closest('tr').querySelector('[data-amount]');

                total += amount;

                if (cell) {
                    cell.textContent = count ? format(amount) : '';
                }
            });

            grid.querySelector('[data-cash-total]').textContent = format(total);
        }

        grid.addEventListener('input', update);
    });

    document.querySelectorAll('[data-float-form]').forEach(function (form) {
        form.addEventListener('change', function (event) {
            if (event.target.name !== 'float_mode') {
                return;
            }

            form.querySelectorAll('[data-float-mode]').forEach(function (block) {
                block.hidden = block.dataset.floatMode !== event.target.value;
            });
        });
    });
})();
