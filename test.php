<?php

use MoodleDeconstruct\Git;

require_once 'vendor/autoload.php';

Git::$defaultRepoDirectory = 'D:\Users\vas07101\Desktop\temp\moodle-deconstruct-repo';

$git = new Git();

// $lines = $git('rev-list --reverse --all')->lines;

$git->extractDirectory('auth/cas');