<?php

/**
 * Client pour l'API XML de la FFTT (apiv2.fftt.com).
 *
 * Authentification : chaque requête signe le timestamp avec md5(serial . timestamp).
 * Credentials stockés dans la configuration (getConfig('fftt_serial') / getConfig('fftt_password')).
 *
 * Usage :
 *   $api = new FfttApi($serial, $password);
 *   $clubs = $api->getClubsDepartement('76');
 */
class FfttApi
{
    private const BASE_URL = 'https://apiv2.fftt.com/mobile/pxml/';
    private const TIMEOUT  = 10;

    private string $serial;
    private string $password;

    public function __construct(string $serial, string $password)
    {
        $this->serial   = $serial;
        $this->password = $password;
    }

    // -------------------------------------------------------------------------
    // Endpoints implémentés
    // -------------------------------------------------------------------------

    /** Liste des clubs d'un département (xml_club_dep2). */
    public function getClubsDepartement(string $numDep): array
    {
        return $this->request('xml_club_dep2', ['dep' => $numDep]);
    }

    /** Détail d'un club (xml_club_detail). */
    public function getClubDetail(string $numClub): array
    {
        return $this->request('xml_club_detail', ['club' => $numClub]);
    }

    /** Liste des divisions d'une épreuve (xml_division). */
    public function getDivisions(string $idOrganisme, string $idEpreuve): array
    {
        return $this->request('xml_division', [
            'organisme_id' => $idOrganisme,
            'epreuve_id'   => $idEpreuve,
        ]);
    }

    /** Liste des licenciés SPID avec classement (xml_liste_joueur_o). */
    public function getLicenciesClub(string $numClub): array
    {
        return $this->request('xml_liste_joueur_o', ['club' => $numClub]);
    }

    /** Détail d'un licencié (xml_licence). */
    public function getLicence(string $licence): array
    {
        return $this->request('xml_licence', ['licence' => $licence]);
    }

    /** Liste des équipes d'un club (xml_equipe). */
    public function getEquipesClub(string $numClub): array
    {
        return $this->request('xml_equipe', ['club' => $numClub]);
    }

    /** Recherche de clubs sur critères (xml_club_b). */
    public function rechercheClubs(array $criteres): array
    {
        return $this->request('xml_club_b', $criteres);
    }

    // -------------------------------------------------------------------------
    // Couche HTTP / parsing
    // -------------------------------------------------------------------------

    /**
     * Envoie une requête à l'API et retourne le XML parsé sous forme de tableau.
     *
     * @throws RuntimeException si la requête échoue ou si l'API retourne une erreur.
     */
    public function request(string $action, array $params = []): array
    {
        $tm  = time();
        $tmc = md5($this->serial . $tm);

        $query = array_merge([
            'serie'    => $this->serial,
            'tm'       => $tm,
            'tmc'      => $tmc,
            'id'       => $action,
        ], $params);

        $url = self::BASE_URL . $action . '.php?' . http_build_query($query);

        $xml = $this->httpGet($url);

        return $this->parseXml($xml);
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'NIJAC/1.0',
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("FfttApi cURL error ($errno): $error");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("FfttApi HTTP $httpCode pour : $url");
        }
        if ($response === false || $response === '') {
            throw new RuntimeException("FfttApi : réponse vide");
        }

        return $response;
    }

    private function parseXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml);

        if ($obj === false) {
            $errors = array_map(fn($e) => $e->message, libxml_get_errors());
            libxml_clear_errors();
            throw new RuntimeException("FfttApi XML invalide : " . implode(', ', $errors));
        }

        // Conversion récursive SimpleXMLElement → tableau PHP natif
        return json_decode(json_encode($obj), true) ?? [];
    }
}
