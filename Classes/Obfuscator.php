<?php
/**
 * Obfuscateur entier ↔ chaîne alphanumérique (8 caractères)
 *
 * Principe identique à C# Kent.Cryptography.Obfuscation :
 *   $o = new Obfuscator(167);
 *   $code = $o->obfuscate(127);     // ex : "xVrAndNb"
 *   $id   = $o->deobfuscate($code); // 127
 *
 * Algorithme :
 *   1. Alphabet base-62 mélangé par Fisher-Yates + LCG (seed).
 *   2. Hash multiplicatif de Knuth  : h = (n × a) mod 2^32  (a impair, dérivé du seed).
 *   3. Encodage de h en 6 chiffres base-62 dans l'alphabet mélangé.
 *   4. 2 chiffres décoratifs (dépendent du seed + somme des 6 premiers) → token de 8 chars.
 *   Décodage : inverse les étapes 3 puis 2.
 *
 * Prérequis PHP : extension bcmath (activée par défaut sous WAMP).
 */
class Obfuscator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    private const BASE     = 62;
    private const LEN      = 8;    // longueur totale du token
    private const SIG      = 6;    // chiffres significatifs (suffisants pour 2^32)
    private const MOD      = '4294967296'; // 2^32

    private int   $seed;
    private array $enc;   // index (0-61) → caractère
    private array $dec;   // caractère   → index (0-61)
    private string $a;    // multiplicateur (odd, dérivé du seed) — string bcmath
    private string $ai;   // inverse mod 2^32 — string bcmath

    // ─────────────────────────────────────────────────────────────────────────
    public function __construct(int $seed)
    {
        $this->seed = $seed;
        [$this->enc, $this->dec] = $this->buildAlphabet($seed);
        [$this->a,   $this->ai]  = $this->buildMultipliers($seed);
    }

    // ── Alphabet mélangé (Fisher-Yates + LCG) ────────────────────────────────
    private function buildAlphabet(int $seed): array
    {
        $chars = str_split(self::ALPHABET);
        $s     = (string)(abs($seed) | 1); // seed impair de départ

        for ($i = self::BASE - 1; $i > 0; $i--) {
            // LCG : s = (1664525 × s + 1013904223) mod 2^31
            $s = bcmod(bcadd(bcmul($s, '1664525'), '1013904223'), '2147483648');
            $j = (int)bcmod($s, (string)($i + 1));
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return [$chars, array_flip($chars)];
    }

    // ── Multiplicateur de Knuth et son inverse mod 2^32 ──────────────────────
    private function buildMultipliers(int $seed): array
    {
        $s = (string)(abs($seed));

        // 5 passes LCG pour dériver un multiplicateur distinct du seed brut
        for ($i = 0; $i < 5; $i++) {
            $s = bcmod(bcadd(bcmul($s, '1664525'), '1013904223'), self::MOD);
        }

        // Forcer impair (indispensable pour que l'inverse mod 2^32 existe)
        $a = (bcmod($s, '2') === '0') ? bcadd($s, '1') : $s;
        if ($a === '0') $a = '1';

        $ai = $this->modInverse($a, self::MOD);
        return [$a, $ai];
    }

    // ── Inverse modulaire via l'algorithme d'Euclide étendu (bcmath) ─────────
    private function modInverse(string $a, string $m): string
    {
        $old_r = $a;  $r = $m;
        $old_s = '1'; $s = '0';

        while (bccomp($r, '0') !== 0) {
            $q     = bcdiv($old_r, $r, 0);
            [$old_r, $r] = [$r, bcsub($old_r, bcmul($q, $r))];
            [$old_s, $s] = [$s, bcsub($old_s, bcmul($q, $s))];
        }

        // Normaliser si négatif
        if (bccomp($old_s, '0') < 0) {
            $old_s = bcadd($old_s, $m);
        }
        return bcmod($old_s, $m);
    }

    // ── Obfuscation : entier → chaîne de LEN caractères ─────────────────────
    public function obfuscate(int $n): string
    {
        // Hash multiplicatif de Knuth mod 2^32
        $h = (int)bcmod(bcmul((string)abs($n), $this->a), self::MOD);

        // Encodage base-62 (SIG chiffres suffisent pour tout entier 32 bits)
        $digits  = [];
        $sum     = 0;
        $tmp     = $h;

        for ($i = 0; $i < self::SIG; $i++) {
            $d       = $tmp % self::BASE;
            $sum    += $d;
            $digits[] = $this->enc[$d];
            $tmp     = intdiv($tmp, self::BASE);
        }

        // 2 chiffres décoratifs (variation visuelle, non utilisés au décodage)
        $deco1   = ($sum            + abs($this->seed) * 7 + 3) % self::BASE;
        $deco2   = ($sum * 31 + 17  + abs($this->seed))         % self::BASE;
        $digits[] = $this->enc[$deco1];
        $digits[] = $this->enc[$deco2];

        return implode('', $digits);
    }

    // ── Déobfuscation : chaîne → entier  (-1 si token invalide) ─────────────
    public function deobfuscate(string $token): int
    {
        if (strlen($token) !== self::LEN) return -1;

        // Décoder uniquement les SIG premiers chiffres
        $h   = 0;
        $pow = 1;

        for ($i = 0; $i < self::SIG; $i++) {
            if (!array_key_exists($token[$i], $this->dec)) return -1;
            $h   += $this->dec[$token[$i]] * $pow;
            $pow *= self::BASE;
        }

        // Inverse du hash de Knuth mod 2^32
        $n = (int)bcmod(bcmul((string)$h, $this->ai), self::MOD);
        return $n;
    }
}
