<?php
// ============================================================================
// RegistrArc - lib/registrarc_backup.inc.php
// Sauvegarde et restauration de toutes les données d'un concours en un seul
// fichier, depuis le tableau de bord.
// ============================================================================

// Fichiers JSON propres au concours, par nom dans la sauvegarde
function registrarc_backup_files($TourId) {
    return [
        'payments' => registrarc_payments_file_path($TourId),
        'settings' => registrarc_settings_file_path($TourId),
        'cheque_groups' => registrarc_cheque_groups_file_path($TourId),
        'archers' => registrarc_archers_file_path($TourId),
        'journal' => registrarc_journal_file_path($TourId),
        'deposits' => registrarc_deposits_file_path($TourId),
        'closures' => registrarc_closures_file_path($TourId),
        'state' => registrarc_state_file_path($TourId),
    ];
}

function registrarc_build_backup($TourId) {
    $files = [];

    foreach (registrarc_backup_files($TourId) as $key => $path) {
        $data = registrarc_storage_read($path);
        $files[$key] = is_array($data) ? $data : [];
    }

    return [
        'module' => 'RegistrArc',
        'format' => 1,
        'tour_id' => intval($TourId),
        'tour_code' => registrarc_current_tournament_code(),
        'exported_at' => date('Y-m-d H:i:s'),
        'files' => $files,
        'invoices' => registrarc_invoices_for_tour($TourId),
    ];
}

// Retourne ['ok' => bool, 'message' => string, 'warning' => string]
function registrarc_restore_backup($TourId, $bundle) {
    if (!is_array($bundle) || (isset($bundle['module']) ? $bundle['module'] : '') !== 'RegistrArc' || empty($bundle['files']) || !is_array($bundle['files'])) {
        return ['ok' => false, 'message' => "Ce fichier n'est pas une sauvegarde RegistrArc.", 'warning' => ''];
    }

    $warning = '';
    $code = isset($bundle['tour_code']) ? (string)$bundle['tour_code'] : '';

    if ($code !== '' && strtoupper($code) !== strtoupper(registrarc_current_tournament_code())) {
        $warning = 'La sauvegarde vient du concours ' . $code . ', différent du concours ouvert.';
    }

    $restored = 0;

    foreach (registrarc_backup_files($TourId) as $key => $path) {
        if (!isset($bundle['files'][$key]) || !is_array($bundle['files'][$key])) {
            continue;
        }

        // L'ancien contenu reste disponible dans la copie .bak
        if (!registrarc_storage_replace($path, $bundle['files'][$key])) {
            return ['ok' => false, 'message' => "Restauration interrompue sur « $key »." . registrarc_storage_error_suffix(), 'warning' => $warning];
        }

        $restored++;
    }

    // Factures : ajoutées au registre si leur numéro n'existe pas déjà
    if (!empty($bundle['invoices']) && is_array($bundle['invoices'])) {
        registrarc_update_json_file(registrarc_invoices_file_path(), function (array &$registry) use ($bundle, $TourId) {
            $registry += ['counters' => [], 'invoices' => []];

            foreach ($bundle['invoices'] as $number => $invoice) {
                if (isset($registry['invoices'][$number]) || !preg_match('/^(\d{4})-(\d+)$/', (string)$number, $m)) {
                    continue;
                }

                $invoice['tour_id'] = intval($TourId);
                $registry['invoices'][$number] = $invoice;
                $registry['counters'][$m[1]] = max(isset($registry['counters'][$m[1]]) ? intval($registry['counters'][$m[1]]) : 0, intval($m[2]));
            }
        });
    }

    registrarc_journal_append($TourId, [[
        'type' => 'restore',
        'amount' => 0,
        'method' => '',
        'label' => 'Sauvegarde du ' . (isset($bundle['exported_at']) ? $bundle['exported_at'] : '?'),
    ]]);

    registrarc_load_engagements($TourId, true);

    return ['ok' => true, 'message' => $restored . ' fichier(s) restauré(s).', 'warning' => $warning];
}
