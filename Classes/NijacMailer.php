<?php
/**
 * PHPMailer avec garantie centrale du mode Développement (instancié par getNijacMailer()).
 *
 * Production ($adresseDev = null) : comportement PHPMailer strictement identique.
 * Développement : toute adresse passée à addAddress/addCC/addBCC/addReplyTo est remplacée
 * par $adresseDev (PHPMailer dédoublonne : l'adresse dev n'apparaît qu'une fois), les adresses
 * réelles remplacées sont tracées dans l'en-tête X-NIJAC-Dev-Original-To et, au send(), le sujet
 * reçoit le préfixe « [DEV → n adresse(s) réelle(s)] » s'il ne contient pas déjà « [DEV ».
 * L'expéditeur (setFrom) n'est pas touché.
 */
class NijacMailer extends \PHPMailer\PHPMailer\PHPMailer
{
    const ENTETE_DEV = 'X-NIJAC-Dev-Original-To';

    /** @var string|null Adresse de redirection en mode Développement ; null = Production. */
    private $adresseDev;

    /** @var array<string, string[]> Adresses réelles remplacées, par champ (to/cc/bcc/Reply-To). */
    private $originales = [];

    public function __construct($exceptions = null, ?string $adresseDev = null)
    {
        parent::__construct($exceptions);
        if ($adresseDev !== null && trim($adresseDev) === '') {
            throw new \RuntimeException('Mode Développement actif mais email_developpement non configuré : envoi bloqué');
        }
        $this->adresseDev = $adresseDev === null ? null : trim($adresseDev);
    }

    public function addAddress($address, $name = '')
    {
        return parent::addAddress(...$this->rediriger('to', $address, $name));
    }

    public function addCC($address, $name = '')
    {
        return parent::addCC(...$this->rediriger('cc', $address, $name));
    }

    public function addBCC($address, $name = '')
    {
        return parent::addBCC(...$this->rediriger('bcc', $address, $name));
    }

    public function addReplyTo($address, $name = '')
    {
        return parent::addReplyTo(...$this->rediriger('Reply-To', $address, $name));
    }

    public function clearAddresses()
    {
        unset($this->originales['to']);
        parent::clearAddresses();
    }

    public function clearCCs()
    {
        unset($this->originales['cc']);
        parent::clearCCs();
    }

    public function clearBCCs()
    {
        unset($this->originales['bcc']);
        parent::clearBCCs();
    }

    public function clearReplyTos()
    {
        unset($this->originales['Reply-To']);
        parent::clearReplyTos();
    }

    public function clearAllRecipients()
    {
        unset($this->originales['to'], $this->originales['cc'], $this->originales['bcc']);
        parent::clearAllRecipients();
    }

    public function preSend()
    {
        if ($this->adresseDev !== null) {
            $reelles = array_values(array_unique(array_merge([], ...array_values($this->originales))));
            // Mailer réutilisé (boucle clearAddresses/addAddress/send) : en-tête recalculé à chaque envoi.
            foreach ($this->CustomHeader as $k => $h) {
                if ($h[0] === self::ENTETE_DEV) {
                    unset($this->CustomHeader[$k]);
                }
            }
            if ($reelles) {
                $this->addCustomHeader(self::ENTETE_DEV, mb_substr(implode(', ', $reelles), 0, 500));
            }
            if (strpos((string) $this->Subject, '[DEV') === false) {
                $this->Subject = '[DEV → ' . count($reelles) . ' adresse(s) réelle(s)] ' . $this->Subject;
            }
        }
        return parent::preSend();
    }

    /** Retourne [adresse, nom] à transmettre à PHPMailer (inchangés en Production). */
    private function rediriger(string $kind, $address, $name): array
    {
        if ($this->adresseDev === null) {
            return [$address, $name];
        }
        $adr = strtolower(trim(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $address)));
        if ($adr !== '' && $adr !== strtolower($this->adresseDev)) {
            $this->originales[$kind][] = $adr;
        }
        if (strpos((string) $name, '@') !== false) {
            $name = ''; // le nom d'affichage exposerait une adresse réelle
        }
        return [$this->adresseDev, $name];
    }
}
