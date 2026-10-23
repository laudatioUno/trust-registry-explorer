<?php
// Psalm only: makes the functions from i18n.php known to the analysis
// (config.php and functions.php use t() without requiring i18n.php themselves).
require_once __DIR__ . '/i18n.php';
