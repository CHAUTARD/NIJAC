<?php
/**
 * Client pour l'API Smartping v2.0 de la FFTT.
 *
 * URL de base : https://apiv2.fftt.com/mobile/pxml/
 *
 * Authentification par requête :
 *   - id    : identifiant applicatif (FFTT_APP_ID)
 *   - serie : numéro de série 15 chars [A-Z0-9] (xml_initialisation.php)
 *   - tm    : timestamp YmdHis + 3 chiffres microtime
 *   - tmc   : hash_hmac('sha1', tm, md5(appKey))
 *
 * Endpoints disponibles :
 *   xml_club_dep2          Liste des clubs d'un département            dep
 *   xml_club_detail        Détail d'un club                           club
 *   xml_club_b             Recherche clubs sur critères                critères libres
 *   xml_organisme          Liste des organismes                        type, organisme
 *   xml_epreuve            Liste des épreuves d'un organisme           organisme, type
 *   xml_division           Liste des divisions d'une épreuve           organisme, epreuve, type
 *   xml_result_equ         Résultats/poules d'un chpt équipes          D1, action, cx_poule, auto
 *                          action=poule → liste des poules (avec lien contenant cx_poule réel)
 *                          action vide  → rencontres (cx_poule optionnel, sinon première poule)
 *   xml_rencontre_equ      Rencontres d'une ou plusieurs poules        poule=id1|id2|id3
 *   xml_result_indiv       Résultats d'une division individuelle        epr, res_division, cx_tableau
 *   xml_chp_renc           Détail d'une rencontre (params du champ lien de xml_result_equ)
 *   xml_equipe             Équipes d'un club                           numclu, type
 *   xml_liste_joueur       Joueurs base classement                     club, nom, prenom
 *   xml_liste_joueur_o     Licenciés base SPID                         club, nom, prenom
 *   xml_joueur             Détail joueur base classement               licence
 *   xml_licence            Détail licencié base SPID                   licence
 *   xml_licence_b          Détail licencié + classement                licence
 *   xml_partie_mysql       Parties d'un joueur base classement         licence
 *   xml_partie             Parties d'un joueur base SPID               licence
 *   xml_histo_classement   Historique classements d'un licencié        licence
 *   xml_res_cla            Classement général d'une division critérium D1
 *
 * Note : xml_poule et xml_rencontre N'EXISTENT PAS dans cette API.
 *
 * Usage :
 *   $api = getFfttApi();   // via app_config.php
 *   $clubs = $api->getClubsDepartement('76');
 */
class FfttApi
{
    private const BASE_URL = 'https://apiv2.fftt.com/mobile/pxml/';
    private const TIMEOUT  = 20;

    private string $appId;
    private string $appKey;
    private string $serial;
    private string $lastUrl   = '';
    private int    $lastHttp  = 0;
    private string $lastRaw   = '';

    public function lastUrl(): string  { return $this->lastUrl; }
    public function lastHttp(): int    { return $this->lastHttp; }
    public function lastRaw(): string  { return $this->lastRaw; }

    public function __construct(string $appId, string $appKey, string $serial)
    {
        $this->appId  = $appId;
        $this->appKey = $appKey;
        $this->serial = $serial;
    }

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /** Liste des clubs d'un département (xml_club_dep2). */
    public function getClubsDepartement(string $numDep): array
    {
        return $this->getCollection('xml_club_dep2', ['dep' => $numDep], 'club');
    }

    /** Détail d'un club (xml_club_detail). */
    public function getClubDetail(string $numClub): array
    {
        return $this->getObject('xml_club_detail', ['club' => $numClub], 'club') ?? [];
    }

    /** Liste des divisions d'une épreuve (xml_division). */
    public function getDivisions(string $idOrganisme, string $idEpreuve, string $type = 'E'): array
    {
        return $this->getCollection('xml_division', [
            'organisme' => $idOrganisme,
            'epreuve'   => $idEpreuve,
            'type'      => $type,
        ], 'division');
    }

    /** Liste des licenciés SPID d'un club (xml_liste_joueur_o). */
    public function getLicenciesClub(string $numClub): array
    {
        return $this->getCollection('xml_liste_joueur_o', ['club' => $numClub], 'joueur');
    }

    /** Détail d'un licencié (xml_licence). */
    public function getLicence(string $licence): ?array
    {
        return $this->getObject('xml_licence', ['licence' => $licence], 'licence');
    }

    /** Détail étendu d'un licencié + classement (xml_licence_b). */
    public function getLicenceB(string $licence): ?array
    {
        return $this->getObject('xml_licence_b', ['licence' => $licence], 'licence');
    }

    /** Liste des équipes d'un club (xml_equipe). */
    public function getEquipesClub(string $numClub, string $type = ''): array
    {
        return $this->getCollection('xml_equipe', ['numclu' => $numClub, 'type' => $type], 'equipe');
    }

    /** Recherche de clubs sur critères (xml_club_b). */
    public function rechercheClubs(array $criteres): array
    {
        return $this->getCollection('xml_club_b', $criteres, 'club');
    }

    /**
     * Retourne tous les licenciés avec un grade d'arbitrage (echelon non vide)
     * pour tous les clubs d'un département.
     *
     * @param string   $dep       Numéro de département (ex: '76')
     * @param callable $progress  Optionnel — callback(int $clubsTraites, int $clubsTotal, string $nomClub)
     */
    public function getArbitresDepartement(string $dep, ?callable $progress = null): array
    {
        $clubs    = $this->getClubsDepartement($dep);
        $total    = count($clubs);
        $arbitres = [];

        foreach ($clubs as $i => $club) {
            $numClub = $club['numero'] ?? $club['numclu'] ?? '';
            if ($numClub === '') continue;

            try {
                $membres = $this->getLicenciesClub($numClub);
                foreach ($membres as $m) {
                    $echelon = $m['echelon'] ?? [];
                    // echelon est [] pour les joueurs ordinaires, string ou tableau pour les arbitres
                    if (!empty($echelon)) {
                        $m['_club_nom'] = $club['nom'] ?? '';
                        $m['_echelon']  = is_array($echelon) ? implode(', ', $echelon) : (string)$echelon;
                        $arbitres[]     = $m;
                    }
                }
            } catch (RuntimeException) {
                // Un club en erreur ne bloque pas les autres
            }

            if ($progress) {
                ($progress)($i + 1, $total, $club['nom'] ?? $numClub);
            }
        }

        return $arbitres;
    }

    // -------------------------------------------------------------------------
    // Couche HTTP / parsing
    // -------------------------------------------------------------------------

    /** Retourne un tableau de résultats (liste). */
    private function getCollection(string $action, array $params, string $key): array
    {
        $data = $this->request($action, $params);
        if (empty($data) || !array_key_exists($key, $data)) return [];
        $items = $data[$key];
        return isset($items[0]) ? $items : [$items];
    }

    /** Retourne un objet unique ou null. */
    private function getObject(string $action, array $params, ?string $key): ?array
    {
        $data = $this->request($action, $params);
        if ($key) return $data[$key] ?? null;
        return empty($data) ? null : $data;
    }

    /**
     * Envoie une requête signée à l'API FFTT.
     *
     * @throws RuntimeException si la requête échoue ou le XML est invalide.
     */
    public function request(string $action, array $params = []): array
    {
        $tm  = date('YmdHis') . substr(microtime(), 2, 3);
        $tmc = hash_hmac('sha1', $tm, hash('md5', $this->appKey, false));

        $query = array_merge([
            'serie' => $this->serial,
            'id'    => $this->appId,
            'tm'    => $tm,
            'tmc'   => $tmc,
        ], $params);

        $url = self::BASE_URL . $action . '.php?' . http_build_query($query);
        $this->lastUrl = $url;

        $raw = $this->httpGet($url);
        $this->lastRaw = $raw;
        return $this->parseXml($raw);
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => '',        // décompresse gzip/deflate automatiquement
            // SSL : désactivé en local (WAMP sans CA bundle) — le trafic reste chiffré HTTPS
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER     => [
                'Accept:',
                'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Win32)',
                'Content-Type: application/x-www-form-urlencoded',
                'Connection: Keep-Alive',
            ],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->lastHttp = $httpCode;

        if ($errno !== 0) throw new RuntimeException("FfttApi cURL ($errno): $error");
        if ($httpCode !== 200) throw new RuntimeException("FfttApi HTTP $httpCode — URL : $this->lastUrl");
        if ($response === false || $response === '') throw new RuntimeException("FfttApi : réponse vide");

        return $response;
    }

    private function parseXml(string $xml): array
    {
        // L'API FFTT répond en ISO-8859-1 — on convertit en UTF-8 avant tout traitement
        if (preg_match('/encoding=["\']([^"\']+)["\']/', $xml, $m)
            && strtolower($m[1]) !== 'utf-8') {
            $xml = mb_convert_encoding($xml, 'UTF-8', $m[1]);
            $xml = preg_replace('/encoding=["\'][^"\']+["\']/', 'encoding="UTF-8"', $xml, 1);
        } elseif (!mb_detect_encoding($xml, 'UTF-8', true)) {
            // Pas de déclaration d'encodage mais contenu non-UTF-8 : on suppose ISO-8859-1
            $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1');
        }

        // L'API FFTT retourne des & non échappés dans certains champs (ex: lien="cx_poule=x&D1=y")
        // ce qui est du XML invalide — on échappe les & orphelins avant le parsing
        $xml = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $xml);

        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        if ($obj === false) {
            $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            $extrait = substr(trim($xml), 0, 300);
            throw new RuntimeException(
                "FfttApi XML invalide : " . implode(', ', $errors)
                . " — Réponse brute : " . $extrait
            );
        }

        $data = json_decode(json_encode($obj, JSON_INVALID_UTF8_SUBSTITUTE), true);
        return $data ?? [];
    }

    /** Enregistre le serial applicatif auprès de l'API FFTT (à appeler une fois par serial). */
    public function initialize(): void
    {
        $tm  = date('YmdHis') . substr(microtime(), 2, 3);
        $tmc = hash_hmac('sha1', $tm, hash('md5', $this->appKey, false));

        $url = self::BASE_URL . 'xml_initialisation.php?' . http_build_query([
            'serie' => $this->serial,
            'id'    => $this->appId,
            'tm'    => $tm,
            'tmc'   => $tmc,
        ]);

        // On ne parse pas la réponse — on l'ignore si elle n'est pas XML valide
        try { $this->parseXml($this->httpGet($url)); } catch (RuntimeException) {}
    }
}
