<?php

namespace MoodleDeconstruct;

use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\OutputInterface;

class Git {

	public static $defaultRepoDirectory;

	public $repoDirectory;

	public $refsRoot = 'refs/deconstruct';

	protected $process;

	public function __construct($repoDirectory = null) {
		if (is_null($repoDirectory)) {
			if (is_null(static::$defaultRepoDirectory)) {
				throw new \Exception("Unable to determine repo directory");
			}
			$this->repoDirectory = static::$defaultRepoDirectory;
		} else {
			$this->repoDirectory = $repoDirectory;
		}
	}

	public function __invoke($command) {
		$this->process = $this->createProcess('git ' . $command);
		$this->process->mustRun();
		return $this;
	}

	public function createProcess($command) {
		//echo ": $command\n";
		$process = new Process($command);
		$process->setWorkingDirectory($this->repoDirectory);
		return $process;
	}

	public function __get($name) {
		$method = "_get_$name";
		if (method_exists($this, $method)) {
			return $this->$method();
		}
	}

	protected function _get_lines() {
	    $lines = trim($this->process->getOutput());

	    // Avoid array of single empty string
        if (empty($lines)) {
            return [];
        }

		return explode("\n", $lines);
	}

	public function lines(Callable $callback) {
		foreach ($this->_get_lines() as $line) {
			$callback->__invoke($line);
		}
	}

	protected function _get_all() {
		return trim($this->process->getOutput());
	}

	public function extractDirectory($directory) {

	    $refsRoot = $this->refsRoot . '/' . str_replace('/', '_', $directory);

		$allrevs = $this("rev-list --branches --reverse -- $directory")->lines;

		$messagefile = tempnam(sys_get_temp_dir(), 'extract');

		// First rev treated as root
		$root = true;
		foreach ($allrevs as $rev) {
			$commit = $this("cat-file commit $rev")->all;
			$commitdata = $this->parseCommit($commit);
			$commitobj = new \ArrayObject($commitdata);
			$newcommitdata = $commitobj->getArrayCopy();

			if ($root) {
				$newcommitdata['parent'] = [];
				$root = false;
			}

			// Rewrite parents
			$newparents = [];
			foreach($newcommitdata['parent'] as $parent) {
				// If parent also affected this directory we should have it
				// already mapped
				if (in_array($parent, $allrevs)) {
					$parentmap = $refsRoot . '/map/' . $parent;
				} else {
					// find the most recent ancestor of the parent that affected this directory
					// basically second line of
					//    git rev-list [root]..[parent] -- [dir]
					$between = $this("rev-list {$allrevs[0]}..{$parent} --max-count=2 -- $directory")->lines;

					if (count($between) == 2 && $between[0] == $parent) {
						echo "FOUND: {$between[1]}\n";
						$parentmap = $refsRoot . '/map/' . $between[1];
					} else if (count($between) >= 1 && $between[0] != $parent) {
					    echo "FOUND: {$between[0]}\n";
					    $parentmap = $refsRoot . '/map/' . $between[0];
				    } else {
						echo "NOT FOUND: Parent for $parent\n";
						print_r($between);
						continue;
					}
				}
				$mappedref = $this("show-ref $parentmap")->all;
				list ($mappedcommit, $ref) = explode(" ", $mappedref, 2);
				//echo "Mapped ref: $mappedref\n";
				$newparents[] = $mappedcommit;
			}

			$newcommitdata['parent'] = $newparents;

			// Read the relevant part of the tree into the index
			$this('read-tree --empty');
			$this("read-tree --prefix=$directory {$commitdata['tree']}");

			// Create a new tree
			$newcommitdata['tree'] = $this("write-tree")->all;

			// Create a new commit
			$command = 'git commit-tree ';
			foreach ($newcommitdata['parent'] as $parent) {
				$command .= " -p $parent ";
			}

			file_put_contents($messagefile, $newcommitdata['message']);
			$command .= " -F \"$messagefile\" {$newcommitdata['tree']}";

			$p = $this->createProcess($command);
			$env = ['PATH' => getenv('PATH')];
			foreach(['author', 'committer'] as $person) {
				foreach(['name', 'email', 'date'] as $data) {
					$env['GIT_' . strtoupper("{$person}_{$data}")] = $newcommitdata["{$person}_{$data}"];
				}
			}
			$p->setEnv($env);

			$p->mustRun();

			$newcommit = trim($p->getOutput());

			$newref = $refsRoot . '/map/' . $rev;
			$this("update-ref $newref $newcommit");

			echo "OLD COMMIT: $rev\nNEW COMMIT: $newcommit\n\n\n";
		}
	}

	/**
	 * Parse a commit object into array.
	 *
	 * @param string $commit the commit object (from cat-file)
	 */
	public function parseCommit($commit) {
		$result = ['parent' => []];
		$lines = explode("\n", trim($commit));

		while (!is_null($line = array_shift($lines))) {

			if (empty(trim($line))) {
				$result['message'] = implode("\n", $lines);
				break;
			}

			list($name, $value) = explode(" ", $line, 2);

			if ($name == 'parent') {
				$result['parent'][] = $value;
			} else {
				$result[$name] = $value;
			}

			if ($name == 'author' || $name == 'committer') {
				$matches = [];
				if (preg_match('/^(.*)<(.*)>(.*)$/', $value, $matches)) {
					$result["{$name}_name"] = $matches[1];
					$result["{$name}_email"] = $matches[2];
					$result["{$name}_date"] = $matches[3];
				} else {
					// what??
				}
			}
		}
		return $result;
	}

}