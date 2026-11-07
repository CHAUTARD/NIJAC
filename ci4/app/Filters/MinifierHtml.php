<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Allège le HTML envoyé au navigateur : retire indentations, lignes vides, espaces de fin de ligne
 * et commentaires HTML inutiles. Sans effet sur le rendu : un retour à la ligne reste un retour à la ligne
 * (donc un espace collapsé). Ne touche JAMAIS au contenu de <script>, <style>, <pre>, <textarea>, ni aux
 * commentaires conditionnels Outlook (<!--[if …]>, <![endif]-->), ni au JSON / aux autres types de réponse.
 */
class MinifierHtml implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $type = strtolower($response->getHeaderLine('Content-Type'));
        if ($type !== '' && !str_contains($type, 'text/html')) {
            return null;
        }
        $corps = (string) $response->getBody();
        if ($corps === '' || stripos($corps, '<html') === false) {
            return null;
        }

        // Blocs à préserver tels quels.
        $garde = [];
        $corps = preg_replace_callback(
            '#<(script|style|pre|textarea)\b[^>]*>.*?</\1\s*>#is',
            static function ($m) use (&$garde) {
                $garde[] = $m[0];
                return "\x01" . (count($garde) - 1) . "\x02";
            },
            $corps
        );
        if ($corps === null) {
            return null; // limite PCRE atteinte : on laisse la page intacte
        }

        // Commentaires HTML (sauf conditionnels Outlook / IE).
        $corps = preg_replace('#<!--(?!\[if|<!\[endif|\s*<!\[endif|\[endif).*?-->#s', '', $corps) ?? $corps;
        // Espaces de fin de ligne, indentations, lignes vides.
        $corps = preg_replace('#[ \t]+(\r?\n)#', '$1', $corps) ?? $corps;
        $corps = preg_replace('#(\r?\n)[ \t]+#', '$1', $corps) ?? $corps;
        $corps = preg_replace('#(\r?\n){2,}#', "\n", $corps) ?? $corps;
        $corps = trim($corps) . "\n";

        $corps = preg_replace_callback('#\x01(\d+)\x02#', static fn($m) => $garde[(int) $m[1]], $corps) ?? $corps;

        $response->setBody($corps);
        return $response;
    }
}
