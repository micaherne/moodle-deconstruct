<?php

use MoodleDeconstruct\Git;

require_once 'vendor/autoload.php';

Git::$defaultRepoDirectory = 'C:\dev\temp\moodle-split-old\moodle-split-plumbing\repos\composer-working';

$git = new Git();

$lines = $git('rev-list --reverse --all')->lines;

$git->extractDirectory('tests');