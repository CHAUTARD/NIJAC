<?php

namespace App\Libraries;

use Alamirault\FFTTApi\Service\UriGenerator;
use GuzzleHttp\Client;

/**
 * Accès aux endpoints FFTT non couverts par la façade FFTTApi de
 * alamirault/fftt-api (xml_division, xml_result_equ avec cx_poule,
 * xml_licence, xml_liste_joueur_o avec le champ echelon…). Réutilise
 * UriGenerator de la lib (même schéma d'authentification que FFTTApi, déjà
 * validé sur tous les endpoints migrés) mais appelle Guzzle directement
 * plutôt que de passer par FFTTClient::get(), pour garder accès à l'URL,
 * au code HTTP et à la réponse brute — remplace Classes/FfttApi.php.
 *
 * Parsing XML → tableau : même logique que l'ancien Classes/FfttApi.php
 * (l'API FFTT répond en ISO-8859-1 et laisse parfois des & non échappés).
 */
class FfttRawClient
{
    private const TIMEOUT = 20;

    private Client $http;
    private UriGenerator $uriGenerator;
    private string $lastUrl  = '';
    private int    $lastHttp = 0;
    private string $lastRaw  = '';

    public function __construct(string $appId, string $appKey)
    {
        $this->http         = new Client(['timeout' => self::TIMEOUT]);
        $this->uriGenerator = new UriGenerator($appId, $appKey);
    }

    public function lastUrl(): string { return $this->lastUrl; }
    public function lastHttp(): int   { return $this->lastHttp; }
    public function lastRaw(): string { return $this->lastRaw; }

    /**
     * @throws \RuntimeException si la requête échoue ou le XML est invalide
     */
    public function request(string $action, array $params = []): array
    {
        $this->lastUrl = $this->uriGenerator->generate($action, $params);

        $response       = $this->http->request('GET', $this->lastUrl, ['http_errors' => false]);
        $this->lastHttp = $response->getStatusCode();
        $raw            = (string) $response->getBody();
        $this->lastRaw  = $raw;

        if ($this->lastHttp !== 200) {
            throw new \RuntimeException("FfttRawClient HTTP {$this->lastHttp} — {$this->lastUrl}");
        }
        if ($raw === '') {
            throw new \RuntimeException('FfttRawClient : réponse vide');
        }

        return $this->parseXml($raw);
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
