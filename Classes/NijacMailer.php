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
 *
 * Délivrabilité (envois en boucle) : chaque message reste une instance neuve (aucun cumul de
 * destinataires, d'en-têtes ou de pièces jointes d'un envoi à l'autre), mais la connexion SMTP
 * est partagée dans la requête PHP (SMTPKeepAlive, une connexion par hôte/port/compte, fermée à
 * la fin du script) et deux envois consécutifs sont espacés d'au moins 0,3 s (aucune pause
 * avant le premier ni après le dernier).
 */
class NijacMailer extends \PHPMailer\PHPMailer\PHPMailer
{
    const ENTETE_DEV = 'X-NIJAC-Dev-Original-To';

    /** Écart minimal entre deux envois consécutifs dans la même requête (microsecondes). */
    const PAUSE_ENTRE_ENVOIS_US = 300000;

    /** Supprime l'en-tête X-Mailer (PHPMailer 7 : '' = en-tête par défaut ; une chaîne blanche = aucun). */
    public $XMailer = ' ';

    /** @var array<string, \PHPMailer\PHPMailer\SMTP> Connexions SMTP partagées, par hôte/port/sécurité/compte. */
    private static $connexions = [];

    /** @var float|null Fin du dernier envoi de la requête (microtime) ; null = aucun envoi encore. */
    private static $dernierEnvoi = null;

    /** @var bool Cette instance utilise une connexion partagée (fermée en fin de script, pas au destructeur). */
    private $connexionPartagee = false;

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

    /**
     * À appeler une fois Host/Port/SMTPSecure/Username renseignés (getNijacMailer()) :
     * réutilise la connexion SMTP déjà ouverte par un envoi précédent de la requête.
     */
    public function activerConnexionPartagee(): void
    {
        $cle = implode('|', [$this->Host, $this->Port, $this->SMTPSecure, $this->Username]);
        if (!isset(self::$connexions[$cle])) {
            if (!self::$connexions) {
                register_shutdown_function([self::class, 'fermerConnexions']);
            }
            self::$connexions[$cle] = new \PHPMailer\PHPMailer\SMTP();
        }
        $this->setSMTPInstance(self::$connexions[$cle]);
        $this->SMTPKeepAlive     = true;
        $this->connexionPartagee = true;
    }

    /** Ferme proprement (QUIT) les connexions partagées — enregistré en fonction d'arrêt. */
    public static function fermerConnexions(): void
    {
        foreach (self::$connexions as $smtp) {
            try {
                if ($smtp->connected()) {
                    $smtp->quit();
                    $smtp->close();
                }
            } catch (\Throwable $e) {
            }
        }
        self::$connexions = [];
    }

    /** Pause entre deux envois consécutifs, puis envoi PHPMailer inchangé (succès/échec/exceptions). */
    public function send()
    {
        if (self::$dernierEnvoi !== null) {
            $ecoule = (int) ((microtime(true) - self::$dernierEnvoi) * 1000000);
            if ($ecoule < self::PAUSE_ENTRE_ENVOIS_US) {
                usleep(self::PAUSE_ENTRE_ENVOIS_US - $ecoule);
            }
        }
        try {
            return parent::send();
        } finally {
            self::$dernierEnvoi = microtime(true);
        }
    }

    /** Une connexion partagée reste ouverte pour l'envoi suivant (fermée par fermerConnexions()). */
    public function __destruct()
    {
        if (!$this->connexionPartagee) {
            parent::__destruct();
        }
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
