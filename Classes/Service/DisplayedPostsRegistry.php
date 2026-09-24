<?php

declare(strict_types=1);

/*
 * This file is part of the package t3g/blog.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace T3G\AgencyPack\Blog\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Merkt sich, welche Beitraege auf der laufenden Seite schon ausgegeben wurden.
 *
 * Hintergrund: sobald ein Beitrag zweimal auf derselben Seite auftauchen kann -
 * hervorgehoben oben und noch einmal in der Liste darunter - braucht es eine
 * Stelle, an der beide Ausgaben voneinander wissen. Die Registrierung ist ein
 * Singleton und lebt genau einen Request lang.
 *
 * PostRepository::getFindAllQuery() fragt hier nach und schliesst die
 * gemerkten uids aus. Wer nichts registriert, aendert nichts: ohne Eintrag
 * bleibt die Abfrage so, wie sie ohne diese Klasse waere.
 *
 * Die Reihenfolge entscheidet. Wer zuerst rendert, gewinnt den Beitrag; die
 * spaetere Ausgabe laesst ihn weg. Im Regelfall erledigt das der
 * Beitragslisten-Controller selbst, der den hervorgehobenen Beitrag vor der
 * Liste holt und registriert. Stehen hervorgehobener Beitrag und Liste als
 * zwei getrennte Inhaltselemente auf der Seite, zaehlt deren Reihenfolge im
 * Seiteninhalt.
 */
class DisplayedPostsRegistry implements SingletonInterface
{
    /**
     * @var array<int, bool> uid => true
     */
    private array $uids = [];

    public function register(int $uid): void
    {
        if ($uid > 0) {
            $this->uids[$uid] = true;
        }
    }

    public function isRegistered(int $uid): bool
    {
        return isset($this->uids[$uid]);
    }

    /**
     * @return int[]
     */
    public function getUids(): array
    {
        return array_keys($this->uids);
    }

    public function reset(): void
    {
        $this->uids = [];
    }
}
