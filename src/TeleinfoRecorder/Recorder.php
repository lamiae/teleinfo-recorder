<?php

/*
 * This file is part of the TeleinfoRecorder package.
 *
 * (c) Thomas Bibard <thomas.bibard@neblion.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TeleinfoRecorder;

use TeleinfoRecorder\Handler\HandlerInterface;

class Recorder {

    /**
     * @var string $name
     */
    protected $name = '';

    /**
     * @var TeleinfoRecorder/Reader $reader
     */
    protected $reader = null;

    /**
     * var array $processors
     */
    protected $processors = array();

    /**
     * var array $handlers
     */
    protected $handlers = array();


    protected $fields = array(
            'ADCO' => 'text', 'OPTARIF' => 'text', 'ISOUSC' => 'int', 'BASE' => 'int',
            'HCHP' => 'int', 'HCHC' => 'int', // Option heures creuses
            'EJPHN' => 'int', 'EJPHPM' => 'int', 'PEJP' => 'int', // Option EJP
            'BBRHCJB' => 'int', 'BBRHPJB' => 'int', 'BBRHCJW' => 'int', 'BBRHPJW' => 'int', 'BBRHCJR' => 'int', 'BBRHPJR' => 'int', // Option Tempo
            'PTEC' => 'text', 'DEMAIN'=> 'text', 'IINST' => 'int', 'ADPS' => 'int', 'IMAX' => 'int', 'PAPP' => 'int', 'HHPHC' => 'text', 'MOTDETAT' => 'text'
        );

    /**
     * Constructor
     *
     * @param TeleinfoRecorder/Reader    $reader Teleinfo reader
     */
    public function __construct($reader = null)
    {
        if (!is_null($reader)) {
            $this->reader = $reader;
        } else {
            $this->reader = new Reader();
        }
    }

    /**
     * Set name of the counter
     *
     * @param string    $name Name of the counter
     */
    public function setName($name) 
    {
        if (empty($name)) {
            throw new \InvalidArgumentException('You have to set name of the counter !');
        } else {
            $this->name = $name;
        }
    }

    /**
     * Return name of the counter
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Add a processor
     *
     * @param callable $callback function or class with __invoke
     * @param string $key Result key after processing record
     */
    public function pushProcessor($callback, $key)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Processors must be valid callables (callback or object with an __invoke method), '.var_export($callback, true).' given');
        }

        return array_push($this->processors, array('key' => $key, 'callback' => $callback));
    }

    /**
     * Pushes a handler on to the stack.
     *
     * @param HandlerInterface $handler
     */
    public function pushHandler(HandlerInterface $handler)
    {
        return array_unshift($this->handlers, $handler);
    }

    /**
     * Pops a handler from the stack
     *
     * @return HandlerInterface
     */
     public function popHandler()
     {
         if (!$this->handlers) {
             throw new \LogicException('You tried to pop from an empty handler stack.');
         }

         return array_shift($this->handlers);
     }

    /**
     * Calculate checksum for a message
     *
     * @see http://www.erdfdistribution.fr/medias/DTR_Racc_Comptage/ERDF-NOI-CPT_02E.pdf (page 12/60)
     */
    private function __calculateCheckSum($message)
    {
        // on retire le checksum fournit
        $msg  = trim(substr($message, 0, -1));
        // checksum
        $sum = 0;
        for ($i = 0; $i < strlen($msg); $i++) {
            $sum += ord($msg[$i]);
        }
        $sum = $sum & 0x3F;
        $sum += 0x20;
        return chr($sum);
    }

    /**
     * Check validity of a message
     *
     * @parma string $message
     * @return bool true|false
     *
     */
    public function isValidMessage($message) {
        $read = substr($message, -1);
        $calc = $this->__calculateCheckSum($message);
        if ($read === $calc) {
            return true;
        }

        return false;
    }

    /**
     * Read a record
     *
     */
    public function getRecord()
    {
        $read = array();

        $trame = $this->reader->getFrame();

        // create array frame
        $lignes = explode(chr(10).chr(10), $trame);

        // read each message in frame
        foreach($lignes as $message) {
            if (!$this->isValidMessage($message)) {
                throw new \LogicException('Checksum n\'est pas valide, la trame a pu être altérée !');
            }
            $elements = explode(chr(32), $message, 3);
            list($key, $value, $checksum) = $elements;
            if (!empty($key)) {
                if ($this->fields[$key] == 'int') {
                    $read[$key] = (int) $value;
                } else {
                    $read[$key] = $value;
                }
            }
        }

        return $read;
    }

    /**
     * Contrôle si l'enregistrement est valide, si il respecte les spécifications ERDF
     *
     * Ce contrôle ce borne pour le moment à vérifier si les clefs sont des clefs connus
     * dans la spécification. Contrôle fonctionnel pour les compteurs de type:
     * monophasé multarifs CBEMM + évolution ICC
     *
     * ToDO: gestion compteurs triphasé (CBETM)
     * ToDO: gestion compteurs Jaune (CJE)
     */
    private function __checkRecord(array $record)
    {
        foreach ($record as $key => $value) {
            if (!array_key_exists($key, $this->fields)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Contrôle si un enregistrement est valide
     *
     * @param array $record
     * @return bool
     */
    public function isValidRecord(array $record)
    {
        if ($this->__checkRecord($record)) {
            return true;
        }

        return false;
    }

    /**
     * Ajoute une date/heure au record
     *
     * @param array $record
     * return array
     */
    private function __addDateTime($record)
    {
        // ajout de la date et de l'heure de la lecture
        $date = new \DateTime('now');
        $record['datetime'] = $date->format('Y-m-d H:i:s');

        return $record;
    }

    /**
     * Traitement des processeurs
     *
     * @param array $record
     * @return array
     *
     */
    private function __processing($record)
    {
        if (!empty($this->processors)) {
            while (!empty($this->processors)) {
                $processor = array_shift($this->processors);
                $record[$processor['key']] = call_user_func($processor['callback'], $record);
            }
        }

        return $record;
    }


    /**
     * Write record
     */
    public function write()
    {
        // Lecture d'un enregistrement sur le bus
        $record = $this->getRecord();

        // Vérification de l'intégrité de l'enregistrement
        if (!$this->__checkRecord($record)) {
            throw new \LogicException('Record is not a valid record!');
        }

        // Ajout de la date de lecture de l'enregistrement
        $record = $this->__addDateTime($record);

        // Traitement processors
        $record = $this->__processing($record);

        // Traitement final de l'enregistrement
        foreach ($this->handlers as $handler) {
            $handler->handle($record);
        }
    }
}
