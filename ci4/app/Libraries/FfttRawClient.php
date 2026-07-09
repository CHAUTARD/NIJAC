<?php

namespace App\Libraries;

/**
 * Client HTTP maison pour l'API FFTT (Smartping v2) — remplace la façade
 * FFTTApi de alamirault/fftt-api (et Guzzle, dont elle dépendait), retirée
 * pour permettre un déploiement par simple copie de fichiers (la production
 * n'a qu'un accès FTP, pas de Composer). cURL natif uniquement.
 *
 * Couvre à la fois les endpoints qu'exposait la façade typée (7 méthodes
 * ci-dessous, celles réellement utilisées par l'application — voir
 * `request()` pour appeler n'importe quel autre endpoint brut, ex.
 * xml_division, xml_result_equ avec cx_poule, xml_licence…) et les appels
 * bas niveau existants (aucune API cassée : `request()`, `lastUrl()`,
 * `lastHttp()`, `lastRaw()` gardent leur signature).
 *
 * Authentification : même schéma que la lib retirée (signature HMAC-SHA1
 * du timestamp, clé = MD5 de la clé d'application) — voir `request()`.
 *
 * Parsing XML → tableau : identique à l'ancien Classes/FfttApi.php (l'API
 * FFTT répond en ISO-8859-1 et laisse parfois des & non échappés).
 */
class FfttRawClient
{
    private const BASE_URL = 'https://www.fftt.com/mobile/pxml/';
    private const TIMEOUT  = 20;

    private string $lastUrl  = '';
    private int    $lastHttp = 0;
    private string $lastRaw  = '';

    public function __construct(
        private readonly string $appId,
        private readonly string $appKey
    ) {
    }

    public function lastUrl(): string { return $this->lastUrl; }
    public function lastHttp(): int   { return $this->lastHttp; }
    public function lastRaw(): string { return $this->lastRaw; }

    /**
     * Appelle un endpoint FFTT brut (ex. 'xml_club_dep2') avec ses paramètres
     * et retourne la réponse XML convertie en tableau associatif.
     *
     * @throws \RuntimeException si la requête échoue ou le XML est invalide
     */
    public function request(string $action, array $params = []): array
    {
        $time = (string) round(microtime(true) * 1000);
        $tmc  = hash_hmac('sha1', $time, md5($this->appKey));

        $url = self::BASE_URL . $action . '.php?serie=' . $this->appId . '&tm=' . $time . '&tmc=' . $tmc . '&id=' . $this->appId;
        foreach ($params as $k => $v) {
            $url .= '&' . $k . '=' . $v;
        }
        $this->lastUrl = $url;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $raw       = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $this->lastHttp = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("FfttRawClient erreur cURL ($curlErrno) : $curlError — {$this->lastUrl}");
        }
        $this->lastRaw = $raw;

        if ($this->lastHttp !== 200) {
            throw new \RuntimeException("FfttRawClient HTTP {$this->lastHttp} — {$this->lastUrl}");
        }
        if ($raw === '') {
            throw new \RuntimeException('FfttRawClient : réponse vide');
        }

        return $this->parseXml($raw);
    }

    /**
     * Liste des clubs d'un département (xml_club_dep2). Éléments : numero, nom.
     */
    public function listClubsByDepartement(string|int $dep): array
    {
        $depPad = str_pad((string) $dep, 2, '0', STR_PAD_LEFT);

        return $this->listFrom($this->request('xml_club_dep2', ['dep' => $depPad]), 'club');
    }

    /**
     * Détail d'un club (xml_club_detail) : numero, nom, nomsalle,
     * adressesalle1/2/3, codepsalle, villesalle, nomcor, prenomcor, mailcor,
     * telcor — un seul club, une seule salle (pour plusieurs salles, appeler
     * `request('xml_club_detail', ...)` directement, voir SalleController).
     */
    public function retrieveClubDetails(string $numClub): array
    {
        $data = $this->request('xml_club_detail', ['club' => $numClub]);

        return $data['club'] ?? [];
    }

    /**
     * Liste des organismes (ligues/comités/clubs FFTT — xml_organisme).
     * Éléments : libelle, id, code, idpere. $type : 'Z' (tous), 'L' (ligues), 'D' (comités).
     */
    public function listOrganismes(string $type = 'L'): array
    {
        return $this->listFrom($this->request('xml_organisme', ['type' => $type]), 'organisme');
    }

    /**
     * Liste des épreuves d'un organisme (xml_epreuve). Éléments : idepreuve,
     * idorga, libelle, typepreuve. $type : 'I' (individuel) ou 'E' (équipe).
     */
    public function listEpreuves(int $organisme, string $type = 'E'): array
    {
        return $this->listFrom(
            $this->request('xml_epreuve', ['type' => $type, 'organisme' => $organisme]),
            'epreuve'
        );
    }

    /**
     * Liste des équipes d'un club (xml_equipe). Éléments : libequipe,
     * libdivision, liendivision.
     */
    public function listEquipesByClub(string $club, ?string $type = null): array
    {
        $params = ['numclu' => $club];
        if ($type !== null && $type !== '') {
            $params['type'] = $type;
        }

        return $this->listFrom($this->request('xml_equipe', $params), 'equipe');
    }

    /**
     * Détail d'une licence (xml_licence_b) : idlicence, licence, nom, prenom,
     * numclub, nomclub, point, validation (d/m/Y), arb, ja... Sans $club,
     * FFTT retourne un enregistrement unique pour la licence demandée.
     */
    public function retrieveJoueurDetails(string $licence, ?string $club = null): array
    {
        $params = ['licence' => $licence];
        if ($club !== null && $club !== '') {
            $params['club'] = $club;
        }

        return $this->request('xml_licence_b', $params)['licence'] ?? [];
    }

    /**
     * Liste des joueurs d'un club (xml_liste_joueur_o). Éléments : licence,
     * nclub, club, nom, prenom, points.
     */
    public function listJoueursByClub(string $club): array
    {
        return $this->listFrom($this->request('xml_liste_joueur_o', ['club' => $club]), 'joueur');
    }

    /**
     * Extrait une liste depuis la réponse déjà parsée, en ré-enveloppant le
     * cas à un seul élément (SimpleXML aplatit une liste XML d'un seul enfant
     * en tableau associatif simple au lieu d'un tableau de sous-tableaux).
     */
    private function listFrom(array $data, string $key): array
    {
        $items = (array) ($data[$key] ?? []);

        if ($items === [] || array_is_list($items)) {
            return $items;
        }

        return [$items];
    }

    private function parseXml(string $xml): array
    {
        if (preg_match('/encoding=["\']([^"\']+)["\']/', $xml, $m)
            && strtolower($m[1]) !== 'utf-8') {
            $xml = mb_convert_encoding($xml, 'UTF-8', $m[1]);
            $xml = preg_replace('/encoding=["\'][^"\']+["\']/', 'encoding="UTF-8"', $xml, 1);
        } elseif (!mb_detect_encoding($xml, 'UTF-8', true)) {
            $xml = mb_convert_encoding($xml, 'UTF-8', 'ISO-8859-1');
        }

        // Certains champs (ex: lien="cx_poule=x&D1=y") contiennent des & non échappés
        $xml = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]*|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $xml);

        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);
        if ($obj === false) {
            $errors = array_map(static fn ($e) => trim($e->message), libxml_get_errors());
            libxml_clear_errors();
            $extrait = substr(trim($xml), 0, 300);
            throw new \RuntimeException(
                'FfttRawClient XML invalide : ' . implode(', ', $errors) . " — Réponse brute : $extrait"
            );
        }

        $data = json_decode(json_encode($obj, JSON_INVALID_UTF8_SUBSTITUTE), true);

        return $data ?? [];
    }
}
