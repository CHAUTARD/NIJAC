<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Déclaration de disponibilité JA (EN22), portage CI4 de
 * Nominateur/disponibilite_ja.php.
 *
 * Écran non documenté dans ECRANS.md/SPECIFICATION.md avant ce portage (le
 * fichier legacy se labellisait par erreur "EA88", déjà attribué à Régions) —
 * code EN22 attribué à la suite de EN21 (Convocation JA).
 *
 * Page PUBLIQUE (sans authentification) permettant à un JA de déclarer ses
 * disponibilités pour les dates du championnat régional (table
 * `competition_regionale`, calendrier saisi par un admin, voir
 * CompetitionRegionaleController EA84). Calendrier mensuel, clic sur une
 * date = bascule Disponible / Non disponible (et vice-versa) ; le passage en
 * Disponible ouvre une popup pour une Note facultative et, si le Commentaire
 * de la date en contient, le choix du/des département(s) concerné(s) parmi
 * 14/27/50/61/76 (stockés dans `disponible.Departement`/`disponible.Note`,
 * même convention que `disponible_regionale` utilisée par EN23). Accessible
 * via ?ja=TOKEN (Obfuscator) ou ?id_ja=N en clair. Lien généré depuis EN11
 * (Juge-Arbitre, action "token") ou utilisé directement depuis EN13
 * (Disponibilités, ?id_ja=N).
 */
class DisponibiliteJaController extends BaseController
{
    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);

        try {
            getPDO()->exec('ALTER TABLE disponible ADD UNIQUE KEY uq_dispo (Id_JA, Id_Rencontre)');
        } catch (\PDOException $ignored) {
            // index déjà présent
        }
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'err' => 'BDD : ' . $e->getMessage()]);
        }
    }

    /**
     * Résout l'Id_JA depuis ?ja=TOKEN (obfusqué) ou ?id_ja=N (clair), comme
     * le fait le legacy en réécrivant $_GET['id_ja'] dès le décodage du token.
     */
    private function resolveIdJa(): int
    {
        $tokenGet = trim($this->request->getGet('ja') ?? $this->request->getPost('ja') ?? '');
        if ($tokenGet !== '') {
            $decoded = $this->obf->deobfuscate($tokenGet);
            if ($decoded > 0) {
                return $decoded;
            }
        }

        return (int) ($this->request->getGet('id_ja') ?? $this->request->getPost('id_ja') ?? 0);
    }

    /**
     * Comme resolveIdJa(), mais pour les actions sensibles (Note interne,
     * Défiscalisation, saisie de disponibilité) : un ?id_ja=N en clair sans
     * token n'est accepté que si l'appelant a une session authentifiée
     * (usage documenté : lien utilisé directement depuis EN13 par un
     * nominateur déjà connecté). Sans token valide ni session, retourne 0 au
     * lieu de faire confiance à n'importe quel entier deviné par un tiers.
     */
    private function resolveIdJaAutorise(): int
    {
        $tokenGet = trim($this->request->getGet('ja') ?? $this->request->getPost('ja') ?? '');
        if ($tokenGet !== '') {
            $decoded = $this->obf->deobfuscate($tokenGet);
            if ($decoded > 0) {
                return $decoded;
            }
        }

        $idClair = (int) ($this->request->getGet('id_ja') ?? $this->request->getPost('id_ja') ?? 0);
        if ($idClair > 0 && $this->sessionAuthentifiee()) {
            return $idClair;
        }

        return 0;
    }

    private function sessionAuthentifiee(): bool
    {
        demarrerSessionNijac();
        $ok = !empty($_SESSION['utilisateur']['role'] ?? null);
        session_write_close();

        return $ok;
    }

    public function index()
    {
        // Page publique, mais accessible aussi depuis un onglet ouvert par un
        // nominateur déjà connecté (EN13, target="_blank" — même session
        // navigateur) : on lit la session si elle existe, sans l'exiger.
        demarrerSessionNijac();
        $u = $_SESSION['utilisateur'] ?? [];
        session_write_close();
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé pour toute
        // réponse HTML non-AJAX, donc à chaque F5) de démarrer le service Session
        // de CI4 — voir Auth.php pour le détail du conflit avec la session native.
        unset($_SESSION);

        $idJa = $this->resolveIdJa();

        // Bouton de retour de la toolbar : vers le menu correspondant au rôle
        // réel de la session (Nominateur -> menu nominateur, Administrateur ->
        // menu administrateur), pas systématiquement admin. Absent si la page
        // est ouverte sans session (accès public par un JA).
        $tbSwitchTo = match ($u['role'] ?? '') {
            'Administrateur' => 'admin',
            'Nominateur'      => 'nominateur',
            default           => null,
        };

        return view('disponibilite_ja_index', [
            'idJa'        => $idJa,
            // Renvoyé au client pour être rejoué sur les appels AJAX d'écriture
            // (resolveIdJaAutorise() exige ce token à chaque requête, pas
            // seulement au chargement de la page — un JA public n'a pas de
            // session qui pourrait sinon porter cette autorisation).
            'tokenJa'     => trim($this->request->getGet('ja') ?? ''),
            'nomComplet'  => isset($u['prenom'], $u['nom']) ? $u['prenom'] . ' ' . $u['nom'] : '',
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'tbSwitchTo'  => $tbSwitchTo,
        ]);
    }

    public function listeJa(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT Id_JA, Nom, Prenom, Grade, Ville FROM ja WHERE Actif = 1 ORDER BY Nom, Prenom'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function ja(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $id     = (int) ($this->request->getGet('id') ?? 0);

            $stmt = $pdo->prepare('
                SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                       ja.ArbitreAutresDepts, ja.DeptsArbitrage, ja.Defiscalisation,
                       lp.CodePostal AS Cp,
                       lp.Nom        AS Ville
                FROM ja
                LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
                WHERE ja.Id_JA = ?
            ');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $this->response->setJSON($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'err' => 'Introuvable']);
        });
    }

    public function journees(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo  = getPDO();
            $idJa = (int) ($this->request->getGet('id_ja') ?? 0);
            if (!$idJa) {
                return $this->response->setJSON(['ok' => false, 'err' => 'JA manquant']);
            }

            $stmt = $pdo->prepare('
                SELECT cr.Id_CompetitionRegionale, cr.Date, cr.Heure, cr.Commentaire,
                       d.Reponse AS Statut, d.Departement, d.Note
                FROM competition_regionale cr
                LEFT JOIN disponible d
                    ON d.Id_JA = ? AND d.DateCompetition = cr.Date AND d.Id_Rencontre IS NULL
                ORDER BY cr.Date, cr.Heure
            ');
            $stmt->execute([$idJa]);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    /**
     * POST : id_ja, date (YYYY-MM-DD, = competition_regionale.Date), statut (O/N/VIDE),
     * note (facultatif), departements[] (facultatif, sous-ensemble de 14/27/50/61/76).
     * VIDE efface la réponse (3e état du cycle : Disponible → Non disponible → pas de réponse).
     */
    public function sauvegarderDispoJournee(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $idJa   = $this->resolveIdJaAutorise();
            $date   = trim($this->request->getPost('date') ?? '');
            $statut = strtoupper(trim($this->request->getPost('statut') ?? ''));
            $note   = trim($this->request->getPost('note') ?? '') ?: null;

            if (!$idJa || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($statut, ['O', 'N', 'VIDE'])) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres invalides']);
            }

            $pdo->prepare('DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL')
                ->execute([$idJa, $date]);

            if ($statut !== 'VIDE') {
                $deptsValides = ['14', '27', '50', '61', '76'];
                $depts        = array_values(array_intersect((array) ($this->request->getPost('departements') ?? []), $deptsValides));
                $departement  = $depts ? implode(',', $depts) : null;

                $pdo->prepare('INSERT INTO disponible (Id_JA, DateCompetition, Reponse, Departement, Note, DateReponse) VALUES (?, ?, ?, ?, ?, CURDATE())')
                    ->execute([$idJa, $date, $statut, $departement, $note]);
            }

            return $this->response->setJSON(['ok' => true, 'statut' => $statut === 'VIDE' ? null : $statut]);
        });
    }

    public function token(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id = (int) ($this->request->getGet('id') ?? $this->request->getPost('id') ?? 0);
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant']);
            }
            $token = $this->obf->obfuscate($id);
            $url   = site_url('disponibilite-ja') . '?ja=' . $token;

            return $this->response->setJSON(['ok' => true, 'token' => $token, 'url' => $url]);
        });
    }

    public function lireNote(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id = $this->resolveIdJaAutorise();
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            $stmt = getPDO()->prepare('SELECT Note FROM JA WHERE Id_JA = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $this->response->setJSON(['ok' => true, 'note' => $row ? ($row['Note'] ?? '') : '']);
        });
    }

    public function sauvegarderNote(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id   = $this->resolveIdJaAutorise();
            $note = trim($this->request->getPost('note') ?? '');
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            getPDO()->prepare('UPDATE JA SET Note = ? WHERE Id_JA = ?')
                ->execute([$note === '' ? null : $note, $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    /**
     * POST : id_ja, actif (0/1), departements[] (sous-ensemble de 14/27/50/61/76).
     * Cartouche EN22 « J'accepte d'arbitrer dans des départements voisins ».
     */
    public function sauvegarderArbitrageVoisins(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id = $this->resolveIdJaAutorise();
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }

            $actif = !empty($this->request->getPost('actif')) ? 1 : 0;
            $deptsValides = ['14', '27', '50', '61', '76'];
            $depts = $actif
                ? array_values(array_intersect((array) ($this->request->getPost('departements') ?? []), $deptsValides))
                : [];

            getPDO()->prepare('UPDATE JA SET ArbitreAutresDepts = ?, DeptsArbitrage = ? WHERE Id_JA = ?')
                ->execute([$actif, $depts ? implode(',', $depts) : null, $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function sauvegarderDefiscalisation(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id     = $this->resolveIdJaAutorise();
            $defisc = !empty($this->request->getPost('defiscalisation')) ? 1 : 0;
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            getPDO()->prepare('UPDATE JA SET Defiscalisation = ? WHERE Id_JA = ?')
                ->execute([$defisc, $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
