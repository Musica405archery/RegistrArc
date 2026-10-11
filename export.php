<?php
// ============================================================================
// RegistrArc - export.php
// Téléchargements pour le trésorier et la sauvegarde
//
// export.php?type=engagements : un engagement par ligne (tableur)
// export.php?type=journal     : toutes les opérations (tableur)
// export.php?type=backup      : sauvegarde complète du concours (JSON)
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

$type = isset($_GET['type']) ? (string)$_GET['type'] : '';
$code = registrarc_safe_filename_part(registrarc_current_tournament_code());
$prefix = 'registrarc_' . ($code !== '' ? $code : 'concours-' . $TourId);
$paymentModes = registrarc_load_payment_modes($TourId);

$modeLabel = function ($code) use ($paymentModes) {
    return isset($paymentModes[$code]) ? $paymentModes[$code] : $code;
};

// Montant au format français, sans séparateur de milliers (lisible par le tableur)
$amount = function ($value) {
    return number_format((float)$value, 2, ',', '');
};

// Tableur : point-virgule et marque UTF-8, comme l'attend Excel en français
function registrarc_send_csv($filename, array $header, array $lines) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ';', '"', '\\');

    foreach ($lines as $line) {
        fputcsv($out, $line, ';', '"', '\\');
    }

    fclose($out);
    exit();
}

if ($type === 'engagements') {
    $lines = [];

    foreach (registrarc_load_engagements($TourId) as $e) {
        $payment = $e['payment'];

        $lines[] = [
            $e['licence'],
            $e['nom'],
            $e['prenom'],
            $e['club'],
            $e['categorie'],
            $e['session'],
            $e['target_label'],
            $e['numero'],
            $amount($e['amount_due']),
            registrarc_state_label($e['state']),
            $e['state'] === 'paid' || $e['state'] === 'refunded' ? $modeLabel($e['payment_method']) : '',
            isset($payment['paid_at']) ? $payment['paid_at'] : (isset($payment['updated_at']) && $e['state'] === 'paid' ? $payment['updated_at'] : ''),
            $e['deposit_id'],
            $e['state'] === 'refunded' && isset($payment['refund_method']) ? $modeLabel($payment['refund_method']) : '',
            registrarc_engagement_special_label($e),
            $e['note'],
        ];
    }

    registrarc_send_csv($prefix . '_engagements.csv', [
        'Licence', 'Nom', 'Prénom', 'Club', 'Catégorie', 'Départ', 'Cible', 'N° engagement', 'Montant dû',
        'Statut', 'Mode de paiement', 'Date du paiement', 'Remise de chèques', 'Remboursé en', 'Prix spécial', 'Note',
    ], $lines);
}

if ($type === 'journal') {
    $lines = [];

    foreach (registrarc_load_journal($TourId) as $entry) {
        $lines[] = [
            isset($entry['at']) ? $entry['at'] : '',
            registrarc_journal_type_label(isset($entry['type']) ? $entry['type'] : ''),
            isset($entry['archer']) ? $entry['archer'] : '',
            $modeLabel(isset($entry['method']) ? $entry['method'] : ''),
            $amount(isset($entry['amount']) ? $entry['amount'] : 0),
            isset($entry['label']) ? $entry['label'] : '',
            isset($entry['operator']) ? $entry['operator'] : '',
            isset($entry['device']) ? $entry['device'] : '',
            isset($entry['source']) ? $entry['source'] : '',
        ];
    }

    registrarc_send_csv($prefix . '_journal.csv', [
        'Date', 'Opération', 'Archer', 'Mode', 'Montant', 'Détail', 'Opérateur', 'Poste', 'Origine',
    ], $lines);
}

if ($type === 'backup') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $prefix . '_sauvegarde_' . date('Ymd-His') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode(registrarc_build_backup($TourId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

header('Location: dashboard.php');
exit();
