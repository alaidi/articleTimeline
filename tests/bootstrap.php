<?php

/**
 * @file plugins/generic/articleTimeline/tests/bootstrap.php
 *
 * Boots the real OJS install (autoloader + config) so the plugin classes and
 * Carbon load as they do in production. Needs a configured config.inc.php.
 */

require dirname(__DIR__, 4) . '/tools/bootstrap.php';
