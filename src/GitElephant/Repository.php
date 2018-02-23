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

namespace ConductorAppOrchestration\GitElephant;

use ConductorAppOrchestration\GitElephant\Command\StashCommand;
use GitElephant\Command\Caller\Caller;
use GitElephant\GitBinary;
use GitElephant\Exception;

/**
 * Temporary class override until PR https://github.com/matteosister/GitElephant/pull/128 gets merged
 *
 * Class Repository
 *
 * @package ConductorAppOrchestration\GitElephant
 */
class Repository extends \GitElephant\Repository
{
    /**
     * the caller instance
     *
     * @var Caller
     */
    private $caller;

    /**
     * Class constructor
     *
     * @param string         $repositoryPath the path of the git repository
     * @param GitBinary|null $binary         the GitBinary instance that calls the commands
     * @param string         $name           a repository name
     *
     * @throws Exception\InvalidRepositoryPathException
     */
    public function __construct($repositoryPath, GitBinary $binary = null, $name = null)
    {
        parent::__construct($repositoryPath, $binary, $name);
        if (is_null($binary)) {
            $binary = new GitBinary();
        }

        $this->caller = new Caller($binary, $repositoryPath);
    }

    /**
     *  Save your local modifications to a new stash, and run git reset --hard to revert them.
     *
     * @param string|null $message
     * @param boolean $includeUntracked
     * @param boolean $keepIndex
     */
    public function stash($message = null, $includeUntracked = false, $keepIndex = false)
    {
        $command = (StashCommand::getInstance($this))->save($message, $includeUntracked, $keepIndex);
        $this->caller->execute($command);
    }
}
