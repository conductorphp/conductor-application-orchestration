<?php
/**
 * GitElephant - An abstraction layer for git written in PHP
 * Copyright (C) 2013  Matteo Giachino
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see [http://www.gnu.org/licenses/].
 */

namespace ConductorAppOrchestration\GitElephant\Command;

use ConductorAppOrchestration\GitElephant\Repository;

/**
 * Stash command generator
 *
 * @author Matteo Giachino <matteog@gmail.com>
 * @author Kirk Madera <kmadera@robofirm.com>
 */
class StashCommand extends \GitElephant\Command\BaseCommand
{
    const STASH_COMMAND = 'stash';

    /**
     * constructor
     *
     * @param \GitElephant\Repository $repo The repository object this command
     *                                      will interact with
     */
    public function __construct(Repository $repo = null)
    {
        parent::__construct($repo);
    }

    /**
     *  Save your local modifications to a new stash, and run git reset --hard to revert them.
     *
     * @param string|null $message
     * @param boolean $includeUntracked
     * @param boolean $keepIndex
     */
    public function save($message = null, $includeUntracked = false, $keepIndex = false)
    {
        $this->clearAll();
        $this->addCommandName(self::STASH_COMMAND . ' save');
        if (!is_null($message)) {
            $this->addCommandSubject($message);
        }

        if ($includeUntracked) {
            $this->addCommandArgument('--include-untracked');
        }

        if ($keepIndex) {
            $this->addCommandArgument('--keep-index');
        }

        return $this->getCommand();
    }

}
