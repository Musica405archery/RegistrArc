<?php
// ============================================================================
// RegistrArc - lib/registrarc_storage.inc.php
// Stockage JSON fiable, partagé par les pages qui écrivent dans data/
//
// - lecture : distingue "fichier absent" ([]) de "fichier illisible" (null)
// - écriture atomique : fichier temporaire puis rename(), jamais de JSON tronqué
// - copie .bak du fichier précédent avant chaque écriture
// - mise à jour sous verrou exclusif (<fichier>.lock) pendant tout le cycle
//   lecture / modification / écriture : pas de paiement perdu entre 2 appareils
// - refus d'écrire par-dessus un JSON illisible
// - protection web du dossier data (.htaccess + index.html)
// ============================================================================

// Dernière erreur de stockage, pour l'afficher dans les messages utilisateur
function registrarc_storage_error($set = null) {
    static $error = '';

    if ($set !== null) {
        $error = (string)$set;
    }

    return $error;
}

function registrarc_storage_protect_dir($dir) {
    $htaccess = $dir . '/.htaccess';

    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# RegistrArc : données privées, aucun accès web direct\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order allow,deny\n"
            . "    Deny from all\n"
            . "</IfModule>\n"
        );
    }

    $index = $dir . '/index.html';

    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
}

// Retourne le tableau lu, [] si le fichier n'existe pas, null s'il est illisible
function registrarc_storage_read($file) {
    if (!is_file($file)) {
        return [];
    }

    $json = @file_get_contents($file);

    if ($json === false || trim($json) === '') {
        return null;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

function registrarc_storage_write($file, array $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false) {
        registrarc_storage_error("Encodage JSON impossible : " . json_last_error_msg() . ".");
        return false;
    }

    $tmp = $file . '.tmp-' . getmypid() . '-' . mt_rand();

    if (@file_put_contents($tmp, $json) !== strlen($json)) {
        @unlink($tmp);
        registrarc_storage_error("Écriture du fichier temporaire impossible dans " . dirname($file) . ".");
        return false;
    }

    if (is_file($file)) {
        @copy($file, $file . '.bak');
    }

    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        registrarc_storage_error("Remplacement du fichier " . basename($file) . " impossible.");
        return false;
    }

    return true;
}

// $mutator reçoit le contenu courant par référence et le modifie.
// Tout le cycle est fait sous verrou exclusif.
function registrarc_storage_update($file, callable $mutator) {
    registrarc_storage_error('');

    $lock = @fopen($file . '.lock', 'c');

    if ($lock === false) {
        registrarc_storage_error("Création du verrou impossible pour " . basename($file) . ".");
        return false;
    }

    if (!flock($lock, LOCK_EX)) {
        fclose($lock);
        registrarc_storage_error("Verrouillage impossible pour " . basename($file) . ".");
        return false;
    }

    try {
        $data = registrarc_storage_read($file);

        if ($data === null) {
            registrarc_storage_error(
                "Le fichier " . basename($file) . " est illisible : écriture refusée pour ne pas écraser les données. "
                . "La version précédente est dans " . basename($file) . ".bak."
            );
            return false;
        }

        $mutator($data);

        return registrarc_storage_write($file, $data);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Remplace entièrement le contenu (ex. formulaire de paramètres), sous verrou
function registrarc_storage_replace($file, array $data) {
    registrarc_storage_error('');

    $lock = @fopen($file . '.lock', 'c');

    if ($lock === false) {
        registrarc_storage_error("Création du verrou impossible pour " . basename($file) . ".");
        return false;
    }

    flock($lock, LOCK_EX);

    try {
        return registrarc_storage_write($file, $data);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// Suffixe à ajouter aux messages d'erreur d'écriture
function registrarc_storage_error_suffix() {
    $error = registrarc_storage_error();

    return $error !== '' ? ' ' . $error : '';
}
