<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Attestations reçues (ED54), rôle Defiscalisateur.
 *
 * Liste les PDF d'attestations sur l'honneur déposés par les JA dans le
 * répertoire `_Defiscalisation/` (un fichier `{Id_JA}.pdf` par JA, écrit par
 * ED53). Colonnes : N° JA, Nom, Prénom, date de dépôt, lien de consultation.
 * Les PDF étant protégés par `_Defiscalisation/.htaccess` (accès direct refusé),
 * la consultation passe par `telecharger()` qui les sert en `inline` derrière
 * le filtre `defiscauth`. Accès rôle Defiscalisateur + Administrateur.
 */
class AttestationsListeController extends BaseController
{
    private const DIR = __DIR__ . '/../../../_Defiscalisation';

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $fichiers = is_dir(self::DIR)
            ? array_values(array_filter(scandir(self::DIR) ?: [], static fn ($f) => preg_match('/^\d+\.pdf$/', $f)))
            : [];

        $ids = array_map(static fn ($f) => (int) basename($f, '.pdf'), $fichiers);

        $noms = [];
        if ($ids !== []) {
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $stmt = getPDO()->prepare("SELECT Id_JA, Nom, Prenom FROM ja WHERE Id_JA IN ($in)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $noms[(int) $r['Id_JA']] = $r;
            }
        }

        $lignes = [];
        foreach ($fichiers as $f) {
            $id = (int) basename($f, '.pdf');
            $lignes[] = [
                'idJa'    => $id,
                'nom'     => $noms[$id]['Nom'] ?? '',
                'prenom'  => $noms[$id]['Prenom'] ?? '',
                'inconnu' => !isset($noms[$id]),
                'depose'  => date('d/m/Y H:i', (int) filemtime(self::DIR . '/' . $f)),
                'taille'  => filesize(self::DIR . '/' . $f),
                'cg'      => (glob(self::DIR . '/' . $id . '_cg.*') ?: []) !== [],
            ];
        }
        usort($lignes, static fn ($a, $b) => [$a['nom'], $a['prenom']] <=> [$b['nom'], $b['prenom']]);

        return view('attestations_defisc_liste_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'lignes'      => $lignes,
        ]);
    }

    /** Sert le PDF `{Id_JA}.pdf` en consultation (inline), derrière `defiscauth`. */
    public function telecharger($idJa = null): ResponseInterface
    {
        $idJa = (int) $idJa;
        $path = self::DIR . '/' . $idJa . '.pdf';

        if ($idJa <= 0 || !is_file($path)) {
            return $this->response->setStatusCode(404)->setBody('Attestation introuvable.');
        }

        return $this->response
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'inline; filename="attestation_' . $idJa . '.pdf"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(file_get_contents($path));
    }

    /** Sert la carte grise `{Id_JA}_cg.{pdf|jpg|png}` en consultation (inline). */
    public function carteGrise($idJa = null): ResponseInterface
    {
        $idJa  = (int) $idJa;
        $match = $idJa > 0 ? (glob(self::DIR . '/' . $idJa . '_cg.*') ?: []) : [];
        if ($match === []) {
            return $this->response->setStatusCode(404)->setBody('Carte grise introuvable.');
        }

        $path = $match[0];
        $mime = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
        ][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="carte_grise_' . $idJa . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(file_get_contents($path));
    }
}
